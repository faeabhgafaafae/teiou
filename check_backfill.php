<?php
/**
 * バックフィル状況チェック
 * 指定期間のresults/racesテーブルのデータ有無を確認する
 * 使い方: curl "https://2410049.moo.jp/check_backfill.php?api_key=teio2025&start=2026-06-29&end=2026-07-14"
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

// 日付ごとのレース数・成績数を集計
$stmt = $pdo->prepare("
    SELECT
        r.date,
        COUNT(DISTINCT r.id)          AS race_count,
        COUNT(res.id)                 AS result_count,
        SUM(CASE WHEN res.id IS NULL THEN 1 ELSE 0 END) AS empty_races
    FROM races r
    LEFT JOIN results res ON res.race_id = r.id
    WHERE r.date BETWEEN ? AND ?
    GROUP BY r.date
    ORDER BY r.date
");
$stmt->execute([$start, $end]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 払戻データも確認
$stmt2 = $pdo->prepare("
    SELECT date, COUNT(*) AS payout_count
    FROM race_payouts
    WHERE date BETWEEN ? AND ?
    GROUP BY date
    ORDER BY date
");
$stmt2->execute([$start, $end]);
$payout_rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
$payout_by_date = [];
foreach ($payout_rows as $pr) {
    $payout_by_date[$pr['date']] = (int)$pr['payout_count'];
}

// 期間内の全日付を生成して比較
$dates_with_data = [];
foreach ($rows as $row) {
    $dates_with_data[$row['date']] = [
        'race_count'   => (int)$row['race_count'],
        'result_count' => (int)$row['result_count'],
        'payout_count' => $payout_by_date[$row['date']] ?? 0,
    ];
}

$all_dates   = [];
$missing     = [];
$cur = new DateTime($start);
$endDt = new DateTime($end);
while ($cur <= $endDt) {
    $d = $cur->format('Y-m-d');
    $all_dates[] = $d;
    if (!isset($dates_with_data[$d])) {
        $missing[] = $d;
    }
    $cur->modify('+1 day');
}

// 最後にデータがある日付
$last_date_with_data = null;
foreach (array_reverse($all_dates) as $d) {
    if (isset($dates_with_data[$d])) {
        $last_date_with_data = $d;
        break;
    }
}

echo json_encode([
    'range'              => ['start' => $start, 'end' => $end],
    'total_days'         => count($all_dates),
    'days_with_data'     => count($dates_with_data),
    'days_missing'       => count($missing),
    'last_date_with_data'=> $last_date_with_data,
    'missing_dates'      => $missing,
    'detail'             => $dates_with_data,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
