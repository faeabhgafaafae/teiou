<?php
/**
 * 艇王 - データ分析: 会場別データAPI
 * GET /get_analysis_venue.php?venue=桐生
 *
 * 枠(lane)別の入着率は全履歴、進入コース(course)別・決まり手推定は
 * courseカラムが追加された2026-06-29以降のデータのみで集計する。
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$user = current_user();
$plan = $user['plan'] ?? 'free';
if (!in_array($plan, ['standard', 'premium'])) {
    json_response(['error' => 'standard_required', 'message' => '会場別データはStandard以上のプランが必要です'], 403);
}

define('COURSE_DATA_SINCE', '2026-06-29');

$venue = $_GET['venue'] ?? '';
if (!$venue) {
    json_response(['error' => 'venue は必須です'], 400);
}

$pdo = get_db();

// 枠(lane)別 入着率(全履歴)
$stmt = $pdo->prepare('
    SELECT res.lane,
        COUNT(*) AS race_count,
        SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END) AS rank1_count,
        SUM(CASE WHEN res.actual_rank <= 2 THEN 1 ELSE 0 END) AS rank2_count,
        SUM(CASE WHEN res.actual_rank <= 3 THEN 1 ELSE 0 END) AS rank3_count
    FROM results res
    JOIN races r ON r.id = res.race_id
    WHERE r.venue = ? AND res.actual_rank IS NOT NULL
    GROUP BY res.lane
    ORDER BY res.lane
');
$stmt->execute([$venue]);
$laneRows = $stmt->fetchAll();

$laneStats = [];
foreach ($laneRows as $row) {
    $rc = (int)$row['race_count'];
    if ($rc === 0) continue;
    $laneStats[] = [
        'lane'       => (int)$row['lane'],
        'race_count' => $rc,
        'rank1_rate' => round($row['rank1_count'] / $rc * 100, 1),
        'rank2_rate' => round($row['rank2_count'] / $rc * 100, 1),
        'rank3_rate' => round($row['rank3_count'] / $rc * 100, 1),
    ];
}

// 進入コース別 入着率(courseカラムが存在する期間のみ)
$stmt = $pdo->prepare('
    SELECT res.course,
        COUNT(*) AS race_count,
        SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END) AS rank1_count,
        SUM(CASE WHEN res.actual_rank <= 2 THEN 1 ELSE 0 END) AS rank2_count,
        SUM(CASE WHEN res.actual_rank <= 3 THEN 1 ELSE 0 END) AS rank3_count
    FROM results res
    JOIN races r ON r.id = res.race_id
    WHERE r.venue = ? AND res.course IS NOT NULL AND r.date >= ?
    GROUP BY res.course
    ORDER BY res.course
');
$stmt->execute([$venue, COURSE_DATA_SINCE]);
$courseRows = $stmt->fetchAll();

$courseStats = [];
foreach ($courseRows as $row) {
    $rc = (int)$row['race_count'];
    if ($rc === 0) continue;
    $courseStats[] = [
        'course'     => (int)$row['course'],
        'race_count' => $rc,
        'rank1_rate' => round($row['rank1_count'] / $rc * 100, 1),
        'rank2_rate' => round($row['rank2_count'] / $rc * 100, 1),
        'rank3_rate' => round($row['rank3_count'] / $rc * 100, 1),
    ];
}

// 決まり手 簡易推定(1着艇のcourseのみで判定。courseカラムが存在する期間のみ)
// 1コース勝利=逃げ、2コース勝利=差し、3〜6コース勝利=まくり系 とする簡易分類
$stmt = $pdo->prepare('
    SELECT res.course, COUNT(*) AS cnt
    FROM results res
    JOIN races r ON r.id = res.race_id
    WHERE r.venue = ? AND res.actual_rank = 1 AND res.course IS NOT NULL AND r.date >= ?
    GROUP BY res.course
');
$stmt->execute([$venue, COURSE_DATA_SINCE]);
$winCourseRows = $stmt->fetchAll();

$nige = 0; $sashi = 0; $makuri = 0; $total = 0;
foreach ($winCourseRows as $row) {
    $c = (int)$row['course'];
    $cnt = (int)$row['cnt'];
    $total += $cnt;
    if ($c === 1) $nige += $cnt;
    elseif ($c === 2) $sashi += $cnt;
    else $makuri += $cnt;
}

$kimarite = null;
if ($total > 0) {
    $kimarite = [
        'total_races'  => $total,
        'nige_rate'    => round($nige   / $total * 100, 1),
        'sashi_rate'   => round($sashi  / $total * 100, 1),
        'makuri_rate'  => round($makuri / $total * 100, 1),
    ];
}

json_response([
    'venue'             => $venue,
    'lane_stats'         => $laneStats,
    'course_data_since'  => COURSE_DATA_SINCE,
    'course_stats'       => $courseStats,
    'kimarite_estimate'  => $kimarite,
]);
