<?php
/**
 * audit_leak_rewrites.php
 * 先読みリークによる事後書き換えの影響範囲調査(読み取り専用・DB書き込みなし)。
 * design_leak_fix_20260930.md §3 の一覧作成用。
 *
 * 判定: レースの predictions 最終書き込み時刻(created_at。upsertのたびNOW()で更新) が
 *       results の初回取込み時刻(created_at。upsertでは更新されない) より後
 *       → 結果を知った状態で再計算・上書きされた(rewritten)とみなす。
 *       同じ api_predict.php 呼び出しで strategies も再生成され、その後の結果取込みで
 *       strategy_results も再清算されるため、同レースの両テーブル行を影響対象として数える。
 *
 * 呼び出し: ?api_key=xxx&from=YYYY-MM-DD&to=YYYY-MM-DD
 */

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

require_admin_or_api_key($_GET['api_key'] ?? '');

$from = $_GET['from'] ?? '2026-06-01';
$to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    http_response_code(400);
    echo json_encode(['error' => 'from/to不正'], JSON_UNESCAPED_UNICODE);
    exit;
}

set_time_limit(120);
$pdo = get_db();

$stmt = $pdo->prepare("
    SELECT r.date,
           COUNT(*)                                          AS settled_races,
           SUM(pw.race_id IS NOT NULL)                       AS races_with_predictions,
           SUM(pw.last_pred > rs.first_res)                  AS rewritten_races,
           SUM(CASE WHEN pw.last_pred > rs.first_res THEN COALESCE(st.n, 0) ELSE 0 END) AS rewritten_strategies,
           SUM(CASE WHEN pw.last_pred > rs.first_res THEN COALESCE(sr.n, 0) ELSE 0 END) AS rewritten_strategy_results,
           SUM(CASE WHEN pw.last_pred > rs.first_res THEN top.hit ELSE 0 END)           AS top1_hits_rewritten,
           SUM(CASE WHEN pw.last_pred <= rs.first_res THEN top.hit ELSE 0 END)          AS top1_hits_clean,
           SUM(pw.last_pred <= rs.first_res)                 AS clean_races,
           MIN(CASE WHEN pw.last_pred > rs.first_res THEN pw.last_pred END)             AS first_rewrite_at,
           MAX(CASE WHEN pw.last_pred > rs.first_res THEN pw.last_pred END)             AS last_rewrite_at
    FROM races r
    JOIN (SELECT race_id, MIN(created_at) AS first_res
          FROM results WHERE actual_rank IS NOT NULL GROUP BY race_id) rs ON rs.race_id = r.id
    LEFT JOIN (SELECT race_id, MAX(created_at) AS last_pred
               FROM predictions GROUP BY race_id) pw ON pw.race_id = r.id
    LEFT JOIN (SELECT race_id, COUNT(*) AS n FROM strategies GROUP BY race_id) st ON st.race_id = r.id
    LEFT JOIN (SELECT race_id, COUNT(*) AS n FROM strategy_results GROUP BY race_id) sr ON sr.race_id = r.id
    LEFT JOIN (SELECT p.race_id, MAX(res.actual_rank = 1) AS hit
               FROM predictions p
               JOIN results res ON res.race_id = p.race_id AND res.player_id = p.player_id
               WHERE p.predicted_rank = 1
               GROUP BY p.race_id) top ON top.race_id = r.id
    WHERE r.date BETWEEN ? AND ?
    GROUP BY r.date
    ORDER BY r.date
");
$stmt->execute([$from, $to]);

$daily  = [];
$totals = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $d = $row['date'];
    unset($row['date']);
    foreach ($row as $k => $v) {
        if (is_numeric($v)) {
            $row[$k]     = (int)$v;
            $totals[$k]  = ($totals[$k] ?? 0) + (int)$v;
        }
    }
    $daily[$d] = $row;
}
$rate = fn($h, $n) => $n > 0 ? round($h / $n * 100, 1) : null;

echo json_encode([
    'from'   => $from,
    'to'     => $to,
    'note'   => '読み取り専用。rewritten = predictions最終書き込みがresults初回取込みより後のレース',
    'totals' => $totals + [
        'top1_rate_rewritten' => $rate($totals['top1_hits_rewritten'] ?? 0, $totals['rewritten_races'] ?? 0),
        'top1_rate_clean'     => $rate($totals['top1_hits_clean'] ?? 0, $totals['clean_races'] ?? 0),
    ],
    'daily'  => $daily,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
