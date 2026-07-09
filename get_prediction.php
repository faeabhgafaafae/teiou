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

// AI予測(スコア・順位)の閲覧はStandard/Premium限定
$user = current_user();
$plan = $user['plan'] ?? 'free';
if (!$user || $plan === 'free') {
    json_response(['error' => 'premium_required', 'message' => 'AI予測はStandard/Premium会員限定です'], 403);
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
    SELECT p.player_id, p.predicted_rank, p.score_total,
           p.score_ability, p.score_course, p.score_today, p.score_weather,
           e.lane, pl.name, pl.grade,
           pp.win_rate, pp.fukusho_rate,
           pp.c1_rank1, pp.c1_count, pp.c1_fukusho,
           pp.c2_rank1, pp.c2_count, pp.c2_fukusho,
           pp.c3_rank1, pp.c3_count, pp.c3_fukusho,
           pp.c4_rank1, pp.c4_count, pp.c4_fukusho,
           pp.c5_rank1, pp.c5_count, pp.c5_fukusho,
           pp.c6_rank1, pp.c6_count, pp.c6_fukusho
    FROM predictions p
    JOIN entries e ON e.race_id = p.race_id AND e.player_id = p.player_id
    JOIN players pl ON pl.id = p.player_id
    LEFT JOIN player_periods pp
      ON pp.player_id = p.player_id
      AND pp.id = (
        SELECT id FROM player_periods
        WHERE player_id = p.player_id
        ORDER BY year DESC, period DESC
        LIMIT 1
      )
    WHERE p.race_id = ?
    ORDER BY p.predicted_rank ASC
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
