<?php
/**
 * 一時確認用API: performance.php実装前のスキーマ・データ量調査
 * 確認が終わったら削除すること
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db();

function describe($pdo, $table) {
    try {
        $stmt = $pdo->query('DESCRIBE ' . $table);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

$out = [];
$out['strategies']        = describe($pdo, 'strategies');
$out['strategy_results']  = describe($pdo, 'strategy_results');
$out['users']             = describe($pdo, 'users');

$stmt = $pdo->query('
    SELECT MIN(r.date) AS min_date, MAX(r.date) AS max_date, COUNT(*) AS total,
           COUNT(DISTINCT sr.race_id) AS race_count
    FROM strategy_results sr
    JOIN races r ON r.id = sr.race_id
');
$out['strategy_results_range'] = $stmt->fetch();

$stmt = $pdo->query('SELECT DISTINCT strategy_type FROM strategies');
$out['strategy_types'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->query('SELECT s.strategy_type, COUNT(sr.id) AS cnt FROM strategies s JOIN strategy_results sr ON sr.strategy_id = s.id GROUP BY s.strategy_type');
$out['by_strategy_type'] = $stmt->fetchAll();

$stmt = $pdo->query('SELECT id, race_id, strategy_type, combinations FROM strategies LIMIT 3');
$out['strategies_sample'] = $stmt->fetchAll();

$stmt = $pdo->query('SELECT DISTINCT plan FROM users');
$out['plan_values'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

json_response($out);
