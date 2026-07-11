<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

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
        COUNT(sr.id)              AS total_races,
        COALESCE(SUM(sr.is_hit), 0)   AS hits,
        COALESCE(SUM(sr.cost), 0)     AS total_cost,
        COALESCE(SUM(sr.payout), 0)   AS total_payout
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

echo json_encode([
    'start_date' => $start_date,
    'end_date'   => $end_date,
    'stats'      => $stats,
], JSON_UNESCAPED_UNICODE);
