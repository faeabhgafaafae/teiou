<?php
/**
 * export_odds_payouts_v3.php
 * v3オフライン戦略KPI検証用のオッズ・払戻・着順エクスポート。読み取り専用・DB書き込みなし。
 * design_v3_model_20260903.md §4.1 のオフライン検証で使用。
 *
 * 呼び出し:
 *   ?api_key=xxx&from=YYYY-MM-DD&to=YYYY-MM-DD&mode=odds     3連単オッズ全組(直前スナップショット)
 *   ?api_key=xxx&from=...&to=...&mode=payouts                3連単確定払戻
 *   ?api_key=xxx&from=...&to=...&mode=finish                 1-3着の枠番
 *
 * オッズは行数が多い(約120組/レース)ため、非バッファクエリで1行ずつ
 * ストリーミング出力しPHPメモリを一定に保つ(共有ホスティング対策)。
 */

require_once __DIR__ . '/config.php';

if (($_GET['api_key'] ?? '') !== API_KEY) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '認証エラー'], JSON_UNESCAPED_UNICODE);
    exit;
}

$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';
$mode = $_GET['mode'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)
    || !in_array($mode, ['odds', 'payouts', 'finish'], true)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'from/to/mode(odds|payouts|finish) は必須です'], JSON_UNESCAPED_UNICODE);
    exit;
}

set_time_limit(180);

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $mode . '_' . $from . '_' . $to . '.csv"');

if ($mode === 'odds') {
    echo "race_id,combo,odds\n";
    $stmt = $pdo->prepare("
        SELECT o.race_id, o.combo, o.odds
        FROM odds_3t o
        JOIN races r ON o.race_id = r.id
        WHERE r.date BETWEEN ? AND ? AND o.odds IS NOT NULL
    ");
    $stmt->execute([$from, $to]);
    while ($row = $stmt->fetch()) {
        echo $row['race_id'] . ',' . $row['combo'] . ',' . $row['odds'] . "\n";
    }
} elseif ($mode === 'payouts') {
    echo "race_id,combo,amount\n";
    $stmt = $pdo->prepare("
        SELECT p.race_id, p.combo, p.amount
        FROM race_payouts p
        JOIN races r ON p.race_id = r.id
        WHERE r.date BETWEEN ? AND ? AND p.bet_type = '3連単'
    ");
    $stmt->execute([$from, $to]);
    while ($row = $stmt->fetch()) {
        echo $row['race_id'] . ',' . $row['combo'] . ',' . $row['amount'] . "\n";
    }
} else { // finish
    echo "race_id,rank,lane\n";
    $stmt = $pdo->prepare("
        SELECT res.race_id, res.actual_rank, res.lane
        FROM results res
        JOIN races r ON res.race_id = r.id
        WHERE r.date BETWEEN ? AND ? AND res.actual_rank IN (1,2,3)
    ");
    $stmt->execute([$from, $to]);
    while ($row = $stmt->fetch()) {
        echo $row['race_id'] . ',' . $row['actual_rank'] . ',' . $row['lane'] . "\n";
    }
}
