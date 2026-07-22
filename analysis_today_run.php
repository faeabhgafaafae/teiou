<?php
/**
 * tmp: score_today要素分解検証用一時エンドポイント(使用後にneutralize予定)
 * 展示タイム/ST/モーター2連率の生データと実着順を行単位で返す。
 * 展示タイムのスコア化は同一レース内の6艇の最小・最大値に依存するため、
 * レース単位でグルーピングできるようrace_idを含めて返す。
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗']);
    exit;
}

try {
    $stmt = $pdo->query('
        SELECT
            r.id AS race_id, r.date,
            res.lane, res.actual_rank,
            e.exhibit_time, e.start_timing, e.motor_2rate
        FROM races r
        JOIN results res ON res.race_id = r.id
        JOIN entries e ON e.race_id = r.id AND e.lane = res.lane
        WHERE res.actual_rank IS NOT NULL
        ORDER BY r.id, res.lane
    ');
    echo json_encode(['rows' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
