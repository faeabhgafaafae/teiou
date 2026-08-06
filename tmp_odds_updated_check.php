<?php
/**
 * tmp_odds_updated_check.php — odds_updated_at 計測 (使用後削除)
 * 3分割ジョブ有効化(7/29)以降のデータで bad率を再測定する
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (($_GET['api_key'] ?? '') !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// 対象: 7/29以降、scheduled_time あり
// bad定義: null(オッズ未取得) OR scheduled_time の180分超前に取得(午前スクレイプのみ)
$sql = "
SELECT
    r.date,
    r.scheduled_time,
    r.odds_updated_at,
    HOUR(STR_TO_DATE(r.scheduled_time, '%H:%i')) AS race_hour,
    CASE
        WHEN r.odds_updated_at IS NULL THEN 'null'
        WHEN TIMESTAMPDIFF(MINUTE,
             r.odds_updated_at,
             STR_TO_DATE(CONCAT(r.date, ' ', r.scheduled_time), '%Y-%m-%d %H:%i')
        ) > 180 THEN 'stale'
        WHEN TIMESTAMPDIFF(MINUTE,
             r.odds_updated_at,
             STR_TO_DATE(CONCAT(r.date, ' ', r.scheduled_time), '%Y-%m-%d %H:%i')
        ) >= 0 THEN 'good'
        ELSE 'after_start'
    END AS status
FROM races r
WHERE r.date >= '2026-07-29'
  AND r.scheduled_time IS NOT NULL
  AND r.scheduled_time != ''
ORDER BY r.date, r.scheduled_time
";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$total = count($rows);

$null_count  = 0;
$stale_count = 0;
$good_count  = 0;
$after_count = 0;
$by_hour = [];
$by_date = [];

foreach ($rows as $row) {
    $status = $row['status'];
    $hour   = (int)$row['race_hour'];
    $date   = $row['date'];

    if ($status === 'null')        $null_count++;
    elseif ($status === 'stale')   $stale_count++;
    elseif ($status === 'good')    $good_count++;
    else                           $after_count++;

    // 時間帯別
    if (!isset($by_hour[$hour])) $by_hour[$hour] = ['total'=>0,'bad'=>0,'null'=>0,'stale'=>0,'good'=>0];
    $by_hour[$hour]['total']++;
    if ($status === 'null')  { $by_hour[$hour]['bad']++; $by_hour[$hour]['null']++; }
    if ($status === 'stale') { $by_hour[$hour]['bad']++; $by_hour[$hour]['stale']++; }
    if ($status === 'good')  $by_hour[$hour]['good']++;

    // 日別
    if (!isset($by_date[$date])) $by_date[$date] = ['total'=>0,'bad'=>0];
    $by_date[$date]['total']++;
    if ($status === 'null' || $status === 'stale') $by_date[$date]['bad']++;
}

ksort($by_hour);
ksort($by_date);

$bad_total = $null_count + $stale_count;

// 時間帯別をレスポンス向けに整形
$by_hour_out = [];
foreach ($by_hour as $h => $v) {
    $by_hour_out[sprintf('%02d', $h)] = [
        'total' => $v['total'],
        'null'  => $v['null'],
        'stale' => $v['stale'],
        'good'  => $v['good'],
        'bad_pct' => $v['total'] > 0 ? round($v['bad']/$v['total']*100, 1) : null,
    ];
}
$by_date_out = [];
foreach ($by_date as $d => $v) {
    $by_date_out[$d] = [
        'total'   => $v['total'],
        'bad'     => $v['bad'],
        'bad_pct' => $v['total'] > 0 ? round($v['bad']/$v['total']*100, 1) : null,
    ];
}

echo json_encode([
    'period'          => '2026-07-29以降(3分割ジョブ適用後)',
    'bad_definition'  => 'null OR scheduled_time 180分超前に更新(午前スクレイプのみ)',
    'total_races'     => $total,
    'null_count'      => $null_count,
    'stale_count'     => $stale_count,
    'good_count'      => $good_count,
    'bad_total'       => $bad_total,
    'bad_rate_pct'    => $total > 0 ? round($bad_total/$total*100, 1) : null,
    'null_rate_pct'   => $total > 0 ? round($null_count/$total*100, 1) : null,
    'stale_rate_pct'  => $total > 0 ? round($stale_count/$total*100, 1) : null,
    'by_date'         => $by_date_out,
    'by_hour'         => $by_hour_out,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
