<?php
// 清算検証スクリプト (使用後削除)
require_once __DIR__ . '/auth.php';

$key = $_GET['key'] ?? '';
require_admin_or_api_key($key);

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = get_db();
    $date = $_GET['date'] ?? '2026-09-09';
    $out = [];

    // stake_scheme カラムの存在確認
    $out['col_stake_scheme_in_strategies']       = $pdo->query("SHOW COLUMNS FROM strategies LIKE 'stake_scheme'")->fetchAll();
    $out['col_stake_scheme_in_strategy_results'] = $pdo->query("SHOW COLUMNS FROM strategy_results LIKE 'stake_scheme'")->fetchAll();
    $out['col_stakes_in_strategies']             = $pdo->query("SHOW COLUMNS FROM strategies LIKE 'stakes'")->fetchAll();

    // 1. 当日 strategy_results 集計(stake_scheme別)
    $stmt = $pdo->prepare("
        SELECT
            sr.stake_scheme,
            COUNT(*)        AS total_rows,
            SUM(sr.is_hit)  AS hits,
            SUM(sr.cost)    AS total_cost,
            MIN(sr.cost)    AS min_cost,
            MAX(sr.cost)    AS max_cost
        FROM strategy_results sr
        JOIN strategies s  ON sr.strategy_id = s.id
        JOIN races r       ON s.race_id = r.id
        WHERE r.date = ?
        GROUP BY sr.stake_scheme
    ");
    $stmt->execute([$date]);
    $out['summary_by_scheme'] = $stmt->fetchAll();

    // 2. strategy_type別コスト集計
    $stmt = $pdo->prepare("
        SELECT
            s.strategy_type,
            sr.stake_scheme,
            COUNT(*)        AS cnt,
            AVG(sr.cost)    AS avg_cost,
            MIN(sr.cost)    AS min_cost,
            MAX(sr.cost)    AS max_cost
        FROM strategy_results sr
        JOIN strategies s ON sr.strategy_id = s.id
        JOIN races r      ON s.race_id = r.id
        WHERE r.date = ?
        GROUP BY s.strategy_type, sr.stake_scheme
        ORDER BY s.strategy_type
    ");
    $stmt->execute([$date]);
    $out['cost_by_type'] = $stmt->fetchAll();

    // 3. 的中レコード詳細
    $stmt = $pdo->prepare("
        SELECT
            r.venue, r.race_no, s.strategy_type,
            sr.stake_scheme, sr.cost, sr.payout, sr.is_hit,
            s.stakes
        FROM strategy_results sr
        JOIN strategies s ON sr.strategy_id = s.id
        JOIN races r      ON s.race_id = r.id
        WHERE r.date = ? AND sr.is_hit = 1
        ORDER BY r.venue, r.race_no, s.strategy_type
        LIMIT 20
    ");
    $stmt->execute([$date]);
    $out['hits'] = $stmt->fetchAll();

    // 4. prob方式サンプル(コスト検証用)
    $stmt = $pdo->prepare("
        SELECT
            r.venue, r.race_no, s.strategy_type,
            sr.cost, sr.payout, sr.stake_scheme, s.stakes
        FROM strategy_results sr
        JOIN strategies s ON sr.strategy_id = s.id
        JOIN races r      ON s.race_id = r.id
        WHERE r.date = ? AND sr.stake_scheme = 'prob'
        ORDER BY r.race_no, s.strategy_type
        LIMIT 20
    ");
    $stmt->execute([$date]);
    $out['prob_sample'] = $stmt->fetchAll();

    // 5. flat/NULL サンプル
    $stmt = $pdo->prepare("
        SELECT
            r.venue, r.race_no, s.strategy_type,
            sr.cost, sr.payout, sr.stake_scheme, s.stakes
        FROM strategy_results sr
        JOIN strategies s ON sr.strategy_id = s.id
        JOIN races r      ON s.race_id = r.id
        WHERE r.date = ? AND (sr.stake_scheme IS NULL OR sr.stake_scheme = 'flat')
        ORDER BY r.race_no, s.strategy_type
        LIMIT 10
    ");
    $stmt->execute([$date]);
    $out['flat_sample'] = $stmt->fetchAll();

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_UNESCAPED_UNICODE);
}
