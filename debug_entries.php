<?php
$dsn = 'mysql:host=mysql323.phy.lolipop.lan;dbname=LAA1670504-12;charset=utf8mb4';
try {
    $pdo = new PDO($dsn, 'LAA1670504', 'teiou', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // exhibit_timeが入っているものを優先して確認
    $stmt = $pdo->query('
        SELECT e.race_id, e.lane, e.exhibit_time, e.start_timing, e.updated_at,
               r.date, r.venue, r.race_no
        FROM entries e
        JOIN races r ON e.race_id = r.id
        ORDER BY e.updated_at DESC
        LIMIT 30
    ');
    $rows = $stmt->fetchAll();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $e->getMessage()]);
}
