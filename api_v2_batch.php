<?php
/**
 * api_v2_batch.php
 * 指定日の全レースにv2予測を一括生成・保存するバッチエンドポイント。
 * GitHub Actions の fetch_results ジョブから curl で呼び出す。
 *
 * GET: ?date=YYYY-MM-DD&api_key=xxxx
 */
// 未キャッチ例外をJSONで返す
set_exception_handler(function(Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error'   => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
        'trace'   => array_slice(array_map(fn($f) => ($f['file'] ?? '?') . ':' . ($f['line'] ?? 0), $e->getTrace()), 0, 5),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/predict_v2_core.php';

header('Content-Type: application/json; charset=utf-8');

// 認証
$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) {
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

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_connect_failed']);
    exit;
}

// ── Step1: 対象日の全エントリーを一括取得 ─────────────────────
$entry_stmt = $pdo->prepare("
    SELECT
        e.race_id,
        r.venue,
        e.lane,
        e.player_id,
        e.exhibit_time,
        e.start_timing,
        e.motor_2rate,
        r.wind_speed,
        r.wave_height,
        r.temperature
    FROM entries e
    JOIN races r ON e.race_id = r.id
    WHERE r.date = ?
    ORDER BY e.race_id, e.lane
");
$entry_stmt->execute([$date]);
$all_entries = $entry_stmt->fetchAll();

if (empty($all_entries)) {
    echo json_encode(['date' => $date, 'races' => 0, 'saved' => 0, 'message' => 'no entries']);
    exit;
}

// race_id ごとにグループ化
$races_map = [];
foreach ($all_entries as $e) {
    $rid = (int)$e['race_id'];
    if (!isset($races_map[$rid])) {
        $races_map[$rid] = [
            'weather'  => ['wind_speed' => $e['wind_speed'], 'wave_height' => $e['wave_height'], 'temperature' => $e['temperature']],
            'entries'  => [],
        ];
    }
    $races_map[$rid]['entries'][] = $e;
}

// ── Step2: 全プレイヤーの能力値を一括取得 ────────────────────
$player_ids = array_unique(array_column($all_entries, 'player_id'));
$ph         = implode(',', array_fill(0, count($player_ids), '?'));

$period_stmt = $pdo->prepare("
    SELECT pp.player_id, pp.win_rate, pp.fukusho_rate
    FROM player_periods pp
    WHERE pp.player_id IN ($ph)
      AND (pp.year * 10 + pp.period) = (
          SELECT MAX(pp2.year * 10 + pp2.period)
          FROM player_periods pp2
          WHERE pp2.player_id = pp.player_id
      )
");
$period_stmt->execute($player_ids);
$periods = [];
foreach ($period_stmt->fetchAll() as $row) {
    $periods[(int)$row['player_id']] = [
        'win_rate'     => (float)$row['win_rate'],
        'fukusho_rate' => (float)$row['fukusho_rate'],
    ];
}

// ── Step3: 当地2年間の成績を一括取得 ─────────────────────────
$cutoff = date('Y-m-d', strtotime('-2 years'));

// player_id + venue の組み合わせを収集
$venue_map = [];
foreach ($all_entries as $e) {
    $venue_map[(int)$e['player_id']][$e['venue']] = true;
}

$local_stmt = $pdo->prepare("
    SELECT res.player_id, rc.venue,
           COUNT(*)                                               as total,
           SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END)  as rank1,
           SUM(CASE WHEN res.actual_rank <= 2 THEN 1 ELSE 0 END) as rank2
    FROM results res
    JOIN races rc ON res.race_id = rc.id
    WHERE res.player_id IN ($ph)
      AND rc.date >= ?
    GROUP BY res.player_id, rc.venue
");
$local_stmt->execute(array_merge($player_ids, [$cutoff]));
$local_stats = [];
foreach ($local_stmt->fetchAll() as $row) {
    $pid   = (int)$row['player_id'];
    $venue = $row['venue'];
    $total = (int)$row['total'];
    $local_stats[$pid][$venue] = [
        'win' => $total > 0 ? $row['rank1'] / $total : null,
        'ni'  => $total > 0 ? $row['rank2'] / $total : null,
    ];
}

// ── Step4: レースごとにv2予測を計算・保存 ─────────────────────
$saved_races = 0;
$errors      = [];

foreach ($races_map as $race_id => $race_data) {
    $v2_entries = [];
    foreach ($race_data['entries'] as $e) {
        $pid    = (int)$e['player_id'];
        $venue  = $e['venue'];
        $period = $periods[$pid] ?? null;
        $local  = $local_stats[$pid][$venue] ?? null;

        $v2_entries[] = [
            'lane'           => (int)$e['lane'],
            'player_id'      => $pid,
            'exhibit_time'   => $e['exhibit_time'] !== null ? (float)$e['exhibit_time'] : null,
            'start_timing'   => $e['start_timing'] !== null ? (float)$e['start_timing'] : null,
            'motor_2rate'    => $e['motor_2rate']  !== null ? (float)$e['motor_2rate']  : null,
            'global_win_rate'=> $period ? $period['win_rate']     : 0,  // % スケール
            'global_2rate'   => $period ? $period['fukusho_rate'] : 0,  // % スケール
            'local_win_rate' => $local  ? $local['win']  : null,  // ratio
            'local_2rate'    => $local  ? $local['ni']   : null,  // ratio
        ];
    }

    try {
        $v2_results = PredictV2::score_race($v2_entries, $race_data['weather']);
        PredictV2::save_predictions($pdo, $race_id, $v2_results);
        $saved_races++;
    } catch (Exception $ex) {
        $errors[] = "race_id=$race_id: " . $ex->getMessage();
    }
}

echo json_encode([
    'date'        => $date,
    'races_total' => count($races_map),
    'races_saved' => $saved_races,
    'errors'      => count($errors),
    'error_detail'=> $errors,
], JSON_UNESCAPED_UNICODE);
