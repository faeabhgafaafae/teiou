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
    SELECT e.waku, e.player_id, e.f_count, e.l_count, e.avg_st,
           e.win_rate_national, e.fukusho_national, e.rank3_national,
           e.win_rate_local, e.fukusho_local, e.rank3_local,
           e.motor_no, e.motor_2rate, e.boat_no, e.boat_2rate,
           pl.name, pl.grade,
           pp.win_rate AS pp_win_rate,
           pp.fukusho_rate AS pp_fukusho_rate
    FROM entries e
    LEFT JOIN players pl ON pl.id = e.player_id
    LEFT JOIN player_periods pp
      ON pp.player_id = e.player_id
      AND pp.id = (
        SELECT id FROM player_periods
        WHERE player_id = e.player_id
        ORDER BY year DESC, period DESC
        LIMIT 1
      )
    WHERE e.race_id = ?
    ORDER BY e.waku ASC
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
