<?php
/**
 * 艇王 - 会場横断 的中率比較ダッシュボードAPI(Premium限定)
 * GET /get_dashboard_comparison.php
 * 全会場・全期間の戦略別成績(会場×戦略のクロス集計)を返す。
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$user = current_user();
$plan = $user['plan'] ?? 'free';
if ($plan !== 'premium') {
    json_response(['error' => 'premium_required', 'message' => 'この機能はPremiumプラン限定です'], 403);
}

$pdo = get_db();

$stmt = $pdo->query('
    SELECT
        r.venue,
        s.strategy_type,
        COUNT(sr.id)                 AS total_races,
        COALESCE(SUM(sr.is_hit), 0)  AS hits,
        COALESCE(SUM(sr.cost), 0)    AS total_cost,
        COALESCE(SUM(sr.payout), 0)  AS total_payout
    FROM strategy_results sr
    JOIN strategies s ON s.id = sr.strategy_id
    JOIN races r       ON r.id = sr.race_id
    GROUP BY r.venue, s.strategy_type
    ORDER BY r.venue
');
$rows = $stmt->fetchAll();

$byVenue = [];
foreach ($rows as $row) {
    $total   = (int)$row['total_races'];
    $hits    = (int)$row['hits'];
    $cost    = (int)$row['total_cost'];
    $payout  = (int)$row['total_payout'];
    $profit  = $payout - $cost;

    $byVenue[] = [
        'venue'         => $row['venue'],
        'strategy_type' => $row['strategy_type'],
        'total_races'   => $total,
        'hits'          => $hits,
        'hit_rate'      => $total > 0 ? round($hits / $total * 100, 1) : 0,
        'total_cost'    => $cost,
        'total_payout'  => $payout,
        'roi'           => $cost > 0 ? round($profit / $cost * 100, 1) : 0,
    ];
}

json_response(['by_venue' => $byVenue]);
