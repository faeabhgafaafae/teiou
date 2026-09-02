<?php
/**
 * バックフィル対象レース取得(指定日の全レース)
 * 2026-09-01分のodds_3tバックフィル用(使用後削除予定)
 * GET ?api_key=xxx&date=YYYY-MM-DD
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key'], JSON_UNESCAPED_UNICODE);
    exit;
}

$date = $_GET['date'] ?? '';
if (!$date) {
    echo json_encode(['error' => 'date は必須です'], JSON_UNESCAPED_UNICODE);
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

$stmt = $pdo->prepare('
    SELECT r.id AS race_id, r.date, r.venue, r.race_no
    FROM races r
    WHERE r.date = ?
    ORDER BY r.venue, r.race_no
');
$stmt->execute([$date]);
$races = $stmt->fetchAll();

echo json_encode([
    'count' => count($races),
    'races' => $races,
], JSON_UNESCAPED_UNICODE);
