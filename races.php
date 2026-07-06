<?php
/**
 * 艇王 - レース一覧API
 * GET /races.php?date=2026-06-17&venue=桐生
 */
define('DB_HOST', 'mysql323.phy.lolipop.lan');
define('DB_USER', 'LAA1670504');
define('DB_PASS', 'teiou');
define('DB_NAME', 'LAA1670504-12');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$date  = $_GET['date']  ?? date('Y-m-d');
$venue = $_GET['venue'] ?? '';

if (!$venue) {
    echo json_encode(['error' => 'venue は必須です']);
    exit;
}

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
    SELECT race_no, scheduled_time, wind_speed, wind_dir, wave_height,
           EXISTS(SELECT 1 FROM results res WHERE res.race_id = races.id) AS has_result
    FROM races
    WHERE date = ? AND venue = ?
    ORDER BY race_no
");
$stmt->execute([$date, $venue]);
$races = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($races as &$r) {
    $r['has_result'] = (bool)$r['has_result'];
}
unset($r);

echo json_encode([
    'date'  => $date,
    'venue' => $venue,
    'races' => $races,
], JSON_UNESCAPED_UNICODE);
