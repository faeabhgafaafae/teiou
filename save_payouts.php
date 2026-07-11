<?php
require_once __DIR__ . '/config.php';

/**
 * 艇王 - 払戻金インポートAPI
 * download_results.py からJSONで受け取り、race_payouts に登録する
 */

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || ($input['api_key'] ?? '') !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

$payouts = $input['payouts'] ?? [];
if (empty($payouts)) {
    echo json_encode(['ok' => 0, 'skip' => 0]);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗: ' . $e->getMessage()]);
    exit;
}

$stmt_race_sel = $pdo->prepare('SELECT id FROM races WHERE date = ? AND venue = ? AND race_no = ?');
$stmt_upsert   = $pdo->prepare('
    INSERT INTO race_payouts (race_id, bet_type, combo, amount, popularity)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        amount     = VALUES(amount),
        popularity = VALUES(popularity)
');

$race_id_cache = [];
$ok        = 0;
$skip      = 0;
$first_err = null;

foreach ($payouts as $p) {
    $date    = $p['date']    ?? '';
    $venue   = $p['venue']   ?? '';
    $race_no = (int)($p['race_no'] ?? 0);
    $key     = $date . '|' . $venue . '|' . $race_no;

    if (!array_key_exists($key, $race_id_cache)) {
        $stmt_race_sel->execute([$date, $venue, $race_no]);
        $race = $stmt_race_sel->fetch();
        $race_id_cache[$key] = $race ? (int)$race['id'] : null;
    }
    $race_id = $race_id_cache[$key];

    if (!$race_id) { $skip++; continue; }

    try {
        $stmt_upsert->execute([
            $race_id,
            $p['bet_type'] ?? '',
            $p['combo']    ?? '',
            (int)($p['amount'] ?? 0),
            isset($p['popularity']) && $p['popularity'] !== null ? (int)$p['popularity'] : null,
        ]);
        $ok++;
    } catch (PDOException $e) {
        $skip++;
        if ($first_err === null) {
            $first_err = '[race_id=' . $race_id . ' bet_type=' . ($p['bet_type'] ?? '') . ' combo=' . ($p['combo'] ?? '') . '] ' . $e->getMessage();
        }
    }
}

echo json_encode(['ok' => $ok, 'skip' => $skip, 'first_error' => $first_err], JSON_UNESCAPED_UNICODE);
