<?php
/**
 * 艇王 - 直前情報: 同時間帯の他会場レースAPI
 * GET /get_nearby_races.php?date=2026-07-06&venue=桐生&race_no=5
 * 指定レースの締切時刻(scheduled_time)に近い、他会場のレースを抽出する
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$date    = $_GET['date']    ?? '';
$venue   = $_GET['venue']   ?? '';
$race_no = (int)($_GET['race_no'] ?? 0);

if (!$date || !$venue || !$race_no) {
    json_response(['error' => 'date, venue, race_no は必須です'], 400);
}

$pdo = get_db();

$stmt = $pdo->prepare('SELECT scheduled_time FROM races WHERE date = ? AND venue = ? AND race_no = ?');
$stmt->execute([$date, $venue, $race_no]);
$current = $stmt->fetch();

if (!$current || !$current['scheduled_time']) {
    json_response(['scheduled_time' => null, 'nearby' => []]);
}

$scheduledTime = $current['scheduled_time'];

// 同日・他会場のレースを、締切時刻の近い順に抽出(前後60分以内)
$stmt2 = $pdo->prepare("
    SELECT venue, race_no, scheduled_time,
        ABS(TIMESTAMPDIFF(MINUTE, CONCAT(?, ' ', scheduled_time), CONCAT(?, ' ', ?))) AS diff_min
    FROM races
    WHERE date = ?
      AND venue <> ?
      AND scheduled_time IS NOT NULL
    HAVING diff_min <= 60
    ORDER BY diff_min ASC, venue ASC
    LIMIT 20
");
$stmt2->execute([$date, $date, $scheduledTime, $date, $venue]);
$nearby = $stmt2->fetchAll();

json_response([
    'scheduled_time' => $scheduledTime,
    'nearby'         => $nearby,
]);
