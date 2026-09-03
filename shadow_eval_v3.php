<?php
/**
 * shadow_eval_v3.php
 * v3シャドウテストの評価 (読み取り専用・DB書き込みなし)。
 * design_v3_model_20260903.md §4.2 の昇格判定モニタリングに使用。
 *
 * 比較対象:
 *   - v3: predictions_v2(シャドウ) の predicted_rank
 *   - v2: predictions(本番) の predicted_rank (model_version='v2_lr')
 * 出力: 1着的中率(top1)、v3順位での4戦略KPIシミュレーション、本番戦略実績
 *
 * 呼び出し: ?api_key=xxx&from=YYYY-MM-DD&to=YYYY-MM-DD
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (($_GET['api_key'] ?? '') !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => '認証エラー'], JSON_UNESCAPED_UNICODE);
    exit;
}

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d', strtotime('-1 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    http_response_code(400);
    echo json_encode(['error' => 'from/to不正'], JSON_UNESCAPED_UNICODE);
    exit;
}

set_time_limit(120);
const BALANCE_MAX_ODDS  = 100.0;
const ICHIGEKI_MIN_ODDS = 15.0;

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// ── 対象レース(結果1-3着が確定しているもの) ─────────────────────────
$stmt = $pdo->prepare("
    SELECT r.id AS race_id, r.date
    FROM races r
    JOIN results res1 ON res1.race_id = r.id AND res1.actual_rank = 1
    JOIN results res3 ON res3.race_id = r.id AND res3.actual_rank = 3
    WHERE r.date BETWEEN ? AND ?
    GROUP BY r.id, r.date
");
$stmt->execute([$from, $to]);
$races = $stmt->fetchAll();
if (!$races) {
    echo json_encode(['error' => '対象レースなし', 'from' => $from, 'to' => $to], JSON_UNESCAPED_UNICODE);
    exit;
}
$race_ids = array_column($races, 'race_id');
$ph = implode(',', array_fill(0, count($race_ids), '?'));

// ── 着順・オッズ・払戻 ──────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT race_id, actual_rank, lane FROM results WHERE race_id IN ($ph) AND actual_rank IN (1,2,3)");
$stmt->execute($race_ids);
$finish = [];
foreach ($stmt->fetchAll() as $row) {
    $finish[(int)$row['race_id']][(int)$row['actual_rank']] = (int)$row['lane'];
}

$stmt = $pdo->prepare("SELECT race_id, combo, odds FROM odds_3t WHERE race_id IN ($ph)");
$stmt->execute($race_ids);
$odds_map = [];
foreach ($stmt->fetchAll() as $row) {
    $odds_map[(int)$row['race_id']][$row['combo']] = (float)$row['odds'];
}

$stmt = $pdo->prepare("SELECT race_id, combo, amount FROM race_payouts WHERE race_id IN ($ph) AND bet_type = '3連単'");
$stmt->execute($race_ids);
$pay_map = [];
foreach ($stmt->fetchAll() as $row) {
    $pay_map[(int)$row['race_id']][$row['combo']] = (int)$row['amount'];
}

// ── 予測順位マップ(v2本番 / v3シャドウ) ─────────────────────────────
function load_ranks(PDO $pdo, string $table, string $ph, array $race_ids): array {
    $stmt = $pdo->prepare("
        SELECT p.race_id, p.predicted_rank, MIN(e.lane) AS lane
        FROM $table p
        JOIN entries e ON e.race_id = p.race_id AND e.player_id = p.player_id
        WHERE p.race_id IN ($ph)
        GROUP BY p.race_id, p.predicted_rank
        ORDER BY p.race_id, p.predicted_rank
    ");
    $stmt->execute($race_ids);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['race_id']][(int)$row['predicted_rank']] = (int)$row['lane'];
    }
    foreach ($map as $rid => &$m) { ksort($m); $m = array_values($m); }
    return $map;
}
$v2_map = load_ranks($pdo, 'predictions',    $ph, $race_ids);
$v3_map = load_ranks($pdo, 'predictions_v2', $ph, $race_ids);

// ── 評価 ────────────────────────────────────────────────────────────
function payout_for(array $pay_map, array $odds_map, int $rid, string $combo): int {
    if (isset($pay_map[$rid][$combo])) return $pay_map[$rid][$combo];
    if (isset($odds_map[$rid][$combo])) return (int)floor($odds_map[$rid][$combo] * 100);
    return 0;
}

function permutations3(array $a): array {
    $out = [];
    foreach ($a as $i) foreach ($a as $j) foreach ($a as $k) {
        if ($i !== $j && $j !== $k && $i !== $k) $out[] = "$i-$j-$k";
    }
    return $out;
}

$top1 = ['v2' => [0, 0], 'v3' => [0, 0]]; // [hits, races]
$daily = [];
$strat = [];
foreach (['tokka', 'balance', 'ichigeki', 'shibori'] as $s) {
    $strat[$s] = ['races' => 0, 'hits' => 0, 'cost' => 0, 'payout' => 0];
}

foreach ($races as $r) {
    $rid = (int)$r['race_id'];
    if (!isset($finish[$rid][1], $finish[$rid][2], $finish[$rid][3])) continue;
    $actual = $finish[$rid][1] . '-' . $finish[$rid][2] . '-' . $finish[$rid][3];
    $odds_r = $odds_map[$rid] ?? [];

    foreach (['v2' => $v2_map, 'v3' => $v3_map] as $m => $map) {
        if (!isset($map[$rid][0])) continue;
        $top1[$m][1]++;
        $hit = $map[$rid][0] === $finish[$rid][1] ? 1 : 0;
        $top1[$m][0] += $hit;
        if ($m === 'v3') {
            $d = $r['date'];
            if (!isset($daily[$d])) $daily[$d] = ['v3_hits' => 0, 'races' => 0];
            $daily[$d]['v3_hits'] += $hit;
            $daily[$d]['races']++;
        }
    }

    // v3順位での4戦略シミュレーション(現行本番設定)
    $lanes = $v3_map[$rid] ?? null;
    if ($lanes === null || count($lanes) < 4) continue;

    $sets = [];
    $sets['tokka'] = permutations3(array_slice($lanes, 0, 3));
    $top4 = array_slice($lanes, 0, 4);
    $bal = [];
    foreach (array_slice($top4, 0, 2) as $first) {
        $rest = array_values(array_filter($top4, fn($l) => $l !== $first));
        foreach ($rest as $sec) foreach ($rest as $thi) {
            if ($sec !== $thi) {
                $c = "$first-$sec-$thi";
                if ($odds_r && isset($odds_r[$c]) && $odds_r[$c] > BALANCE_MAX_ODDS) continue;
                $bal[] = $c;
            }
        }
    }
    $sets['balance'] = $bal;
    $ichi = [];
    $first = $lanes[0];
    foreach (array_slice($lanes, 1, 3) as $sec) foreach (array_slice($lanes, 1, 3) as $thi) {
        if ($sec !== $thi) {
            $c = "$first-$sec-$thi";
            if ($odds_r && isset($odds_r[$c]) && $odds_r[$c] < ICHIGEKI_MIN_ODDS) continue;
            $ichi[] = $c;
        }
    }
    $sets['ichigeki'] = $ichi;
    $sh = array_slice($lanes, 0, 3);
    sort($sh);
    $sets['shibori'] = [implode('-', $sh)];

    foreach ($sets as $s => $combos) {
        if (!$combos) continue;
        $strat[$s]['races']++;
        $strat[$s]['cost'] += count($combos) * 100;
        if (in_array($actual, $combos, true)) {
            $strat[$s]['hits']++;
            $strat[$s]['payout'] += payout_for($pay_map, $odds_map, $rid, $actual);
        }
    }
}

// ── 本番戦略実績(同期間、strategy_results) ──────────────────────────
$stmt = $pdo->prepare("
    SELECT s.strategy_type,
           COUNT(sr.id) AS races, COALESCE(SUM(sr.is_hit),0) AS hits,
           COALESCE(SUM(sr.cost),0) AS cost, COALESCE(SUM(sr.payout),0) AS payout
    FROM strategies s
    JOIN strategy_results sr ON sr.strategy_id = s.id
    JOIN races r ON r.id = s.race_id
    WHERE r.date BETWEEN ? AND ?
    GROUP BY s.strategy_type
");
$stmt->execute([$from, $to]);
$prod = [];
foreach ($stmt->fetchAll() as $row) {
    $prod[$row['strategy_type']] = [
        'races'    => (int)$row['races'],
        'hit_rate' => $row['races'] > 0 ? round($row['hits'] / $row['races'] * 100, 1) : 0,
        'roi'      => $row['cost'] > 0 ? round($row['payout'] / $row['cost'] * 100, 1) : 0,
    ];
}

$names = ['tokka' => '的中特化', 'balance' => 'バランス', 'ichigeki' => '一撃重視', 'shibori' => '絞り込み'];
$strat_out = [];
foreach ($strat as $s => $a) {
    $strat_out[$names[$s]] = [
        'races'      => $a['races'],
        'hit_rate'   => $a['races'] > 0 ? round($a['hits'] / $a['races'] * 100, 1) : 0,
        'roi'        => $a['cost'] > 0 ? round($a['payout'] / $a['cost'] * 100, 1) : 0,
        'avg_combos' => $a['races'] > 0 ? round($a['cost'] / 100 / $a['races'], 2) : 0,
    ];
}
ksort($daily);

echo json_encode([
    'from' => $from, 'to' => $to,
    'note' => '読み取り専用。v3=predictions_v2(シャドウ)、v2=predictions(本番)。戦略シムは現行本番設定(バランス100倍/一撃15倍)',
    'top1' => [
        'v2' => ['races' => $top1['v2'][1], 'hit_rate' => $top1['v2'][1] > 0 ? round($top1['v2'][0] / $top1['v2'][1] * 100, 1) : 0],
        'v3' => ['races' => $top1['v3'][1], 'hit_rate' => $top1['v3'][1] > 0 ? round($top1['v3'][0] / $top1['v3'][1] * 100, 1) : 0],
    ],
    'daily_v3' => $daily,
    'strategy_sim_v3' => $strat_out,
    'strategy_prod_v2' => $prod,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
