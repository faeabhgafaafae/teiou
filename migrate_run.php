<?php
define('DB_HOST', 'mysql323.phy.lolipop.lan');
define('DB_NAME', 'LAA1670504-12');
define('DB_USER', 'LAA1670504');
define('DB_PASS', 'teiou');
define('API_KEY', 'teio2025');

header('Content-Type: application/json; charset=utf-8');

$api_key = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
if ($api_key !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

$sql_file = __DIR__ . '/migrations/add_weather_to_races.sql';
if (!file_exists($sql_file)) {
    http_response_code(500);
    echo json_encode(['error' => 'SQL file not found: ' . $sql_file]);
    exit;
}

$sql = file_get_contents($sql_file);
if ($sql === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to read SQL file']);
    exit;
}

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗: ' . $mysqli->connect_error]);
    exit;
}
$mysqli->set_charset('utf8mb4');

if ($mysqli->multi_query($sql)) {
    do {
        if ($res = $mysqli->store_result()) {
            $res->free();
        }
    } while ($mysqli->more_results() && $mysqli->next_result());
}

if ($mysqli->errno) {
    echo json_encode(['error' => 'SQL実行失敗: ' . $mysqli->error]);
} else {
    echo json_encode(['ok' => true, 'message' => 'マイグレーション完了: ' . basename($sql_file)]);
}

$mysqli->close();
