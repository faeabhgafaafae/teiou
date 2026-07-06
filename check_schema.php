<?php
/**
 * 一時確認用API: results テーブルの実データを確認する
 * 使い方: check_schema.php?race_date=2026-07-05
 * 確認が終わったら削除すること
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$race_date = $_GET['race_date'] ?? '';

$pdo = get_db();

$stmt = $pdo->query('DESCRIBE results');
$columns = $stmt->fetchAll();

$sample = [];
$stats  = null;
if ($race_date) {
    $stmt2 = $pdo->prepare('
        SELECT res.*
        FROM results res
        JOIN races r ON r.id = res.race_id
        WHERE r.date = ?
        ORDER BY r.race_no, res.actual_rank
        LIMIT 12
    ');
    $stmt2->execute([$race_date]);
    $sample = $stmt2->fetchAll();

    $stmt3 = $pdo->prepare('
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN res.time IS NOT NULL THEN 1 ELSE 0 END) AS time_notnull,
            SUM(CASE WHEN res.actual_rank <= 4 AND res.time IS NOT NULL THEN 1 ELSE 0 END) AS time_notnull_rank1to4,
            SUM(CASE WHEN res.actual_rank <= 4 THEN 1 ELSE 0 END) AS rank1to4_total,
            SUM(CASE WHEN res.start_timing IS NOT NULL THEN 1 ELSE 0 END) AS st_notnull,
            SUM(CASE WHEN res.course IS NOT NULL THEN 1 ELSE 0 END) AS course_notnull,
            MIN(res.start_timing) AS st_min,
            MAX(res.start_timing) AS st_max,
            MIN(res.course) AS course_min,
            MAX(res.course) AS course_max
        FROM results res
        JOIN races r ON r.id = res.race_id
        WHERE r.date = ?
    ');
    $stmt3->execute([$race_date]);
    $stats = $stmt3->fetch();
}

json_response([
    'columns' => $columns,
    'sample'  => $sample,
    'stats'   => $stats,
]);
