<?php
/**
 * 艇王 - データ分析: レーサー個人成績(簡易)API
 * GET /get_player_detail.php?player_id=3876
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$player_id = (int)($_GET['player_id'] ?? 0);
if (!$player_id) {
    json_response(['error' => 'player_id は必須です'], 400);
}

$pdo = get_db();

$stmt = $pdo->prepare('SELECT id, name, name_kana, branch, grade FROM players WHERE id = ?');
$stmt->execute([$player_id]);
$player = $stmt->fetch();

if (!$player) {
    json_response(['error' => '選手が見つかりません'], 404);
}

$stmt2 = $pdo->prepare('
    SELECT
        COUNT(*) AS race_count,
        SUM(CASE WHEN actual_rank = 1 THEN 1 ELSE 0 END) AS rank1_count,
        SUM(CASE WHEN actual_rank <= 2 THEN 1 ELSE 0 END) AS rank2_count,
        SUM(CASE WHEN actual_rank <= 3 THEN 1 ELSE 0 END) AS rank3_count,
        AVG(start_timing) AS avg_st
    FROM results
    WHERE player_id = ? AND actual_rank IS NOT NULL
');
$stmt2->execute([$player_id]);
$stats = $stmt2->fetch();

$rc = (int)$stats['race_count'];
$statsOut = null;
if ($rc > 0) {
    $statsOut = [
        'race_count'  => $rc,
        'rank1_rate'  => round($stats['rank1_count'] / $rc * 100, 1),
        'rank2_rate'  => round($stats['rank2_count'] / $rc * 100, 1),
        'rank3_rate'  => round($stats['rank3_count'] / $rc * 100, 1),
        'avg_st'      => $stats['avg_st'] !== null ? round((float)$stats['avg_st'], 2) : null,
    ];
}

// 直近成績(10走)
$stmt3 = $pdo->prepare('
    SELECT r.date, r.venue, r.race_no, res.lane, res.actual_rank
    FROM results res
    JOIN races r ON r.id = res.race_id
    WHERE res.player_id = ?
    ORDER BY r.date DESC, r.race_no DESC
    LIMIT 10
');
$stmt3->execute([$player_id]);
$recent = $stmt3->fetchAll();

json_response([
    'player' => $player,
    'stats'  => $statsOut,
    'recent' => $recent,
]);
