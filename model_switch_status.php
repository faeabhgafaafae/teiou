<?php
/**
 * model_switch_status.php
 * v3w 本番切り替えの監視(読み取り専用・DB書き込みなし)。design_v3w_switch_20261001.md §3。
 * 日別に、予測の保存状況(v2=predictions / v3w=predictions_v2)と、
 * strategies / strategy_results の model_ref 別件数・的中・回収率、
 * 保存済み予測(結果取込み前に保存された値)の1着的中・1号艇1位率を返す。
 *
 * 呼び出し: ?api_key=xxx&from=YYYY-MM-DD&to=YYYY-MM-DD
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/model_switch.php';
require_once __DIR__ . '/model_status_lib.php';

header('Content-Type: application/json; charset=utf-8');

require_admin_or_api_key($_GET['api_key'] ?? '');

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-1 day'));
$to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    http_response_code(400);
    echo json_encode(['error' => 'from/to不正'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = get_db();

// 集計は model_status_lib.php(admin.php と共用)。出力形式は 2026-10-08 版と同じ。
$daily = [];
foreach (ms_prediction_coverage($pdo, $from, $to) as $d => $row) {
    $daily[$d] = $row + ['strategies' => [], 'strategy_results' => []];
}
foreach (ms_strategy_counts($pdo, $from, $to) as $d => $types) {
    $daily[$d]['strategies'] = $types;
}
foreach (ms_strategy_results($pdo, $from, $to) as $d => $types) {
    $daily[$d]['strategy_results'] = $types;
}
foreach (ms_top1_daily($pdo, $from, $to) as $d => $row) {
    $daily[$d]['top1'] = $row;
}

echo json_encode([
    'from'   => $from,
    'to'     => $to,
    'config' => [
        'STRATEGY_MODEL_MAP'       => STRATEGY_MODEL_MAP,
        'STRATEGY_MODEL_FALLBACK'  => STRATEGY_MODEL_FALLBACK,
        'PREDICTION_DISPLAY_MODEL' => PREDICTION_DISPLAY_MODEL,
    ],
    'daily'  => $daily,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
