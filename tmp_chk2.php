<?php
// 清算検証スクリプト2 (使用後削除)
ini_set('display_errors', 1);
$key = $_GET['key'] ?? '';
if ($key !== 'teio2025') { http_response_code(403); echo 'forbidden'; exit; }
header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/config.php';
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $date = $_GET['date'] ?? '2026-09-09';
    $out  = ['date' => $date];

    // 1. strategy_results件数
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total, SUM(sr.is_hit) AS hits
        FROM strategy_results sr
        JOIN strategies s ON sr.strategy_id = s.id
        JOIN races r ON s.race_id = r.id
        WHERE r.date = ?
    ");
    $stmt->execute([$date]);
    $out['totals'] = $stmt->fetch();

    // 2. strategy_type × stake_scheme 別コスト
    $stmt = $pdo->prepare("
        SELECT s.strategy_type, s.stake_scheme,
               COUNT(*) AS cnt, SUM(sr.is_hit) AS hits,
               AVG(sr.cost) AS avg_cost, MIN(sr.cost) AS min_cost, MAX(sr.cost) AS max_cost
        FROM strategy_results sr
        JOIN strategies s ON sr.strategy_id = s.id
        JOIN races r ON s.race_id = r.id
        WHERE r.date = ?
        GROUP BY s.strategy_type, s.stake_scheme
        ORDER BY s.strategy_type, s.stake_scheme
    ");
    $stmt->execute([$date]);
    $out['by_type_scheme'] = $stmt->fetchAll();

    // 3. 的中詳細(全件)
    $stmt = $pdo->prepare("
        SELECT r.venue, r.race_no, s.strategy_type,
               s.stake_scheme, sr.cost, sr.payout, s.stakes
        FROM strategy_results sr
        JOIN strategies s ON sr.strategy_id = s.id
        JOIN races r ON s.race_id = r.id
        WHERE r.date = ? AND sr.is_hit = 1
        ORDER BY r.venue, r.race_no, s.strategy_type
    ");
    $stmt->execute([$date]);
    $out['hits'] = $stmt->fetchAll();

    // 4. 桐生・大村12Rのstrategy_results詳細
    $stmt = $pdo->prepare("
        SELECT r.venue, r.race_no, s.strategy_type,
               s.stake_scheme, sr.cost, sr.payout, sr.is_hit, s.stakes
        FROM strategy_results sr
        JOIN strategies s ON sr.strategy_id = s.id
        JOIN races r ON s.race_id = r.id
        WHERE r.date = ? AND r.race_no = 12 AND r.venue IN ('桐生','大村')
        ORDER BY r.venue, s.strategy_type
    ");
    $stmt->execute([$date]);
    $out['r12_detail'] = $stmt->fetchAll();

    // 5. stakes=NULLレース(flat)のコスト確認サンプル
    $stmt = $pdo->prepare("
        SELECT r.venue, r.race_no, s.strategy_type,
               s.stake_scheme, sr.cost, sr.payout, sr.is_hit
        FROM strategy_results sr
        JOIN strategies s ON sr.strategy_id = s.id
        JOIN races r ON s.race_id = r.id
        WHERE r.date = ? AND (s.stake_scheme IS NULL OR s.stake_scheme = 'flat')
        ORDER BY r.race_no, s.strategy_type LIMIT 16
    ");
    $stmt->execute([$date]);
    $out['flat_sample'] = $stmt->fetchAll();

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    echo json_encode(['error'=>$e->getMessage(),'line'=>$e->getLine()], JSON_UNESCAPED_UNICODE);
}
