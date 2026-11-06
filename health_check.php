<?php
/**
 * health_check.php — データ取得・生成・清算のヘルスチェックAPI(2026-11-07〜。読み取り + チェック結果の記録のみ)
 *
 * GET  ?api_key=xxx&date=YYYY-MM-DD[&detail=1]
 *      その日の出走表・オッズ・買い目(締切前に生成されたか)・成績の状態と、記録済みのチェック結果を返す。
 *      detail=1 で、締切前に買い目を作れなかったレースの一覧(理由つき)も返す。
 * POST ?api_key=xxx  body: {"date":"YYYY-MM-DD","check":"racelist","status":"ok|retrying|alert|pending",
 *                           "detail":"...","action":true|false}
 *      healthcheck.py(毎時の自動チェック)が判定結果と再実行の有無を記録する。admin.php が alert を警告表示する。
 * 集計は health_lib.php。
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/health_lib.php';

header('Content-Type: application/json; charset=utf-8');
require_admin_or_api_key($_GET['api_key'] ?? '');

$pdo = get_db();
$now = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $date   = (string)($in['date'] ?? '');
    $check  = (string)($in['check'] ?? '');
    $status = (string)($in['status'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        || !in_array($check, ['racelist', 'odds', 'strategies', 'results'], true)
        || !in_array($status, ['ok', 'retrying', 'alert', 'pending'], true)) {
        json_response(['error' => 'date / check(racelist|odds|strategies|results) / status(ok|retrying|alert|pending) が不正'], 400);
    }
    hc_record($pdo, $date, $check, $status, mb_substr((string)($in['detail'] ?? ''), 0, 1000), !empty($in['action']), $now);
    json_response(['ok' => true]);
}

$date = $_GET['date'] ?? substr($now, 0, 10);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_response(['error' => 'date不正'], 400);
}

$rows    = hc_race_rows($pdo, $date);
$summary = hc_day_summary($rows, $now);
$checks  = array_values(array_filter(hc_list($pdo, $date), fn($c) => $c['check_date'] === $date));

// 締切済みで未清算のレース(healthcheck.py が boatrace.jp で「レース中止」かどうかを確かめるため)
$unsettled = [];
foreach ($rows as $rid => $r) {
    if ($r['deadline'] !== null && $r['deadline'] <= $now && !$r['settled'] && $r['n_entries'] > 0) {
        $unsettled[] = ['race_id' => $rid, 'venue' => $r['venue'], 'race_no' => $r['race_no']];
    }
}
$out = ['date' => $date, 'now' => $now, 'summary' => $summary, 'reason_labels' => HC_REASON_LABELS, 'checks' => $checks,
        'unsettled_races' => $unsettled];
if (($_GET['detail'] ?? '') === '1') {
    $late = [];
    foreach ($rows as $rid => $r) {
        if ($r['deadline'] === null || $r['deadline'] > $now) continue;
        $reason = hc_late_reason($r);
        if ($reason === null && !$r['balance_without_odds']) continue;
        $late[] = ['race_id' => $rid, 'venue' => $r['venue'], 'race_no' => $r['race_no'], 'deadline' => $r['deadline'],
                   'first_entry' => $r['first_entry'], 'first_strategy' => $r['first_strategy'],
                   'reason' => $reason, 'balance_without_odds' => $r['balance_without_odds']];
    }
    $out['late_races'] = $late;
}
json_response($out);
