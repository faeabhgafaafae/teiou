<?php
// 清算検証スクリプト (使用後削除)
require_once __DIR__ . '/auth.php';

$key = $_GET['key'] ?? '';
require_admin_or_api_key($key);

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db();
$date = $_GET['date'] ?? '2026-09-09';

// 1. 当日のstrategy_results 全体集計
$stmt = $pdo->prepare("
    SELECT
        sr.stake_scheme,
        COUNT(*)               AS total_rows,
        SUM(sr.is_hit)         AS hits,
        SUM(sr.cost)           AS total_cost,
        SUM(sr.payout)         AS total_payout,
        MIN(sr.cost)           AS min_cost,
        MAX(sr.cost)           AS max_cost
    FROM strategy_results sr
    JOIN strategies s ON sr.strategy_id = s.id
    JOIN races r ON s.race_id = r.id
    WHERE r.date = ?
    GROUP BY sr.stake_scheme
");
$stmt->execute([$date]);
$summary = $stmt->fetchAll();

// 2. 的中レコードの詳細
$stmt = $pdo->prepare("
    SELECT
        r.venue, r.race_no, s.strategy_type,
        sr.stake_scheme, sr.cost, sr.payout, sr.is_hit,
        s.stakes, s.combinations
    FROM strategy_results sr
    JOIN strategies s ON sr.strategy_id = s.id
    JOIN races r ON s.race_id = r.id
    WHERE r.date = ? AND sr.is_hit = 1
    ORDER BY r.venue, r.race_no, s.strategy_type
    LIMIT 30
");
$stmt->execute([$date]);
$hits = $stmt->fetchAll();

// 3. stake有りレースのコスト分布(prob方式)
$stmt = $pdo->prepare("
    SELECT
        r.venue, r.race_no, s.strategy_type,
        sr.cost, sr.stake_scheme, s.stakes
    FROM strategy_results sr
    JOIN strategies s ON sr.strategy_id = s.id
    JOIN races r ON s.race_id = r.id
    WHERE r.date = ? AND sr.stake_scheme = 'prob'
    ORDER BY r.venue, r.race_no, s.strategy_type
    LIMIT 40
");
$stmt->execute([$date]);
$prob_rows = $stmt->fetchAll();

// 4. NULL stakes (flat/後方互換) のコスト確認
$stmt = $pdo->prepare("
    SELECT
        r.venue, r.race_no, s.strategy_type,
        sr.cost, sr.stake_scheme, s.stakes
    FROM strategy_results sr
    JOIN strategies s ON sr.strategy_id = s.id
    JOIN races r ON s.race_id = r.id
    WHERE r.date = ? AND (sr.stake_scheme IS NULL OR sr.stake_scheme = 'flat')
    ORDER BY r.venue, r.race_no, s.strategy_type
    LIMIT 20
");
$stmt->execute([$date]);
$flat_rows = $stmt->fetchAll();

// 5. stake_scheme カラムが存在するか確認
$col_check = $pdo->query("SHOW COLUMNS FROM strategy_results LIKE 'stake_scheme'")->fetchAll();

echo json_encode([
    'date'        => $date,
    'col_exists'  => count($col_check) > 0,
    'summary'     => $summary,
    'hits'        => $hits,
    'prob_sample' => $prob_rows,
    'flat_sample' => $flat_rows,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
