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

$stmt = $pdo->prepare('
    SELECT waku, player_id, f_count, l_count, avg_st,
           win_rate_national, fukusho_national, rank3_national,
           win_rate_local, fukusho_local, rank3_local,
           motor_no, motor_2rate, boat_no, boat_2rate
    FROM entries
    WHERE race_id = ?
    ORDER BY waku ASC
');
$stmt->execute([$race['id']]);
$entries = $stmt->fetchAll();

$stmt2 = $pdo->prepare('SELECT scheduled_time, wind_speed, wind_dir, wave_height FROM races WHERE id = ?');
$stmt2->execute([$race['id']]);
$raceInfo = $stmt2->fetch();

json_response([
    'date'    => $date,
    'venue'   => $venue,
    'race_no' => (int)$race_no,
    'scheduled_time' => $raceInfo['scheduled_time'] ?? null,
    'weather' => [
        'wind_speed'  => $raceInfo['wind_speed']  ?? null,
        'wind_dir'    => $raceInfo['wind_dir']    ?? null,
        'wave_height' => $raceInfo['wave_height'] ?? null,
    ],
    'entries' => $entries,
]);
