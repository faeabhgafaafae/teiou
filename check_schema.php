<?php
/**
 * 一時確認用API: バックフィル後のresults整合性確認
 * 確認が終わったら削除すること
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$pdo = get_db();

$dates = ['2026-06-29', '2026-06-30', '2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04', '2026-07-05'];
$out = [];

$stmt = $pdo->prepare('
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN res.actual_rank IS NULL THEN 1 ELSE 0 END) AS rank_null,
        SUM(CASE WHEN res.actual_rank BETWEEN 1 AND 6 THEN 1 ELSE 0 END) AS rank_valid,
        SUM(CASE WHEN res.course IS NOT NULL THEN 1 ELSE 0 END) AS course_notnull,
        SUM(CASE WHEN res.course BETWEEN 1 AND 6 THEN 1 ELSE 0 END) AS course_valid,
        SUM(CASE WHEN res.start_timing IS NOT NULL THEN 1 ELSE 0 END) AS st_notnull,
        MIN(res.start_timing) AS st_min,
        MAX(res.start_timing) AS st_max,
        SUM(CASE WHEN res.time IS NOT NULL THEN 1 ELSE 0 END) AS time_notnull
    FROM results res
    JOIN races r ON r.id = res.race_id
    WHERE r.date = ?
');

foreach ($dates as $d) {
    $stmt->execute([$d]);
    $out[$d] = $stmt->fetch();
}

json_response(['by_date' => $out]);
