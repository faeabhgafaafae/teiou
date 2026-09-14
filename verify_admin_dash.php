<?php
/**
 * verify_admin_dash.php (一時スクリプト・確認後削除)
 * admin.php のダッシュボード集計クエリを本番DBで実行し、カラム名エラーや
 * 実データを検証する。読み取り専用・DB書き込みなし。
 * GET: ?api_key=xxxx
 */
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');
require_admin_or_api_key($_GET['api_key'] ?? '');

const SHADOW_START = '2026-09-13';
$today = date('Y-m-d');
$out = ['today' => $today];

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS races, COUNT(DISTINCT venue) AS venues,
               SUM(odds_updated_at IS NOT NULL) AS odds_races,
               MAX(before_updated_at) AS before_last, MAX(odds_updated_at) AS odds_last
        FROM races WHERE date = ?");
    $stmt->execute([$today]);
    $out['today_races'] = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total, SUM(e.exhibit_time IS NOT NULL) AS filled
        FROM entries e JOIN races r ON r.id = e.race_id WHERE r.date = ?");
    $stmt->execute([$today]);
    $out['today_exhibit'] = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT p.race_id) AS races
        FROM predictions_v2 p JOIN races r ON r.id = p.race_id WHERE r.date >= ?");
    $stmt->execute([SHADOW_START]);
    $out['shadow_races'] = $stmt->fetch();

    foreach (['v2' => 'predictions', 'v3w' => 'predictions_v2'] as $key => $table) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS races, SUM(res.actual_rank = 1) AS hits
            FROM $table p
            JOIN races r     ON r.id = p.race_id
            JOIN results res ON res.race_id = p.race_id AND res.player_id = p.player_id
            WHERE p.predicted_rank = 1 AND r.date >= ?");
        $stmt->execute([SHADOW_START]);
        $out['shadow_' . $key] = $stmt->fetch();
    }

    $strat_sql = "
        SELECT s.strategy_type, COUNT(sr.id) AS races, COALESCE(SUM(sr.is_hit),0) AS hits,
               COALESCE(SUM(sr.cost),0) AS cost, COALESCE(SUM(sr.payout),0) AS payout
        FROM strategy_results sr
        JOIN strategies s ON s.id = sr.strategy_id
        JOIN races r      ON r.id = s.race_id %s
        GROUP BY s.strategy_type";
    $stmt = $pdo->prepare(sprintf($strat_sql, 'WHERE r.date >= ?'));
    $stmt->execute([date('Y-m-d', strtotime('-6 days'))]);
    $out['strat_7d'] = $stmt->fetchAll();
    $out['strat_all'] = $pdo->query(sprintf($strat_sql, ''))->fetchAll();

    $out['status'] = 'OK - all queries executed';
} catch (Exception $e) {
    $out['status'] = 'ERROR';
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
