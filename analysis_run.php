<?php
/**
 * temp: スコアリング改修(7/9)の前後比較分析用エンドポイント(使用後にneutralize/削除する)
 * ?action=summary&start=YYYY-MM-DD&end=YYYY-MM-DD : 期間内の戦略別集計(get_performance_venue.php相当のロジック)
 * ?action=daily&start=YYYY-MM-DD&end=YYYY-MM-DD   : 期間内の日別・戦略別件数(サンプル分布確認用)
 */
$api_key = $_GET['api_key'] ?? '';
if ($api_key !== 'teio2025') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$pdo = new PDO(
    'mysql:host=mysql323.phy.lolipop.lan;dbname=LAA1670504-12;charset=utf8mb4',
    'LAA1670504', 'teiou',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$action = $_GET['action'] ?? 'summary';
$start  = $_GET['start'] ?? '';
$end    = $_GET['end']   ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    http_response_code(400);
    echo json_encode(['error' => 'start/end は YYYY-MM-DD 形式で指定してください']);
    exit;
}

if ($action === 'summary') {
    $stmt = $pdo->prepare('
        SELECT
            s.strategy_type,
            COUNT(sr.id)                 AS total_races,
            COALESCE(SUM(sr.is_hit), 0)  AS hits,
            COALESCE(SUM(sr.cost), 0)    AS total_cost,
            COALESCE(SUM(sr.payout), 0)  AS total_payout
        FROM strategy_results sr
        JOIN strategies s ON s.id = sr.strategy_id
        JOIN races r       ON r.id = sr.race_id
        WHERE r.date >= ? AND r.date <= ?
        GROUP BY s.strategy_type
        ORDER BY FIELD(s.strategy_type, \'的中特化\', \'バランス\', \'一撃重視\', \'絞り込み\')
    ');
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll();

    $stats = [];
    foreach ($rows as $row) {
        $total  = (int)$row['total_races'];
        $hits   = (int)$row['hits'];
        $cost   = (int)$row['total_cost'];
        $payout = (int)$row['total_payout'];
        $profit = $payout - $cost;

        $stats[] = [
            'strategy_type' => $row['strategy_type'],
            'total_races'   => $total,
            'hits'          => $hits,
            'hit_rate'      => $total > 0 ? round($hits / $total * 100, 2) : 0,
            'total_cost'    => $cost,
            'total_payout'  => $payout,
            'profit'        => $profit,
            'roi'           => $cost > 0 ? round($profit / $cost * 100, 2) : 0,
        ];
    }

    // 対象レース総数(戦略に依存しないユニークレース数)
    $stmtRaces = $pdo->prepare('
        SELECT COUNT(DISTINCT sr.race_id) AS race_count
        FROM strategy_results sr
        JOIN races r ON r.id = sr.race_id
        WHERE r.date >= ? AND r.date <= ?
    ');
    $stmtRaces->execute([$start, $end]);
    $raceCount = (int)$stmtRaces->fetch()['race_count'];

    echo json_encode([
        'start' => $start, 'end' => $end,
        'unique_race_count' => $raceCount,
        'stats' => $stats,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'daily') {
    $stmt = $pdo->prepare('
        SELECT
            r.date,
            s.strategy_type,
            COUNT(sr.id) AS total_races,
            COALESCE(SUM(sr.is_hit), 0) AS hits
        FROM strategy_results sr
        JOIN strategies s ON s.id = sr.strategy_id
        JOIN races r       ON r.id = sr.race_id
        WHERE r.date >= ? AND r.date <= ?
        GROUP BY r.date, s.strategy_type
        ORDER BY r.date ASC
    ');
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll();

    echo json_encode(['daily' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'zero_payout_check') {
    // is_hit=1 かつ payout=0 (オッズ欠損によるROI下振れの疑い)の件数を期間・戦略別に集計
    $stmt = $pdo->prepare('
        SELECT
            s.strategy_type,
            COUNT(*) AS total_hits,
            SUM(CASE WHEN sr.payout = 0 THEN 1 ELSE 0 END) AS zero_payout_hits
        FROM strategy_results sr
        JOIN strategies s ON s.id = sr.strategy_id
        JOIN races r       ON r.id = sr.race_id
        WHERE r.date >= ? AND r.date <= ? AND sr.is_hit = 1
        GROUP BY s.strategy_type
        ORDER BY FIELD(s.strategy_type, \'的中特化\', \'バランス\', \'一撃重視\', \'絞り込み\')
    ');
    $stmt->execute([$start, $end]);
    echo json_encode(['zero_payout' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid action']);
