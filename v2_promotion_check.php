<?php
/**
 * v2_promotion_check.php - v2本番昇格判定用 読み取り専用診断
 * GET /v2_promotion_check.php?api_key=xxxx
 * 確認後に削除予定
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
set_time_limit(60);

$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗']);
    exit;
}

$since = date('Y-m-d', strtotime('-30 days'));  // 直近30日
$today = date('Y-m-d');

// ① predictions_v2 の日別レコード数 (連続性チェック)
$stmt = $pdo->prepare("
    SELECT r.date, COUNT(DISTINCT p2.race_id) AS v2_races
    FROM predictions_v2 p2
    JOIN races r ON r.id = p2.race_id
    WHERE r.date >= ? AND r.date <= ?
    GROUP BY r.date
    ORDER BY r.date
");
$stmt->execute([$since, $today]);
$v2_daily = $stmt->fetchAll();

// ② v1 日別集計 (predicted_rank=1 → actual_rank=1, + 1号艇ベースライン)
$stmt2 = $pdo->prepare("
    SELECT r.date,
           COUNT(DISTINCT p.race_id)                                      AS v1_races,
           SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END)           AS v1_hits,
           SUM(CASE WHEN lres.actual_rank = 1 THEN 1 ELSE 0 END)          AS lane1_hits
    FROM predictions p
    JOIN races r      ON r.id = p.race_id
    JOIN results res  ON res.race_id = p.race_id AND res.player_id = p.player_id
    JOIN results lres ON lres.race_id = p.race_id AND lres.lane = 1 AND lres.actual_rank IS NOT NULL
    WHERE p.predicted_rank = 1
      AND r.date >= ? AND r.date <= ?
    GROUP BY r.date
    ORDER BY r.date
");
$stmt2->execute([$since, $today]);
$v1_daily = [];
foreach ($stmt2->fetchAll() as $row) {
    $v1_daily[$row['date']] = $row;
}

// ③ v2 日別集計 (predicted_rank=1 → actual_rank=1)
$stmt3 = $pdo->prepare("
    SELECT r.date,
           COUNT(DISTINCT p2.race_id)                                     AS v2_races,
           SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END)           AS v2_hits
    FROM predictions_v2 p2
    JOIN races r      ON r.id = p2.race_id
    JOIN results res  ON res.race_id = p2.race_id AND res.player_id = p2.player_id
    WHERE p2.predicted_rank = 1
      AND r.date >= ? AND r.date <= ?
    GROUP BY r.date
    ORDER BY r.date
");
$stmt3->execute([$since, $today]);
$v2_hits_daily = [];
foreach ($stmt3->fetchAll() as $row) {
    $v2_hits_daily[$row['date']] = $row;
}

// ④ 全期間サマリー (v1/v2/baseline)
$stmt4 = $pdo->prepare("
    SELECT
        COUNT(DISTINCT p.race_id)                                          AS v1_races,
        SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END)              AS v1_hits,
        SUM(CASE WHEN lres.actual_rank = 1 THEN 1 ELSE 0 END)             AS lane1_hits
    FROM predictions p
    JOIN races r      ON r.id = p.race_id
    JOIN results res  ON res.race_id = p.race_id AND res.player_id = p.player_id
    JOIN results lres ON lres.race_id = p.race_id AND lres.lane = 1 AND lres.actual_rank IS NOT NULL
    WHERE p.predicted_rank = 1
      AND r.date >= ? AND r.date <= ?
");
$stmt4->execute([$since, $today]);
$v1_summary = $stmt4->fetch();

$stmt5 = $pdo->prepare("
    SELECT
        COUNT(DISTINCT p2.race_id)                                        AS v2_races,
        SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END)             AS v2_hits
    FROM predictions_v2 p2
    JOIN races r      ON r.id = p2.race_id
    JOIN results res  ON res.race_id = p2.race_id AND res.player_id = p2.player_id
    WHERE p2.predicted_rank = 1
      AND r.date >= ? AND r.date <= ?
");
$stmt5->execute([$since, $today]);
$v2_summary = $stmt5->fetch();

// ⑤ 欠損日の特定
$all_v1_dates  = array_keys($v1_daily);
$v2_date_set   = array_column($v2_daily, 'date');
$missing_v2    = array_diff($all_v1_dates, $v2_date_set);

// 日別マージ
$all_dates = array_unique(array_merge($all_v1_dates, $v2_date_set));
sort($all_dates);
$daily_comparison = [];
foreach ($all_dates as $d) {
    $v1 = $v1_daily[$d] ?? null;
    $v2 = $v2_hits_daily[$d] ?? null;
    $v1r = $v1 ? (int)$v1['v1_races'] : 0;
    $v1h = $v1 ? (int)$v1['v1_hits']  : 0;
    $bl  = $v1 ? (int)$v1['lane1_hits'] : 0;
    $v2r = $v2 ? (int)$v2['v2_races']  : 0;
    $v2h = $v2 ? (int)$v2['v2_hits']   : 0;
    $daily_comparison[] = [
        'date'        => $d,
        'v1_races'    => $v1r,
        'v1_hits'     => $v1h,
        'v1_rate'     => $v1r > 0 ? round($v1h / $v1r * 100, 1) : null,
        'v2_races'    => $v2r,
        'v2_hits'     => $v2h,
        'v2_rate'     => $v2r > 0 ? round($v2h / $v2r * 100, 1) : null,
        'diff_pt'     => ($v1r > 0 && $v2r > 0) ? round(($v2h / $v2r - $v1h / $v1r) * 100, 1) : null,
        'baseline_rate'=> $v1r > 0 ? round($bl / $v1r * 100, 1) : null,
    ];
}

echo json_encode([
    'period'    => ['from' => $since, 'to' => $today],
    'v1_summary' => [
        'races'       => (int)$v1_summary['v1_races'],
        'hits'        => (int)$v1_summary['v1_hits'],
        'hit_rate_pct'=> $v1_summary['v1_races'] > 0
            ? round($v1_summary['v1_hits'] / $v1_summary['v1_races'] * 100, 2) : null,
        'lane1_hits'  => (int)$v1_summary['lane1_hits'],
        'baseline_rate_pct' => $v1_summary['v1_races'] > 0
            ? round($v1_summary['lane1_hits'] / $v1_summary['v1_races'] * 100, 2) : null,
    ],
    'v2_summary' => [
        'races'       => (int)$v2_summary['v2_races'],
        'hits'        => (int)$v2_summary['v2_hits'],
        'hit_rate_pct'=> $v2_summary['v2_races'] > 0
            ? round($v2_summary['v2_hits'] / $v2_summary['v2_races'] * 100, 2) : null,
    ],
    'v2_continuity' => [
        'days_with_v2' => count($v2_date_set),
        'days_with_v1' => count($all_v1_dates),
        'missing_v2_dates' => array_values($missing_v2),
    ],
    'daily' => $daily_comparison,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
