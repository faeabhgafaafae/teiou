<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$venue   = $_GET['venue']   ?? '';
$date    = $_GET['date']    ?? '';
$race_no = (int)($_GET['race_no'] ?? 0);

if (!$venue || !$date || !$race_no) {
    echo json_encode(['strategies' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// AI予測由来の買い目候補の閲覧はStandard/Premium限定
$user = current_user();
$plan = $user['plan'] ?? 'free';
if (!$user || $plan === 'free') {
    json_response(['error' => 'premium_required', 'message' => 'AI予測はStandard/Premium会員限定です'], 403);
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

// レース結果を取得（1〜3着の艇番）
$stmt = $pdo->prepare('SELECT actual_rank, lane FROM results WHERE race_id = ? AND actual_rank IN (1, 2, 3)');
$stmt->execute([$race_id]);
$result_rows = $stmt->fetchAll();

$finish = [];
foreach ($result_rows as $r) {
    $finish[(int)$r['actual_rank']] = (int)$r['lane'];
}

$is_finished     = isset($finish[1], $finish[2], $finish[3]);
$hit_combination = null;
$hit_payout      = null;
$hit_odds        = null;

if ($is_finished) {
    $hit_combination = $finish[1] . '-' . $finish[2] . '-' . $finish[3];
    $stmt2 = $pdo->prepare('SELECT odds FROM odds_3t WHERE race_id = ? AND combo = ? LIMIT 1');
    $stmt2->execute([$race_id, $hit_combination]);
    $odds_row = $stmt2->fetch();
    if ($odds_row) {
        $hit_odds   = (float)$odds_row['odds'];
        $hit_payout = (int)floor($hit_odds * 100);
    }
}

// stakes カラム(傾斜配分、2026-09-09導入)の有無を確認
$has_stakes = true;
try {
    $pdo->query('SELECT stakes FROM strategies LIMIT 1');
} catch (PDOException $e) {
    $has_stakes = false;
}

// このレースの戦略を取得
if ($has_stakes) {
    $stmt = $pdo->prepare("
        SELECT strategy_type, combinations, stakes, stake_scheme
        FROM strategies
        WHERE race_id = ?
        ORDER BY FIELD(strategy_type, '的中特化', 'バランス', '一撃重視', '絞り込み')
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT strategy_type, combinations, NULL AS stakes, NULL AS stake_scheme
        FROM strategies
        WHERE race_id = ?
        ORDER BY FIELD(strategy_type, '的中特化', 'バランス', '一撃重視', '絞り込み')
    ");
}
$stmt->execute([$race_id]);
$strats = $stmt->fetchAll();

if (!$strats) {
    echo json_encode([
        'race_id'         => $race_id,
        'is_finished'     => $is_finished,
        'hit_combination' => $hit_combination,
        'hit_payout'      => $hit_payout,
        'hit_odds'        => $hit_odds,
        'strategies'      => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 全コンボを収集（重複排除）
$all_combos  = [];
$strats_data = [];
foreach ($strats as $s) {
    $combos = json_decode($s['combinations'], true) ?? [];
    $stakes = !empty($s['stakes']) ? (json_decode($s['stakes'], true) ?? []) : [];
    $strats_data[] = [
        'type'         => $s['strategy_type'],
        'combos'       => $combos,
        'stakes'       => $stakes,
        'stake_scheme' => $s['stake_scheme'] ?? null,
    ];
    foreach ($combos as $c) { $all_combos[$c] = null; } // キーによる重複排除(in_arrayより高速)
}

// 全戦略のコンボをまとめてIN句で一括取得（コンボ数分の個別クエリを避けるため）
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
    $items             = [];
    $strat_hit         = false;
    $strat_hit_payout  = null;
    foreach ($s['combos'] as $idx => $c) {
        $stake  = isset($s['stakes'][$idx]) ? (int)$s['stakes'][$idx] : 100;
        $is_hit = $is_finished && ($c === $hit_combination);
        if ($is_hit) {
            $strat_hit = true;
            if ($hit_odds !== null) {
                $strat_hit_payout = (int)floor($hit_odds * $stake);
            }
        }
        $items[] = [
            'combo'  => $c,
            'odds'   => isset($odds_map[$c]) ? $odds_map[$c] : null,
            'is_hit' => $is_hit,
            'stake'  => $stake,
        ];
    }
    $total_cost = array_sum(array_column($items, 'stake'));
    $result[] = [
        'strategy_type' => $s['type'],
        'combinations'  => $items,
        'combo_count'   => count($s['combos']),
        'total_cost'    => $total_cost,
        'stake_scheme'  => $s['stake_scheme'],
        'is_hit'        => $strat_hit,
        'hit_payout'    => $strat_hit_payout,
    ];
}

echo json_encode([
    'race_id'         => $race_id,
    'venue'           => $venue,
    'date'            => $date,
    'race_no'         => $race_no,
    'is_finished'     => $is_finished,
    'hit_combination' => $hit_combination,
    'hit_odds'        => $hit_odds,
    'hit_payout'      => $hit_payout,
    'strategies'      => $result,
], JSON_UNESCAPED_UNICODE);
