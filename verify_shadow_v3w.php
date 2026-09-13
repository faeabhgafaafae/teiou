<?php
/**
 * verify_shadow_v3w.php (一時スクリプト・確認後削除)
 * v3wシャドウ切替の本番非影響検証用。読み取り専用・DB書き込みなし。
 *
 * 指定日の predictions(本番) / strategies(本番) / predictions_v2(シャドウ) の
 * 件数・最終更新時刻と、シャドウのrank1枠番分布(モデル指紋)を返す。
 * シャドウ実行の前後で呼び、本番側が不変であることを確認する。
 *
 * GET: ?date=YYYY-MM-DD&api_key=xxxx
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

require_admin_or_api_key($_GET['api_key'] ?? '');

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid date']);
    exit;
}

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

function table_stat(PDO $pdo, string $sql, string $d): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$d]);
    return $stmt->fetch();
}

$out = ['date' => $date];

$out['predictions_prod'] = table_stat($pdo, "
    SELECT COUNT(*) AS rows_cnt, COUNT(DISTINCT p.race_id) AS races,
           MAX(p.created_at) AS last_created
    FROM predictions p JOIN races r ON r.id = p.race_id
    WHERE r.date = ?", $date);

$out['strategies_prod'] = table_stat($pdo, "
    SELECT COUNT(*) AS rows_cnt, MAX(s.created_at) AS last_created
    FROM strategies s JOIN races r ON r.id = s.race_id
    WHERE r.date = ?", $date);

$out['predictions_v2_shadow'] = table_stat($pdo, "
    SELECT COUNT(*) AS rows_cnt, COUNT(DISTINCT p.race_id) AS races,
           MAX(p.created_at) AS last_created
    FROM predictions_v2 p JOIN races r ON r.id = p.race_id
    WHERE r.date = ?", $date);

// シャドウのrank1枠番分布 (モデル指紋: 旧v3は1号艇率~90%、v3wは~75%想定)
$stmt = $pdo->prepare("
    SELECT e.lane, COUNT(*) AS cnt
    FROM predictions_v2 p
    JOIN races r ON r.id = p.race_id
    JOIN entries e ON e.race_id = p.race_id AND e.player_id = p.player_id
    WHERE r.date = ? AND p.predicted_rank = 1
    GROUP BY e.lane ORDER BY e.lane");
$stmt->execute([$date]);
$dist = ['total' => 0];
foreach ($stmt->fetchAll() as $row) {
    $dist['lane' . $row['lane']] = (int)$row['cnt'];
    $dist['total'] += (int)$row['cnt'];
}
$dist['lane1_pct'] = $dist['total'] > 0
    ? round(($dist['lane1'] ?? 0) / $dist['total'] * 100, 1) : null;
$out['shadow_rank1_lane_dist'] = $dist;

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
