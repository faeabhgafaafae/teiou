<?php
require_once __DIR__ . '/config.php';

/**
 * 的中速報API
 * strategy_results の is_hit=1 を返す
 *
 * GET パラメータ:
 *   date   : 対象日 (YYYY-MM-DD, 省略時は全期間)
 *   limit  : 取得件数 (デフォルト=20, 最大=500)
 *   offset : 取得開始位置 (デフォルト=0)
 *
 * レスポンス: { total: N, hits: [...] }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$date   = isset($_GET['date'])   ? trim($_GET['date'])         : null;
$limit  = isset($_GET['limit'])  ? (int)$_GET['limit']         : 20;
$offset = isset($_GET['offset']) ? (int)$_GET['offset']        : 0;

if ($limit < 1 || $limit > 500) $limit = 20;
if ($offset < 0) $offset = 0;

$where  = 'sr.is_hit = 1 AND sr.payout > 0';
$params = [];

if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $where   .= ' AND r.date = ?';
    $params[] = $date;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    echo json_encode(['total' => 0, 'hits' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmtCount = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM strategy_results sr
        JOIN strategies s ON s.id = sr.strategy_id
        JOIN races r      ON r.id = sr.race_id
        WHERE $where
    ");
    $stmtCount->execute($params);
    $total = (int)($stmtCount->fetch()['cnt'] ?? 0);

    $stmtHits = $pdo->prepare("
        SELECT
            r.venue,
            r.date,
            r.race_no,
            s.strategy_type,
            (
                SELECT GROUP_CONCAT(res2.lane ORDER BY res2.actual_rank SEPARATOR '-')
                FROM results res2
                WHERE res2.race_id = sr.race_id
                  AND res2.actual_rank IN (1, 2, 3)
            ) AS combination,
            sr.payout
        FROM strategy_results sr
        JOIN strategies s ON s.id = sr.strategy_id
        JOIN races r      ON r.id = sr.race_id
        WHERE $where
        ORDER BY r.date DESC, r.race_no DESC, sr.id DESC
        LIMIT ? OFFSET ?
    ");
    $stmtHits->execute(array_merge($params, [$limit, $offset]));
    $hits = $stmtHits->fetchAll();
} catch (PDOException $e) {
    $total = 0;
    $hits  = [];
}

echo json_encode(['total' => $total, 'hits' => $hits], JSON_UNESCAPED_UNICODE);
