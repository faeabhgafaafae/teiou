<?php
/**
 * 艇王 - レース結果API
 * GET /get_race_result.php?date=2026-07-05&venue=下関&race_no=1
 * レース結果(着順・タイム・進入・ST)と払戻金一覧をまとめて返す
 */
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

$stmt = $pdo->prepare('SELECT id, scheduled_time FROM races WHERE date = ? AND venue = ? AND race_no = ?');
$stmt->execute([$date, $venue, (int)$race_no]);
$race = $stmt->fetch();

if (!$race) {
    json_response(['error' => 'レースが見つかりません'], 404);
}
$race_id = $race['id'];

$stmt2 = $pdo->prepare('
    SELECT res.lane, res.course, res.actual_rank, res.time, res.start_timing,
           pl.name, pl.grade
    FROM results res
    LEFT JOIN players pl ON pl.id = res.player_id
    WHERE res.race_id = ?
    ORDER BY (res.actual_rank IS NULL), res.actual_rank ASC
');
$stmt2->execute([$race_id]);
$results = $stmt2->fetchAll();

$stmt3 = $pdo->prepare('
    SELECT bet_type, combo, amount, popularity
    FROM race_payouts
    WHERE race_id = ?
');
$stmt3->execute([$race_id]);
$payouts = $stmt3->fetchAll();

json_response([
    'date'           => $date,
    'venue'          => $venue,
    'race_no'        => (int)$race_no,
    'scheduled_time' => $race['scheduled_time'],
    'has_result'     => count($results) > 0,
    'results'        => $results,
    'payouts'        => $payouts,
]);
