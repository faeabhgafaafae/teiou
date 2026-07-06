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
    SET exhibit_time   = COALESCE(?, exhibit_time),
        start_timing   = COALESCE(?, start_timing),
        adjust_weight  = COALESCE(?, adjust_weight),
        tilt           = COALESCE(?, tilt),
        propeller_mark = COALESCE(?, propeller_mark),
        parts_exchange = COALESCE(?, parts_exchange)
    WHERE race_id = ? AND lane = ?
');

$ok = 0;
$errors = [];

foreach ($players as $p) {
    $lane          = (int)($p['waku'] ?? 0);
    $exhibit_time  = isset($p['exhibit_time'])  ? (float)$p['exhibit_time']  : null;
    $adjust_weight = isset($p['adjust_weight']) ? (float)$p['adjust_weight'] : null;
    $tilt          = isset($p['tilt'])          ? (float)$p['tilt']          : null;
    $propeller     = $p['propeller_mark'] ?? null;
    $parts         = $p['parts_exchange'] ?? null;
    // start_exhibition は waku 順（index 0 = 1号艇）
    $st = isset($starts[$lane - 1]['st']) ? (float)$starts[$lane - 1]['st'] : null;

    if (!$lane) continue;

    try {
        $update->execute([$exhibit_time, $st, $adjust_weight, $tilt, $propeller, $parts, $race_id, $lane]);
        $ok++;
    } catch (PDOException $e) {
        $errors[] = ['lane' => $lane, 'message' => $e->getMessage()];
    }
}

// 気象情報を races に保存（wind_speed/wind_dir/wave_height/weather/temperature/water_temperature）
$wx = $data['weather'] ?? [];
if (!empty($wx)) {
    try {
        $pdo->prepare('
            UPDATE races
            SET wind_speed        = COALESCE(?, wind_speed),
                wind_dir          = COALESCE(?, wind_dir),
                wave_height       = COALESCE(?, wave_height),
                weather           = COALESCE(?, weather),
                temperature       = COALESCE(?, temperature),
                water_temperature = COALESCE(?, water_temperature)
            WHERE id = ?
        ')->execute([
            isset($wx['wind_speed'])        ? (float)$wx['wind_speed']        : null,
            $wx['wind_dir']                 ?? null,
            isset($wx['wave_height'])       ? (int)$wx['wave_height']         : null,
            $wx['weather']                  ?? null,
            isset($wx['temperature'])       ? (float)$wx['temperature']       : null,
            isset($wx['water_temperature']) ? (float)$wx['water_temperature'] : null,
            $race_id,
        ]);
    } catch (PDOException $e) {
        // カラム未追加時など非致命的エラーは無視
    }
}

$pdo->prepare('UPDATE races SET before_updated_at = NOW() WHERE id = ?')->execute([$race_id]);

echo json_encode([
    'race_id' => (int)$race_id,
    'ok'      => $ok,
    'errors'  => $errors,
], JSON_UNESCAPED_UNICODE);
