<?php
// 2026-09-01バックフィル進捗確認(読み取り専用・使用後削除予定)
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$date = '2026-09-01';

$stmt = $pdo->prepare("SELECT COUNT(*) AS races FROM races WHERE date = ?");
$stmt->execute([$date]);
$races = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS entry_rows, COUNT(DISTINCT e.race_id) AS distinct_races
    FROM entries e JOIN races r ON r.id = e.race_id
    WHERE r.date = ?
");
$stmt->execute([$date]);
$entries = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS odds_rows, COUNT(DISTINCT o.race_id) AS distinct_races
    FROM odds_3t o JOIN races r ON r.id = o.race_id
    WHERE r.date = ?
");
$stmt->execute([$date]);
$odds = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT p.model_version, COUNT(DISTINCT p.race_id) AS races
    FROM predictions p JOIN races r ON r.id = p.race_id
    WHERE r.date = ? GROUP BY p.model_version
");
$stmt->execute([$date]);
$predictions = $stmt->fetchAll();

echo json_encode(['races' => $races, 'entries' => $entries, 'odds_3t' => $odds, 'predictions' => $predictions], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
