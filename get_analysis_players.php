<?php
/**
 * 艇王 - データ分析: 選手ランキングAPI
 * GET /get_analysis_players.php?scope=national|local&venue=桐生&min_races=20&sort=rank1_rate&order=desc&limit=50
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$user = current_user();
$plan = $user['plan'] ?? 'free';
$isStandardPlus = in_array($plan, ['standard', 'premium']);

$scope     = $_GET['scope']     ?? 'national';
$venue     = $_GET['venue']     ?? '';
$min_races = max(1, (int)($_GET['min_races'] ?? 20));
$sort      = $_GET['sort']      ?? 'rank1_rate';
$order     = strtolower($_GET['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$limit     = min(200, max(1, (int)($_GET['limit'] ?? 50)));

// Free: 全国上位10件のみ許可。それ以外は Standard+ 必須
if (!$isStandardPlus) {
    if ($limit > 10 || $scope !== 'national') {
        json_response(['error' => 'standard_required', 'message' => '全件表示・当地絞り込みはStandard以上のプランが必要です'], 403);
    }
}

$SORT_COLUMNS = ['win_score', 'rank1_rate', 'rank2_rate', 'rank3_rate', 'avg_st', 'race_count'];
if (!in_array($sort, $SORT_COLUMNS, true)) {
    $sort = 'rank1_rate';
}

$pdo = get_db();

$joinRaces = '';
$whereVenue = '';
$params = [];
if ($scope === 'local') {
    if (!$venue) {
        json_response(['error' => 'localモードではvenueが必須です'], 400);
    }
    $joinRaces = 'JOIN races r ON r.id = res.race_id';
    $whereVenue = 'AND r.venue = ?';
    $params[] = $venue;
}

$sql = "
    SELECT
        p.id, p.name, p.grade,
        COUNT(*) AS race_count,
        SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END) AS rank1_count,
        SUM(CASE WHEN res.actual_rank <= 2 THEN 1 ELSE 0 END) AS rank2_count,
        SUM(CASE WHEN res.actual_rank <= 3 THEN 1 ELSE 0 END) AS rank3_count,
        SUM(CASE WHEN res.actual_rank = 1 THEN 2 WHEN res.actual_rank = 2 THEN 1 ELSE 0 END) AS score_sum,
        AVG(res.start_timing) AS avg_st
    FROM results res
    JOIN players p ON p.id = res.player_id
    $joinRaces
    WHERE res.actual_rank IS NOT NULL
    $whereVenue
    GROUP BY p.id
    HAVING race_count >= ?
";
$params[] = $min_races;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$out = [];
foreach ($rows as $row) {
    $rc = (int)$row['race_count'];
    $out[] = [
        'player_id'   => (int)$row['id'],
        'name'        => $row['name'],
        'grade'       => $row['grade'],
        'race_count'  => $rc,
        'win_score'   => round($row['score_sum'] / $rc, 2),
        'rank1_rate'  => round($row['rank1_count'] / $rc * 100, 1),
        'rank2_rate'  => round($row['rank2_count'] / $rc * 100, 1),
        'rank3_rate'  => round($row['rank3_count'] / $rc * 100, 1),
        'avg_st'      => $row['avg_st'] !== null ? round((float)$row['avg_st'], 2) : null,
    ];
}

usort($out, function($a, $b) use ($sort, $order) {
    $av = $a[$sort]; $bv = $b[$sort];
    if ($av === null) $av = $order === 'ASC' ? PHP_FLOAT_MAX : -PHP_FLOAT_MAX;
    if ($bv === null) $bv = $order === 'ASC' ? PHP_FLOAT_MAX : -PHP_FLOAT_MAX;
    if ($av == $bv) return 0;
    if ($order === 'ASC') return $av <=> $bv;
    return $bv <=> $av;
});

$out = array_slice($out, 0, $limit);

json_response([
    'scope'     => $scope,
    'venue'     => $scope === 'local' ? $venue : null,
    'min_races' => $min_races,
    'sort'      => $sort,
    'order'     => $order,
    'count'     => count($out),
    'players'   => $out,
]);
