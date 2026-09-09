<?php
// 清算検証スクリプト (使用後削除)
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
    $out  = ['ok' => true, 'date' => $date];

    // 最新 strategy_results の日付分布
    $out['sr_date_dist'] = $pdo->query("
        SELECT r.date, COUNT(*) AS total_rows, SUM(sr.is_hit) AS hits, SUM(sr.cost) AS cost_sum
        FROM strategy_results sr
        JOIN strategies s ON sr.strategy_id = s.id
        JOIN races r ON s.race_id = r.id
        GROUP BY r.date ORDER BY r.date DESC LIMIT 7
    ")->fetchAll();

    // 当日のstrategiesは存在するか
    $stmt = $pdo->prepare("
        SELECT s.id, r.venue, r.race_no, s.strategy_type, s.stake_scheme, s.stakes
        FROM strategies s
        JOIN races r ON s.race_id = r.id
        WHERE r.date = ?
        ORDER BY r.race_no, s.strategy_type LIMIT 20
    ");
    $stmt->execute([$date]);
    $out['strategies_today'] = $stmt->fetchAll();

    // 当日の策略に対してstrategy_resultsはあるか
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM strategy_results sr
        JOIN strategies s ON sr.strategy_id = s.id
        JOIN races r ON s.race_id = r.id
        WHERE r.date = ?
    ");
    $stmt->execute([$date]);
    $out['sr_count_today'] = $stmt->fetchColumn();

    // 昨日のstrategy_results詳細(前日分が清算されているなら)
    $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
    $out['yesterday'] = $yesterday;
    $stmt = $pdo->prepare("
        SELECT s.strategy_type, s.stake_scheme,
               COUNT(*) AS total_rows,
               SUM(sr.is_hit) AS hits,
               SUM(sr.cost) AS cost_sum,
               AVG(sr.cost) AS cost_avg,
               MIN(sr.cost) AS cost_min,
               MAX(sr.cost) AS cost_max
        FROM strategy_results sr
        JOIN strategies s ON sr.strategy_id = s.id
        JOIN races r ON s.race_id = r.id
        WHERE r.date = ?
        GROUP BY s.strategy_type, s.stake_scheme
        ORDER BY s.strategy_type
    ");
    $stmt->execute([$yesterday]);
    $out['yesterday_by_type'] = $stmt->fetchAll();

    // 昨日の的中
    $stmt = $pdo->prepare("
        SELECT r.venue, r.race_no, s.strategy_type,
               sr.cost, sr.payout, s.stake_scheme, s.stakes
        FROM strategy_results sr
        JOIN strategies s ON sr.strategy_id = s.id
        JOIN races r ON s.race_id = r.id
        WHERE r.date = ? AND sr.is_hit = 1
        ORDER BY r.venue, r.race_no LIMIT 20
    ");
    $stmt->execute([$yesterday]);
    $out['yesterday_hits'] = $stmt->fetchAll();

    // 傾斜配分が入った最新のstrategy(strategies.stake_scheme='prob')
    $stmt = $pdo->prepare("
        SELECT r.date, r.venue, r.race_no, s.strategy_type,
               s.stake_scheme, s.stakes
        FROM strategies s
        JOIN races r ON s.race_id = r.id
        WHERE s.stake_scheme = 'prob'
        ORDER BY r.date DESC, r.race_no DESC LIMIT 10
    ");
    $stmt->execute();
    $out['prob_strategies_latest'] = $stmt->fetchAll();

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'file'  => basename($e->getFile()),
        'line'  => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
