<?php
// ロジスティック回帰用データエクスポート(一時スクリプト、実行後削除)
if (($_GET['k'] ?? '') !== 'teiou2026') { http_response_code(403); exit; }
require_once __DIR__ . '/config.php';

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="lr_data.csv"');

$entries_all = $pdo->query("
    SELECT
        e.race_id,
        r.date,
        r.venue,
        e.lane,
        e.player_id,
        e.exhibit_time,
        e.start_timing,
        e.motor_2rate,
        r.wind_speed,
        r.wave_height,
        r.temperature,
        CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END as is_winner,
        COALESCE(pp.win_rate, 0)     as global_win_rate,
        COALESCE(pp.fukusho_rate, 0) as global_2rate,
        COALESCE(pr.score_ability, 0)   as score_ability,
        COALESCE(pr.score_course, 0)    as score_course,
        pr.score_total                  as score_total_current
    FROM entries e
    JOIN races r ON e.race_id = r.id
    JOIN results res
        ON res.race_id = e.race_id AND res.player_id = e.player_id
    LEFT JOIN player_periods pp
        ON pp.player_id = e.player_id
        AND (pp.year * 10 + pp.period) = (
            SELECT MAX(pp2.year * 10 + pp2.period)
            FROM player_periods pp2
            WHERE pp2.player_id = e.player_id
        )
    LEFT JOIN predictions pr
        ON pr.race_id = e.race_id AND pr.player_id = e.player_id
    WHERE r.date BETWEEN '2026-06-01' AND CURDATE()
      AND EXISTS (
          SELECT 1 FROM results res2
          WHERE res2.race_id = r.id AND res2.actual_rank = 1
      )
    ORDER BY r.date, e.race_id, e.lane
")->fetchAll();

$race_exhibit = [];
$race_motor   = [];
foreach ($entries_all as $e) {
    $rid = $e['race_id'];
    if ($e['exhibit_time'] !== null) {
        if (!isset($race_exhibit[$rid])) $race_exhibit[$rid] = ['min' => 9999, 'max' => -9999];
        $race_exhibit[$rid]['min'] = min($race_exhibit[$rid]['min'], (float)$e['exhibit_time']);
        $race_exhibit[$rid]['max'] = max($race_exhibit[$rid]['max'], (float)$e['exhibit_time']);
    }
    if ($e['motor_2rate'] !== null) {
        if (!isset($race_motor[$rid])) $race_motor[$rid] = ['min' => 9999, 'max' => -9999];
        $race_motor[$rid]['min'] = min($race_motor[$rid]['min'], (float)$e['motor_2rate']);
        $race_motor[$rid]['max'] = max($race_motor[$rid]['max'], (float)$e['motor_2rate']);
    }
}

$player_ids = array_unique(array_column($entries_all, 'player_id'));
$local_rates = [];
if (!empty($player_ids)) {
    $ph      = implode(',', array_fill(0, count($player_ids), '?'));
    $cutoff  = date('Y-m-d', strtotime('-2 years'));
    $local_s = $pdo->prepare("
        SELECT res.player_id, rc.venue,
               COUNT(*)                                                as total,
               SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END)   as rank1,
               SUM(CASE WHEN res.actual_rank <= 2 THEN 1 ELSE 0 END)  as rank2
        FROM results res
        JOIN races rc ON res.race_id = rc.id
        WHERE res.player_id IN ($ph)
          AND rc.date >= ?
        GROUP BY res.player_id, rc.venue
    ");
    $local_s->execute(array_merge($player_ids, [$cutoff]));
    foreach ($local_s->fetchAll() as $row) {
        $local_rates[$row['player_id']][$row['venue']] = [
            'win' => $row['total'] > 0 ? $row['rank1'] / $row['total'] : null,
            'ni'  => $row['total'] > 0 ? $row['rank2'] / $row['total'] : null,
        ];
    }
}

echo "race_id,date,venue,lane," .
     "global_win_rate,global_2rate,local_win_rate,local_2rate," .
     "exhibit_time_raw,exhibit_time_rel," .
     "start_timing,motor_2rate,motor_2rate_rel," .
     "wind_speed,wave_height,temperature," .
     "score_ability,score_course,score_total_current," .
     "is_winner\n";

foreach ($entries_all as $e) {
    $rid   = $e['race_id'];
    $venue = $e['venue'];
    $pid   = $e['player_id'];

    $ex_raw = $e['exhibit_time'];
    $ex_rel = '';
    if ($ex_raw !== null && isset($race_exhibit[$rid])) {
        $range  = $race_exhibit[$rid]['max'] - $race_exhibit[$rid]['min'];
        $ex_rel = $range > 0 ? round(($race_exhibit[$rid]['max'] - (float)$ex_raw) / $range, 4) : 0.5;
    }

    $mo_raw = $e['motor_2rate'];
    $mo_rel = '';
    if ($mo_raw !== null && isset($race_motor[$rid])) {
        $range_m = $race_motor[$rid]['max'] - $race_motor[$rid]['min'];
        $mo_rel  = $range_m > 0 ? round(((float)$mo_raw - $race_motor[$rid]['min']) / $range_m, 4) : 0.5;
    }

    $lc       = $local_rates[$pid][$venue] ?? null;
    $local_wr = $lc ? round($lc['win'], 4) : '';
    $local_2r = $lc ? round($lc['ni'],  4) : '';

    $row = [
        $rid, $e['date'], $venue, $e['lane'],
        $e['global_win_rate'], $e['global_2rate'],
        $local_wr, $local_2r,
        $ex_raw !== null ? $ex_raw : '', $ex_rel,
        $e['start_timing'] !== null ? $e['start_timing'] : '',
        $mo_raw !== null ? $mo_raw : '', $mo_rel,
        $e['wind_speed']  !== null ? $e['wind_speed']  : '',
        $e['wave_height'] !== null ? $e['wave_height'] : '',
        $e['temperature'] !== null ? $e['temperature'] : '',
        $e['score_ability'], $e['score_course'],
        $e['score_total_current'] !== null ? $e['score_total_current'] : '',
        $e['is_winner'],
    ];
    echo implode(',', $row) . "\n";
}
