<?php
/**
 * バランス戦略シミュレーター (読み取り専用 / DB書き込みなし)
 * simulate_ichigeki.php と同型。model_improvement_report_20260903.md §4.2 の検証用。
 *
 * Part1) 診断: 実際に使われた予測順位(8/28前=v1、以降=v2)で
 *         12点候補のオッズ分布とオッズ上限25倍フィルタの挙動を期間比較
 * Part2) 代替フィルタ比較: v2順位ベースで オッズ上限/バンド/EVフィルタ をシミュレーション
 *         (EV = Harville近似コンボ確率 × 直前オッズ。確率は predictions_v2.win_probability)
 *
 * 呼び出し: ?api_key=teio2025 [&days=30]
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (($_GET['api_key'] ?? '') !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => '認証エラー'], JSON_UNESCAPED_UNICODE);
    exit;
}

set_time_limit(180);
$days = max(1, min(90, (int)($_GET['days'] ?? 30)));
$V2_CUTOVER = '2026-08-28'; // v2昇格後の初日

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 対象レース: 予測・結果(1-3着)・オッズが揃っているもの ──────────
$stmt = $pdo->prepare('
    SELECT DISTINCT r.id AS race_id, r.date
    FROM races r
    JOIN predictions p  ON p.race_id = r.id
    JOIN results res1   ON res1.race_id = r.id AND res1.actual_rank = 1
    JOIN results res3   ON res3.race_id = r.id AND res3.actual_rank = 3
    JOIN odds_3t  o     ON o.race_id    = r.id
    WHERE r.date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    ORDER BY r.date
');
$stmt->execute([$days]);
$races = $stmt->fetchAll();

if (!$races) {
    echo json_encode(['error' => '対象レースなし', 'days' => $days], JSON_UNESCAPED_UNICODE);
    exit;
}

$race_ids  = array_column($races, 'race_id');
$race_date = [];
foreach ($races as $r) $race_date[(int)$r['race_id']] = $r['date'];
$ph = implode(',', array_fill(0, count($race_ids), '?'));

// ── 実運用の予測順位(predictions: 8/28前=v1、以降=v2) ────────────────
$stmt = $pdo->prepare("
    SELECT p.race_id, p.predicted_rank, MIN(e.lane) AS lane
    FROM predictions p
    JOIN entries e ON e.race_id = p.race_id AND e.player_id = p.player_id
    WHERE p.race_id IN ($ph)
    GROUP BY p.race_id, p.predicted_rank
    ORDER BY p.race_id, p.predicted_rank ASC
");
$stmt->execute($race_ids);
$pred_map = []; // race_id => [0=>1位lane, 1=>2位lane, ...]
foreach ($stmt->fetchAll() as $row) {
    $pred_map[(int)$row['race_id']][(int)$row['predicted_rank']] = (int)$row['lane'];
}
foreach ($pred_map as $rid => &$m) { ksort($m); $m = array_values($m); }
unset($m);

// ── v2予測(predictions_v2: 順位+勝率。EVフィルタ用) ──────────────────
$v2_map = []; // race_id => ['lanes'=>[rank順lane], 'prob'=>[lane=>win_probability]]
try {
    $stmt = $pdo->prepare("
        SELECT p.race_id, p.predicted_rank, p.win_probability, MIN(e.lane) AS lane
        FROM predictions_v2 p
        JOIN entries e ON e.race_id = p.race_id AND e.player_id = p.player_id
        WHERE p.race_id IN ($ph)
        GROUP BY p.race_id, p.predicted_rank, p.win_probability
        ORDER BY p.race_id, p.predicted_rank ASC
    ");
    $stmt->execute($race_ids);
    foreach ($stmt->fetchAll() as $row) {
        $rid  = (int)$row['race_id'];
        $lane = (int)$row['lane'];
        $v2_map[$rid]['ranks'][(int)$row['predicted_rank']] = $lane;
        $v2_map[$rid]['prob'][$lane] = (float)$row['win_probability'];
    }
    foreach ($v2_map as $rid => &$m) { ksort($m['ranks']); $m['lanes'] = array_values($m['ranks']); }
    unset($m);
} catch (PDOException $e) { /* predictions_v2なし → EV系はスキップ */ }

// ── 結果・オッズ・確定払戻 (bulk) ────────────────────────────────────
$stmt = $pdo->prepare("SELECT race_id, actual_rank, lane FROM results WHERE race_id IN ($ph) AND actual_rank IN (1,2,3)");
$stmt->execute($race_ids);
$result_map = [];
foreach ($stmt->fetchAll() as $row) {
    $result_map[(int)$row['race_id']][(int)$row['actual_rank']] = (int)$row['lane'];
}

$stmt = $pdo->prepare("SELECT race_id, combo, odds FROM odds_3t WHERE race_id IN ($ph)");
$stmt->execute($race_ids);
$odds_map = [];
foreach ($stmt->fetchAll() as $row) {
    $odds_map[(int)$row['race_id']][$row['combo']] = (float)$row['odds'];
}

// 確定払戻(3連単)。strategy_resultsの清算と同じソース。なければ直前オッズ×100で代用
$stmt = $pdo->prepare("SELECT race_id, combo, amount FROM race_payouts WHERE race_id IN ($ph) AND bet_type = '3連単'");
$stmt->execute($race_ids);
$payout_map = [];
foreach ($stmt->fetchAll() as $row) {
    $payout_map[(int)$row['race_id']][$row['combo']] = (int)$row['amount'];
}

// ── バランス戦略のコンボ生成(フィルタなし12点) ──────────────────────
function balance_combos(array $lanes): array {
    if (count($lanes) < 4) return [];
    $top4   = array_slice($lanes, 0, 4);
    $combos = [];
    foreach (array_slice($top4, 0, 2) as $first) {
        $rest = array_values(array_filter($top4, fn($l) => $l !== $first));
        foreach ($rest as $sec) {
            foreach ($rest as $thi) {
                if ($sec !== $thi) $combos[] = $first . '-' . $sec . '-' . $thi;
            }
        }
    }
    return $combos;
}

// Harville近似: P(a-b-c) = pa * pb/(1-pa) * pc/(1-pa-pb)
function harville_prob(array $prob, string $combo): ?float {
    [$a, $b, $c] = array_map('intval', explode('-', $combo));
    if (!isset($prob[$a], $prob[$b], $prob[$c])) return null;
    $pa = $prob[$a]; $pb = $prob[$b]; $pc = $prob[$c];
    $d1 = 1 - $pa; $d2 = 1 - $pa - $pb;
    if ($d1 <= 1e-9 || $d2 <= 1e-9) return null;
    return $pa * ($pb / $d1) * ($pc / $d2);
}

function payout_for(array $payout_map, array $odds_map, int $rid, string $combo): int {
    if (isset($payout_map[$rid][$combo])) return $payout_map[$rid][$combo];
    if (isset($odds_map[$rid][$combo]))   return (int)floor($odds_map[$rid][$combo] * 100);
    return 0;
}

// ════════ Part1: 診断(実運用予測での12点候補とオッズ上限25倍の挙動) ════════
$diag = [
    'v1期(〜08-27)' => null,
    'v2期(08-28〜)' => null,
];
foreach (array_keys($diag) as $era) {
    $diag[$era] = [
        'races' => 0, 'rank1_lane1_pct' => 0, '_rank1_lane1' => 0,
        'combo_odds_le25_pct' => 0, '_combos' => 0, '_combos_le25' => 0,
        'combo_odds_avg' => 0, '_odds_sum' => 0,
        // フィルタなし12点
        'nofilter' => ['hits' => 0, 'cost' => 0, 'payout' => 0],
        // 現行(25倍上限)
        'cap25'    => ['hits' => 0, 'cost' => 0, 'payout' => 0],
        // 25倍上限で削られた買い目が当たっていたケース(逸失)
        'lost_hits_by_cap25' => 0, 'lost_payout_by_cap25' => 0,
        'avg_hit_payout_nofilter' => 0, 'avg_hit_payout_cap25' => 0,
    ];
}

foreach ($race_ids as $rid) {
    $rid = (int)$rid;
    if (!isset($pred_map[$rid], $result_map[$rid])) continue;
    $finish = $result_map[$rid];
    if (!isset($finish[1], $finish[2], $finish[3])) continue;

    $era    = ($race_date[$rid] < $V2_CUTOVER) ? 'v1期(〜08-27)' : 'v2期(08-28〜)';
    $lanes  = $pred_map[$rid];
    $combos = balance_combos($lanes);
    if (!$combos) continue;

    $actual = $finish[1] . '-' . $finish[2] . '-' . $finish[3];
    $d = &$diag[$era];
    $d['races']++;
    if (($lanes[0] ?? 0) === 1) $d['_rank1_lane1']++;

    $kept = [];
    foreach ($combos as $c) {
        $o = $odds_map[$rid][$c] ?? null;
        if ($o !== null) {
            $d['_combos']++;
            $d['_odds_sum'] += $o;
            if ($o <= 25.0) $d['_combos_le25']++;
        }
        // 現行ロジック再現: オッズがあり25倍超なら除外
        if ($o !== null && $o > 25.0) continue;
        $kept[] = $c;
    }

    // フィルタなし12点
    $d['nofilter']['cost'] += count($combos) * 100;
    if (in_array($actual, $combos, true)) {
        $d['nofilter']['hits']++;
        $d['nofilter']['payout'] += payout_for($payout_map, $odds_map, $rid, $actual);
    }
    // 現行cap25
    $d['cap25']['cost'] += count($kept) * 100;
    if (in_array($actual, $kept, true)) {
        $d['cap25']['hits']++;
        $d['cap25']['payout'] += payout_for($payout_map, $odds_map, $rid, $actual);
    } elseif (in_array($actual, $combos, true)) {
        // 12点候補には入っていたのにcapで削られて逃した的中
        $d['lost_hits_by_cap25']++;
        $d['lost_payout_by_cap25'] += payout_for($payout_map, $odds_map, $rid, $actual);
    }
    unset($d);
}

foreach ($diag as $era => &$d) {
    $d['rank1_lane1_pct']     = $d['races'] > 0 ? round($d['_rank1_lane1'] / $d['races'] * 100, 1) : 0;
    $d['combo_odds_le25_pct'] = $d['_combos'] > 0 ? round($d['_combos_le25'] / $d['_combos'] * 100, 1) : 0;
    $d['combo_odds_avg']      = $d['_combos'] > 0 ? round($d['_odds_sum'] / $d['_combos'], 1) : 0;
    foreach (['nofilter', 'cap25'] as $k) {
        $s = $d[$k];
        $d[$k]['hit_rate'] = $d['races'] > 0 ? round($s['hits'] / $d['races'] * 100, 1) : 0;
        $d[$k]['roi']      = $s['cost'] > 0 ? round($s['payout'] / $s['cost'] * 100, 1) : 0;
        $d['avg_hit_payout_' . $k] = $s['hits'] > 0 ? (int)round($s['payout'] / $s['hits']) : 0;
    }
    unset($d['_rank1_lane1'], $d['_combos'], $d['_combos_le25'], $d['_odds_sum']);
}
unset($d);

// ════════ Part2: v2順位ベースの代替フィルタ比較(全期間) ════════
$variants = [
    'フィルタなし(12点)'      => ['type' => 'cap',  'cap' => 0,   'floor' => 0],
    '上限15倍'                => ['type' => 'cap',  'cap' => 15,  'floor' => 0],
    '上限25倍(現行)'          => ['type' => 'cap',  'cap' => 25,  'floor' => 0],
    '上限40倍'                => ['type' => 'cap',  'cap' => 40,  'floor' => 0],
    '上限60倍'                => ['type' => 'cap',  'cap' => 60,  'floor' => 0],
    '上限100倍'               => ['type' => 'cap',  'cap' => 100, 'floor' => 0],
    '下限5倍のみ'             => ['type' => 'cap',  'cap' => 0,   'floor' => 5],
    '下限5倍+上限60倍'        => ['type' => 'cap',  'cap' => 60,  'floor' => 5],
    'EV≥0.5'                  => ['type' => 'ev',   'ev' => 0.5,  'pmin' => 0],
    'EV≥0.7'                  => ['type' => 'ev',   'ev' => 0.7,  'pmin' => 0],
    'EV≥0.9'                  => ['type' => 'ev',   'ev' => 0.9,  'pmin' => 0],
    'EV≥1.1'                  => ['type' => 'ev',   'ev' => 1.1,  'pmin' => 0],
    'EV≥0.7+p≥1.5%'           => ['type' => 'ev',   'ev' => 0.7,  'pmin' => 0.015],
    'EV≥0.9+p≥1.5%'           => ['type' => 'ev',   'ev' => 0.9,  'pmin' => 0.015],
];

$sim = [];
foreach ($variants as $name => $cfg) {
    $sim[$name] = ['races' => 0, 'active' => 0, 'combos' => 0, 'hits' => 0, 'cost' => 0, 'payout' => 0];
}
$v2_races = 0;

foreach ($race_ids as $rid) {
    $rid = (int)$rid;
    if (!isset($v2_map[$rid]['lanes'], $result_map[$rid])) continue;
    $finish = $result_map[$rid];
    if (!isset($finish[1], $finish[2], $finish[3])) continue;

    $lanes  = $v2_map[$rid]['lanes'];
    $prob   = $v2_map[$rid]['prob'] ?? [];
    $combos = balance_combos($lanes);
    if (!$combos) continue;

    $actual = $finish[1] . '-' . $finish[2] . '-' . $finish[3];
    $v2_races++;

    foreach ($variants as $name => $cfg) {
        $sim[$name]['races']++;
        $kept = [];
        foreach ($combos as $c) {
            $o = $odds_map[$rid][$c] ?? null;
            if ($cfg['type'] === 'cap') {
                if ($o !== null && $cfg['cap']   > 0 && $o > $cfg['cap'])   continue;
                if ($o !== null && $cfg['floor'] > 0 && $o < $cfg['floor']) continue;
            } else { // ev
                $p = harville_prob($prob, $c);
                if ($p === null || $o === null) continue; // EV計算不能は買わない
                if ($p * $o < $cfg['ev']) continue;
                if ($cfg['pmin'] > 0 && $p < $cfg['pmin']) continue;
            }
            $kept[] = $c;
        }
        if (!$kept) continue;
        $sim[$name]['active']++;
        $sim[$name]['combos'] += count($kept);
        $sim[$name]['cost']   += count($kept) * 100;
        if (in_array($actual, $kept, true)) {
            $sim[$name]['hits']++;
            $sim[$name]['payout'] += payout_for($payout_map, $odds_map, $rid, $actual);
        }
    }
}

$variant_out = [];
foreach ($sim as $name => $s) {
    $variant_out[] = [
        'variant'         => $name,
        'total_races'     => $s['races'],
        'active_races'    => $s['active'],
        'entry_rate_pct'  => $s['races'] > 0 ? round($s['active'] / $s['races'] * 100, 1) : 0,
        'avg_combos'      => $s['active'] > 0 ? round($s['combos'] / $s['active'], 2) : 0,
        'hits'            => $s['hits'],
        'hit_rate_active' => $s['active'] > 0 ? round($s['hits'] / $s['active'] * 100, 1) : 0,
        'hit_rate_all'    => $s['races'] > 0 ? round($s['hits'] / $s['races'] * 100, 1) : 0,
        'avg_hit_payout'  => $s['hits'] > 0 ? (int)round($s['payout'] / $s['hits']) : 0,
        'total_cost'      => $s['cost'],
        'total_payout'    => $s['payout'],
        'profit'          => $s['payout'] - $s['cost'],
        'roi'             => $s['cost'] > 0 ? round($s['payout'] / $s['cost'] * 100, 1) : 0,
    ];
}

echo json_encode([
    'simulation_days' => $days,
    'date_from'       => date('Y-m-d', strtotime("-{$days} days")),
    'date_to'         => date('Y-m-d'),
    'v2_cutover'      => $V2_CUTOVER,
    'note'            => 'DB書き込みなし / 読み取り専用。Part1=実運用予測での25倍上限診断(期間比較)、Part2=v2順位での代替フィルタ比較。払戻はrace_payouts優先(なければ直前オッズ×100)',
    'part1_diagnosis' => $diag,
    'part2_variants'  => ['v2_races' => $v2_races, 'results' => $variant_out],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
