<?php
/**
 * 一時確認用API: entriesのexhibit_time/start_timingのNULL状況を確認する
 * 使い方: check_beforeinfo.php?date=2026-07-05
 * 確認が終わったら削除すること
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db();
$date = $_GET['date'] ?? '2026-07-05';

$stmt = $pdo->query('DESCRIBE entries');
$columns = $stmt->fetchAll();

$stmt2 = $pdo->prepare('
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN e.exhibit_time IS NULL THEN 1 ELSE 0 END) AS exhibit_null,
        SUM(CASE WHEN e.start_timing IS NULL THEN 1 ELSE 0 END) AS st_null,
        SUM(CASE WHEN r.before_updated_at IS NULL THEN 1 ELSE 0 END) AS never_scraped
    FROM entries e
    JOIN races r ON r.id = e.race_id
    WHERE r.date = ?
');
$stmt2->execute([$date]);
$summary = $stmt2->fetch();

// レースごとの内訳(締切時刻・before_updated_at・NULL件数)
$stmt3 = $pdo->prepare('
    SELECT r.venue, r.race_no, r.scheduled_time, r.before_updated_at,
           COUNT(*) AS total,
           SUM(CASE WHEN e.exhibit_time IS NULL THEN 1 ELSE 0 END) AS exhibit_null,
           SUM(CASE WHEN e.start_timing IS NULL THEN 1 ELSE 0 END) AS st_null
    FROM entries e
    JOIN races r ON r.id = e.race_id
    WHERE r.date = ?
    GROUP BY r.id
    HAVING exhibit_null > 0 OR st_null > 0
    ORDER BY r.venue, r.race_no
');
$stmt3->execute([$date]);
$byRace = $stmt3->fetchAll();

// 成功した(NULLでない)レースの scheduled_time - before_updated_at の差分(分)を集計
$stmt4 = $pdo->prepare('
    SELECT r.venue, r.race_no, r.scheduled_time, r.before_updated_at,
           TIMESTAMPDIFF(MINUTE, r.before_updated_at, CONCAT(r.date, " ", r.scheduled_time)) AS mins_before_deadline,
           COUNT(*) AS total,
           SUM(CASE WHEN e.exhibit_time IS NULL THEN 1 ELSE 0 END) AS exhibit_null
    FROM entries e
    JOIN races r ON r.id = e.race_id
    WHERE r.date = ?
    GROUP BY r.id
    HAVING exhibit_null = 0
    ORDER BY r.venue, r.race_no
');
$stmt4->execute([$date]);
$successRaces = $stmt4->fetchAll();

// NULLレース側にも同じ差分を付与
$stmt5 = $pdo->prepare('
    SELECT TIMESTAMPDIFF(MINUTE, r.before_updated_at, CONCAT(r.date, " ", r.scheduled_time)) AS mins_before_deadline
    FROM entries e
    JOIN races r ON r.id = e.race_id
    WHERE r.date = ?
    GROUP BY r.id
    HAVING SUM(CASE WHEN e.exhibit_time IS NULL THEN 1 ELSE 0 END) > 0
');
$stmt5->execute([$date]);
$nullMinsBefore = $stmt5->fetchAll(PDO::FETCH_COLUMN);

json_response([
    'columns'            => $columns,
    'summary'            => $summary,
    'null_races'         => $byRace,
    'success_races'      => $successRaces,
    'null_mins_before_deadline' => $nullMinsBefore,
]);
