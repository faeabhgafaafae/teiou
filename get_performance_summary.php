<?php
/**
 * 艇王 - 成績・回収率: 全体サマリーAPI(無料公開)
 * GET /get_performance_summary.php
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$pdo = get_db();

$stmt = $pdo->query('
    SELECT
        s.strategy_type,
        COUNT(sr.id)                 AS total_races,
        COALESCE(SUM(sr.is_hit), 0)  AS hits,
        COALESCE(SUM(sr.cost), 0)    AS total_cost,
        COALESCE(SUM(sr.payout), 0)  AS total_payout
    FROM strategies s
    JOIN strategy_results sr ON sr.strategy_id = s.id
    GROUP BY s.strategy_type
    ORDER BY FIELD(s.strategy_type, \'的中特化\', \'バランス\', \'一撃重視\', \'絞り込み\')
');
$rows = $stmt->fetchAll();

$stats = [];
foreach ($rows as $row) {
    $total   = (int)$row['total_races'];
    $hits    = (int)$row['hits'];
    $cost    = (int)$row['total_cost'];
    $payout  = (int)$row['total_payout'];
    $profit  = $payout - $cost;

    $stats[] = [
        'strategy_type' => $row['strategy_type'],
        'total_races'   => $total,
        'hits'          => $hits,
        'hit_rate'      => $total > 0 ? round($hits / $total * 100, 1) : 0,
        'total_cost'    => $cost,
        'total_payout'  => $payout,
        'profit'        => $profit,
        'roi'           => $cost > 0 ? round($profit / $cost * 100, 1) : 0,
    ];
}

$stmt2 = $pdo->query('
    SELECT MIN(r.date) AS min_date, MAX(r.date) AS max_date, COUNT(DISTINCT sr.race_id) AS race_count
    FROM strategy_results sr
    JOIN races r ON r.id = sr.race_id
');
$range = $stmt2->fetch();

json_response([
    'date_range' => $range,
    'stats'      => $stats,
]);
