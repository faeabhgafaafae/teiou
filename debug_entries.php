<?php
$dsn = 'mysql:host=mysql323.phy.lolipop.lan;dbname=LAA1670504-12;charset=utf8mb4';
try {
    $pdo = new PDO($dsn, 'LAA1670504', 'teiou', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $stmt = $pdo->query('SELECT race_id, lane, exhibit_time, start_timing FROM entries ORDER BY id DESC LIMIT 30');
    $rows = $stmt->fetchAll();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $e->getMessage()]);
}
