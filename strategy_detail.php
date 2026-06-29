<?php
define('DB_HOST', 'mysql323.phy.lolipop.lan');
define('DB_NAME', 'LAA1670504-12');
define('DB_USER', 'LAA1670504');
define('DB_PASS', 'teiou');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$venue   = $_GET['venue']   ?? '';
$date    = $_GET['date']    ?? '';
$race_no = (int)($_GET['race_no'] ?? 0);

if (!$venue || !$date || !$race_no) {
    echo json_encode(['strategies' => []], JSON_UNESCAPED_UNICODE);
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
    echo json_encode(['error' => 'DB接続失敗'], JSON_UNESCAPED_UNICODE);
    exit;
}

// race_id を取得
$stmt = $pdo->prepare('SELECT id FROM races WHERE date = ? AND venue = ? AND race_no = ? LIMIT 1');
$stmt->execute([$date, $venue, $race_no]);
$race = $stmt->fetch();
if (!$race) {
    echo json_encode(['strategies' => []], JSON_UNESCAPED_UNICODE);
    exit;
}
$race_id = (int)$race['id'];

// このレースの戦略を取得
$stmt = $pdo->prepare("
    SELECT strategy_type, combinations
    FROM strategies
    WHERE race_id = ?
    ORDER BY FIELD(strategy_type, '的中特化', 'バランス', '一撃重視', '絞り込み')
");
$stmt->execute([$race_id]);
$strats = $stmt->fetchAll();

if (!$strats) {
    echo json_encode(['race_id' => $race_id, 'strategies' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// 全コンボを収集（重複排除）
$all_combos  = [];
$strats_data = [];
foreach ($strats as $s) {
    $combos = json_decode($s['combinations'], true) ?? [];
    $strats_data[] = ['type' => $s['strategy_type'], 'combos' => $combos];
    foreach ($combos as $c) { $all_combos[$c] = null; }
}

// オッズを一括取得
$odds_map   = [];
$combo_keys = array_keys($all_combos);
if ($combo_keys) {
    $ph   = implode(',', array_fill(0, count($combo_keys), '?'));
    $stmt = $pdo->prepare('SELECT combo, odds FROM odds_3t WHERE race_id = ? AND combo IN (' . $ph . ')');
    $stmt->execute(array_merge([$race_id], $combo_keys));
    foreach ($stmt->fetchAll() as $row) {
        $odds_map[$row['combo']] = (float)$row['odds'];
    }
}

// レスポンス構築
$result = [];
foreach ($strats_data as $s) {
    $items = [];
    foreach ($s['combos'] as $c) {
        $items[] = [
            'combo' => $c,
            'odds'  => isset($odds_map[$c]) ? $odds_map[$c] : null,
        ];
    }
    $result[] = [
        'strategy_type' => $s['type'],
        'combinations'  => $items,
        'combo_count'   => count($s['combos']),
        'total_cost'    => count($s['combos']) * 100,
    ];
}

echo json_encode([
    'race_id'    => $race_id,
    'venue'      => $venue,
    'date'       => $date,
    'race_no'    => $race_no,
    'strategies' => $result,
], JSON_UNESCAPED_UNICODE);
