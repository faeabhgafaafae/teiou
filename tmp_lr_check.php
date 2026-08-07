<?php
/**
 * v2 LR訓練データのjcdバグ影響確認 (読み取り専用)
 * GET ?api_key=xxx
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$BUG_VENUES = ['丸亀','児島','宮島','徳山','下関','若松','芦屋','福岡','唐津','大村'];
$ph = implode(',', array_fill(0, count($BUG_VENUES), '?'));

// ─── 1. 訓練データ件数 (export_lr_data.phpと同じJOIN条件) ──────────────
// 全体: 6/29〜7/19, exhibit_time AND start_timing NOT NULL
$total_sql = "
    SELECT COUNT(*) AS rows,
           COUNT(DISTINCT e.race_id) AS races,
           MIN(r.date) AS min_date,
           MAX(r.date) AS max_date
    FROM entries e
    JOIN races r ON e.race_id = r.id
    JOIN results res ON res.race_id = e.race_id AND res.player_id = e.player_id
    WHERE r.date BETWEEN '2026-06-29' AND '2026-07-19'
      AND EXISTS (SELECT 1 FROM results res2 WHERE res2.race_id = r.id AND res2.actual_rank = 1)
      AND e.exhibit_time IS NOT NULL
      AND e.start_timing IS NOT NULL
";
$total_stats = $pdo->query($total_sql)->fetch();

// ─── 2. バグ期間(6/29〜7/5)の影響10会場: JOINが成立する件数 ──────────
$stmt1 = $pdo->prepare("
    SELECT r.venue,
           COUNT(*) AS matched_rows,
           COUNT(DISTINCT e.race_id) AS matched_races
    FROM entries e
    JOIN races r ON e.race_id = r.id
    JOIN results res ON res.race_id = e.race_id AND res.player_id = e.player_id
    WHERE r.date BETWEEN '2026-06-29' AND '2026-07-05'
      AND r.venue IN ($ph)
      AND EXISTS (SELECT 1 FROM results res2 WHERE res2.race_id = r.id AND res2.actual_rank = 1)
      AND e.exhibit_time IS NOT NULL
      AND e.start_timing IS NOT NULL
    GROUP BY r.venue
    ORDER BY r.venue
");
$stmt1->execute($BUG_VENUES);
$bug_period_joined = $stmt1->fetchAll();

// ─── 3. バグ期間の影響10会場: entries件数 vs results件数 (player_id不一致の確認) ──
$stmt2 = $pdo->prepare("
    SELECT
        r.venue,
        r.date,
        r.race_no,
        r.id AS race_id,
        COUNT(DISTINCT e.player_id)   AS entry_players,
        COUNT(DISTINCT res.player_id) AS result_players,
        COUNT(DISTINCT CASE WHEN res.player_id IS NOT NULL THEN e.player_id END) AS matched_players
    FROM races r
    JOIN entries e ON e.race_id = r.id
    LEFT JOIN results res ON res.race_id = r.id AND res.player_id = e.player_id
    WHERE r.date BETWEEN '2026-06-29' AND '2026-07-05'
      AND r.venue IN ($ph)
      AND EXISTS (SELECT 1 FROM results res2 WHERE res2.race_id = r.id AND res2.actual_rank = 1)
    GROUP BY r.id, r.venue, r.date, r.race_no
    HAVING matched_players = 0
    ORDER BY r.date, r.venue, r.race_no
    LIMIT 20
");
$stmt2->execute($BUG_VENUES);
$zero_match_races = $stmt2->fetchAll();

// ─── 4. バグ期間の影響10会場: 全レース件数 vs JOINマッチ件数 ───────────
$stmt3 = $pdo->prepare("
    SELECT
        r.venue,
        COUNT(DISTINCT r.id) AS total_races_with_result,
        SUM(has_entry_match) AS races_with_any_entry_match,
        COUNT(DISTINCT r.id) - SUM(has_entry_match) AS races_with_zero_match
    FROM (
        SELECT DISTINCT r.id,
               r.venue,
               CASE WHEN e_matched.race_id IS NOT NULL THEN 1 ELSE 0 END AS has_entry_match
        FROM races r
        LEFT JOIN (
            SELECT DISTINCT e.race_id
            FROM entries e
            JOIN results res ON res.race_id = e.race_id AND res.player_id = e.player_id
        ) e_matched ON e_matched.race_id = r.id
        WHERE r.date BETWEEN '2026-06-29' AND '2026-07-05'
          AND r.venue IN ($ph)
          AND EXISTS (SELECT 1 FROM results res2 WHERE res2.race_id = r.id AND res2.actual_rank = 1)
    ) t
    JOIN races r ON r.id = t.id
    GROUP BY r.venue
    ORDER BY r.venue
");
$stmt3->execute($BUG_VENUES);
$match_summary = $stmt3->fetchAll();

// ─── 5. 期間別・venue別の訓練データ件数 ────────────────────────────────
// バグ期間(6/29〜7/5) vs 正常期間(7/6〜7/19), 影響会場 vs その他
$stmt4 = $pdo->prepare("
    SELECT
        CASE WHEN r.date <= '2026-07-05' THEN 'bug_period' ELSE 'clean_period' END AS period,
        CASE WHEN r.venue IN ($ph) THEN 'affected' ELSE 'normal' END AS venue_type,
        COUNT(*) AS rows,
        COUNT(DISTINCT e.race_id) AS races
    FROM entries e
    JOIN races r ON e.race_id = r.id
    JOIN results res ON res.race_id = e.race_id AND res.player_id = e.player_id
    WHERE r.date BETWEEN '2026-06-29' AND '2026-07-19'
      AND EXISTS (SELECT 1 FROM results res2 WHERE res2.race_id = r.id AND res2.actual_rank = 1)
      AND e.exhibit_time IS NOT NULL
      AND e.start_timing IS NOT NULL
    GROUP BY period, venue_type
    ORDER BY period, venue_type
");
$stmt4->execute($BUG_VENUES);
$period_breakdown = $stmt4->fetchAll();

// ─── 6. 現在の訓練データのtrain/test分割と日付分布 ─────────────────────
$stmt5 = $pdo->query("
    SELECT r.date, COUNT(DISTINCT e.race_id) AS races
    FROM entries e
    JOIN races r ON e.race_id = r.id
    JOIN results res ON res.race_id = e.race_id AND res.player_id = e.player_id
    WHERE r.date BETWEEN '2026-06-29' AND '2026-07-19'
      AND EXISTS (SELECT 1 FROM results res2 WHERE res2.race_id = r.id AND res2.actual_rank = 1)
      AND e.exhibit_time IS NOT NULL
      AND e.start_timing IS NOT NULL
    GROUP BY r.date
    ORDER BY r.date
");
$date_dist = $stmt5->fetchAll();

// ─── 7. バグ期間の影響会場: entries vs results のplayer_id一致率サンプル ─
$stmt6 = $pdo->prepare("
    SELECT r.date, r.venue, r.race_no,
           GROUP_CONCAT(DISTINCT e.player_id ORDER BY e.lane SEPARATOR ',') AS entry_players,
           GROUP_CONCAT(DISTINCT res2.player_id ORDER BY res2.actual_rank SEPARATOR ',') AS result_players
    FROM races r
    JOIN entries e ON e.race_id = r.id
    JOIN results res2 ON res2.race_id = r.id
    WHERE r.date BETWEEN '2026-06-29' AND '2026-07-05'
      AND r.venue IN ($ph)
      AND EXISTS (SELECT 1 FROM results res3 WHERE res3.race_id = r.id AND res3.actual_rank = 1)
    GROUP BY r.id, r.date, r.venue, r.race_no
    ORDER BY r.date, r.venue, r.race_no
    LIMIT 10
");
$stmt6->execute($BUG_VENUES);
$player_mismatch_sample = $stmt6->fetchAll();

echo json_encode([
    'summary' => [
        'question' => 'バグ期間(6/29〜7/5)の影響10会場レコードが訓練データに含まれるか',
        'training_period' => '2026-06-29 ~ 2026-07-19',
        'bug_affected_venues' => '丸亀・児島・宮島・徳山・下関・若松・芦屋・福岡・唐津・大村',
    ],
    'total_training_stats'     => $total_stats,
    'bug_period_joined_rows'   => $bug_period_joined,
    'zero_match_race_samples'  => $zero_match_races,
    'match_summary_by_venue'   => $match_summary,
    'period_venue_breakdown'   => $period_breakdown,
    'date_distribution'        => $date_dist,
    'player_mismatch_samples'  => $player_mismatch_sample,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
