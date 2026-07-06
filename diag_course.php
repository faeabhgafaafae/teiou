<?php
$api_key = $_GET['api_key'] ?? '';
if ($api_key !== 'teio2025') { http_response_code(403); exit; }

$pdo = new PDO('mysql:host=mysql323.phy.lolipop.lan;dbname=LAA1670504-12;charset=utf8mb4',
               'LAA1670504', 'teiou', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// results テーブルの全エントリ数・コース変更エントリ数・対象レース数
$r1 = $pdo->query('SELECT COUNT(*) FROM results WHERE course IS NOT NULL')->fetchColumn();
$r2 = $pdo->query('SELECT COUNT(*) FROM results WHERE course IS NOT NULL AND lane != course')->fetchColumn();
$r3 = $pdo->query('SELECT COUNT(DISTINCT race_id) FROM results WHERE course IS NOT NULL')->fetchColumn();
$r4 = $pdo->query('SELECT COUNT(DISTINCT race_id) FROM results WHERE course IS NOT NULL AND lane != course')->fetchColumn();

// entries.start_timing が入っているレース（scrape_live.py スクレイプ済み）
$r5 = $pdo->query('SELECT COUNT(DISTINCT race_id) FROM entries WHERE start_timing IS NOT NULL')->fetchColumn();

// entries.exhibit_course が入っているレース（新実装以降）
$r6 = $pdo->query('SELECT COUNT(DISTINCT race_id) FROM entries WHERE exhibit_course IS NOT NULL')->fetchColumn();

echo json_encode([
    'results_rows_with_course'          => (int)$r1,
    'results_rows_course_changed'       => (int)$r2,
    'races_with_course_data'            => (int)$r3,
    'races_with_any_course_change'      => (int)$r4,
    'entries_races_with_start_timing'   => (int)$r5,
    'entries_races_with_exhibit_course' => (int)$r6,
], JSON_PRETTY_PRINT);
