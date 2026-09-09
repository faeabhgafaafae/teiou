<?php
// 一撃重視戦略の詳細分析スクリプト（一時利用）
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
require_admin_json();

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

$mode = $_GET['mode'] ?? 'weekly';

// 1) 週別トレンド（8/28以降、週単位）
if ($mode === 'weekly') {
    $stmt = $pdo->prepare("
        SELECT
            r.date,
            WEEK(r.date, 1) AS week_num,
            MIN(r.date) AS week_start,
            MAX(r.date) AS week_end,
            COUNT(sr.id)                 AS total_races,
            COALESCE(SUM(sr.is_hit), 0)  AS hits,
            COALESCE(SUM(sr.cost), 0)    AS total_cost,
            COALESCE(SUM(sr.payout), 0)  AS total_payout
        FROM strategies s
        JOIN strategy_results sr ON sr.strategy_id = s.id
        JOIN races r ON r.id = s.race_id
        WHERE s.strategy_type = '一撃重視'
          AND r.date >= '2026-06-29'
        GROUP BY week_num
        ORDER BY week_num
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $cost    = (int)$row['total_cost'];
        $payout  = (int)$row['total_payout'];
        $hits    = (int)$row['hits'];
        $total   = (int)$row['total_races'];
        $result[] = [
            'week_start'  => $row['week_start'],
            'week_end'    => $row['week_end'],
            'total_races' => $total,
            'hits'        => $hits,
            'hit_rate'    => $total > 0 ? round($hits / $total * 100, 1) : 0,
            'total_cost'  => $cost,
            'total_payout'=> $payout,
            'roi'         => $cost > 0 ? round(($payout - $cost) / $cost * 100, 1) : 0,
            'return_rate' => $cost > 0 ? round($payout / $cost * 100, 1) : 0,
        ];
    }
    echo json_encode(['mode' => 'weekly', 'data' => $result], JSON_UNESCAPED_UNICODE);
}

// 2) 会場別（8/28以降）
elseif ($mode === 'venue') {
    $start = $_GET['start'] ?? '2026-08-28';
    $end   = $_GET['end']   ?? '2026-09-07';
    $stmt  = $pdo->prepare("
        SELECT
            r.venue,
            COUNT(sr.id)                 AS total_races,
            COALESCE(SUM(sr.is_hit), 0)  AS hits,
            COALESCE(SUM(sr.cost), 0)    AS total_cost,
            COALESCE(SUM(sr.payout), 0)  AS total_payout
        FROM strategies s
        JOIN strategy_results sr ON sr.strategy_id = s.id
        JOIN races r ON r.id = s.race_id
        WHERE s.strategy_type = '一撃重視'
          AND r.date BETWEEN ? AND ?
        GROUP BY r.venue
        ORDER BY total_payout / total_cost DESC
    ");
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $cost   = (int)$row['total_cost'];
        $payout = (int)$row['total_payout'];
        $hits   = (int)$row['hits'];
        $total  = (int)$row['total_races'];
        $result[] = [
            'venue'       => $row['venue'],
            'total_races' => $total,
            'hits'        => $hits,
            'hit_rate'    => $total > 0 ? round($hits / $total * 100, 1) : 0,
            'total_cost'  => $cost,
            'total_payout'=> $payout,
            'return_rate' => $cost > 0 ? round($payout / $cost * 100, 1) : 0,
        ];
    }
    echo json_encode(['mode' => 'venue', 'start' => $start, 'end' => $end, 'data' => $result], JSON_UNESCAPED_UNICODE);
}

// 3) 日別（9/1以降）
elseif ($mode === 'daily') {
    $start = $_GET['start'] ?? '2026-08-28';
    $end   = $_GET['end']   ?? '2026-09-07';
    $stmt  = $pdo->prepare("
        SELECT
            r.date,
            COUNT(sr.id)                 AS total_races,
            COALESCE(SUM(sr.is_hit), 0)  AS hits,
            COALESCE(SUM(sr.cost), 0)    AS total_cost,
            COALESCE(SUM(sr.payout), 0)  AS total_payout
        FROM strategies s
        JOIN strategy_results sr ON sr.strategy_id = s.id
        JOIN races r ON r.id = s.race_id
        WHERE s.strategy_type = '一撃重視'
          AND r.date BETWEEN ? AND ?
        GROUP BY r.date
        ORDER BY r.date
    ");
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $cost   = (int)$row['total_cost'];
        $payout = (int)$row['total_payout'];
        $hits   = (int)$row['hits'];
        $total  = (int)$row['total_races'];
        $result[] = [
            'date'        => $row['date'],
            'total_races' => $total,
            'hits'        => $hits,
            'hit_rate'    => $total > 0 ? round($hits / $total * 100, 1) : 0,
            'total_cost'  => $cost,
            'total_payout'=> $payout,
            'return_rate' => $cost > 0 ? round($payout / $cost * 100, 1) : 0,
        ];
    }
    echo json_encode(['mode' => 'daily', 'start' => $start, 'end' => $end, 'data' => $result], JSON_UNESCAPED_UNICODE);
}

// 4) 的中したオッズ分布（8/28以降、一撃重視）
elseif ($mode === 'odds_dist') {
    $start = $_GET['start'] ?? '2026-08-28';
    $end   = $_GET['end']   ?? '2026-09-07';
    $stmt  = $pdo->prepare("
        SELECT
            CASE
                WHEN o.odds < 10  THEN 'under10'
                WHEN o.odds < 20  THEN '10-20'
                WHEN o.odds < 50  THEN '20-50'
                WHEN o.odds < 100 THEN '50-100'
                ELSE 'over100'
            END AS odds_range,
            COUNT(*)         AS combos_checked,
            SUM(sr.is_hit)   AS hits
        FROM strategies s
        JOIN strategy_results sr ON sr.strategy_id = s.id
        JOIN races r ON r.id = s.race_id
        JOIN odds_3t o ON o.race_id = r.id
            AND JSON_CONTAINS(s.combinations, JSON_QUOTE(o.combo))
        WHERE s.strategy_type = '一撃重視'
          AND r.date BETWEEN ? AND ?
        GROUP BY odds_range
        ORDER BY FIELD(odds_range, 'under10','10-20','20-50','50-100','over100')
    ");
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll();
    echo json_encode(['mode' => 'odds_dist', 'start' => $start, 'end' => $end, 'data' => $rows], JSON_UNESCAPED_UNICODE);
}

// 5) 的中時の平均払戻額（8/28以降 vs 9/4以降）
elseif ($mode === 'payout_compare') {
    $periods = [
        ['label' => '8/28-9/3', 'start' => '2026-08-28', 'end' => '2026-09-03'],
        ['label' => '9/4-9/7',  'start' => '2026-09-04', 'end' => '2026-09-07'],
    ];
    $result = [];
    foreach ($periods as $p) {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(sr.id) AS total_races,
                SUM(sr.is_hit) AS hits,
                SUM(sr.cost) AS total_cost,
                SUM(sr.payout) AS total_payout,
                AVG(CASE WHEN sr.is_hit = 1 THEN sr.payout ELSE NULL END) AS avg_hit_payout,
                MAX(CASE WHEN sr.is_hit = 1 THEN sr.payout ELSE NULL END) AS max_hit_payout,
                AVG(sr.cost) AS avg_cost_per_race
            FROM strategies s
            JOIN strategy_results sr ON sr.strategy_id = s.id
            JOIN races r ON r.id = s.race_id
            WHERE s.strategy_type = '一撃重視'
              AND r.date BETWEEN ? AND ?
        ");
        $stmt->execute([$p['start'], $p['end']]);
        $row = $stmt->fetch();
        $cost   = (int)$row['total_cost'];
        $payout = (int)$row['total_payout'];
        $result[] = [
            'label'           => $p['label'],
            'total_races'     => (int)$row['total_races'],
            'hits'            => (int)$row['hits'],
            'hit_rate'        => $row['total_races'] > 0 ? round($row['hits'] / $row['total_races'] * 100, 1) : 0,
            'return_rate'     => $cost > 0 ? round($payout / $cost * 100, 1) : 0,
            'avg_cost'        => round((float)$row['avg_cost_per_race'], 0),
            'avg_hit_payout'  => $row['avg_hit_payout'] ? round((float)$row['avg_hit_payout'], 0) : null,
            'max_hit_payout'  => $row['max_hit_payout'] ? (int)$row['max_hit_payout'] : null,
        ];
    }
    echo json_encode(['mode' => 'payout_compare', 'data' => $result], JSON_UNESCAPED_UNICODE);
}

else {
    echo json_encode(['error' => 'mode不正。weekly/venue/daily/odds_dist/payout_compareを指定'], JSON_UNESCAPED_UNICODE);
}
