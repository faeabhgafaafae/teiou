<?php
/**
 * 艇王 - 成績・回収率: 個別レース一覧API(Standard 以上。plan_access.php)
 * GET /get_performance_races.php
 * 戦略買い目の的中・不的中を個別レース単位で返す。
 * 各レースの予想の根拠は get_prediction.php(factors)を別途呼び出して取得する。
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/data_quality.php';
require_once __DIR__ . '/plan_access.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
// 買い目・予想の根拠は Standard 以上(plan_access.php)
if (!plan_can_view_details($user)) {
    json_response(['error' => 'premium_required', 'message' => 'この機能はStandard/Premiumプラン限定です'], 403);
}

$pdo = get_db();

$stmt = $pdo->query('
    SELECT
        r.id      AS race_id,
        r.venue,
        r.date,
        r.race_no,
        s.strategy_type,
        sr.is_hit,
        sr.payout,
        sr.cost,
        (
            SELECT GROUP_CONCAT(res2.lane ORDER BY res2.actual_rank SEPARATOR \'-\')
            FROM results res2
            WHERE res2.race_id = sr.race_id
              AND res2.actual_rank IN (1, 2, 3)
        ) AS combination
    FROM strategy_results sr
    JOIN strategies s ON s.id = sr.strategy_id
    JOIN races r      ON r.id = sr.race_id
    WHERE 1=1' . public_exclusion_sql('r.date') . '
    ORDER BY r.date DESC, r.race_no DESC, sr.id DESC
    LIMIT 30
');
$rows = $stmt->fetchAll();

$races = [];
foreach ($rows as $row) {
    $races[] = [
        'race_id'       => (int)$row['race_id'],
        'venue'         => $row['venue'],
        'date'          => $row['date'],
        'race_no'       => (int)$row['race_no'],
        'strategy_type' => $row['strategy_type'],
        'is_hit'        => (int)$row['is_hit'],
        'payout'        => (int)$row['payout'],
        'cost'          => (int)$row['cost'],
        'combination'   => $row['combination'],
    ];
}

json_response(['races' => $races]);
