<?php
/**
 * 一時確認用API: racesテーブルの重複行・venue名の不一致を確認する
 * 確認が終わったら削除すること
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db();

$date = $_GET['date'] ?? '2026-07-05';

$stmt = $pdo->prepare('
    SELECT r.id, r.date, r.venue, r.race_no, r.scheduled_time,
           (SELECT COUNT(*) FROM results res WHERE res.race_id = r.id) AS result_count
    FROM races r
    WHERE r.date = ?
    ORDER BY r.venue, r.race_no, r.id
');
$stmt->execute([$date]);
$rows = $stmt->fetchAll();

// venueごとに件数集計、重複(同じvenue+race_noで複数id)を検出
$byVenueRace = [];
foreach ($rows as $r) {
    $key = $r['venue'] . '|' . $r['race_no'];
    $byVenueRace[$key][] = $r;
}
$dups = [];
foreach ($byVenueRace as $k => $list) {
    if (count($list) > 1) $dups[$k] = $list;
}

$distinctVenues = [];
foreach ($rows as $r) { $distinctVenues[$r['venue']] = true; }

json_response([
    'total_rows'       => count($rows),
    'distinct_venues'  => array_keys($distinctVenues),
    'duplicates'       => $dups,
    'sample_miyajima'  => array_values(array_filter($rows, function($r) { return mb_strpos($r['venue'], '宮') !== false; })),
]);
