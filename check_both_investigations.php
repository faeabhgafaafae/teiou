<?php
/**
 * 一時調査エンドポイント: ⑪ odds_updated_at再計測 + ⑧ jcdバグ影響確認
 * 確認後に削除する
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// =====================================================================
// ⑪ odds_updated_at 更新タイミング分布 (7/23以降のデータ)
// =====================================================================
// 「締切時刻から何分前にオッズが最終更新されたか」のバケット分布を集計
// 対象: 締切確定済み(scheduled_time is not null)・直近~2週間

$odds_sql = "
SELECT
    r.venue,
    COUNT(*) AS total,
    SUM(CASE
        WHEN r.odds_updated_at IS NULL THEN 1 ELSE 0
    END) AS null_count,
    SUM(CASE
        WHEN r.odds_updated_at IS NOT NULL
         AND TIMESTAMPDIFF(MINUTE, r.odds_updated_at, r.scheduled_time) > 60 THEN 1 ELSE 0
    END) AS over_60min,
    SUM(CASE
        WHEN r.odds_updated_at IS NOT NULL
         AND TIMESTAMPDIFF(MINUTE, r.odds_updated_at, r.scheduled_time) BETWEEN 30 AND 60 THEN 1 ELSE 0
    END) AS between_30_60,
    SUM(CASE
        WHEN r.odds_updated_at IS NOT NULL
         AND TIMESTAMPDIFF(MINUTE, r.odds_updated_at, r.scheduled_time) BETWEEN 10 AND 29 THEN 1 ELSE 0
    END) AS between_10_29,
    SUM(CASE
        WHEN r.odds_updated_at IS NOT NULL
         AND TIMESTAMPDIFF(MINUTE, r.odds_updated_at, r.scheduled_time) < 10 THEN 1 ELSE 0
    END) AS under_10min,
    -- 負値 = 締切後に更新(異常値)
    SUM(CASE
        WHEN r.odds_updated_at IS NOT NULL
         AND TIMESTAMPDIFF(MINUTE, r.odds_updated_at, r.scheduled_time) < 0 THEN 1 ELSE 0
    END) AS negative_diff
FROM races r
WHERE r.date >= '2026-07-23'
  AND r.scheduled_time IS NOT NULL
GROUP BY r.venue
ORDER BY r.venue
";

$odds_venue = $pdo->query($odds_sql)->fetchAll();

// 全体集計
$odds_total_sql = "
SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN r.odds_updated_at IS NULL THEN 1 ELSE 0 END) AS null_count,
    SUM(CASE WHEN r.odds_updated_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, r.odds_updated_at, r.scheduled_time) > 60 THEN 1 ELSE 0 END) AS over_60min,
    SUM(CASE WHEN r.odds_updated_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, r.odds_updated_at, r.scheduled_time) BETWEEN 30 AND 60 THEN 1 ELSE 0 END) AS between_30_60,
    SUM(CASE WHEN r.odds_updated_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, r.odds_updated_at, r.scheduled_time) BETWEEN 10 AND 29 THEN 1 ELSE 0 END) AS between_10_29,
    SUM(CASE WHEN r.odds_updated_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, r.odds_updated_at, r.scheduled_time) < 10 THEN 1 ELSE 0 END) AS under_10min,
    SUM(CASE WHEN r.odds_updated_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, r.odds_updated_at, r.scheduled_time) < 0 THEN 1 ELSE 0 END) AS negative_diff
FROM races r
WHERE r.date >= '2026-07-23'
  AND r.scheduled_time IS NOT NULL
";
$odds_total = $pdo->query($odds_total_sql)->fetch();

// 時間帯別(hour_of_day)集計 (前回は14-16時台が悪かった)
$odds_hour_sql = "
SELECT
    HOUR(r.scheduled_time) AS hour_jst,
    COUNT(*) AS total,
    SUM(CASE WHEN r.odds_updated_at IS NULL OR TIMESTAMPDIFF(MINUTE, r.odds_updated_at, r.scheduled_time) > 60 THEN 1 ELSE 0 END) AS bad_count
FROM races r
WHERE r.date >= '2026-07-23'
  AND r.scheduled_time IS NOT NULL
GROUP BY HOUR(r.scheduled_time)
ORDER BY HOUR(r.scheduled_time)
";
$odds_hour = $pdo->query($odds_hour_sql)->fetchAll();

// 日別推移(7/23以降)
$odds_daily_sql = "
SELECT
    r.date,
    COUNT(*) AS total,
    SUM(CASE WHEN r.odds_updated_at IS NULL OR TIMESTAMPDIFF(MINUTE, r.odds_updated_at, r.scheduled_time) > 60 THEN 1 ELSE 0 END) AS bad_count
FROM races r
WHERE r.date >= '2026-07-23'
  AND r.scheduled_time IS NOT NULL
GROUP BY r.date
ORDER BY r.date
";
$odds_daily = $pdo->query($odds_daily_sql)->fetchAll();

// =====================================================================
// ⑧ jcdバグの影響調査
// =====================================================================
// Bug前マッピング(scrape_boatrace.py/scrape_racelist.py): 高松=15, 丸亀=16...大村=25
// Fix日: 2026-07-06 (commit 7261251)
// backfill_beforeinfo.py fix日: 2026-07-23 (commit 43b43a9)

// 1. 「高松」というvenue名でentries/racesが存在するか（架空会場）
$takamatsu_sql = "
SELECT
    (SELECT COUNT(*) FROM races WHERE venue='高松') AS races_takamatsu,
    (SELECT COUNT(*) FROM entries e JOIN races r ON r.id=e.race_id WHERE r.venue='高松') AS entries_takamatsu,
    (SELECT COUNT(*) FROM results res JOIN races r ON r.id=res.race_id WHERE r.venue='高松') AS results_takamatsu,
    (SELECT MIN(date) FROM races WHERE venue='高松') AS min_date_takamatsu,
    (SELECT MAX(date) FROM races WHERE venue='高松') AS max_date_takamatsu
";
$takamatsu = $pdo->query($takamatsu_sql)->fetch();

// 2. 影響会場のentries件数（日付範囲別）
// Fix前(~2026-07-05)に保存されたentriesは誤ラベルの可能性がある
$affected_venues = ['高松','丸亀','児島','宮島','徳山','下関','若松','芦屋','福岡','唐津','大村'];
$ph = implode(',', array_fill(0, count($affected_venues), '?'));

$venue_entries_sql = "
SELECT
    r.venue,
    COUNT(e.id) AS entries_count,
    SUM(CASE WHEN r.date < '2026-07-06' THEN 1 ELSE 0 END) AS entries_before_fix,
    SUM(CASE WHEN r.date >= '2026-07-06' THEN 1 ELSE 0 END) AS entries_after_fix,
    MIN(r.date) AS min_date,
    MAX(r.date) AS max_date
FROM entries e
JOIN races r ON r.id = e.race_id
WHERE r.venue IN ($ph)
GROUP BY r.venue
ORDER BY r.venue
";
$stmt = $pdo->prepare($venue_entries_sql);
$stmt->execute($affected_venues);
$venue_entries = $stmt->fetchAll();

// 3. 特に「高松」のentries(exhibit_time/start_timing)のサンプル - 実在するか確認
$takamatsu_entries_sample_sql = "
SELECT e.id, r.date, r.race_no, e.lane, e.player_id, e.exhibit_time, e.start_timing, e.motor_2rate
FROM entries e
JOIN races r ON r.id = e.race_id
WHERE r.venue = '高松'
ORDER BY r.date DESC, r.race_no, e.lane
LIMIT 20
";
$takamatsu_entries_sample = $pdo->query($takamatsu_entries_sample_sql)->fetchAll();

// 4. backfill期間(6/29〜7/14)の影響会場のentries件数
// 最初のbackfill(4442e14)はいつ実行されたか不明だが、backfill_beforeinfo.pyのfixが7/23なので
// 7/06〜7/23の間に実行されたバックフィルは誤マッピングの可能性がある
$backfill_range_sql = "
SELECT
    r.venue,
    COUNT(e.id) AS entries_in_backfill_range
FROM entries e
JOIN races r ON r.id = e.race_id
WHERE r.date >= '2026-06-29' AND r.date <= '2026-07-14'
  AND r.venue IN ($ph)
  AND (e.exhibit_time IS NOT NULL OR e.start_timing IS NOT NULL)
GROUP BY r.venue
ORDER BY r.venue
";
$stmt2 = $pdo->prepare($backfill_range_sql);
$stmt2->execute($affected_venues);
$backfill_entries = $stmt2->fetchAll();

// 5. 現在の全venue一覧(DBに存在する会場名)
$all_venues_sql = "SELECT venue, COUNT(*) as race_count, MIN(date) as min_date, MAX(date) as max_date FROM races GROUP BY venue ORDER BY venue";
$all_venues = $pdo->query($all_venues_sql)->fetchAll();

echo json_encode([
    'odds_investigation' => [
        'period'      => '2026-07-23 ~ now',
        'total'       => $odds_total,
        'by_venue'    => $odds_venue,
        'by_hour_jst' => $odds_hour,
        'by_date'     => $odds_daily,
    ],
    'jcd_investigation' => [
        'takamatsu_check'      => $takamatsu,
        'venue_entries_count'  => $venue_entries,
        'takamatsu_sample'     => $takamatsu_entries_sample,
        'backfill_range_entries' => $backfill_entries,
        'all_venues_in_db'     => $all_venues,
    ],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
