<?php
header('Content-Type: application/json; charset=utf-8');

define('DB_HOST', 'mysql323.phy.lolipop.lan');
define('DB_NAME', 'LAA1670504-12');
define('DB_USER', 'LAA1670504');
define('DB_PASS', 'teiou');
define('API_KEY', 'teio2025');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || ($input['api_key'] ?? '') !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data    = $input['data'] ?? [];
$date    = $data['date']    ?? '';
$venue   = $data['venue']   ?? '';
$race_no = $data['race_no'] ?? 0;
$players = $data['players'] ?? [];
$starts  = $data['start_exhibition'] ?? [];

if (!$date || !$venue || !$race_no || !$players) {
    http_response_code(400);
    echo json_encode(['error' => 'date, venue, race_no, players は必須です'], JSON_UNESCAPED_UNICODE);
    exit;
}

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

$stmt = $pdo->prepare('SELECT id FROM races WHERE date = ? AND venue = ? AND race_no = ?');
$stmt->execute([$date, $venue, (int)$race_no]);
$race = $stmt->fetch();

if (!$race) {
    http_response_code(404);
    echo json_encode(['error' => 'レースが見つかりません (races未登録)'], JSON_UNESCAPED_UNICODE);
    exit;
}

$race_id = $race['id'];

$update = $pdo->prepare('
    UPDATE entries
    SET exhibit_time = ?, start_timing = ?
    WHERE race_id = ? AND lane = ?
');

$ok = 0;
$errors = [];

foreach ($players as $p) {
    $lane         = (int)($p['waku'] ?? 0);
    $exhibit_time = isset($p['exhibit_time']) ? (float)$p['exhibit_time'] : null;
    // start_exhibition は waku 順（index 0 = 1号艇）
    $st = isset($starts[$lane - 1]['st']) ? (float)$starts[$lane - 1]['st'] : null;

    if (!$lane) continue;

    try {
        $update->execute([$exhibit_time, $st, $race_id, $lane]);
        $ok++;
    } catch (PDOException $e) {
        $errors[] = ['lane' => $lane, 'message' => $e->getMessage()];
    }
}

$pdo->prepare('UPDATE races SET before_updated_at = NOW() WHERE id = ?')->execute([$race_id]);

echo json_encode([
    'race_id' => (int)$race_id,
    'ok'      => $ok,
    'errors'  => $errors,
], JSON_UNESCAPED_UNICODE);
