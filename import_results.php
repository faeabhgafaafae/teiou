<?php
/**
 * 艇王 - 競走成績インポートAPI
 * download_results.py からJSONで受け取ってDBに登録し、
 * strategy_results に的中・払戻を記録する
 */

define('DB_HOST', 'mysql323.phy.lolipop.lan');
define('DB_NAME', 'LAA1670504-12');
define('DB_USER', 'LAA1670504');
define('DB_PASS', 'teiou');
define('API_KEY', 'teio2025');

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || ($input['api_key'] ?? '') !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

$records = $input['records'] ?? [];
if (empty($records)) {
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

// レースごとにグループ化
$race_groups = [];
foreach ($records as $r) {
    $key = $r['date'] . '|' . $r['venue'] . '|' . (int)$r['race_no'];
    $race_groups[$key][] = $r;
}

$ok        = 0;
$skip      = 0;
$first_err = null;

$stmt_race_upsert = $pdo->prepare('
    INSERT INTO races (date, venue, race_no, wind_speed, wind_dir, wave_height)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        wind_speed  = COALESCE(VALUES(wind_speed),  wind_speed),
        wind_dir    = COALESCE(VALUES(wind_dir),    wind_dir),
        wave_height = COALESCE(VALUES(wave_height), wave_height)
');
$stmt_race_sel = $pdo->prepare('SELECT id FROM races WHERE date = ? AND venue = ? AND race_no = ?');

$stmt_result = $pdo->prepare('
    INSERT INTO results (race_id, player_id, lane, actual_rank)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        lane        = VALUES(lane),
        actual_rank = VALUES(actual_rank)
');

$stmt_strats = $pdo->prepare('SELECT id, strategy_type, combinations FROM strategies WHERE race_id = ?');
$stmt_odds   = $pdo->prepare('SELECT odds FROM odds_3t WHERE race_id = ? AND combo = ? LIMIT 1');
$stmt_sr     = $pdo->prepare('
    INSERT INTO strategy_results (strategy_id, race_id, is_hit, payout, cost)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE is_hit = VALUES(is_hit), payout = VALUES(payout)
');

foreach ($race_groups as $race_records) {
    $r0      = $race_records[0];
    $date    = $r0['date'];
    $venue   = $r0['venue'];
    $race_no = (int)$r0['race_no'];

    // レース upsert
    $stmt_race_upsert->execute([
        $date, $venue, $race_no,
        $r0['wind_speed']  ?? null,
        $r0['wind_dir']    ?? null,
        $r0['wave_height'] ?? null,
    ]);

    $stmt_race_sel->execute([$date, $venue, $race_no]);
    $race = $stmt_race_sel->fetch();
    if (!$race) { $skip += count($race_records); continue; }
    $race_id = (int)$race['id'];

    // 成績 upsert・着順収集
    $finish = [];
    foreach ($race_records as $r) {
        try {
            $stmt_result->execute([
                $race_id,
                (int)$r['player_id'],
                (int)$r['lane'],
                (int)$r['actual_rank'],
            ]);
            $ok++;
        } catch (PDOException $e) {
            $skip++;
            if ($first_err === null) {
                $first_err = '[race_id=' . $race_id . ' player_id=' . (int)$r['player_id'] . '] ' . $e->getMessage();
            }
        }
        $finish[(int)$r['actual_rank']] = (int)$r['lane'];
    }

    // 1〜3着が揃っていなければ戦略照合しない
    if (!isset($finish[1], $finish[2], $finish[3])) continue;
    $winning_combo = $finish[1] . '-' . $finish[2] . '-' . $finish[3];

    // このレースの戦略一覧を取得
    $stmt_strats->execute([$race_id]);
    $strategies = $stmt_strats->fetchAll();
    if (!$strategies) continue;

    // 1着払戻オッズを取得
    $stmt_odds->execute([$race_id, $winning_combo]);
    $odds_row     = $stmt_odds->fetch();
    $winning_odds = $odds_row ? (float)$odds_row['odds'] : null;

    foreach ($strategies as $s) {
        $combos = json_decode($s['combinations'], true);
        $is_hit = in_array($winning_combo, $combos) ? 1 : 0;
        $cost   = count($combos) * 100;
        $payout = ($is_hit && $winning_odds !== null) ? (int)floor($winning_odds * 100) : 0;
        $stmt_sr->execute([(int)$s['id'], $race_id, $is_hit, $payout, $cost]);
    }
}

echo json_encode(['ok' => $ok, 'skip' => $skip, 'first_error' => $first_err], JSON_UNESCAPED_UNICODE);
