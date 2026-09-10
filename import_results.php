<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settlement_lib.php';

/**
 * 艇王 - 競走成績インポートAPI
 * download_results.py からJSONで受け取ってDBに登録し、
 * strategy_results に的中・払戻を記録する
 */

header('Content-Type: application/json; charset=utf-8');

// GETパラメータではなくPOSTボディで api_key を受け取るのは、Webサーバーのアクセスログにキーを残さないため
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
        wave_height = COALESCE(VALUES(wave_height), wave_height) -- スクレイパーがNULLを送っても既存値を上書きしない
');
$stmt_race_sel = $pdo->prepare('SELECT id FROM races WHERE date = ? AND venue = ? AND race_no = ?');

$stmt_result = $pdo->prepare('
    INSERT INTO results (race_id, player_id, lane, actual_rank, time, start_timing, course)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        lane         = VALUES(lane),
        actual_rank  = VALUES(actual_rank),
        time         = VALUES(time),
        start_timing = VALUES(start_timing),
        course       = VALUES(course)
');

// stakes カラム(傾斜配分、2026-09-09導入)の有無を確認して照会SQLを切り替え
$has_stakes = true;
try {
    $pdo->query('SELECT stakes FROM strategies LIMIT 1');
} catch (PDOException $e) {
    $has_stakes = false;
}
$stmt_strats = $has_stakes
    ? $pdo->prepare('SELECT id, strategy_type, combinations, stakes FROM strategies WHERE race_id = ?')
    : $pdo->prepare('SELECT id, strategy_type, combinations FROM strategies WHERE race_id = ?');
$stmt_odds   = $pdo->prepare('SELECT odds FROM odds_3t WHERE race_id = ? AND combo = ? LIMIT 1');
$stmt_sr     = $pdo->prepare('
    INSERT INTO strategy_results (strategy_id, race_id, is_hit, payout, cost)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE is_hit = VALUES(is_hit), payout = VALUES(payout), cost = VALUES(cost)
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
                $r['race_time'] ?? null,
                isset($r['start_timing']) ? (float)$r['start_timing'] : null,
                isset($r['course']) ? (int)$r['course'] : null,
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
        // stakes(傾斜配分)があれば買い目ごとの金額で清算。なければ従来の1点100円均等
        $stakes = null;
        if ($has_stakes && !empty($s['stakes'])) {
            $decoded = json_decode($s['stakes'], true);
            if (is_array($decoded) && count($decoded) === count($combos)) {
                $stakes = $decoded;
            }
        }
        $settlement = calc_settlement($combos, $stakes, $winning_combo, $winning_odds);
        $stmt_sr->execute([(int)$s['id'], $race_id, $settlement['is_hit'], $settlement['payout'], $settlement['cost']]);
    }
}

echo json_encode(['ok' => $ok, 'skip' => $skip, 'first_error' => $first_err], JSON_UNESCAPED_UNICODE);
