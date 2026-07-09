<?php
/**
 * temp: 2フェーズ制導入後のオッズ取得タイミング再計測用エンドポイント（調査後に削除）
 * 前回調査(before_updated_atを代理指標に使用)との比較のため、
 * 新設のodds_updated_atを使って同種の集計を行う。
 */
define('DB_HOST', 'mysql323.phy.lolipop.lan');
define('DB_NAME', 'LAA1670504-12');
define('DB_USER', 'LAA1670504');
define('DB_PASS', 'teiou');

header('Content-Type: application/json; charset=utf-8');

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$out = [];
$today = date('Y-m-d');

// 1. odds_updated_atがそもそも記録されているレース数(scrape_live.py実行の効果があったか)
$stmt = $pdo->prepare('
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN odds_updated_at IS NOT NULL THEN 1 ELSE 0 END) AS has_odds_updated_at
    FROM races
    WHERE date = ?
');
$stmt->execute([$today]);
$out['odds_updated_at_coverage_today'] = $stmt->fetch();

// 2. odds_updated_at基準での取得タイミング分布(本日、締切が既に過ぎたレースのみ=確定した乖離)
$stmt = $pdo->prepare("
    SELECT r.id, r.venue, r.race_no, r.scheduled_time, r.odds_updated_at,
           TIMESTAMPDIFF(MINUTE, r.odds_updated_at, CONCAT(r.date, ' ', r.scheduled_time)) AS diff_min
    FROM races r
    WHERE r.date = ? AND r.odds_updated_at IS NOT NULL
      AND CONCAT(r.date, ' ', r.scheduled_time) <= NOW()
");
$stmt->execute([$today]);
$rows = $stmt->fetchAll();
$diffs = array_map(function($r) { return (int)$r['diff_min']; }, $rows);
sort($diffs);
$n = count($diffs);
$out['today_closed_races_odds_timing'] = [
    'n' => $n,
    'min' => $n ? $diffs[0] : null,
    'max' => $n ? $diffs[$n - 1] : null,
    'median' => $n ? $diffs[intdiv($n, 2)] : null,
    'avg' => $n ? round(array_sum($diffs) / $n, 1) : null,
];

$buckets = ['fresh(-10~30分)' => 0, 'やや古い(30~60分)' => 0, '古い(60~180分)' => 0, '非常に古い(180分超)' => 0, '締切後(-10分より前)' => 0];
foreach ($diffs as $d) {
    if ($d >= -10 && $d <= 30) $buckets['fresh(-10~30分)']++;
    elseif ($d > 30 && $d <= 60) $buckets['やや古い(30~60分)']++;
    elseif ($d > 60 && $d <= 180) $buckets['古い(60~180分)']++;
    elseif ($d > 180) $buckets['非常に古い(180分超)']++;
    else $buckets['締切後(-10分より前)']++;
}
$out['today_closed_races_timing_bucket'] = $buckets;
$out['today_closed_races_sample'] = array_slice($rows, 0, 15);

// 3. odds=0異常値(直近測定と同じ定義)
$stmt = $pdo->prepare('
    SELECT COUNT(*) AS total, SUM(CASE WHEN o.odds = 0 THEN 1 ELSE 0 END) AS zero_count
    FROM odds_3t o
    JOIN races r ON r.id = o.race_id
    WHERE r.date = ?
');
$stmt->execute([$today]);
$out['odds3t_zero_today'] = $stmt->fetch();

// 4. まだ全くodds_updated_atが入っていないレース(本日、締切済み)=完全な取りこぼし
$stmt = $pdo->prepare("
    SELECT r.venue, r.race_no, r.scheduled_time
    FROM races r
    WHERE r.date = ? AND r.odds_updated_at IS NULL
      AND CONCAT(r.date, ' ', r.scheduled_time) <= NOW()
    ORDER BY r.scheduled_time
");
$stmt->execute([$today]);
$out['closed_races_never_got_odds'] = $stmt->fetchAll();

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
