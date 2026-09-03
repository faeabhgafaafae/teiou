<?php
/**
 * api_v3_shadow.php
 * 指定日の全レースにv3シャドウ予測を一括生成し predictions_v2 テーブル(転用)に保存する。
 * boatrace.yml の fetch_results ジョブから呼ばれる(20:00=前日分 / 21:30=当日分)。
 *
 * 本番の predictions / strategies / 画面表示には一切書き込まない(design §4.2)。
 * 集計系特徴量(当地・コース別・直近10走)はすべて「対象日より前」のresultsのみを
 * 使うため、レース後に実行しても予測時点で利用可能だった情報と等価になる。
 *
 * GET: ?date=YYYY-MM-DD&api_key=xxxx
 */
set_exception_handler(function(Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error' => $e->getMessage(),
        'file'  => basename($e->getFile()),
        'line'  => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

set_time_limit(180);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/predict_v3_core.php';

header('Content-Type: application/json; charset=utf-8');

if (($_GET['api_key'] ?? '') !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid date']);
    exit;
}

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// ── Step1: 対象日の全エントリー ─────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT e.race_id, r.venue, e.lane, e.player_id,
           e.exhibit_time, e.start_timing, e.motor_2rate,
           r.wind_speed, r.wave_height
    FROM entries e
    JOIN races r ON e.race_id = r.id
    WHERE r.date = ?
    ORDER BY e.race_id, e.lane
");
$stmt->execute([$date]);
$all_entries = $stmt->fetchAll();

if (empty($all_entries)) {
    echo json_encode(['date' => $date, 'races' => 0, 'saved' => 0, 'message' => 'no entries']);
    exit;
}

$races_map = [];
foreach ($all_entries as $e) {
    $rid = (int)$e['race_id'];
    if (!isset($races_map[$rid])) {
        $races_map[$rid] = [
            'weather' => ['wind_speed' => $e['wind_speed'], 'wave_height' => $e['wave_height']],
            'entries' => [],
        ];
    }
    $races_map[$rid]['entries'][] = $e;
}

$player_ids = array_values(array_unique(array_column($all_entries, 'player_id')));
$ph         = implode(',', array_fill(0, count($player_ids), '?'));

// ── Step2: 期別成績(最新期=実行時点でのas-of正) ──────────────────────
$stmt = $pdo->prepare("
    SELECT player_id, win_rate, avg_st, grade
    FROM player_periods
    WHERE player_id IN ($ph)
    ORDER BY player_id, year DESC, period DESC
");
$stmt->execute($player_ids);
$periods = [];
foreach ($stmt->fetchAll() as $row) {
    $pid = (int)$row['player_id'];
    if (!isset($periods[$pid])) {
        $periods[$pid] = [
            'win_rate' => $row['win_rate'] !== null ? (float)$row['win_rate'] : null,
            'avg_st'   => $row['avg_st']   !== null ? (float)$row['avg_st']   : null,
            'grade'    => $row['grade'],
        ];
    }
}

// ── Step3: 当地2年成績(対象日より前のみ) ─────────────────────────────
$cutoff_2y = date('Y-m-d', strtotime($date . ' -2 years'));
$stmt = $pdo->prepare("
    SELECT res.player_id, rc.venue,
           COUNT(*) AS total,
           SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END) AS rank1
    FROM results res
    JOIN races rc ON res.race_id = rc.id
    WHERE res.player_id IN ($ph)
      AND rc.date >= ? AND rc.date < ?
    GROUP BY res.player_id, rc.venue
");
$stmt->execute(array_merge($player_ids, [$cutoff_2y, $date]));
$local_stats = [];
foreach ($stmt->fetchAll() as $row) {
    $t = (int)$row['total'];
    $local_stats[(int)$row['player_id']][$row['venue']] = $t > 0 ? $row['rank1'] / $t : null;
}

// ── Step4: コース別成績(枠番×選手、2年、対象日より前のみ) ─────────────
$stmt = $pdo->prepare("
    SELECT res.player_id, res.lane,
           COUNT(*) AS total,
           SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END)  AS rank1,
           SUM(CASE WHEN res.actual_rank <= 2 THEN 1 ELSE 0 END) AS rank2
    FROM results res
    JOIN races rc ON res.race_id = rc.id
    WHERE res.player_id IN ($ph)
      AND rc.date >= ? AND rc.date < ?
    GROUP BY res.player_id, res.lane
");
$stmt->execute(array_merge($player_ids, [$cutoff_2y, $date]));
$course_stats = [];
foreach ($stmt->fetchAll() as $row) {
    $course_stats[(int)$row['player_id']][(int)$row['lane']] =
        [(int)$row['total'], (int)$row['rank1'], (int)$row['rank2']];
}

// ── Step5: 直近10走(180日、対象日より前、actual_rankありのみ) ─────────
$cutoff_180 = date('Y-m-d', strtotime($date . ' -180 days'));
$stmt = $pdo->prepare("
    SELECT res.player_id, rc.date, res.actual_rank, res.start_timing
    FROM results res
    JOIN races rc ON res.race_id = rc.id
    WHERE res.player_id IN ($ph)
      AND rc.date >= ? AND rc.date < ?
      AND res.actual_rank IS NOT NULL
    ORDER BY res.player_id, rc.date
");
$stmt->execute(array_merge($player_ids, [$cutoff_180, $date]));
$hist = []; // pid => [[rank, st|null], ...] 日付昇順
foreach ($stmt->fetchAll() as $row) {
    $hist[(int)$row['player_id']][] = [
        (int)$row['actual_rank'],
        $row['start_timing'] !== null ? (float)$row['start_timing'] : null,
    ];
}

function recent10_stats(array $hist, int $pid): array {
    $rows = $hist[$pid] ?? [];
    $n = count($rows);
    if ($n < 3) return [null, null, null]; // 3走未満は信頼性不足(学習時と同一基準)
    $slice = array_slice($rows, max(0, $n - 10));
    $cnt = count($slice);
    $sum_rank = 0; $wins = 0; $st_sum = 0.0; $st_cnt = 0;
    foreach ($slice as [$rank, $st]) {
        $sum_rank += $rank;
        if ($rank === 1) $wins++;
        if ($st !== null) { $st_sum += $st; $st_cnt++; }
    }
    return [
        round($sum_rank / $cnt, 4),
        round($wins / $cnt, 4),
        $st_cnt > 0 ? round($st_sum / $st_cnt, 4) : null,
    ];
}

// ── Step6: レースごとにv3予測を計算・保存 ────────────────────────────
$saved_races = 0;
$errors      = [];

foreach ($races_map as $race_id => $race_data) {
    $v3_entries = [];
    foreach ($race_data['entries'] as $e) {
        $pid   = (int)$e['player_id'];
        $lane  = (int)$e['lane'];
        $venue = $e['venue'];
        $pp    = $periods[$pid] ?? null;
        [$c_total, $c_r1, $c_r2] = $course_stats[$pid][$lane] ?? [0, 0, 0];
        [$r10_rank, $r10_win, $r10_st] = recent10_stats($hist, $pid);

        $v3_entries[] = [
            'lane'              => $lane,
            'player_id'         => $pid,
            'exhibit_time'      => $e['exhibit_time'] !== null ? (float)$e['exhibit_time'] : null,
            'start_timing'      => $e['start_timing'] !== null ? (float)$e['start_timing'] : null,
            'motor_2rate'       => $e['motor_2rate']  !== null ? (float)$e['motor_2rate']  : null,
            'global_win_rate'   => $pp ? $pp['win_rate'] : null,
            'local_win_rate'    => $local_stats[$pid][$venue] ?? null,
            'avg_st'            => $pp ? $pp['avg_st'] : null,
            'grade'             => $pp ? $pp['grade'] : null,
            'course_rank1'      => $c_r1,
            'course_count'      => $c_total,
            'course_rank2'      => $c_r2,
            'recent10_avg_rank' => $r10_rank,
            'recent10_win_rate' => $r10_win,
            'recent10_st_mean'  => $r10_st,
        ];
    }

    try {
        $v3_results = PredictV3::score_race($v3_entries, $race_data['weather']);
        PredictV3::save_shadow_predictions($pdo, $race_id, $v3_results);
        $saved_races++;
    } catch (Exception $ex) {
        $errors[] = "race_id=$race_id: " . $ex->getMessage();
    }
}

echo json_encode([
    'date'         => $date,
    'model'        => 'v3_lr_shadow',
    'races_total'  => count($races_map),
    'races_saved'  => $saved_races,
    'errors'       => count($errors),
    'error_detail' => array_slice($errors, 0, 5),
], JSON_UNESCAPED_UNICODE);
