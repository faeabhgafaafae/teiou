<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$pdo = get_db();
$stmt = $pdo->query('SELECT race_id, boat_number, exhibit_time, st FROM entries ORDER BY id DESC LIMIT 30');
$rows = $stmt->fetchAll();

echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
