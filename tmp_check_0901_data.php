<?php
// 2026-09-01のデータ取得状況調査(読み取り専用・調査後削除予定)
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$date = $_GET['date'] ?? '2026-09-01';

$out = ['date' => $date];

// races
$stmt = $pdo->prepare("SELECT COUNT(*) AS races, GROUP_CONCAT(DISTINCT venue ORDER BY venue) AS venues FROM races WHERE date = ?");
$stmt->execute([$date]);
$out['races'] = $stmt->fetch();

// entries (紐づくraceの数、exhibit_time等の充足率)
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS entry_rows,
           SUM(CASE WHEN e.exhibit_time IS NOT NULL THEN 1 ELSE 0 END) AS has_exhibit_time,
           SUM(CASE WHEN e.start_timing IS NOT NULL THEN 1 ELSE 0 END) AS has_start_timing,
           SUM(CASE WHEN e.motor_2rate IS NOT NULL THEN 1 ELSE 0 END) AS has_motor_2rate,
           COUNT(DISTINCT e.race_id) AS distinct_races
    FROM entries e
    JOIN races r ON r.id = e.race_id
    WHERE r.date = ?
");
$stmt->execute([$date]);
$out['entries'] = $stmt->fetch();

// venue別の直前情報充足率(会場ごとにどこまで取れているか把握するため)
$stmt = $pdo->prepare("
    SELECT r.venue,
           COUNT(DISTINCT e.race_id) AS races,
           SUM(CASE WHEN e.exhibit_time IS NOT NULL THEN 1 ELSE 0 END) AS has_exhibit_time,
           COUNT(*) AS entry_rows
    FROM entries e
    JOIN races r ON r.id = e.race_id
    WHERE r.date = ?
    GROUP BY r.venue
    ORDER BY r.venue
");
$stmt->execute([$date]);
$out['entries_by_venue'] = $stmt->fetchAll();

// odds_3t
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS odds_rows, COUNT(DISTINCT o.race_id) AS distinct_races,
           MIN(o.updated_at) AS min_updated, MAX(o.updated_at) AS max_updated
    FROM odds_3t o
    JOIN races r ON r.id = o.race_id
    WHERE r.date = ?
");
try {
    $stmt->execute([$date]);
    $out['odds_3t'] = $stmt->fetch();
} catch (PDOException $e) {
    $out['odds_3t_error'] = $e->getMessage();
}

// odds_3t venue別
$stmt = $pdo->prepare("
    SELECT r.venue, COUNT(DISTINCT o.race_id) AS races_with_odds, COUNT(*) AS odds_rows
    FROM odds_3t o
    JOIN races r ON r.id = o.race_id
    WHERE r.date = ?
    GROUP BY r.venue
    ORDER BY r.venue
");
try {
    $stmt->execute([$date]);
    $out['odds_3t_by_venue'] = $stmt->fetchAll();
} catch (PDOException $e) {
    $out['odds_3t_by_venue_error'] = $e->getMessage();
}

// results
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS result_rows, COUNT(DISTINCT res.race_id) AS distinct_races,
           SUM(CASE WHEN res.actual_rank IS NOT NULL THEN 1 ELSE 0 END) AS has_actual_rank
    FROM results res
    JOIN races r ON r.id = res.race_id
    WHERE r.date = ?
");
$stmt->execute([$date]);
$out['results'] = $stmt->fetch();

// predictions (v2含む)
$stmt = $pdo->prepare("
    SELECT p.model_version, COUNT(DISTINCT p.race_id) AS races
    FROM predictions p JOIN races r ON r.id = p.race_id
    WHERE r.date = ?
    GROUP BY p.model_version
");
$stmt->execute([$date]);
$out['predictions_by_version'] = $stmt->fetchAll();

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
