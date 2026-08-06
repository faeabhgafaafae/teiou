<?php
/**
 * バックフィル対象レース取得
 * is_hit=1 & payout=0 かつ odds_3t が未取得のレース一覧を返す
 * GET ?api_key=xxx
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->query('
    SELECT DISTINCT r.id AS race_id, r.date, r.venue, r.race_no
    FROM strategy_results sr
    JOIN races r ON r.id = sr.race_id
    WHERE sr.is_hit = 1
      AND sr.payout = 0
      AND NOT EXISTS (SELECT 1 FROM odds_3t o WHERE o.race_id = sr.race_id)
    ORDER BY r.date, r.venue, r.race_no
');
$races = $stmt->fetchAll();

echo json_encode([
    'count' => count($races),
    'races' => $races,
], JSON_UNESCAPED_UNICODE);
