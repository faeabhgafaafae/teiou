<?php
/**
 * model_switch_status.php
 * v3w 本番切り替えの監視(読み取り専用・DB書き込みなし)。design_v3w_switch_20261001.md §3。
 * 日別に、予測の保存状況(v2=predictions / v3w=predictions_v2)と、
 * strategies / strategy_results の model_ref 別件数・的中・回収率、
 * 保存済み予測(結果取込み前に保存された値)の1着的中・1号艇1位率を返す。
 *
 * 呼び出し: ?api_key=xxx&from=YYYY-MM-DD&to=YYYY-MM-DD
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/model_switch.php';

header('Content-Type: application/json; charset=utf-8');

require_admin_or_api_key($_GET['api_key'] ?? '');

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-1 day'));
$to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    http_response_code(400);
    echo json_encode(['error' => 'from/to不正'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = get_db();

$daily = [];
$stmt = $pdo->prepare("
    SELECT r.date,
           COUNT(*)                     AS races,
           SUM(v2.race_id IS NOT NULL)  AS races_with_v2,
           SUM(v3.race_id IS NOT NULL)  AS races_with_v3w,
           SUM(res.race_id IS NOT NULL) AS races_settled
    FROM races r
    LEFT JOIN (SELECT DISTINCT race_id FROM predictions)    v2  ON v2.race_id  = r.id
    LEFT JOIN (SELECT DISTINCT race_id FROM predictions_v2) v3  ON v3.race_id  = r.id
    LEFT JOIN (SELECT DISTINCT race_id FROM results WHERE actual_rank IS NOT NULL) res ON res.race_id = r.id
    WHERE r.date BETWEEN ? AND ?
    GROUP BY r.date ORDER BY r.date
");
$stmt->execute([$from, $to]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $d = $row['date'];
    unset($row['date']);
    $daily[$d] = array_map('intval', $row) + ['strategies' => [], 'strategy_results' => []];
}

$stmt = $pdo->prepare("
    SELECT r.date, s.strategy_type, COALESCE(s.model_ref, 'NULL') AS model_ref, COUNT(*) AS n
    FROM strategies s JOIN races r ON r.id = s.race_id
    WHERE r.date BETWEEN ? AND ?
    GROUP BY r.date, s.strategy_type, s.model_ref
");
$stmt->execute([$from, $to]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $daily[$row['date']]['strategies'][$row['strategy_type']][$row['model_ref']] = (int)$row['n'];
}

$stmt = $pdo->prepare("
    SELECT r.date, s.strategy_type, COALESCE(sr.model_ref, 'NULL') AS model_ref,
           COUNT(*) AS n, SUM(sr.is_hit) AS hits, SUM(sr.cost) AS cost, SUM(sr.payout) AS payout
    FROM strategy_results sr
    JOIN strategies s ON s.id = sr.strategy_id
    JOIN races r ON r.id = sr.race_id
    WHERE r.date BETWEEN ? AND ?
    GROUP BY r.date, s.strategy_type, sr.model_ref
");
$stmt->execute([$from, $to]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $cost = (int)$row['cost'];
    $daily[$row['date']]['strategy_results'][$row['strategy_type']][$row['model_ref']] = [
        'n'        => (int)$row['n'],
        'hits'     => (int)$row['hits'],
        'cost'     => $cost,
        'payout'   => (int)$row['payout'],
        'hit_rate' => $row['n'] > 0 ? round($row['hits'] / $row['n'] * 100, 1) : null,
        'roi'      => $cost > 0 ? round($row['payout'] / $cost * 100, 1) : null,
    ];
}

// 保存済み予測の1着的中(v2・v3w とも予測があり結果確定済みのレースのみ。同着は枠番の小さい方)
$top = function (string $table): string {
    return "SELECT p.race_id, MIN(e.lane) AS lane
            FROM {$table} p JOIN entries e ON e.race_id = p.race_id AND e.player_id = p.player_id
            WHERE p.predicted_rank = 1 GROUP BY p.race_id";
};
$top_v2  = $top('predictions');
$top_v3w = $top('predictions_v2');
$stmt = $pdo->prepare("
    SELECT r.date,
           COUNT(*)              AS races,
           SUM(w.lane = p2.lane) AS v2_hits,
           SUM(w.lane = p3.lane) AS v3w_hits,
           SUM(w.lane = 1)       AS lane1_wins,
           SUM(p2.lane = 1)      AS v2_lane1_rank1,
           SUM(p3.lane = 1)      AS v3w_lane1_rank1
    FROM races r
    JOIN (SELECT race_id, MIN(lane) AS lane FROM results WHERE actual_rank = 1 GROUP BY race_id) w ON w.race_id = r.id
    JOIN ({$top_v2})  p2 ON p2.race_id = r.id
    JOIN ({$top_v3w}) p3 ON p3.race_id = r.id
    WHERE r.date BETWEEN ? AND ?
    GROUP BY r.date
");
$stmt->execute([$from, $to]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $d = $row['date'];
    unset($row['date']);
    $daily[$d]['top1'] = array_map('intval', $row);
}

echo json_encode([
    'from'   => $from,
    'to'     => $to,
    'config' => [
        'STRATEGY_MODEL_MAP'       => STRATEGY_MODEL_MAP,
        'STRATEGY_MODEL_FALLBACK'  => STRATEGY_MODEL_FALLBACK,
        'PREDICTION_DISPLAY_MODEL' => PREDICTION_DISPLAY_MODEL,
    ],
    'daily'  => $daily,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
