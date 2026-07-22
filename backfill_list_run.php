<?php
/**
 * tmp: exhibit_time/start_timing欠損レースのバックフィル対象一覧(使用後にneutralize予定)
 * GET /backfill_list_run.php
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

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

try {
    $stmt = $pdo->query('
        SELECT r.id AS race_id, r.date, r.venue, r.race_no, r.scheduled_time
        FROM races r
        WHERE r.scheduled_time IS NOT NULL
          AND CONCAT(r.date, " ", r.scheduled_time) < NOW()
          AND EXISTS (
              SELECT 1 FROM entries e
              WHERE e.race_id = r.id
                AND (e.exhibit_time IS NULL OR e.start_timing IS NULL)
          )
        ORDER BY r.date, r.venue, r.race_no
    ');
    $races = $stmt->fetchAll();

    $stmt2 = $pdo->query('
        SELECT
            COUNT(*) AS total_entries,
            SUM(CASE WHEN exhibit_time IS NULL THEN 1 ELSE 0 END) AS exhibit_null,
            SUM(CASE WHEN start_timing IS NULL THEN 1 ELSE 0 END) AS st_null
        FROM entries
    ');
    $summary = $stmt2->fetch();

    $stmt3 = $pdo->query('
        SELECT
            COUNT(*) AS total_entries,
            SUM(CASE WHEN e.exhibit_time IS NULL THEN 1 ELSE 0 END) AS exhibit_null,
            SUM(CASE WHEN e.start_timing IS NULL THEN 1 ELSE 0 END) AS st_null
        FROM entries e
        JOIN races r ON r.id = e.race_id
        WHERE r.date >= "2026-07-15" AND r.date <= "2026-07-22"
    ');
    $summary_week = $stmt3->fetch();

    echo json_encode([
        'race_count' => count($races),
        'races'      => $races,
        'entries_summary' => $summary,
        'entries_summary_week_0715_0722' => $summary_week,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
