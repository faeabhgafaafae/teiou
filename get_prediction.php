<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$date    = $_GET['date']    ?? '';
$venue   = $_GET['venue']   ?? '';
$race_no = $_GET['race_no'] ?? '';

if (!$date || !$venue || !$race_no) {
    json_response(['error' => 'date, venue, race_no は必須です'], 400);
}

$pdo = get_db();

$stmt = $pdo->prepare('SELECT id FROM races WHERE date = ? AND venue = ? AND race_no = ?');
$stmt->execute([$date, $venue, (int)$race_no]);
$race = $stmt->fetch();

if (!$race) {
    json_response(['error' => 'レースが見つかりません'], 404);
}

$raceId = (int)$race['id'];

$stmt = $pdo->prepare('
    SELECT lane, player_id, name, grade, score_total,
           score_ability, score_course, score_today, score_weather,
           predicted_rank
    FROM predictions
    WHERE race_id = ?
    ORDER BY predicted_rank ASC
');
$stmt->execute([$raceId]);
$predictions = $stmt->fetchAll();

json_response([
    'race_id'     => $raceId,
    'date'        => $date,
    'venue'       => $venue,
    'race_no'     => (int)$race_no,
    'predictions' => $predictions,
]);
