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
    $out = ['ok' => true, 'date' => $date];

    // テーブル構造確認
    $out['desc_strategy_results'] = $pdo->query("DESCRIBE strategy_results")->fetchAll();
    $out['desc_strategies']       = $pdo->query("DESCRIBE strategies")->fetchAll();

    // strategy_results に stake_scheme があるか
    $sr_cols = array_column($out['desc_strategy_results'], 'Field');
    $s_cols  = array_column($out['desc_strategies'], 'Field');
    $has_sr_scheme  = in_array('stake_scheme', $sr_cols);
    $has_s_scheme   = in_array('stake_scheme', $s_cols);
    $has_stakes     = in_array('stakes', $s_cols);
    $has_cost       = in_array('cost', $sr_cols);

    $out['flags'] = compact('has_sr_scheme','has_s_scheme','has_stakes','has_cost');

    // 基本集計 (stake_schemeが無い場合はstrategy_results.costのみで)
    if ($has_cost) {
        $stmt = $pdo->prepare("
            SELECT s.strategy_type,
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
            GROUP BY s.strategy_type
            ORDER BY s.strategy_type
        ");
        $stmt->execute([$date]);
        $out['cost_by_type'] = $stmt->fetchAll();
    }

    // stake_scheme別(あれば)
    if ($has_sr_scheme) {
        $stmt = $pdo->prepare("
            SELECT sr.stake_scheme,
                   COUNT(*) AS total_rows,
                   SUM(sr.is_hit) AS hits,
                   SUM(sr.cost) AS cost_sum
            FROM strategy_results sr
            JOIN strategies s ON sr.strategy_id = s.id
            JOIN races r ON s.race_id = r.id
            WHERE r.date = ?
            GROUP BY sr.stake_scheme
        ");
        $stmt->execute([$date]);
        $out['by_sr_scheme'] = $stmt->fetchAll();
    }

    // strategies.stake_scheme(あれば)
    if ($has_s_scheme) {
        $stmt = $pdo->prepare("
            SELECT s.stake_scheme,
                   COUNT(*) AS total_rows,
                   SUM(sr.is_hit) AS hits,
                   SUM(sr.cost) AS cost_sum
            FROM strategy_results sr
            JOIN strategies s ON sr.strategy_id = s.id
            JOIN races r ON s.race_id = r.id
            WHERE r.date = ?
            GROUP BY s.stake_scheme
        ");
        $stmt->execute([$date]);
        $out['by_s_scheme'] = $stmt->fetchAll();
    }

    // 的中詳細
    $hit_sel = "r.venue, r.race_no, s.strategy_type, sr.cost, sr.payout";
    if ($has_sr_scheme) $hit_sel .= ", sr.stake_scheme AS sr_scheme";
    if ($has_s_scheme)  $hit_sel .= ", s.stake_scheme AS s_scheme";
    if ($has_stakes)    $hit_sel .= ", s.stakes";
    $stmt = $pdo->prepare("
        SELECT $hit_sel
        FROM strategy_results sr
        JOIN strategies s ON sr.strategy_id = s.id
        JOIN races r ON s.race_id = r.id
        WHERE r.date = ? AND sr.is_hit = 1
        ORDER BY r.venue, r.race_no LIMIT 20
    ");
    $stmt->execute([$date]);
    $out['hits'] = $stmt->fetchAll();

    // prob方式サンプル(strategies.stake_schemeで判断)
    if ($has_s_scheme && $has_stakes) {
        $stmt = $pdo->prepare("
            SELECT r.venue, r.race_no, s.strategy_type,
                   sr.cost, sr.payout, s.stake_scheme, s.stakes
            FROM strategy_results sr
            JOIN strategies s ON sr.strategy_id = s.id
            JOIN races r ON s.race_id = r.id
            WHERE r.date = ? AND s.stake_scheme = 'prob'
            ORDER BY r.race_no, s.strategy_type LIMIT 15
        ");
        $stmt->execute([$date]);
        $out['prob_sample'] = $stmt->fetchAll();
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'file'  => basename($e->getFile()),
        'line'  => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
