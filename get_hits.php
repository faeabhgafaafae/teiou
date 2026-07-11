<?php
require_once __DIR__ . '/config.php';

/**
 * 的中速報API
 * strategy_results の is_hit=1 を直近10件返す
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    echo json_encode(['hits' => []]);
    exit;
}

try {
    $stmt = $pdo->query('
        SELECT
            r.venue,
            r.date,
            r.race_no,
            s.strategy_type,
            (
                SELECT GROUP_CONCAT(res2.lane ORDER BY res2.actual_rank SEPARATOR \'-\')
                FROM results res2
                WHERE res2.race_id = sr.race_id
                  AND res2.actual_rank IN (1, 2, 3)
            ) AS combination,
            sr.payout
        FROM strategy_results sr
        JOIN strategies s ON s.id = sr.strategy_id
        JOIN races r      ON r.id = sr.race_id
        WHERE sr.is_hit = 1
          AND sr.payout > 0
        ORDER BY r.date DESC, r.race_no DESC, sr.id DESC
        LIMIT 10
    ');
    $hits = $stmt->fetchAll();
} catch (PDOException $e) {
    $hits = [];
}

echo json_encode(['hits' => $hits], JSON_UNESCAPED_UNICODE);
