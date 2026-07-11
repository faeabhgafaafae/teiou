<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || ($input['api_key'] ?? '') !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data       = $input['data'] ?? [];
$date       = $data['date']       ?? '';
$venue      = $data['venue']      ?? '';
$race_no    = $data['race_no']    ?? 0;
$odds       = $data['odds']       ?? [];
$odds_multi = $data['odds_multi'] ?? [];

if (!$date || !$venue || !$race_no || (!$odds && !$odds_multi)) {
    http_response_code(400);
    echo json_encode(['error' => 'date, venue, race_no, odds(またはodds_multi) は必須です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$VALID_BET_TYPES = ['tansho', 'fukusho', 'rentan2', 'renfuku2', 'kakurenku', 'sanrenfuku'];

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

$upsert_multi = $pdo->prepare('
    INSERT INTO odds_multi (race_id, bet_type, combo, odds)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE odds = VALUES(odds)
');

$ok_multi = 0;

foreach ($odds_multi as $bet_type => $combos) {
    if (!in_array($bet_type, $VALID_BET_TYPES, true) || !is_array($combos)) {
        continue;
    }
    foreach ($combos as $combo => $odds_val) {
        try {
            $upsert_multi->execute([$race_id, $bet_type, $combo, $odds_val]);
            $ok_multi++;
        } catch (PDOException $e) {
            $errors[] = ['combo' => $bet_type . ':' . $combo, 'message' => $e->getMessage()];
        }
    }
}

// オッズの更新時刻は直前情報用のbefore_updated_atとは独立して管理する
$pdo->prepare('UPDATE races SET odds_updated_at = NOW() WHERE id = ?')->execute([$race_id]);

echo json_encode([
    'race_id'  => (int)$race_id,
    'ok'       => $ok,
    'ok_multi' => $ok_multi,
    'errors'   => $errors,
], JSON_UNESCAPED_UNICODE);
