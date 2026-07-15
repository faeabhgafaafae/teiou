<?php
/**
 * 艇王 - 成績・回収率: 戦略×会場 クロス集計API(standard/premium限定)
 * GET /get_performance_venue.php
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$user = current_user();
$plan = $user['plan'] ?? 'free';
if ($plan === 'free') {
    json_response(['error' => 'premium_required', 'message' => 'この機能はStandard/Premiumプラン限定です'], 403);
}

$pdo = get_db();

$stmt = $pdo->query('
    SELECT
        r.venue,
        s.strategy_type,
        COUNT(sr.id)                AS total_races,
        COALESCE(SUM(sr.is_hit), 0) AS hits,
        COALESCE(SUM(sr.cost), 0)   AS total_cost,
        COALESCE(SUM(sr.payout), 0) AS total_payout
    FROM strategy_results sr
    JOIN strategies s ON s.id = sr.strategy_id
    JOIN races r ON r.id = sr.race_id
    GROUP BY r.venue, s.strategy_type
    ORDER BY r.venue
');
$rows = $stmt->fetchAll();

$byVenue = [];
foreach ($rows as $row) {
    $total  = (int)$row['total_races'];
    $hits   = (int)$row['hits'];
    $cost   = (int)$row['total_cost'];
    $payout = (int)$row['total_payout'];
    $profit = $payout - $cost;

    $byVenue[] = [
        'venue'         => $row['venue'],
        'strategy_type' => $row['strategy_type'],
        'total_races'   => $total,
        'hit_rate'      => $total > 0 ? round($hits / $total * 100, 1) : 0,
        'total_cost'    => $cost,
        'total_payout'  => $payout,
        'roi'           => $cost > 0 ? round($profit / $cost * 100, 1) : 0,
    ];
}

json_response(['by_venue' => $byVenue]);
