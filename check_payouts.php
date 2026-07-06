<?php
/**
 * 一時確認用API: race_payouts の登録件数を確認する
 * 使い方: check_payouts.php?race_date=2026-07-05
 * 確認が終わったら削除すること
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$race_date = $_GET['race_date'] ?? '';
if (!$race_date) {
    json_response(['error' => 'race_date は必須です（例: 2026-07-05）'], 400);
}

$pdo = get_db();

$stmt = $pdo->prepare('
    SELECT COUNT(*) AS total,
           COUNT(DISTINCT r.id)    AS race_count,
           COUNT(DISTINCT r.venue) AS venue_count
    FROM race_payouts rp
    JOIN races r ON r.id = rp.race_id
    WHERE r.date = ?
');
$stmt->execute([$race_date]);
$summary = $stmt->fetch();

$stmt2 = $pdo->prepare('
    SELECT rp.bet_type, COUNT(*) AS cnt
    FROM race_payouts rp
    JOIN races r ON r.id = rp.race_id
    WHERE r.date = ?
    GROUP BY rp.bet_type
    ORDER BY rp.bet_type
');
$stmt2->execute([$race_date]);
$by_bet_type = $stmt2->fetchAll();

$stmt3 = $pdo->prepare('
    SELECT COUNT(*) AS cnt
    FROM results res
    JOIN races r ON r.id = res.race_id
    WHERE r.date = ?
');
$stmt3->execute([$race_date]);
$results_count = (int)$stmt3->fetch()['cnt'];

json_response([
    'race_date'      => $race_date,
    'payout_total'   => (int)$summary['total'],
    'race_count'     => (int)$summary['race_count'],
    'venue_count'    => (int)$summary['venue_count'],
    'by_bet_type'    => $by_bet_type,
    'results_count'  => $results_count,
]);
