<?php
date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=utf-8');

define('DB_HOST', 'mysql323.phy.lolipop.lan');
define('DB_NAME', 'LAA1670504-12');
define('DB_USER', 'LAA1670504');
define('DB_PASS', 'teiou');
define('API_KEY', 'teio2025');

$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key'], JSON_UNESCAPED_UNICODE);
    exit;
}

$date = $_GET['date'] ?? date('Y-m-d');
$all  = ($_GET['all'] ?? '') === '1';

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($all) {
    $stmt = $pdo->prepare('
        SELECT id AS race_id, date, venue, race_no, scheduled_time,
               TIMESTAMPDIFF(MINUTE, NOW(), CONCAT(date, " ", scheduled_time)) AS minutes_until_deadline
        FROM races
        WHERE date = :date
          AND scheduled_time IS NOT NULL
        ORDER BY CONCAT(date, " ", scheduled_time) ASC
    ');
    $stmt->execute([':date' => $date]);
} else {
    // 現在時刻(JST)からscheduled_timeまで60分以内 かつ 締切前のレースのみ
    $stmt = $pdo->prepare('
        SELECT id AS race_id, date, venue, race_no, scheduled_time,
               TIMESTAMPDIFF(MINUTE, NOW(), CONCAT(date, " ", scheduled_time)) AS minutes_until_deadline
        FROM races
        WHERE date = :date
          AND scheduled_time IS NOT NULL
          AND CONCAT(date, " ", scheduled_time) >= NOW()
          AND CONCAT(date, " ", scheduled_time) <= NOW() + INTERVAL 60 MINUTE
        ORDER BY CONCAT(date, " ", scheduled_time) ASC
    ');
    $stmt->execute([':date' => $date]);
}
$races = $stmt->fetchAll();

echo json_encode([
    'count' => count($races),
    'races' => $races,
], JSON_UNESCAPED_UNICODE);
