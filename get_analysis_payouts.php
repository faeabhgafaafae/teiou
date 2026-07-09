<?php
/**
 * 艇王 - データ分析: 払戻金傾向分析API
 * GET /get_analysis_payouts.php
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$user = current_user();
$plan = $user['plan'] ?? 'free';
if ($plan !== 'premium') {
    json_response(['error' => 'premium_required', 'message' => 'データ分析はPremium会員限定です'], 403);
}

$pdo = get_db();

// 賭式別 平均・最高・最低配当
$stmt = $pdo->query('
    SELECT bet_type,
        COUNT(*) AS cnt,
        AVG(amount) AS avg_amount,
        MAX(amount) AS max_amount,
        MIN(amount) AS min_amount
    FROM race_payouts
    GROUP BY bet_type
');
$rows = $stmt->fetchAll();

$BET_TYPE_ORDER = ['3連単', '3連複', '2連単', '2連複', '拡連複', '単勝', '複勝'];
usort($rows, function($a, $b) use ($BET_TYPE_ORDER) {
    $ia = array_search($a['bet_type'], $BET_TYPE_ORDER);
    $ib = array_search($b['bet_type'], $BET_TYPE_ORDER);
    $ia = $ia === false ? 99 : $ia;
    $ib = $ib === false ? 99 : $ib;
    return $ia <=> $ib;
});

$byBetType = [];
foreach ($rows as $row) {
    $byBetType[] = [
        'bet_type'   => $row['bet_type'],
        'count'      => (int)$row['cnt'],
        'avg_amount' => round((float)$row['avg_amount']),
        'max_amount' => (int)$row['max_amount'],
        'min_amount' => (int)$row['min_amount'],
    ];
}

// 3連単の人気別決着分布(荒れ具合の指標)
$stmt = $pdo->query('
    SELECT
        CASE
            WHEN popularity = 1 THEN "1番人気"
            WHEN popularity BETWEEN 2 AND 3 THEN "2-3番人気"
            WHEN popularity BETWEEN 4 AND 6 THEN "4-6番人気"
            WHEN popularity BETWEEN 7 AND 10 THEN "7-10番人気"
            WHEN popularity > 10 THEN "11番人気以下"
            ELSE "不明"
        END AS bucket,
        COUNT(*) AS cnt
    FROM race_payouts
    WHERE bet_type = "3連単" AND popularity IS NOT NULL
    GROUP BY bucket
');
$buckets = $stmt->fetchAll();

$BUCKET_ORDER = ['1番人気', '2-3番人気', '4-6番人気', '7-10番人気', '11番人気以下', '不明'];
$bucketMap = [];
$totalSanrentan = 0;
foreach ($buckets as $b) {
    $bucketMap[$b['bucket']] = (int)$b['cnt'];
    $totalSanrentan += (int)$b['cnt'];
}

$popularityDist = [];
foreach ($BUCKET_ORDER as $label) {
    if (!isset($bucketMap[$label])) continue;
    $cnt = $bucketMap[$label];
    $popularityDist[] = [
        'bucket' => $label,
        'count'  => $cnt,
        'rate'   => $totalSanrentan > 0 ? round($cnt / $totalSanrentan * 100, 1) : 0,
    ];
}

// データ期間
$stmt = $pdo->query('
    SELECT MIN(r.date) AS min_date, MAX(r.date) AS max_date
    FROM race_payouts rp
    JOIN races r ON r.id = rp.race_id
');
$range = $stmt->fetch();

json_response([
    'date_range'          => $range,
    'by_bet_type'         => $byBetType,
    'sanrentan_total'     => $totalSanrentan,
    'popularity_dist'     => $popularityDist,
]);
