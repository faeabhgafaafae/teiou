<?php
/**
 * tmp: 的中率・回収率のA/Bパターン比較集計用一時エンドポイント(使用後にneutralize予定)
 * A) 生データそのまま
 * B) is_hit=1 かつ payout=0(オッズ欠損によるノイズ)を除外した実質ベース
 * GET /strategy_stats_ab_run.php?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$start_date = $_GET['start_date'] ?? null;
$end_date   = $_GET['end_date']   ?? null;

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗']);
    exit;
}

$where  = '';
$params = [];
if ($start_date) { $where .= ' AND r.date >= ?'; $params[] = $start_date; }
if ($end_date)   { $where .= ' AND r.date <= ?'; $params[] = $end_date;   }

$stmt = $pdo->prepare('
    SELECT
        s.strategy_type,
        COUNT(sr.id)                                                              AS total_races_a,
        COALESCE(SUM(sr.is_hit), 0)                                               AS hits_a,
        COALESCE(SUM(sr.cost), 0)                                                 AS cost_a,
        COALESCE(SUM(sr.payout), 0)                                               AS payout_a,
        COALESCE(SUM(CASE WHEN sr.is_hit = 1 AND sr.payout = 0 THEN 1 ELSE 0 END), 0) AS excluded,
        COUNT(sr.id) - COALESCE(SUM(CASE WHEN sr.is_hit = 1 AND sr.payout = 0 THEN 1 ELSE 0 END), 0) AS total_races_b,
        COALESCE(SUM(CASE WHEN NOT (sr.is_hit = 1 AND sr.payout = 0) THEN sr.is_hit ELSE 0 END), 0)   AS hits_b,
        COALESCE(SUM(CASE WHEN NOT (sr.is_hit = 1 AND sr.payout = 0) THEN sr.cost   ELSE 0 END), 0)   AS cost_b,
        COALESCE(SUM(CASE WHEN NOT (sr.is_hit = 1 AND sr.payout = 0) THEN sr.payout ELSE 0 END), 0)   AS payout_b
    FROM strategies s
    JOIN strategy_results sr ON sr.strategy_id = s.id
    JOIN races r ON r.id = s.race_id
    WHERE 1=1' . $where . '
    GROUP BY s.strategy_type
    ORDER BY FIELD(s.strategy_type, \'的中特化\', \'バランス\', \'一撃重視\', \'絞り込み\')
');
$stmt->execute($params);
$rows = $stmt->fetchAll();

$stats = [];
foreach ($rows as $row) {
    $totalA  = (int)$row['total_races_a'];
    $hitsA   = (int)$row['hits_a'];
    $costA   = (int)$row['cost_a'];
    $payoutA = (int)$row['payout_a'];
    $profitA = $payoutA - $costA;

    $totalB  = (int)$row['total_races_b'];
    $hitsB   = (int)$row['hits_b'];
    $costB   = (int)$row['cost_b'];
    $payoutB = (int)$row['payout_b'];
    $profitB = $payoutB - $costB;

    $stats[] = [
        'strategy_type' => $row['strategy_type'],
        'excluded'      => (int)$row['excluded'],
        'a_raw' => [
            'total_races' => $totalA,
            'hits'        => $hitsA,
            'hit_rate'    => $totalA > 0 ? round($hitsA / $totalA * 100, 1) : 0,
            'total_cost'  => $costA,
            'total_payout'=> $payoutA,
            'roi'         => $costA > 0 ? round($profitA / $costA * 100, 1) : 0,
        ],
        'b_excl_zero_payout_hit' => [
            'total_races' => $totalB,
            'hits'        => $hitsB,
            'hit_rate'    => $totalB > 0 ? round($hitsB / $totalB * 100, 1) : 0,
            'total_cost'  => $costB,
            'total_payout'=> $payoutB,
            'roi'         => $costB > 0 ? round($profitB / $costB * 100, 1) : 0,
        ],
    ];
}

echo json_encode([
    'start_date' => $start_date,
    'end_date'   => $end_date,
    'stats'      => $stats,
], JSON_UNESCAPED_UNICODE);
