<?php
/**
 * ⑧ entries歴史データ復元 - jcdマッピング不整合 現状調査
 * 読み取り専用。DB書き込みなし。
 * GET ?api_key=xxx
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// バグ期間: 2026-06-17 ~ 2026-07-05 (7/6修正)
// 影響会場: 高松(本来=丸亀), 丸亀(本来=児島), 児島→宮島, 宮島→徳山, 徳山→下関,
//          下関→若松, 若松→芦屋, 芦屋→福岡, 福岡→唐津, 唐津→大村, 大村=jcd25(存在しない)
$AFFECTED_VENUES = ['高松','丸亀','児島','宮島','徳山','下関','若松','芦屋','福岡','唐津','大村'];
$ph = implode(',', array_fill(0, count($AFFECTED_VENUES), '?'));

// ─── 1. 「高松」レコードが残存するか ────────────────────────────
$takamatsu = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM races WHERE venue='高松') AS races_count,
        (SELECT MIN(date) FROM races WHERE venue='高松') AS min_date,
        (SELECT MAX(date) FROM races WHERE venue='高松') AS max_date,
        (SELECT COUNT(*) FROM entries e JOIN races r ON r.id=e.race_id WHERE r.venue='高松') AS entries_count
")->fetch();

// ─── 2. 影響会場の日付カバレッジ ────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        r.venue,
        COUNT(DISTINCT r.id)        AS race_count,
        MIN(r.date)                 AS min_date,
        MAX(r.date)                 AS max_date,
        SUM(CASE WHEN r.date < '2026-07-06' THEN 1 ELSE 0 END) AS races_before_fix,
        SUM(CASE WHEN r.date >= '2026-07-06' THEN 1 ELSE 0 END) AS races_after_fix
    FROM races r
    WHERE r.venue IN ($ph)
    GROUP BY r.venue
    ORDER BY FIELD(r.venue,'高松','丸亀','児島','宮島','徳山','下関','若松','芦屋','福岡','唐津','大村')
");
$stmt->execute($AFFECTED_VENUES);
$venue_coverage = $stmt->fetchAll();

// ─── 3. バグ期間(6/17~7/5)の丸亀 日別レース件数 ─────────────────
$stmt2 = $pdo->prepare("
    SELECT r.date, COUNT(*) AS race_count
    FROM races r
    WHERE r.venue = '丸亀'
      AND r.date >= '2026-06-17' AND r.date <= '2026-07-14'
    GROUP BY r.date
    ORDER BY r.date
");
$stmt2->execute();
$marugame_daily = $stmt2->fetchAll();

// ─── 4. entries exhibit_time / start_timing 充足率 ─────────────────
// バグ前 vs バグ後 で比較
$stmt3 = $pdo->prepare("
    SELECT
        r.venue,
        CASE WHEN r.date < '2026-07-06' THEN 'before_fix' ELSE 'after_fix' END AS period,
        COUNT(e.id)                                        AS entries_count,
        SUM(CASE WHEN e.exhibit_time IS NOT NULL THEN 1 ELSE 0 END)  AS with_exhibit,
        SUM(CASE WHEN e.start_timing IS NOT NULL THEN 1 ELSE 0 END)  AS with_st,
        SUM(CASE WHEN e.motor_2rate  IS NOT NULL THEN 1 ELSE 0 END)  AS with_motor
    FROM entries e
    JOIN races r ON r.id = e.race_id
    WHERE r.venue IN ($ph)
    GROUP BY r.venue, period
    ORDER BY FIELD(r.venue,'高松','丸亀','児島','宮島','徳山','下関','若松','芦屋','福岡','唐津','大村'), period
");
$stmt3->execute($AFFECTED_VENUES);
$fill_rates = $stmt3->fetchAll();

// ─── 5. 非影響会場(対照群)の充足率 ────────────────────────────────
$NORMAL_VENUES = ['桐生','戸田','江戸川','平和島','浜名湖','住之江','津'];
$ph2 = implode(',', array_fill(0, count($NORMAL_VENUES), '?'));
$stmt4 = $pdo->prepare("
    SELECT
        r.venue,
        CASE WHEN r.date < '2026-07-06' THEN 'before_fix' ELSE 'after_fix' END AS period,
        COUNT(e.id) AS entries_count,
        SUM(CASE WHEN e.exhibit_time IS NOT NULL THEN 1 ELSE 0 END) AS with_exhibit,
        SUM(CASE WHEN e.start_timing IS NOT NULL THEN 1 ELSE 0 END) AS with_st
    FROM entries e
    JOIN races r ON r.id = e.race_id
    WHERE r.venue IN ($ph2)
    GROUP BY r.venue, period
    ORDER BY r.venue, period
");
$stmt4->execute($NORMAL_VENUES);
$control_fill = $stmt4->fetchAll();

// ─── 6. strategies/strategy_results への影響確認 ────────────────────
// entries.exhibit_timeやmotor_2rateはスコア計算の特徴量に使われる可能性がある
// strategy_resultsのis_hitに影響する予測結果への影響範囲を確認
$stmt5 = $pdo->prepare("
    SELECT
        r.venue,
        COUNT(DISTINCT sr.race_id)   AS races_with_strategy,
        SUM(sr.is_hit)               AS total_hits,
        SUM(sr.payout)               AS total_payout
    FROM strategy_results sr
    JOIN races r ON r.id = sr.race_id
    WHERE r.venue IN ($ph)
      AND r.date < '2026-07-06'
    GROUP BY r.venue
    ORDER BY FIELD(r.venue,'高松','丸亀','児島','宮島','徳山','下関','若松','芦屋','福岡','唐津','大村')
");
$stmt5->execute($AFFECTED_VENUES);
$strategy_impact = $stmt5->fetchAll();

// ─── 7. predictions への影響 (v1) ────────────────────────────────
$stmt6 = $pdo->prepare("
    SELECT
        r.venue,
        COUNT(p.id)  AS predictions_count,
        MIN(r.date)  AS min_date,
        MAX(r.date)  AS max_date
    FROM predictions p
    JOIN races r ON r.id = p.race_id
    WHERE r.venue IN ($ph)
      AND r.date < '2026-07-06'
    GROUP BY r.venue
    ORDER BY r.venue
");
$stmt6->execute($AFFECTED_VENUES);
$pred_impact = $stmt6->fetchAll();

// ─── 8. バックフィル対象の見積もり ─────────────────────────────────
// 丸亀(正しい)レースの6/17~7/5範囲で、exhibit_timeがNULLのentries数
$stmt7 = $pdo->prepare("
    SELECT
        r.venue,
        COUNT(e.id) AS null_exhibit_entries,
        COUNT(DISTINCT r.id) AS affected_races
    FROM entries e
    JOIN races r ON r.id = e.race_id
    WHERE r.venue IN ($ph)
      AND r.date >= '2026-06-17' AND r.date <= '2026-07-05'
      AND e.exhibit_time IS NULL
    GROUP BY r.venue
    ORDER BY FIELD(r.venue,'高松','丸亀','児島','宮島','徳山','下関','若松','芦屋','福岡','唐津','大村')
");
$stmt7->execute($AFFECTED_VENUES);
$null_exhibit = $stmt7->fetchAll();

echo json_encode([
    'bug_period'   => '2026-06-17 ~ 2026-07-05',
    'fix_date'     => '2026-07-06 (commit 48c4741c)',
    'bf_fix_date'  => '2026-07-23 (commit 43b43a9)',
    'takamatsu_remaining'    => $takamatsu,
    'venue_date_coverage'    => $venue_coverage,
    'marugame_daily_races'   => $marugame_daily,
    'fill_rates_affected'    => $fill_rates,
    'fill_rates_control'     => $control_fill,
    'strategy_impact_before' => $strategy_impact,
    'prediction_impact'      => $pred_impact,
    'null_exhibit_bug_period' => $null_exhibit,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
