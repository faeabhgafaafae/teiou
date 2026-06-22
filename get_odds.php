<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$race_id = $_GET['race_id'] ?? '';

if (!$race_id) {
    json_response(['error' => 'race_id は必須です'], 400);
}

$pdo = get_db();

$stmt = $pdo->prepare('
    SELECT first, second, third, odds
    FROM odds_3t
    WHERE race_id = ?
    ORDER BY odds ASC
    LIMIT 10
');
$stmt->execute([(int)$race_id]);
$odds = $stmt->fetchAll();

json_response($odds);
