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

$data = $input['data'] ?? [];
$date    = $data['date']    ?? '';
$venue   = $data['venue']   ?? '';
$race_no = $data['race_no'] ?? 0;
$odds    = $data['odds']    ?? [];

if (!$date || !$venue || !$race_no || !$odds) {
    http_response_code(400);
    echo json_encode(['error' => 'date, venue, race_no, odds は必須です'], JSON_UNESCAPED_UNICODE);
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

$upsert = $pdo->prepare('
    INSERT INTO odds_3t (race_id, combo, odds)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE odds = VALUES(odds)
');

$ok = 0;
$errors = [];

foreach ($odds as $combo => $odds_val) {
    try {
        $upsert->execute([$race_id, $combo, $odds_val]);
        $ok++;
    } catch (PDOException $e) {
        $errors[] = ['combo' => $combo, 'message' => $e->getMessage()];
    }
}

$pdo->prepare('UPDATE races SET before_updated_at = NOW() WHERE id = ?')->execute([$race_id]);

echo json_encode([
    'race_id' => (int)$race_id,
    'ok'      => $ok,
    'errors'  => $errors,
], JSON_UNESCAPED_UNICODE);
