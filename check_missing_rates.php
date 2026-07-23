<?php
/**
 * バックフィル前後の欠損率チェック
 * 使い方: curl "https://2410049.moo.jp/check_missing_rates.php?api_key=teio2025&start=2026-06-29&end=2026-07-14"
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (($_GET['api_key'] ?? '') !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

$start = $_GET['start'] ?? '2026-06-29';
$end   = $_GET['end']   ?? '2026-07-14';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗: ' . $e->getMessage()]);
    exit;
}

// ── results テーブル: 成績・start_timing・course 欠損 ──────────────
$stmt = $pdo->prepare("
    SELECT
        r.date,
        COUNT(res.id)                                        AS result_rows,
        SUM(CASE WHEN res.start_timing IS NULL THEN 1 END)  AS st_null,
        SUM(CASE WHEN res.course       IS NULL THEN 1 END)  AS course_null
    FROM races r
    LEFT JOIN results res ON res.race_id = r.id
    WHERE r.date BETWEEN ? AND ?
    GROUP BY r.date
    ORDER BY r.date
");
$stmt->execute([$start, $end]);
$result_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── entries テーブル: exhibit_time・start_timing 欠損 ─────────────
$stmt2 = $pdo->prepare("
    SELECT
        r.date,
        COUNT(e.id)                                           AS entry_rows,
        SUM(CASE WHEN e.exhibit_time IS NULL THEN 1 END)     AS et_null,
        SUM(CASE WHEN e.start_timing IS NULL THEN 1 END)     AS ent_st_null
    FROM races r
    LEFT JOIN entries e ON e.race_id = r.id
    WHERE r.date BETWEEN ? AND ?
    GROUP BY r.date
    ORDER BY r.date
");
$stmt2->execute([$start, $end]);
$entry_rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// ── races テーブル: has_result ────────────────────────────────────
$stmt3 = $pdo->prepare("
    SELECT
        r.date,
        COUNT(r.id)                                                   AS race_count,
        SUM(CASE WHEN EXISTS(SELECT 1 FROM results x WHERE x.race_id = r.id) THEN 1 ELSE 0 END) AS races_with_result
    FROM races r
    WHERE r.date BETWEEN ? AND ?
    GROUP BY r.date
    ORDER BY r.date
");
$stmt3->execute([$start, $end]);
$race_rows = $stmt3->fetchAll(PDO::FETCH_ASSOC);

// ── 集計 ──────────────────────────────────────────────────────────
$by_date = [];
foreach ($race_rows as $row) {
    $by_date[$row['date']] = [
        'race_count'        => (int)$row['race_count'],
        'races_with_result' => (int)$row['races_with_result'],
        'result_rows'       => 0, 'st_null'    => 0, 'course_null'  => 0,
        'entry_rows'        => 0, 'et_null'    => 0, 'ent_st_null'  => 0,
    ];
}
foreach ($result_rows as $row) {
    if (!isset($by_date[$row['date']])) continue;
    $by_date[$row['date']]['result_rows']  = (int)$row['result_rows'];
    $by_date[$row['date']]['st_null']      = (int)$row['st_null'];
    $by_date[$row['date']]['course_null']  = (int)$row['course_null'];
}
foreach ($entry_rows as $row) {
    if (!isset($by_date[$row['date']])) continue;
    $by_date[$row['date']]['entry_rows']   = (int)$row['entry_rows'];
    $by_date[$row['date']]['et_null']      = (int)$row['et_null'];
    $by_date[$row['date']]['ent_st_null']  = (int)$row['ent_st_null'];
}

// ── 全期間合計 ────────────────────────────────────────────────────
$tot = [
    'race_count'=>0,'races_with_result'=>0,
    'result_rows'=>0,'st_null'=>0,'course_null'=>0,
    'entry_rows'=>0,'et_null'=>0,'ent_st_null'=>0,
];
foreach ($by_date as $d) {
    foreach ($tot as $k => &$v) { $v += $d[$k]; }
}
unset($v);

function pct($null, $total) {
    if (!$total) return null;
    return round($null / $total * 100, 2);
}

echo json_encode([
    'range'   => ['start' => $start, 'end' => $end],
    'summary' => [
        'race_count'           => $tot['race_count'],
        'races_with_result'    => $tot['races_with_result'],
        'races_missing_result' => $tot['race_count'] - $tot['races_with_result'],
        'result_coverage_pct'  => pct($tot['races_with_result'], $tot['race_count']),
        'result_rows'          => $tot['result_rows'],
        'start_timing_null'    => $tot['st_null'],
        'start_timing_null_pct'=> pct($tot['st_null'], $tot['result_rows']),
        'course_null'          => $tot['course_null'],
        'course_null_pct'      => pct($tot['course_null'], $tot['result_rows']),
        'entry_rows'           => $tot['entry_rows'],
        'exhibit_time_null'    => $tot['et_null'],
        'exhibit_time_null_pct'=> pct($tot['et_null'], $tot['entry_rows']),
        'entry_start_timing_null'    => $tot['ent_st_null'],
        'entry_start_timing_null_pct'=> pct($tot['ent_st_null'], $tot['entry_rows']),
    ],
    'by_date' => array_values(array_map(function($d, $date) {
        return array_merge(['date' => $date], $d);
    }, $by_date, array_keys($by_date))),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
