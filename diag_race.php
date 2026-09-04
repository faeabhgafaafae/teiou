<?php
// 一時診断用。確認後削除すること
require_once __DIR__ . '/config.php';
if (($_GET['api_key'] ?? '') !== API_KEY) { http_response_code(403); exit; }
$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS);
$date = $_GET['date'] ?? '2026-09-04';
$venue = $_GET['venue'] ?? '戸田';
$race_no = (int)($_GET['race_no'] ?? 12);
// レース基本情報
$r = $pdo->prepare('SELECT id, scheduled_time, before_updated_at FROM races WHERE date=? AND venue=? AND race_no=?');
$r->execute([$date, $venue, $race_no]);
$race = $r->fetch(PDO::FETCH_ASSOC);
if (!$race) { echo json_encode(['error' => 'race not found']); exit; }
// entries(展示情報)
$e = $pdo->prepare('SELECT lane, exhibit_time, start_timing, exhibit_course FROM entries WHERE race_id=? ORDER BY lane');
$e->execute([$race['id']]);
$entries = $e->fetchAll(PDO::FETCH_ASSOC);
// results(実績情報)
$res = $pdo->prepare('SELECT lane, course, start_timing, actual_rank FROM results WHERE race_id=? ORDER BY lane');
$res->execute([$race['id']]);
$results = $res->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['race' => $race, 'entries' => $entries, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
