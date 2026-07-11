<?php
require_once __DIR__ . '/config.php';

/**
 * 艇王 - 出走表インポートAPI
 * scrape_racelist.py からJSONで受け取ってDBに登録する
 */

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (($input['api_key'] ?? '') !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => '認証エラー']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗']);
    exit;
}

$data           = $input['data'] ?? [];
$date           = $data['date']           ?? null;
$venue          = $data['venue']          ?? null;
$race_no        = $data['race_no']        ?? null;
$players        = $data['players']        ?? [];
$scheduled_time = $data['scheduled_time'] ?? null;

if (!$date || !$venue || !$race_no) {
    echo json_encode(['error' => 'パラメータ不足']);
    exit;
}

// races テーブルにupsert（締切時刻も保存）
$stmt_race = $pdo->prepare("
    INSERT INTO races (date, venue, race_no, scheduled_time)
    VALUES (:date, :venue, :race_no, :scheduled_time)
    ON DUPLICATE KEY UPDATE
        scheduled_time = COALESCE(VALUES(scheduled_time), scheduled_time)
");
$stmt_race->execute([
    ':date'           => $date,
    ':venue'          => $venue,
    ':race_no'        => $race_no,
    ':scheduled_time' => $scheduled_time,
]);

// race_id取得
$s = $pdo->prepare("SELECT id FROM races WHERE date=? AND venue=? AND race_no=?");
$s->execute([$date, $venue, $race_no]);
$race_id = $s->fetchColumn();

if (!$race_id) {
    echo json_encode(['error' => 'race_id取得失敗']);
    exit;
}

// entries テーブルにupsert
$stmt_entry = $pdo->prepare("
    INSERT INTO entries
        (race_id, lane, player_id, motor_2rate)
    VALUES
        (:race_id, :lane, :player_id, :motor_2rate)
    ON DUPLICATE KEY UPDATE
        player_id=VALUES(player_id),
        motor_2rate=COALESCE(VALUES(motor_2rate), motor_2rate)
");

$ok = 0;
$errors = [];
foreach ($players as $p) {
    try {
        $stmt_entry->execute([
            ':race_id'     => $race_id,
            ':lane'        => $p['waku'],
            ':player_id'   => $p['player_id'],
            ':motor_2rate' => $p['motor_2rate'],
        ]);
        $ok++;
    } catch (PDOException $e) {
        $errors[] = [
            'lane'    => $p['waku'] ?? null,
            'message' => $e->getMessage(),
        ];
    }
}

echo json_encode([
    'race_id'        => $race_id,
    'ok'             => $ok,
    'errors'         => $errors,
    'scheduled_time' => $scheduled_time,
    'message'        => "{$ok}件登録完了" . (count($errors) > 0 ? "、" . count($errors) . "件失敗" : ""),
]);
