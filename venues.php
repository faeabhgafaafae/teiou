<?php
/**
 * 艇王 - 本日の開催場一覧API
 * GET /venues.php?date=2026-06-17
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$date = $_GET['date'] ?? date('Y-m-d');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo json_encode(['error' => 'DB接続失敗']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT venue, COUNT(*) as race_count,
           GROUP_CONCAT(race_no ORDER BY race_no) as race_nos_csv
    FROM races
    WHERE date = ?
    GROUP BY venue
    ORDER BY venue
");
$stmt->execute([$date]);
$venues = $stmt->fetchAll(PDO::FETCH_ASSOC);

// race_nos_csv を整数配列に変換して race_nos として返す
foreach ($venues as &$v) {
    $v['race_nos'] = array_map('intval', explode(',', $v['race_nos_csv'] ?? ''));
    unset($v['race_nos_csv']);
}
unset($v);

echo json_encode([
    'date'   => $date,
    'venues' => $venues,
], JSON_UNESCAPED_UNICODE);
