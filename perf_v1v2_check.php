<?php
// 一時エンドポイント: v1/v2 1着的中率・ベースライン集計 (使用後削除)
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
if (($_GET['api_key'] ?? '') !== API_KEY) { http_response_code(403); echo '{"error":"forbidden"}'; exit; }

$since = $_GET['since'] ?? '2026-06-01';

$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// v1: predicted_rank=1 の選手が actual_rank=1 だったか
$v1 = $pdo->prepare("
    SELECT COUNT(DISTINCT p.race_id) AS races,
           SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END) AS hits,
           SUM(CASE WHEN lres.actual_rank = 1 THEN 1 ELSE 0 END) AS lane1_hits
    FROM predictions p
    JOIN races r      ON r.id = p.race_id
    JOIN results res  ON res.race_id = p.race_id AND res.player_id = p.player_id
    JOIN results lres ON lres.race_id = p.race_id AND lres.lane = 1 AND lres.actual_rank IS NOT NULL
    WHERE p.predicted_rank = 1 AND r.date >= ?
");
$v1->execute([$since]);
$v1r = $v1->fetch();

// v2: predictions_v2 が存在するか確認してから集計
$has_v2 = false;
try { $pdo->query("SELECT 1 FROM predictions_v2 LIMIT 1"); $has_v2 = true; } catch (PDOException $e) {}

$v2r = ['races' => 0, 'hits' => 0];
if ($has_v2) {
    $v2 = $pdo->prepare("
        SELECT COUNT(DISTINCT p2.race_id) AS races,
               SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END) AS hits
        FROM predictions_v2 p2
        JOIN races r     ON r.id = p2.race_id
        JOIN results res ON res.race_id = p2.race_id AND res.player_id = p2.player_id
        WHERE p2.predicted_rank = 1 AND r.date >= ?
    ");
    $v2->execute([$since]);
    $v2r = $v2->fetch();
}

// 直近30日でも同様に集計
$since30 = date('Y-m-d', strtotime('-30 days'));
$v1b = $pdo->prepare("
    SELECT COUNT(DISTINCT p.race_id) AS races,
           SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END) AS hits,
           SUM(CASE WHEN lres.actual_rank = 1 THEN 1 ELSE 0 END) AS lane1_hits
    FROM predictions p
    JOIN races r      ON r.id = p.race_id
    JOIN results res  ON res.race_id = p.race_id AND res.player_id = p.player_id
    JOIN results lres ON lres.race_id = p.race_id AND lres.lane = 1 AND lres.actual_rank IS NOT NULL
    WHERE p.predicted_rank = 1 AND r.date >= ?
");
$v1b->execute([$since30]);
$v1r30 = $v1b->fetch();

$v2r30 = ['races' => 0, 'hits' => 0];
if ($has_v2) {
    $v2b = $pdo->prepare("
        SELECT COUNT(DISTINCT p2.race_id) AS races,
               SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END) AS hits
        FROM predictions_v2 p2
        JOIN races r     ON r.id = p2.race_id
        JOIN results res ON res.race_id = p2.race_id AND res.player_id = p2.player_id
        WHERE p2.predicted_rank = 1 AND r.date >= ?
    ");
    $v2b->execute([$since30]);
    $v2r30 = $v2b->fetch();
}

function pct($h, $t) { return $t > 0 ? round($h / $t * 100, 1) : null; }

echo json_encode([
    'since_provided' => [
        'since'       => $since,
        'v1_races'    => (int)$v1r['races'],
        'v1_hits'     => (int)$v1r['hits'],
        'v1_hit_rate' => pct($v1r['hits'], $v1r['races']),
        'lane1_hits'  => (int)$v1r['lane1_hits'],
        'lane1_rate'  => pct($v1r['lane1_hits'], $v1r['races']),
        'v2_races'    => (int)$v2r['races'],
        'v2_hits'     => (int)$v2r['hits'],
        'v2_hit_rate' => pct($v2r['hits'], $v2r['races']),
    ],
    'last_30days' => [
        'since'       => $since30,
        'v1_races'    => (int)$v1r30['races'],
        'v1_hits'     => (int)$v1r30['hits'],
        'v1_hit_rate' => pct($v1r30['hits'], $v1r30['races']),
        'lane1_hits'  => (int)$v1r30['lane1_hits'],
        'lane1_rate'  => pct($v1r30['lane1_hits'], $v1r30['races']),
        'v2_races'    => (int)$v2r30['races'],
        'v2_hits'     => (int)$v2r30['hits'],
        'v2_hit_rate' => pct($v2r30['hits'], $v2r30['races']),
    ],
], JSON_UNESCAPED_UNICODE);
