<?php
// 一時調査用: is_hit=1・payout=0 欠損データ影響度確認 (読み取り専用)
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── 1. is_hit=1 & payout=0 の全件一覧(戦略type別・レース情報付き) ───
$gap_rows = $pdo->query('
    SELECT
        sr.id       AS sr_id,
        sr.race_id,
        sr.strategy_id,
        sr.cost,
        s.strategy_type,
        r.date,
        r.venue,
        r.race_no,
        -- 実際の着順(1-2-3着の枠番コンボ)
        (
            SELECT GROUP_CONCAT(res2.lane ORDER BY res2.actual_rank SEPARATOR "-")
            FROM results res2
            WHERE res2.race_id = sr.race_id AND res2.actual_rank IN (1,2,3)
        ) AS winning_combo,
        -- odds_3t に当該コンボが存在するか
        (
            SELECT COUNT(*)
            FROM odds_3t o
            WHERE o.race_id = sr.race_id
              AND o.combo = (
                SELECT GROUP_CONCAT(res3.lane ORDER BY res3.actual_rank SEPARATOR "-")
                FROM results res3
                WHERE res3.race_id = sr.race_id AND res3.actual_rank IN (1,2,3)
              )
        ) AS odds_exists,
        -- 存在する場合のオッズ値
        (
            SELECT o.odds
            FROM odds_3t o
            WHERE o.race_id = sr.race_id
              AND o.combo = (
                SELECT GROUP_CONCAT(res4.lane ORDER BY res4.actual_rank SEPARATOR "-")
                FROM results res4
                WHERE res4.race_id = sr.race_id AND res4.actual_rank IN (1,2,3)
              )
            LIMIT 1
        ) AS odds_value,
        -- odds_3t 自体が当該レースに何件あるか(スクレイプ有無確認)
        (SELECT COUNT(*) FROM odds_3t o2 WHERE o2.race_id = sr.race_id) AS odds_3t_count
    FROM strategy_results sr
    JOIN strategies s ON s.id = sr.strategy_id
    JOIN races r ON r.id = sr.race_id
    WHERE sr.is_hit = 1 AND sr.payout = 0
    ORDER BY r.date, r.venue, r.race_no, s.strategy_type
')->fetchAll();

// ─── 2. 全体集計(is_hit=1 & payout=0 件数) ───
$summary_by_type = $pdo->query('
    SELECT
        s.strategy_type,
        COUNT(*) AS gap_count,
        SUM(sr.cost) AS gap_cost
    FROM strategy_results sr
    JOIN strategies s ON s.id = sr.strategy_id
    WHERE sr.is_hit = 1 AND sr.payout = 0
    GROUP BY s.strategy_type
')->fetchAll();

// ─── 3. 現状の成績サマリー(全戦略合計) ───
$current_stats = $pdo->query('
    SELECT
        s.strategy_type,
        COUNT(sr.id)                AS total_races,
        SUM(sr.is_hit)              AS hits,
        SUM(sr.cost)                AS total_cost,
        SUM(sr.payout)              AS total_payout,
        -- is_hit=1 & payout=0 の件数(欠損)
        SUM(CASE WHEN sr.is_hit=1 AND sr.payout=0 THEN 1 ELSE 0 END) AS gap_hits,
        -- 欠損分のコスト
        SUM(CASE WHEN sr.is_hit=1 AND sr.payout=0 THEN sr.cost ELSE 0 END) AS gap_cost
    FROM strategy_results sr
    JOIN strategies s ON s.id = sr.strategy_id
    GROUP BY s.strategy_type
    ORDER BY FIELD(s.strategy_type, "的中特化", "バランス", "一撃重視", "絞り込み")
')->fetchAll();

// ─── 4. 欠損レースのレース重複確認(同一レースで複数戦略が欠損しているか) ───
$unique_races = $pdo->query('
    SELECT DISTINCT sr.race_id, r.date, r.venue, r.race_no
    FROM strategy_results sr
    JOIN races r ON r.id = sr.race_id
    WHERE sr.is_hit = 1 AND sr.payout = 0
    ORDER BY r.date, r.venue, r.race_no
')->fetchAll();

// ─── 5. 欠損レースの odds_3t 保有状況サマリー ───
$odds_status = $pdo->query('
    SELECT
        COUNT(DISTINCT sr.race_id)                                         AS total_gap_races,
        SUM(CASE WHEN (SELECT COUNT(*) FROM odds_3t o WHERE o.race_id = sr.race_id) = 0
                 THEN 1 ELSE 0 END)                                        AS races_no_odds_at_all,
        SUM(CASE WHEN (SELECT COUNT(*) FROM odds_3t o WHERE o.race_id = sr.race_id) > 0
                 THEN 1 ELSE 0 END)                                        AS races_have_odds,
        SUM(CASE WHEN (
                SELECT COUNT(*) FROM odds_3t o WHERE o.race_id = sr.race_id
                  AND o.combo = (
                    SELECT GROUP_CONCAT(res.lane ORDER BY res.actual_rank SEPARATOR "-")
                    FROM results res WHERE res.race_id = sr.race_id AND res.actual_rank IN (1,2,3)
                  )
            ) > 0 THEN 1 ELSE 0 END)                                      AS races_winning_combo_exists
    FROM (SELECT DISTINCT race_id FROM strategy_results WHERE is_hit=1 AND payout=0) sr
')->fetch();

// ─── 6. もしオッズが復元できた場合のROI試算 ───
// gap_rowsから復元可能なもの(odds_exists=1)の合計payout試算
$recoverable_payout = 0;
$recoverable_count  = 0;
$unrecoverable = [];
foreach ($gap_rows as $row) {
    if ($row['odds_exists'] > 0 && $row['odds_value'] !== null) {
        $recoverable_payout += (int)floor((float)$row['odds_value'] * 100);
        $recoverable_count++;
    } else {
        $unrecoverable[] = [
            'date'    => $row['date'],
            'venue'   => $row['venue'],
            'race_no' => $row['race_no'],
            'strategy_type' => $row['strategy_type'],
            'winning_combo' => $row['winning_combo'],
            'odds_3t_count' => $row['odds_3t_count'],
        ];
    }
}

echo json_encode([
    'gap_details'       => $gap_rows,
    'unique_races'      => $unique_races,
    'summary_by_type'   => $summary_by_type,
    'current_stats'     => $current_stats,
    'odds_status'       => $odds_status,
    'recovery_estimate' => [
        'recoverable_count'   => $recoverable_count,
        'recoverable_payout'  => $recoverable_payout,
        'unrecoverable'       => $unrecoverable,
    ],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
