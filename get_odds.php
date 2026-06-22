<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$race_id = $_GET['race_id'] ?? '';
$venue   = $_GET['venue']   ?? '';
$date    = $_GET['date']    ?? '';
$race_no = $_GET['race_no'] ?? '';
$all     = $_GET['all']     ?? '';

$pdo = get_db();

if (!$race_id) {
    if (!$venue || !$date || !$race_no) {
        json_response(['error' => 'race_id または venue/date/race_no は必須です'], 400);
    }
    $stmt = $pdo->prepare('SELECT id FROM races WHERE date = ? AND venue = ? AND race_no = ?');
    $stmt->execute([$date, $venue, (int)$race_no]);
    $race = $stmt->fetch();
    if (!$race) {
        json_response(['error' => 'レースが見つかりません'], 404);
    }
    $race_id = $race['id'];
}

if ($all) {
    $sql = 'SELECT combo, odds FROM odds_3t WHERE race_id = ? ORDER BY odds ASC';
} else {
    $sql = 'SELECT combo, odds FROM odds_3t WHERE race_id = ? ORDER BY odds ASC LIMIT 10';
}
$stmt = $pdo->prepare($sql);
$stmt->execute([(int)$race_id]);
$odds = $stmt->fetchAll();

json_response(['odds' => $odds]);
