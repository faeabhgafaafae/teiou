<?php
// 清算検証スクリプト (使用後削除)
// エラー表示を強制ON
ini_set('display_errors', 1);
error_reporting(E_ALL);

$key = $_GET['key'] ?? '';
if ($key !== 'teio2025') { http_response_code(403); echo 'forbidden'; exit; }

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/config.php';
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $date = $_GET['date'] ?? '2026-09-09';
    $out = ['ok' => true, 'date' => $date];

    // カラム存在確認
    $out['cols']['strategies_stakes']              = count($pdo->query("SHOW COLUMNS FROM strategies LIKE 'stakes'")->fetchAll());
    $out['cols']['strategies_stake_scheme']        = count($pdo->query("SHOW COLUMNS FROM strategies LIKE 'stake_scheme'")->fetchAll());
    $out['cols']['strategy_results_stake_scheme']  = count($pdo->query("SHOW COLUMNS FROM strategy_results LIKE 'stake_scheme'")->fetchAll());
    $out['cols']['strategy_results_cost']          = count($pdo->query("SHOW COLUMNS FROM strategy_results LIKE 'cost'")->fetchAll());

    // 全体集計
    $stmt = $pdo->prepare("
        SELECT sr.stake_scheme,
               COUNT(*) AS rows,
               SUM(sr.is_hit) AS hits,
               SUM(sr.cost) AS cost_sum,
               MIN(sr.cost) AS cost_min,
               MAX(sr.cost) AS cost_max
        FROM strategy_results sr
        JOIN strategies s ON sr.strategy_id = s.id
        JOIN races r ON s.race_id = r.id
        WHERE r.date = ?
        GROUP BY sr.stake_scheme
    ");
    $stmt->execute([$date]);
    $out['summary'] = $stmt->fetchAll();

    // strategy_type×scheme別コスト
    $stmt = $pdo->prepare("
        SELECT s.strategy_type, sr.stake_scheme,
               COUNT(*) AS cnt, AVG(sr.cost) AS avg_cost,
               MIN(sr.cost) AS min_cost, MAX(sr.cost) AS max_cost
        FROM strategy_results sr
        JOIN strategies s ON sr.strategy_id = s.id
        JOIN races r ON s.race_id = r.id
        WHERE r.date = ?
        GROUP BY s.strategy_type, sr.stake_scheme
        ORDER BY s.strategy_type
    ");
    $stmt->execute([$date]);
    $out['by_type'] = $stmt->fetchAll();

    // 的中詳細
    $stmt = $pdo->prepare("
        SELECT r.venue, r.race_no, s.strategy_type,
               sr.stake_scheme, sr.cost, sr.payout, s.stakes
        FROM strategy_results sr
        JOIN strategies s ON sr.strategy_id = s.id
        JOIN races r ON s.race_id = r.id
        WHERE r.date = ? AND sr.is_hit = 1
        ORDER BY r.venue, r.race_no LIMIT 20
    ");
    $stmt->execute([$date]);
    $out['hits'] = $stmt->fetchAll();

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'file'  => basename($e->getFile()),
        'line'  => $e->getLine(),
        'trace' => substr($e->getTraceAsString(), 0, 500),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
