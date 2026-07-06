<?php
/**
 * 一時確認用API: results テーブルに course カラムが存在するか確認する
 * 確認が終わったら削除すること
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db();
$stmt = $pdo->query('DESCRIBE results');
$columns = $stmt->fetchAll();
$has_course = false;
foreach ($columns as $c) {
    if ($c['Field'] === 'course') { $has_course = true; break; }
}

json_response(['has_course' => $has_course, 'columns' => $columns]);
