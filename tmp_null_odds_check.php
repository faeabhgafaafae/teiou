<?php
// 一時調査用: 7/29-8/6のオッズ未取得レース一覧(読み取り専用)
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// 全期間の日別サマリ(bad率含む)
$summary_stmt = $pdo->query('
    SELECT
        date,
        COUNT(*) AS total,
        SUM(CASE WHEN odds_updated_at IS NULL THEN 1 ELSE 0 END) AS null_count,
        SUM(CASE WHEN odds_updated_at IS NOT NULL AND odds_updated_at < CONCAT(date, " ", scheduled_time) - INTERVAL 5 MINUTE THEN 1 ELSE 0 END) AS bad_count
    FROM races
    WHERE date BETWEEN "2026-07-29" AND "2026-08-06"
      AND scheduled_time IS NOT NULL
    GROUP BY date
    ORDER BY date
');
$summary = $summary_stmt->fetchAll();

// nullレース詳細一覧
$null_stmt = $pdo->query('
    SELECT id, date, venue, race_no, scheduled_time, odds_updated_at
    FROM races
    WHERE date BETWEEN "2026-07-29" AND "2026-08-06"
      AND scheduled_time IS NOT NULL
      AND odds_updated_at IS NULL
    ORDER BY date, scheduled_time, venue
');
$null_races = $null_stmt->fetchAll();

// 会場別集計
$venue_stmt = $pdo->query('
    SELECT venue, COUNT(*) AS null_count
    FROM races
    WHERE date BETWEEN "2026-07-29" AND "2026-08-06"
      AND scheduled_time IS NOT NULL
      AND odds_updated_at IS NULL
    GROUP BY venue
    ORDER BY null_count DESC
');
$by_venue = $venue_stmt->fetchAll();

// 時間帯別集計(時台ごと)
$hour_stmt = $pdo->query('
    SELECT HOUR(scheduled_time) AS hour, COUNT(*) AS null_count
    FROM races
    WHERE date BETWEEN "2026-07-29" AND "2026-08-06"
      AND scheduled_time IS NOT NULL
      AND odds_updated_at IS NULL
    GROUP BY HOUR(scheduled_time)
    ORDER BY hour
');
$by_hour = $hour_stmt->fetchAll();

echo json_encode([
    'summary'    => $summary,
    'null_races' => $null_races,
    'by_venue'   => $by_venue,
    'by_hour'    => $by_hour,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
