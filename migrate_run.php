<?php
// TEMPORARY TEST FILE - DELETE AFTER USE
$api_key = $_GET['api_key'] ?? '';
if ($api_key !== 'teio2025') { http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit; }

header('Content-Type: application/json; charset=utf-8');

$conn = mysqli_connect('mysql323.phy.lolipop.lan', 'LAA1670504', 'teiou', 'LAA1670504-12');
if (!$conn) { http_response_code(500); echo json_encode(['error' => mysqli_connect_error()]); exit; }
mysqli_set_charset($conn, 'utf8mb4');

$results = [];

// --- EXPLAIN: 天候検索 (races only) ---
$r = mysqli_query($conn, "EXPLAIN SELECT r.date, r.venue, r.race_no, r.weather, r.wind_speed, r.wave_height, EXISTS(SELECT 1 FROM results res WHERE res.race_id = r.id) AS has_result FROM races r WHERE r.weather = '雨' ORDER BY r.date DESC LIMIT 200");
$results['explain_weather'] = [];
while ($row = mysqli_fetch_assoc($r)) $results['explain_weather'][] = $row;

// --- EXPLAIN: 選手名＋期間検索 (entries join) ---
$r2 = mysqli_query($conn, "EXPLAIN SELECT r.date, r.venue, r.race_no, r.weather, r.wind_speed, r.wave_height, pl.name AS player_name, pl.grade, e.lane, e.exhibit_course, res.actual_rank, EXISTS(SELECT 1 FROM results res2 WHERE res2.race_id = r.id) AS has_result FROM races r JOIN entries e ON e.race_id = r.id JOIN players pl ON pl.id = e.player_id LEFT JOIN results res ON res.race_id = r.id AND res.player_id = e.player_id WHERE r.date >= '2026-01-01' AND r.date <= '2026-12-31' AND pl.name LIKE '%中辻%' ORDER BY r.date DESC, r.venue, r.race_no, e.lane LIMIT 200");
$results['explain_player_date'] = [];
while ($row = mysqli_fetch_assoc($r2)) $results['explain_player_date'][] = $row;

// --- EXPLAIN: コース検索 ---
$r3 = mysqli_query($conn, "EXPLAIN SELECT r.date, r.venue, r.race_no, r.weather, r.wind_speed, r.wave_height, pl.name AS player_name, pl.grade, e.lane, e.exhibit_course, res.actual_rank, EXISTS(SELECT 1 FROM results res2 WHERE res2.race_id = r.id) AS has_result FROM races r JOIN entries e ON e.race_id = r.id JOIN players pl ON pl.id = e.player_id LEFT JOIN results res ON res.race_id = r.id AND res.player_id = e.player_id WHERE e.exhibit_course = 1 ORDER BY r.date DESC, r.venue, r.race_no, e.lane LIMIT 200");
$results['explain_course'] = [];
while ($row = mysqli_fetch_assoc($r3)) $results['explain_course'][] = $row;

// --- 実際の検索: 天候=雨 (最近30日) ---
$since = date('Y-m-d', strtotime('-30 days'));
$r4 = mysqli_query($conn, "SELECT r.date, r.venue, r.race_no, r.weather, r.wind_speed, r.wave_height, EXISTS(SELECT 1 FROM results res WHERE res.race_id = r.id) AS has_result FROM races r WHERE r.weather = '雨' AND r.date >= '" . mysqli_real_escape_string($conn, $since) . "' ORDER BY r.date DESC LIMIT 20");
$results['search_rain_recent'] = [];
while ($row = mysqli_fetch_assoc($r4)) $results['search_rain_recent'][] = $row;

// --- 実際の検索: 風速5m以上 ---
$r5 = mysqli_query($conn, "SELECT r.date, r.venue, r.race_no, r.weather, r.wind_speed, r.wave_height, EXISTS(SELECT 1 FROM results res WHERE res.race_id = r.id) AS has_result FROM races r WHERE r.wind_speed >= 5 ORDER BY r.date DESC LIMIT 10");
$results['search_wind5'] = [];
while ($row = mysqli_fetch_assoc($r5)) $results['search_wind5'][] = $row;

// --- 実際の検索: 期間指定（直近） ---
$r6 = mysqli_query($conn, "SELECT r.date, r.venue, r.race_no, r.weather, r.wind_speed, r.wave_height, EXISTS(SELECT 1 FROM results res WHERE res.race_id = r.id) AS has_result FROM races r WHERE r.date >= '" . mysqli_real_escape_string($conn, $since) . "' AND r.venue = '桐生' ORDER BY r.date DESC LIMIT 10");
$results['search_venue_date'] = [];
while ($row = mysqli_fetch_assoc($r6)) $results['search_venue_date'][] = $row;

// --- 選手名検索 (直近90日) ---
$since90 = date('Y-m-d', strtotime('-90 days'));
$r7 = mysqli_query($conn, "SELECT r.date, r.venue, r.race_no, pl.name AS player_name, e.lane, e.exhibit_course, res.actual_rank FROM races r JOIN entries e ON e.race_id = r.id JOIN players pl ON pl.id = e.player_id LEFT JOIN results res ON res.race_id = r.id AND res.player_id = e.player_id WHERE pl.name LIKE '%西山%' AND r.date >= '" . mysqli_real_escape_string($conn, $since90) . "' ORDER BY r.date DESC LIMIT 10");
$results['search_player'] = [];
while ($row = mysqli_fetch_assoc($r7)) $results['search_player'][] = $row;

mysqli_close($conn);
echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
