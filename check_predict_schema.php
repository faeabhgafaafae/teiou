<?php
/**
 * 一時確認用API: predict.html刷新前のデータ有無調査
 * 確認が終わったら削除すること
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db();

function describe($pdo, $table) {
    try {
        $stmt = $pdo->query('DESCRIBE ' . $table);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

$out = [];
$out['entries'] = describe($pdo, 'entries');
$out['players'] = describe($pdo, 'players');
$out['races']   = describe($pdo, 'races');

// 気象データの実際の値サンプル(直近日)
$stmt = $pdo->query("SELECT date, venue, race_no, wind_speed, wind_dir, wave_height FROM races WHERE wind_speed IS NOT NULL ORDER BY date DESC LIMIT 5");
$out['weather_sample'] = $stmt->fetchAll();

// exhibit_time/start_timingの直近の充足率(サンプル)
$stmt2 = $pdo->query("
    SELECT r.date, COUNT(*) AS total,
        SUM(CASE WHEN e.exhibit_time IS NOT NULL THEN 1 ELSE 0 END) AS exhibit_notnull,
        SUM(CASE WHEN e.start_timing IS NOT NULL THEN 1 ELSE 0 END) AS st_notnull
    FROM entries e JOIN races r ON r.id = e.race_id
    GROUP BY r.date ORDER BY r.date DESC LIMIT 5
");
$out['entries_fill_sample'] = $stmt2->fetchAll();

json_response($out);
