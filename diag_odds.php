<?php
/**
 * temp: オッズ実データ確認用の診断エンドポイント（調査後に削除）
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
$yesterday = date('Y-m-d', strtotime('-1 day'));

// 0. 対象日のレース数
$out['target_dates'] = ['today' => $today, 'yesterday' => $yesterday];
$stmt = $pdo->prepare('SELECT date, COUNT(*) AS races FROM races WHERE date IN (?, ?) GROUP BY date');
$stmt->execute([$today, $yesterday]);
$out['races_today_yesterday'] = $stmt->fetchAll();

// 1. odds_3t: 本日・昨日のレースごとの件数分布（正常なら120件/レース）
$stmt = $pdo->prepare('
    SELECT r.date, r.venue, r.race_no, r.scheduled_time, r.before_updated_at,
           (SELECT COUNT(*) FROM odds_3t o WHERE o.race_id = r.id) AS odds3t_count
    FROM races r
    WHERE r.date IN (?, ?)
    ORDER BY r.date, r.venue, r.race_no
');
$stmt->execute([$today, $yesterday]);
$rows = $stmt->fetchAll();
$out['odds3t_count_per_race_sample'] = array_slice($rows, 0, 20);

$countBuckets = [];
foreach ($rows as $r) {
    $c = (int)$r['odds3t_count'];
    $b = $c === 120 ? '120(正常)' : ($c === 0 ? '0(未取得)' : $c . '(異常)');
    $countBuckets[$b] = ($countBuckets[$b] ?? 0) + 1;
}
$out['odds3t_count_distribution'] = $countBuckets;
$out['odds3t_total_races_checked'] = count($rows);

// 2. odds_multi: 券種別のカバレッジ（本日・昨日）
$stmt = $pdo->query("
    SELECT bet_type, COUNT(DISTINCT race_id) AS races_with_data, COUNT(*) AS total_rows
    FROM odds_multi om
    JOIN races r ON r.id = om.race_id
    WHERE r.date IN ('" . $today . "', '" . $yesterday . "')
    GROUP BY bet_type
");
$out['odds_multi_coverage'] = $stmt->fetchAll();

// 3. 異常値チェック: odds_3t
$out['odds3t_abnormal'] = $pdo->prepare('
    SELECT r.date, r.venue, r.race_no, o.combo, o.odds
    FROM odds_3t o
    JOIN races r ON r.id = o.race_id
    WHERE r.date IN (?, ?) AND (o.odds < 1.0 OR o.odds > 9999 OR o.odds IS NULL)
    LIMIT 30
');
$out['odds3t_abnormal']->execute([$today, $yesterday]);
$out['odds3t_abnormal'] = $out['odds3t_abnormal']->fetchAll();

$stmt = $pdo->prepare('
    SELECT MIN(o.odds) AS min_odds, MAX(o.odds) AS max_odds, AVG(o.odds) AS avg_odds, COUNT(*) AS n
    FROM odds_3t o
    JOIN races r ON r.id = o.race_id
    WHERE r.date IN (?, ?)
');
$stmt->execute([$today, $yesterday]);
$out['odds3t_stats'] = $stmt->fetch();

// 4. odds_multi 異常値チェック（テキスト混在のfukusho/kakurenkuも考慮し数値抽出できるもののみ）
$stmt = $pdo->prepare("
    SELECT r.date, r.venue, r.race_no, om.bet_type, om.combo, om.odds
    FROM odds_multi om
    JOIN races r ON r.id = om.race_id
    WHERE r.date IN (?, ?)
      AND om.bet_type IN ('tansho','rentan2','renfuku2','sanrenfuku')
      AND (CAST(om.odds AS DECIMAL(10,2)) < 1.0 OR CAST(om.odds AS DECIMAL(10,2)) > 9999)
    LIMIT 30
");
$stmt->execute([$today, $yesterday]);
$out['odds_multi_abnormal'] = $stmt->fetchAll();

// 5. NULL件数（欠損の程度）：races全体に対してodds_3tが1件も無いレースの割合
$stmt = $pdo->prepare('
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM odds_3t o WHERE o.race_id = r.id) THEN 1 ELSE 0 END) AS no_odds3t,
           SUM(CASE WHEN r.before_updated_at IS NULL THEN 1 ELSE 0 END) AS never_scraped
    FROM races r
    WHERE r.date IN (?, ?)
');
$stmt->execute([$today, $yesterday]);
$out['missing_summary'] = $stmt->fetch();

// 6. 取得タイミング: scheduled_time と before_updated_at の差(分) の分布
$stmt = $pdo->prepare("
    SELECT r.date, r.venue, r.race_no, r.scheduled_time, r.before_updated_at,
           TIMESTAMPDIFF(MINUTE, r.before_updated_at, CONCAT(r.date, ' ', r.scheduled_time)) AS minutes_before_deadline
    FROM races r
    WHERE r.date IN (?, ?) AND r.before_updated_at IS NOT NULL
    ORDER BY r.date, r.venue, r.race_no
");
$stmt->execute([$today, $yesterday]);
$timingRows = $stmt->fetchAll();
$out['timing_sample'] = array_slice($timingRows, 0, 15);

$diffs = array_map(function($r) { return (int)$r['minutes_before_deadline']; }, $timingRows);
sort($diffs);
$n = count($diffs);
$out['timing_stats'] = [
    'n' => $n,
    'min' => $n ? $diffs[0] : null,
    'max' => $n ? $diffs[$n - 1] : null,
    'median' => $n ? $diffs[intdiv($n, 2)] : null,
    'avg' => $n ? round(array_sum($diffs) / $n, 1) : null,
];

// 7. 具体例: 直近で完了しているレースを1つピックアップ（ボートレース公式ページとの目視突合用）
$stmt = $pdo->prepare("
    SELECT r.id, r.date, r.venue, r.race_no, r.scheduled_time, r.before_updated_at
    FROM races r
    WHERE r.date IN (?, ?)
      AND EXISTS (SELECT 1 FROM odds_3t o WHERE o.race_id = r.id)
    ORDER BY r.before_updated_at DESC
    LIMIT 5
");
$stmt->execute([$today, $yesterday]);
$out['recent_scraped_races'] = $stmt->fetchAll();

if (!empty($out['recent_scraped_races'])) {
    $sample_race_id = $out['recent_scraped_races'][0]['id'];
    $stmt = $pdo->prepare('SELECT combo, odds FROM odds_3t WHERE race_id = ? ORDER BY odds ASC LIMIT 10');
    $stmt->execute([$sample_race_id]);
    $out['sample_race_odds3t_top10'] = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT bet_type, combo, odds FROM odds_multi WHERE race_id = ? ORDER BY bet_type, (odds+0) ASC');
    $stmt->execute([$sample_race_id]);
    $out['sample_race_odds_multi'] = $stmt->fetchAll();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
