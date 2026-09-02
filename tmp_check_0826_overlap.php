<?php
// admin_v2.phpのUNIONクエリで2026-08-26のv2レース数が異常(330 vs v1:164)な原因調査(調査後削除予定)
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$date = '2026-08-26';

// ソース1: predictions_v2
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT p2.race_id) AS races
    FROM predictions_v2 p2 JOIN races r ON r.id = p2.race_id
    WHERE p2.predicted_rank = 1 AND r.date = ?
");
$stmt->execute([$date]);
$fromV2Table = $stmt->fetch();

// ソース2: predictions model_version='v2_lr'
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT p.race_id) AS races
    FROM predictions p JOIN races r ON r.id = p.race_id
    WHERE p.predicted_rank = 1 AND p.model_version = 'v2_lr' AND r.date = ?
");
$stmt->execute([$date]);
$fromPredTable = $stmt->fetch();

// 重複race_idの確認
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT p2.race_id) AS overlap
    FROM predictions_v2 p2
    JOIN predictions p ON p.race_id = p2.race_id AND p.model_version = 'v2_lr'
    JOIN races r ON r.id = p2.race_id
    WHERE r.date = ?
");
$stmt->execute([$date]);
$overlap = $stmt->fetch();

// predictions テーブルの model_version 内訳(8/26)
$stmt = $pdo->prepare("
    SELECT p.model_version, COUNT(DISTINCT p.race_id) AS races
    FROM predictions p JOIN races r ON r.id = p.race_id
    WHERE r.date = ?
    GROUP BY p.model_version
");
$stmt->execute([$date]);
$versionBreakdown = $stmt->fetchAll();

// created_at の範囲(v2_lr行がいつ作られたか)
$stmt = $pdo->prepare("
    SELECT MIN(p.created_at) AS min_created, MAX(p.created_at) AS max_created, COUNT(*) AS row_count
    FROM predictions p JOIN races r ON r.id = p.race_id
    WHERE r.date = ? AND p.model_version = 'v2_lr'
");
$stmt->execute([$date]);
$createdRange = $stmt->fetch();

echo json_encode([
    'date' => $date,
    'from_predictions_v2_table' => $fromV2Table,
    'from_predictions_v2lr' => $fromPredTable,
    'overlap_race_id_count' => $overlap,
    'model_version_breakdown' => $versionBreakdown,
    'v2lr_created_at_range' => $createdRange,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
