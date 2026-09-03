<?php
/**
 * export_lr_data_v3.php
 * v3モデル(design_v3_model_20260903.md)学習用データエクスポート。読み取り専用・DB書き込みなし。
 * 月次再学習の道具として恒久運用する(api_key必須)。
 *
 * 呼び出し:
 *   CSV出力 : ?api_key=xxx&from=2026-06-29&to=2026-07-12
 *   検品情報: ?api_key=xxx&from=2026-06-29&to=2026-07-12&stats=1
 *
 * 設計上の要点:
 * - 期間はチャンク指定(推奨2週間)。共有ホスティングのメモリ/実行時間制限対策
 * - 当地成績の先読みリーク修正: 「チャンク開始日より前」の基礎集計に
 *   チャンク内の増分をレース日ごとにPHP側で加算し、各レースについて
 *   厳密に「レース日より前」のデータのみで算出する(v2学習時のリークを解消)
 * - player_periodsはas-of JOIN: レース日時点で公開済みの最新期のみ使用。
 *   公開日は period=2(04=後期データ)→当年5/1、period=1(10=前期データ)→当年11/1 とみなす
 * - 直近10走: レース当日を除外した過去日、遡り180日、事故等でactual_rankがNULLの行は除外
 */

require_once __DIR__ . '/config.php';

if (($_GET['api_key'] ?? '') !== API_KEY) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '認証エラー'], JSON_UNESCAPED_UNICODE);
    exit;
}

$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'from/to (YYYY-MM-DD, from<=to) は必須です'], JSON_UNESCAPED_UNICODE);
    exit;
}

set_time_limit(180);

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// ── 検品モード: 日別×会場別レース数(JOIN成立/全体)を返す ─────────────────
// jcdバグ期間(〜2026-07-05, 影響10会場)のentriesがJOIN不成立で自動除外
// されていることの確認用(design §2.3)
if (($_GET['stats'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');

    // 結果が確定している全レース数
    $stmt = $pdo->prepare("
        SELECT r.date, r.venue, COUNT(DISTINCT r.id) AS races_total
        FROM races r
        WHERE r.date BETWEEN ? AND ?
          AND EXISTS (SELECT 1 FROM results res2 WHERE res2.race_id = r.id AND res2.actual_rank = 1)
        GROUP BY r.date, r.venue
        ORDER BY r.date, r.venue
    ");
    $stmt->execute([$from, $to]);
    $total_rows = $stmt->fetchAll();

    // 学習JOIN(entries×resultsのplayer_id一致)が成立するレース数
    $stmt = $pdo->prepare("
        SELECT r.date, r.venue, COUNT(DISTINCT e.race_id) AS races_matched
        FROM entries e
        JOIN races r   ON e.race_id = r.id
        JOIN results res ON res.race_id = e.race_id AND res.player_id = e.player_id
        WHERE r.date BETWEEN ? AND ?
          AND EXISTS (SELECT 1 FROM results res2 WHERE res2.race_id = r.id AND res2.actual_rank = 1)
        GROUP BY r.date, r.venue
        ORDER BY r.date, r.venue
    ");
    $stmt->execute([$from, $to]);
    $matched = [];
    foreach ($stmt->fetchAll() as $row) {
        $matched[$row['date'] . '|' . $row['venue']] = (int)$row['races_matched'];
    }

    $out = [];
    foreach ($total_rows as $row) {
        $key = $row['date'] . '|' . $row['venue'];
        $out[] = [
            'date'          => $row['date'],
            'venue'         => $row['venue'],
            'races_total'   => (int)$row['races_total'],
            'races_matched' => $matched[$key] ?? 0,
        ];
    }
    echo json_encode(['from' => $from, 'to' => $to, 'stats' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════ CSVエクスポート ═══════════

// ── 1. メイン: エントリー×結果(JOIN不成立=jcdバグ汚染行は自動除外) ───────
$stmt = $pdo->prepare("
    SELECT
        e.race_id, r.date, r.venue, e.lane, e.player_id,
        e.exhibit_time, e.start_timing, e.motor_2rate,
        r.wind_speed, r.wave_height, r.temperature,
        res.actual_rank,
        pr.score_total   AS score_total_current,
        pr.model_version AS model_version
    FROM entries e
    JOIN races r     ON e.race_id = r.id
    JOIN results res ON res.race_id = e.race_id AND res.player_id = e.player_id
    LEFT JOIN predictions pr ON pr.race_id = e.race_id AND pr.player_id = e.player_id
    WHERE r.date BETWEEN ? AND ?
      AND EXISTS (SELECT 1 FROM results res2 WHERE res2.race_id = r.id AND res2.actual_rank = 1)
    ORDER BY r.date, e.race_id, e.lane
");
$stmt->execute([$from, $to]);
$entries_all = $stmt->fetchAll();

if (empty($entries_all)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '対象データなし', 'from' => $from, 'to' => $to], JSON_UNESCAPED_UNICODE);
    exit;
}

$player_ids = array_values(array_unique(array_column($entries_all, 'player_id')));
$ph         = implode(',', array_fill(0, count($player_ids), '?'));

// ── 2. player_periods 全期データ(as-ofはPHP側で解決) ────────────────────
$stmt = $pdo->prepare("
    SELECT player_id, year, period, grade, win_rate, fukusho_rate, avg_st,
           c1_rank1, c2_rank1, c3_rank1, c4_rank1, c5_rank1, c6_rank1,
           c1_count, c2_count, c3_count, c4_count, c5_count, c6_count,
           c1_fukusho, c2_fukusho, c3_fukusho, c4_fukusho, c5_fukusho, c6_fukusho
    FROM player_periods
    WHERE player_id IN ($ph)
");
$stmt->execute($player_ids);
$pp_all = []; // player_id => [ [avail(int YYYYMMDD), row], ... ] avail降順
foreach ($stmt->fetchAll() as $row) {
    $y = (int)$row['year'];
    // 公開日: period=2(4月版データ)→当年5/1, period=1(10月版データ)→当年11/1
    $avail = ((int)$row['period'] === 2) ? ($y * 10000 + 501) : ($y * 10000 + 1101);
    $pp_all[(int)$row['player_id']][] = ['avail' => $avail, 'row' => $row];
}
foreach ($pp_all as &$list) {
    usort($list, fn($a, $b) => $b['avail'] <=> $a['avail']);
}
unset($list);

function pp_asof(array $pp_all, int $pid, int $date_int): ?array {
    foreach ($pp_all[$pid] ?? [] as $item) {
        if ($item['avail'] <= $date_int) return $item['row'];
    }
    return null;
}

// ── 3. 当地成績: 基礎集計(チャンク開始日より前2年) + チャンク内増分 ──────
// 各レースについて「レース日より前」のみを厳密集計(先読みリーク修正)
$cutoff_2y = date('Y-m-d', strtotime($from . ' -2 years'));

$stmt = $pdo->prepare("
    SELECT res.player_id, rc.venue,
           COUNT(*) AS total,
           SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END)  AS rank1,
           SUM(CASE WHEN res.actual_rank <= 2 THEN 1 ELSE 0 END) AS rank2
    FROM results res
    JOIN races rc ON res.race_id = rc.id
    WHERE res.player_id IN ($ph)
      AND rc.date >= ? AND rc.date < ?
    GROUP BY res.player_id, rc.venue
");
$stmt->execute(array_merge($player_ids, [$cutoff_2y, $from]));
$local_base = []; // pid => venue => [total, rank1, rank2]
foreach ($stmt->fetchAll() as $row) {
    $local_base[(int)$row['player_id']][$row['venue']] =
        [(int)$row['total'], (int)$row['rank1'], (int)$row['rank2']];
}

// チャンク内増分(レース日ごとにPHPで加算するため個票で取得)
$stmt = $pdo->prepare("
    SELECT res.player_id, rc.venue, rc.date, res.actual_rank
    FROM results res
    JOIN races rc ON res.race_id = rc.id
    WHERE res.player_id IN ($ph)
      AND rc.date >= ? AND rc.date <= ?
    ORDER BY rc.date
");
$stmt->execute(array_merge($player_ids, [$from, $to]));
$local_inc = []; // pid => venue => [ [date_int, rank|null], ... ] 日付昇順
foreach ($stmt->fetchAll() as $row) {
    $local_inc[(int)$row['player_id']][$row['venue']][] =
        [(int)str_replace('-', '', $row['date']),
         $row['actual_rank'] !== null ? (int)$row['actual_rank'] : null];
}

function local_asof(array $local_base, array $local_inc, int $pid, string $venue, int $date_int): array {
    [$total, $rank1, $rank2] = $local_base[$pid][$venue] ?? [0, 0, 0];
    foreach ($local_inc[$pid][$venue] ?? [] as $item) {
        if ($item[0] >= $date_int) break; // 日付昇順なので打ち切り可
        $total++;
        if ($item[1] !== null) {
            if ($item[1] === 1) $rank1++;
            if ($item[1] <= 2)  $rank2++;
        }
    }
    return [$total, $rank1, $rank2];
}

// ── 4. 直近10走(遡り180日、当日除外、actual_rank NULL除外) ──────────────
// メモリ対策: 選手ごとにフラット配列(date/rank/st)で保持
$cutoff_180 = date('Y-m-d', strtotime($from . ' -180 days'));

$stmt = $pdo->prepare("
    SELECT res.player_id, rc.date, res.actual_rank, res.start_timing
    FROM results res
    JOIN races rc ON res.race_id = rc.id
    WHERE res.player_id IN ($ph)
      AND rc.date >= ? AND rc.date <= ?
      AND res.actual_rank IS NOT NULL
    ORDER BY res.player_id, rc.date
");
$stmt->execute(array_merge($player_ids, [$cutoff_180, $to]));
$hist_d = []; $hist_r = []; $hist_s = []; // pid => [int date] / [int rank] / [float st|null]
while ($row = $stmt->fetch()) {
    $pid = (int)$row['player_id'];
    $hist_d[$pid][] = (int)str_replace('-', '', $row['date']);
    $hist_r[$pid][] = (int)$row['actual_rank'];
    $hist_s[$pid][] = $row['start_timing'] !== null ? (float)$row['start_timing'] : null;
}

function recent10(array $hist_d, array $hist_r, array $hist_s, int $pid, int $date_int): array {
    $dates = $hist_d[$pid] ?? [];
    $n = count($dates);
    if ($n === 0) return [null, null, null, 0];
    // date_int より前の最後のindexを二分探索
    $lo = 0; $hi = $n; // [lo, hi)
    while ($lo < $hi) {
        $mid = intdiv($lo + $hi, 2);
        if ($dates[$mid] < $date_int) $lo = $mid + 1; else $hi = $mid;
    }
    $end = $lo; // dates[0..end-1] が対象
    if ($end === 0) return [null, null, null, 0];
    $start = max(0, $end - 10);
    $cnt = $end - $start;
    if ($cnt < 3) return [null, null, null, $cnt]; // 3走未満は信頼性不足→NULL

    $sum_rank = 0; $wins = 0; $st_sum = 0.0; $st_cnt = 0;
    for ($i = $start; $i < $end; $i++) {
        $sum_rank += $hist_r[$pid][$i];
        if ($hist_r[$pid][$i] === 1) $wins++;
        if ($hist_s[$pid][$i] !== null) { $st_sum += $hist_s[$pid][$i]; $st_cnt++; }
    }
    return [
        round($sum_rank / $cnt, 4),
        round($wins / $cnt, 4),
        $st_cnt > 0 ? round($st_sum / $st_cnt, 4) : null,
        $cnt,
    ];
}

// ── 5. レース内 min/max (展示タイム・モーター相対化用) ──────────────────
$race_exhibit = []; $race_motor = [];
foreach ($entries_all as $e) {
    $rid = (int)$e['race_id'];
    if ($e['exhibit_time'] !== null) {
        $v = (float)$e['exhibit_time'];
        if (!isset($race_exhibit[$rid])) $race_exhibit[$rid] = [$v, $v];
        else { $race_exhibit[$rid][0] = min($race_exhibit[$rid][0], $v); $race_exhibit[$rid][1] = max($race_exhibit[$rid][1], $v); }
    }
    if ($e['motor_2rate'] !== null) {
        $v = (float)$e['motor_2rate'];
        if (!isset($race_motor[$rid])) $race_motor[$rid] = [$v, $v];
        else { $race_motor[$rid][0] = min($race_motor[$rid][0], $v); $race_motor[$rid][1] = max($race_motor[$rid][1], $v); }
    }
}

// ── 6. CSV出力 ──────────────────────────────────────────────────────────
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="lr_data_v3_' . $from . '_' . $to . '.csv"');

echo "race_id,date,venue,lane,player_id," .
     "global_win_rate,global_2rate,local_win_rate,local_2rate,local_race_cnt," .
     "grade_period,avg_st," .
     "course_rank1,course_count,course_fukusho," .
     "recent10_avg_rank,recent10_win_rate,recent10_st_mean,recent10_count," .
     "exhibit_time_raw,exhibit_time_rel,start_timing,motor_2rate,motor_2rate_rel," .
     "wind_speed,wave_height,temperature," .
     "score_total_current,model_version,is_winner\n";

foreach ($entries_all as $e) {
    $rid      = (int)$e['race_id'];
    $pid      = (int)$e['player_id'];
    $lane     = (int)$e['lane'];
    $venue    = $e['venue'];
    $date_int = (int)str_replace('-', '', $e['date']);

    // 期別成績 (as-of)
    $pp = pp_asof($pp_all, $pid, $date_int);
    $g_wr  = $pp !== null && $pp['win_rate']     !== null ? $pp['win_rate']     : '';
    $g_2r  = $pp !== null && $pp['fukusho_rate'] !== null ? $pp['fukusho_rate'] : '';
    $grade = $pp !== null && $pp['grade']        !== null ? $pp['grade']        : '';
    $avgst = $pp !== null && $pp['avg_st']       !== null ? $pp['avg_st']       : '';
    $c_r1  = $pp !== null && $pp["c{$lane}_rank1"]   !== null ? $pp["c{$lane}_rank1"]   : '';
    $c_cnt = $pp !== null && $pp["c{$lane}_count"]   !== null ? $pp["c{$lane}_count"]   : '';
    $c_fk  = $pp !== null && $pp["c{$lane}_fukusho"] !== null ? $pp["c{$lane}_fukusho"] : '';

    // 当地成績 (レース日より前のみ・リーク修正済み)
    [$l_total, $l_rank1, $l_rank2] = local_asof($local_base, $local_inc, $pid, $venue, $date_int);
    $local_wr = $l_total > 0 ? round($l_rank1 / $l_total, 4) : '';
    $local_2r = $l_total > 0 ? round($l_rank2 / $l_total, 4) : '';

    // 直近10走
    [$r10_rank, $r10_win, $r10_st, $r10_cnt] = recent10($hist_d, $hist_r, $hist_s, $pid, $date_int);

    // 展示タイム相対 (0=最遅, 1=最速)
    $ex_raw = $e['exhibit_time'];
    $ex_rel = '';
    if ($ex_raw !== null && isset($race_exhibit[$rid])) {
        $range  = $race_exhibit[$rid][1] - $race_exhibit[$rid][0];
        $ex_rel = $range > 1e-6 ? round(($race_exhibit[$rid][1] - (float)$ex_raw) / $range, 4) : 0.5;
    }

    // モーター相対
    $mo_raw = $e['motor_2rate'];
    $mo_rel = '';
    if ($mo_raw !== null && isset($race_motor[$rid])) {
        $range_m = $race_motor[$rid][1] - $race_motor[$rid][0];
        $mo_rel  = $range_m > 1e-6 ? round(((float)$mo_raw - $race_motor[$rid][0]) / $range_m, 4) : 0.5;
    }

    $row = [
        $rid, $e['date'], $venue, $lane, $pid,
        $g_wr, $g_2r, $local_wr, $local_2r, $l_total,
        $grade, $avgst,
        $c_r1, $c_cnt, $c_fk,
        $r10_rank !== null ? $r10_rank : '',
        $r10_win  !== null ? $r10_win  : '',
        $r10_st   !== null ? $r10_st   : '',
        $r10_cnt,
        $ex_raw !== null ? $ex_raw : '',
        $ex_rel,
        $e['start_timing'] !== null ? $e['start_timing'] : '',
        $mo_raw !== null ? $mo_raw : '',
        $mo_rel,
        $e['wind_speed']  !== null ? $e['wind_speed']  : '',
        $e['wave_height'] !== null ? $e['wave_height'] : '',
        $e['temperature'] !== null ? $e['temperature'] : '',
        $e['score_total_current'] !== null ? $e['score_total_current'] : '',
        $e['model_version'] !== null ? $e['model_version'] : '',
        ((int)$e['actual_rank'] === 1) ? 1 : 0,
    ];
    echo implode(',', $row) . "\n";
}
