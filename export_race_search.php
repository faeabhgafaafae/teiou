<?php
/**
 * データ分析: 過去レース検索(高度検索)結果CSVエクスポートAPI(Premium限定)
 * GET /export_race_search.php
 * search_races_advanced.php と同じ検索条件・同じクエリロジックを用い、
 * 結果をCSV形式でダウンロードさせる。DB書き込みは発生しない。
 */
require_once __DIR__ . '/auth.php';

$user = current_user();
$plan = $user['plan'] ?? 'free';
if (!$user || $plan !== 'premium') {
    json_response(['error' => 'premium_required', 'message' => 'CSVエクスポートはPremiumプラン限定です'], 403);
}

$playerName = isset($_GET['player_name']) ? trim($_GET['player_name']) : '';
$venues     = isset($_GET['venues'])      ? (array)$_GET['venues']      : [];
$weather    = isset($_GET['weather'])     ? trim($_GET['weather'])       : '';
$windMin    = (isset($_GET['wind_min'])  && $_GET['wind_min']  !== '') ? (float)$_GET['wind_min']  : null;
$waveMin    = (isset($_GET['wave_min'])  && $_GET['wave_min']  !== '') ? (float)$_GET['wave_min']  : null;
$course     = (isset($_GET['course'])    && $_GET['course']    !== '') ? (int)$_GET['course']      : null;
$dateFrom   = isset($_GET['date_from'])  ? trim($_GET['date_from'])     : '';
$dateTo     = isset($_GET['date_to'])    ? trim($_GET['date_to'])       : '';

$validDateRe = '/^\d{4}-\d{2}-\d{2}$/';
if ($dateFrom && !preg_match($validDateRe, $dateFrom)) $dateFrom = '';
if ($dateTo   && !preg_match($validDateRe, $dateTo))   $dateTo   = '';

$allVenues = ['桐生','戸田','江戸川','平和島','多摩川','浜名湖','蒲郡','常滑','津','三国','琵琶湖','住之江','尼崎','鳴門','高松','丸亀','児島','宮島','徳山','下関','若松','芦屋','福岡','唐津','大村'];
$venues = array_values(array_intersect($venues, $allVenues));

if (!$playerName && empty($venues) && !$weather && $windMin === null && $waveMin === null && $course === null && !$dateFrom && !$dateTo) {
    json_response(['error' => '検索条件を1つ以上指定してください'], 400);
}

$pdo = get_db();

$params = [];
$where  = ['1=1'];

if (!empty($venues)) {
    $placeholders = implode(',', array_fill(0, count($venues), '?'));
    $where[] = 'r.venue IN (' . $placeholders . ')';
    foreach ($venues as $v) $params[] = $v;
}
if ($weather !== '') {
    $where[] = 'r.weather = ?';
    $params[] = $weather;
}
if ($windMin !== null) {
    $where[] = 'r.wind_speed >= ?';
    $params[] = $windMin;
}
if ($waveMin !== null) {
    $where[] = 'r.wave_height >= ?';
    $params[] = $waveMin;
}
if ($dateFrom !== '') {
    $where[] = 'r.date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'r.date <= ?';
    $params[] = $dateTo;
}
$whereStr = implode(' AND ', $where);

$useEntries = ($playerName !== '' || $course !== null);

if ($useEntries) {
    $entryWhere  = [];
    $entryParams = [];
    if ($playerName !== '') {
        $entryWhere[]  = 'pl.name LIKE ?';
        $entryParams[] = '%' . $playerName . '%';
    }
    if ($course !== null) {
        $entryWhere[]  = 'e.exhibit_course = ?';
        $entryParams[] = $course;
    }
    $entryWhereStr = $entryWhere ? (' AND ' . implode(' AND ', $entryWhere)) : '';

    $sql = '
        SELECT r.date, r.venue, r.race_no, r.weather, r.wind_speed, r.wave_height,
               pl.name AS player_name, pl.grade, e.lane, e.exhibit_course,
               res.actual_rank
        FROM races r
        JOIN entries e  ON e.race_id  = r.id
        JOIN players pl ON pl.id      = e.player_id
        LEFT JOIN results res ON res.race_id = r.id AND res.player_id = e.player_id
        WHERE ' . $whereStr . $entryWhereStr . '
        ORDER BY r.date DESC, r.venue, r.race_no, e.lane
        LIMIT 2000
    ';
    $allParams = array_merge($params, $entryParams);
} else {
    $sql = '
        SELECT r.date, r.venue, r.race_no, r.weather, r.wind_speed, r.wave_height
        FROM races r
        WHERE ' . $whereStr . '
        ORDER BY r.date DESC, r.venue, r.race_no
        LIMIT 2000
    ';
    $allParams = $params;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($allParams);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="teiou_race_search_' . date('Ymd') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

if ($useEntries) {
    fputcsv($out, ['日付', '会場', 'レース', '天候', '風速(m/s)', '波高(cm)', '選手名', '級別', '枠番', '進入コース', '着順']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['date'],
            $row['venue'],
            $row['race_no'] . 'R',
            $row['weather'],
            $row['wind_speed'],
            $row['wave_height'],
            $row['player_name'],
            $row['grade'],
            $row['lane'],
            $row['exhibit_course'],
            $row['actual_rank'] !== null ? $row['actual_rank'] . '着' : '',
        ]);
    }
} else {
    fputcsv($out, ['日付', '会場', 'レース', '天候', '風速(m/s)', '波高(cm)']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['date'],
            $row['venue'],
            $row['race_no'] . 'R',
            $row['weather'],
            $row['wind_speed'],
            $row['wave_height'],
        ]);
    }
}

fclose($out);
