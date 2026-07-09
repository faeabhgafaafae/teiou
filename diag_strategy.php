<?php
/**
 * temp: 予想AI的中率・回収率の診断用エンドポイント（調査後に削除）
 */
define('DB_HOST', 'mysql323.phy.lolipop.lan');
define('DB_NAME', 'LAA1670504-12');
define('DB_USER', 'LAA1670504');
define('DB_PASS', 'teiou');

header('Content-Type: application/json; charset=utf-8');

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$out = [];

// 0. サンプル範囲
$out['sample_range'] = $pdo->query('
    SELECT COUNT(DISTINCT sr.race_id) AS races, MIN(r.date) AS min_date, MAX(r.date) AS max_date
    FROM strategy_results sr JOIN races r ON r.id = sr.race_id
')->fetch();

// 1. predicted_rank=1 が実際にactual_rank=1(単純1着的中)になっている割合
$out['rank1_win_accuracy'] = $pdo->query('
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END) AS rank1_actually_won,
           ROUND(SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) AS pct
    FROM predictions p
    JOIN results res ON res.race_id = p.race_id AND res.player_id = p.player_id
    WHERE p.predicted_rank = 1
')->fetch();

// 2. predicted_rank(1-6) × actual_rank(1-6) のクロス集計（予測順位と実際着順の相関確認）
$out['rank_cross_matrix'] = $pdo->query('
    SELECT p.predicted_rank, res.actual_rank, COUNT(*) AS cnt
    FROM predictions p
    JOIN results res ON res.race_id = p.race_id AND res.player_id = p.player_id
    GROUP BY p.predicted_rank, res.actual_rank
    ORDER BY p.predicted_rank, res.actual_rank
')->fetchAll();

// 3. predicted_rankごとの平均actual_rank・平均score_total（単調性の確認）
$out['score_vs_actual_by_predicted_rank'] = $pdo->query('
    SELECT p.predicted_rank,
           COUNT(*) AS cnt,
           ROUND(AVG(p.score_total), 2) AS avg_score_total,
           ROUND(AVG(res.actual_rank), 2) AS avg_actual_rank,
           SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END) AS won_count,
           SUM(CASE WHEN res.actual_rank <= 3 THEN 1 ELSE 0 END) AS top3_count
    FROM predictions p
    JOIN results res ON res.race_id = p.race_id AND res.player_id = p.player_id
    GROUP BY p.predicted_rank
    ORDER BY p.predicted_rank
')->fetchAll();

// 4. 予測1位と2位のスコア差(自信度)別に見た的中率（自信度が高いほど当たっているか）
$out['confidence_gap_vs_hit'] = $pdo->query('
    SELECT
        CASE
            WHEN gap >= 15 THEN "1: gap>=15(高)"
            WHEN gap >= 7  THEN "2: gap 7-15(中)"
            ELSE "3: gap<7(拮抗)"
        END AS bucket,
        COUNT(*) AS cnt,
        SUM(rank1_won) AS rank1_won_count,
        ROUND(SUM(rank1_won) / COUNT(*) * 100, 1) AS rank1_win_pct
    FROM (
        SELECT
            p1.race_id,
            (p1.score_total - p2.score_total) AS gap,
            (CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END) AS rank1_won
        FROM predictions p1
        JOIN predictions p2 ON p2.race_id = p1.race_id AND p2.predicted_rank = 2
        JOIN results res ON res.race_id = p1.race_id AND res.player_id = p1.player_id
        WHERE p1.predicted_rank = 1
    ) t
    GROUP BY bucket
    ORDER BY bucket
')->fetchAll();

// 5. 実際の勝者(actual_rank=1)の枠番(lane)分布 = 実データにおけるコース優位性の実態
$out['actual_winner_lane_distribution'] = $pdo->query('
    SELECT res.lane, COUNT(*) AS cnt,
           ROUND(COUNT(*) / (SELECT COUNT(*) FROM results WHERE actual_rank = 1) * 100, 1) AS pct
    FROM results res
    WHERE res.actual_rank = 1
    GROUP BY res.lane
    ORDER BY res.lane
')->fetchAll();

// 6. 実際の1-2-3着が「枠なり(1-2-3)」である割合 = ナイーブな基準線
$out['naive_baseline_123_hit'] = $pdo->query('
    SELECT
        COUNT(*) AS total_races,
        SUM(CASE WHEN t.combo = "1-2-3" THEN 1 ELSE 0 END) AS exact_123_order,
        SUM(CASE WHEN t.lane_set = "1,2,3" THEN 1 ELSE 0 END) AS top3_is_123_any_order
    FROM (
        SELECT
            r.id,
            GROUP_CONCAT(res.lane ORDER BY res.actual_rank SEPARATOR "-") AS combo,
            (SELECT GROUP_CONCAT(lane ORDER BY lane) FROM results WHERE race_id = r.id AND actual_rank <= 3) AS lane_set
        FROM races r
        JOIN results res ON res.race_id = r.id
        WHERE res.actual_rank IN (1,2,3)
        GROUP BY r.id
        HAVING COUNT(*) = 3
    ) t
')->fetch();

// 7. 戦略別: is_hit×payout の内訳（payout=0なのにis_hit=1という不整合＝オッズ欠損バグの検出）
$out['strategy_hit_payout_breakdown'] = $pdo->query('
    SELECT s.strategy_type, sr.is_hit,
           COUNT(*) AS cnt,
           SUM(sr.cost) AS total_cost,
           SUM(sr.payout) AS total_payout,
           SUM(CASE WHEN sr.is_hit = 1 AND sr.payout = 0 THEN 1 ELSE 0 END) AS hit_but_zero_payout,
           ROUND(AVG(CASE WHEN sr.is_hit = 1 THEN sr.payout END), 1) AS avg_payout_on_hit,
           ROUND(AVG(sr.cost), 1) AS avg_cost_per_race
    FROM strategy_results sr
    JOIN strategies s ON s.id = sr.strategy_id
    GROUP BY s.strategy_type, sr.is_hit
    ORDER BY s.strategy_type, sr.is_hit
')->fetchAll();

// 8. 戦略別の組み合わせ数(コスト)分布
$out['combo_count_distribution'] = $pdo->query('
    SELECT strategy_type, JSON_LENGTH(combinations) AS combo_count, COUNT(*) AS race_count
    FROM strategies
    GROUP BY strategy_type, combo_count
    ORDER BY strategy_type, combo_count
')->fetchAll();

// 9. オッズデータのカバレッジ確認（odds_3tに該当レースがどれだけあるか）
$out['odds_coverage'] = $pdo->query('
    SELECT
        (SELECT COUNT(DISTINCT race_id) FROM strategy_results) AS races_with_strategy_results,
        (SELECT COUNT(DISTINCT race_id) FROM odds_3t) AS races_with_odds_3t
')->fetch();

// 10. 絞り込み戦略の中身: 実際に賭けた組み合わせが「人気(popularity)」的にどのあたりか
//     race_payouts(3連単)とstrategiesを突き合わせ、賭けた組み合わせの人気順位を見る
$out['shiborikomi_popularity'] = $pdo->query('
    SELECT rp.popularity, COUNT(*) AS cnt
    FROM strategies s
    JOIN race_payouts rp ON rp.race_id = s.race_id AND rp.bet_type = "3連単"
       AND rp.combo = JSON_UNQUOTE(JSON_EXTRACT(s.combinations, "$[0]"))
    WHERE s.strategy_type = "絞り込み"
    GROUP BY rp.popularity
    ORDER BY rp.popularity
')->fetchAll();

// 11. 参考: 全レースの決着人気分布(get_analysis_payouts.phpと同じロジック、絞り込みとの比較用)
$out['all_winning_combo_popularity_dist'] = $pdo->query('
    SELECT
        CASE
            WHEN popularity = 1 THEN "1番人気"
            WHEN popularity BETWEEN 2 AND 3 THEN "2-3番人気"
            WHEN popularity BETWEEN 4 AND 6 THEN "4-6番人気"
            WHEN popularity BETWEEN 7 AND 10 THEN "7-10番人気"
            WHEN popularity > 10 THEN "11番人気以下"
            ELSE "不明"
        END AS bucket,
        COUNT(*) AS cnt
    FROM race_payouts
    WHERE bet_type = "3連単" AND popularity IS NOT NULL AND amount IS NOT NULL
    GROUP BY bucket
')->fetchAll();

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
