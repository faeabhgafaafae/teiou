<?php
/**
 * 一時確認用API: results テーブルのスキーマと実データを確認する
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
if ($race_date) {
    $stmt2 = $pdo->prepare('
        SELECT res.*
        FROM results res
        JOIN races r ON r.id = res.race_id
        WHERE r.date = ?
        ORDER BY r.race_no, res.actual_rank
        LIMIT 6
    ');
    $stmt2->execute([$race_date]);
    $sample = $stmt2->fetchAll();
}

json_response([
    'columns' => $columns,
    'sample'  => $sample,
]);
