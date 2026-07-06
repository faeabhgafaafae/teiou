<?php
/**
 * 一時確認用API: entriesのexhibit_time/start_timing改善状況を確認する
 * 使い方: check_beforeinfo2.php?date=2026-07-06
 * 確認が終わったら削除すること
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db();
$date = $_GET['date'] ?? '2026-07-06';

// 締切を過ぎたレースのみ対象(まだ先のレースはNULLで当然のため除外)
$stmt = $pdo->prepare('
    SELECT
        COUNT(*) AS total_entries,
        SUM(CASE WHEN e.exhibit_time IS NULL THEN 1 ELSE 0 END) AS exhibit_null,
        SUM(CASE WHEN e.start_timing IS NULL THEN 1 ELSE 0 END) AS st_null,
        COUNT(DISTINCT r.id) AS total_races
    FROM entries e
    JOIN races r ON r.id = e.race_id
    WHERE r.date = ?
      AND r.scheduled_time IS NOT NULL
      AND CONCAT(r.date, " ", r.scheduled_time) <= NOW()
');
$stmt->execute([$date]);
$summary = $stmt->fetch();

$stmt2 = $pdo->prepare('
    SELECT r.venue, r.race_no, r.scheduled_time, r.before_updated_at,
           SUM(CASE WHEN e.exhibit_time IS NULL THEN 1 ELSE 0 END) AS exhibit_null,
           COUNT(*) AS total
    FROM entries e
    JOIN races r ON r.id = e.race_id
    WHERE r.date = ?
      AND r.scheduled_time IS NOT NULL
      AND CONCAT(r.date, " ", r.scheduled_time) <= NOW()
    GROUP BY r.id
    ORDER BY r.scheduled_time
');
$stmt2->execute([$date]);
$byRace = $stmt2->fetchAll();

json_response([
    'summary' => $summary,
    'by_race' => $byRace,
]);
