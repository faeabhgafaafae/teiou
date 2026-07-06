<?php
/**
 * 一時確認用API: jcdマッピングバグの影響範囲(races/entries/results/race_payouts)を確認する
 * 確認が終わったら削除すること
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db();

$affected = ['丸亀', '児島', '宮島', '徳山', '下関', '若松', '芦屋', '福岡', '唐津', '大村'];
$placeholders = implode(',', array_fill(0, count($affected), '?'));

// races/entries/results/race_payoutsの件数を会場別に集計
$stmt = $pdo->prepare("
    SELECT r.venue,
        COUNT(DISTINCT r.id) AS race_count,
        (SELECT COUNT(*) FROM entries e WHERE e.race_id IN (SELECT id FROM races WHERE venue = r.venue)) AS entries_count,
        (SELECT COUNT(*) FROM results res WHERE res.race_id IN (SELECT id FROM races WHERE venue = r.venue)) AS results_count,
        (SELECT COUNT(*) FROM race_payouts rp WHERE rp.race_id IN (SELECT id FROM races WHERE venue = r.venue)) AS payouts_count
    FROM races r
    WHERE r.venue IN ($placeholders)
    GROUP BY r.venue
    ORDER BY r.venue
");
$stmt->execute($affected);
$byVenue = $stmt->fetchAll();

// 大村: results/race_payoutsのサンプル(実データが正しく存在するか、着順・払戻内容を目視)
$stmt2 = $pdo->prepare("
    SELECT r.id, r.date, r.venue, r.race_no, r.scheduled_time
    FROM races r
    WHERE r.venue = '大村'
    ORDER BY r.date DESC
    LIMIT 5
");
$stmt2->execute();
$omuraRaces = $stmt2->fetchAll();

$omuraResultsSample = [];
if ($omuraRaces) {
    $raceId = $omuraRaces[0]['id'];
    $stmt3 = $pdo->prepare("SELECT lane, player_id, actual_rank, time FROM results WHERE race_id = ? ORDER BY actual_rank");
    $stmt3->execute([$raceId]);
    $omuraResultsSample = $stmt3->fetchAll();
}

// 大村のplayer_idが実在の選手か(playersテーブルとJOINして名前が引けるか)
$omuraPlayerCheck = [];
if ($omuraResultsSample) {
    $pids = array_column($omuraResultsSample, 'player_id');
    $ph2 = implode(',', array_fill(0, count($pids), '?'));
    $stmt4 = $pdo->prepare("SELECT id, name, branch FROM players WHERE id IN ($ph2)");
    $stmt4->execute($pids);
    $omuraPlayerCheck = $stmt4->fetchAll();
}

// 「高松」という架空venue名でresults/race_payoutsが存在するか(結果側に汚染がないか)
$stmt5 = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM races WHERE venue = '高松') AS races_takamatsu,
        (SELECT COUNT(*) FROM results res JOIN races r ON r.id=res.race_id WHERE r.venue = '高松') AS results_takamatsu,
        (SELECT COUNT(*) FROM race_payouts rp JOIN races r ON r.id=rp.race_id WHERE r.venue = '高松') AS payouts_takamatsu,
        (SELECT COUNT(*) FROM entries e JOIN races r ON r.id=e.race_id WHERE r.venue = '高松') AS entries_takamatsu
");
$takamatsuAll = $stmt5->fetch();

json_response([
    'by_venue_all_tables' => $byVenue,
    'omura_recent_races'  => $omuraRaces,
    'omura_results_sample'=> $omuraResultsSample,
    'omura_player_check'  => $omuraPlayerCheck,
    'takamatsu_all_tables'=> $takamatsuAll,
]);
