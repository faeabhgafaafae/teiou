<?php
/**
 * 一撃重視戦略シミュレーター (読み取り専用 / DB書き込みなし)
 *
 * 直近30日の実データを使い、以下を比較:
 *   A) ICHIGEKI_MIN_ODDS 閾値: 10/15/20/25/30倍
 *   B) 選定プール: 4-6位(現行) / 3-5位 / 5-6位 / 2-4位
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

$days = max(1, min(90, (int)($_GET['days'] ?? 30)));

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

// ── 対象レース取得: 予測・結果・オッズが全て揃っているもの ──────────
$stmt = $pdo->prepare('
    SELECT DISTINCT r.id AS race_id, r.date
    FROM races r
    JOIN predictions p  ON p.race_id = r.id
    JOIN results res1   ON res1.race_id = r.id AND res1.actual_rank = 1
    JOIN results res2   ON res2.race_id = r.id AND res2.actual_rank = 2
    JOIN results res3   ON res3.race_id = r.id AND res3.actual_rank = 3
    JOIN odds_3t  o     ON o.race_id    = r.id
    WHERE r.date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    ORDER BY r.date DESC
');
$stmt->execute([$days]);
$races = $stmt->fetchAll();

if (!$races) {
    echo json_encode(['error' => '対象レースなし', 'days' => $days], JSON_UNESCAPED_UNICODE);
    exit;
}

$race_ids = array_column($races, 'race_id');
$ph       = implode(',', array_fill(0, count($race_ids), '?'));

// ── 予測ランク→艇番マップ (bulk) ──────────────────────────────────
$stmt = $pdo->prepare("
    SELECT p.race_id, p.predicted_rank, MIN(e.lane) AS lane
    FROM predictions p
    JOIN entries e ON e.race_id = p.race_id AND e.player_id = p.player_id
    WHERE p.race_id IN ($ph)
    GROUP BY p.race_id, p.predicted_rank
    ORDER BY p.race_id, p.predicted_rank ASC
");
$stmt->execute($race_ids);
$pred_rows = $stmt->fetchAll();

$pred_map = []; // race_id => [lane_rank1, lane_rank2, ..., lane_rank6]
foreach ($pred_rows as $row) {
    $rid  = (int)$row['race_id'];
    $rank = (int)$row['predicted_rank'];
    $pred_map[$rid][$rank] = (int)$row['lane'];
}
// rank順に整列して0-indexedの配列に変換
foreach ($pred_map as $rid => &$rankLaneMap) {
    ksort($rankLaneMap);
    $rankLaneMap = array_values($rankLaneMap); // [0]=1位lane, [1]=2位lane, ...
}
unset($rankLaneMap);

// ── 実際の着順取得 (bulk) ─────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT race_id, actual_rank, lane
    FROM results
    WHERE race_id IN ($ph) AND actual_rank IN (1,2,3)
");
$stmt->execute($race_ids);
$result_rows = $stmt->fetchAll();

$result_map = []; // race_id => [1=>lane, 2=>lane, 3=>lane]
foreach ($result_rows as $row) {
    $result_map[(int)$row['race_id']][(int)$row['actual_rank']] = (int)$row['lane'];
}

// ── オッズ取得 (bulk) ──────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT race_id, combo, odds FROM odds_3t WHERE race_id IN ($ph)");
$stmt->execute($race_ids);
$odds_rows = $stmt->fetchAll();

$odds_map = []; // race_id => [combo => odds]
foreach ($odds_rows as $row) {
    $odds_map[(int)$row['race_id']][$row['combo']] = (float)$row['odds'];
}

// ── プール定義 ────────────────────────────────────────────────────
// lanes配列(0-indexed): [0]=1位 [1]=2位 ... [5]=6位
$pools = [
    '4-6位(現行)' => ['first_idx' => 0, 'pool_offset' => 3, 'pool_len' => 3], // bottom=lanes[3..5]
    '3-5位'       => ['first_idx' => 0, 'pool_offset' => 2, 'pool_len' => 3], // bottom=lanes[2..4]
    '5-6位'       => ['first_idx' => 0, 'pool_offset' => 4, 'pool_len' => 2], // bottom=lanes[4..5]
    '2-4位'       => ['first_idx' => 0, 'pool_offset' => 1, 'pool_len' => 3], // bottom=lanes[1..3]
];

// 閾値リスト
$thresholds = [0.0, 10.0, 15.0, 20.0, 25.0, 30.0];

// ── シミュレーション ───────────────────────────────────────────────
// 結果格納: [pool_name][threshold] => {races, combos_total, hits, cost, payout, skipped_by_odds}
$sim = [];
foreach ($pools as $pool_name => $pcfg) {
    foreach ($thresholds as $thr) {
        $sim[$pool_name][(string)$thr] = [
            'races'           => 0,
            'races_with_combo'=> 0, // オッズフィルタ後にコンボが残ったレース数
            'combos_total'    => 0,
            'hits'            => 0,
            'cost'            => 0,
            'payout'          => 0,
        ];
    }
}

$race_count_total = 0;

foreach ($races as $race_info) {
    $rid = (int)$race_info['race_id'];

    if (!isset($pred_map[$rid]) || !isset($result_map[$rid])) continue;

    $lanes = $pred_map[$rid]; // 0-indexed: [0]=1位lane
    $finish = $result_map[$rid]; // [1=>lane, 2=>lane, 3=>lane]
    if (!isset($finish[1], $finish[2], $finish[3])) continue;

    $actual_combo = $finish[1] . '-' . $finish[2] . '-' . $finish[3];
    $race_odds    = $odds_map[$rid] ?? [];
    $has_odds     = !empty($race_odds);

    $race_count_total++;

    foreach ($pools as $pool_name => $pcfg) {
        $first_lane = $lanes[$pcfg['first_idx']] ?? null;
        if ($first_lane === null) continue;

        $pool_lanes = array_slice($lanes, $pcfg['pool_offset'], $pcfg['pool_len']);
        if (count($pool_lanes) < 2) continue;

        foreach ($thresholds as $thr) {
            $thr_key = (string)$thr;
            $sim[$pool_name][$thr_key]['races']++;

            // コンボ生成
            $combos = [];
            foreach ($pool_lanes as $sec) {
                foreach ($pool_lanes as $thi) {
                    if ($sec === $thi) continue;
                    $combo = $first_lane . '-' . $sec . '-' . $thi;
                    // オッズフィルタ
                    if ($has_odds && $thr > 0) {
                        $o = $race_odds[$combo] ?? null;
                        if ($o !== null && $o < $thr) continue;
                    }
                    $combos[] = $combo;
                }
            }

            if (empty($combos)) continue;

            $sim[$pool_name][$thr_key]['races_with_combo']++;
            $sim[$pool_name][$thr_key]['combos_total'] += count($combos);
            $sim[$pool_name][$thr_key]['cost']         += count($combos) * 100;

            if (in_array($actual_combo, $combos, true)) {
                $sim[$pool_name][$thr_key]['hits']++;
                $hit_odds = $race_odds[$actual_combo] ?? null;
                if ($hit_odds !== null) {
                    $sim[$pool_name][$thr_key]['payout'] += (int)floor($hit_odds * 100);
                }
            }
        }
    }
}

// ── 集計 ──────────────────────────────────────────────────────────
$results_out = [];
foreach ($sim as $pool_name => $thr_data) {
    foreach ($thr_data as $thr_key => $d) {
        $races  = $d['races'];
        $rwc    = $d['races_with_combo'];
        $hits   = $d['hits'];
        $cost   = $d['cost'];
        $payout = $d['payout'];
        $profit = $payout - $cost;

        // 的中率: コンボが残ったレース中の的中率
        $hit_rate_of_active = $rwc > 0 ? round($hits / $rwc * 100, 1) : 0;
        // 的中率(全レース基準): 予測できた全レース中
        $hit_rate_overall   = $races > 0 ? round($hits / $races * 100, 1) : 0;
        // 回収率
        $roi = $cost > 0 ? round($payout / $cost * 100, 1) : 0;
        // 参加率(オッズフィルタ後にコンボが残ったレースの割合)
        $entry_rate = $races > 0 ? round($rwc / $races * 100, 1) : 0;
        // 平均コンボ数/レース
        $avg_combos = $rwc > 0 ? round($d['combos_total'] / $rwc, 2) : 0;

        $results_out[] = [
            'pool'            => $pool_name,
            'min_odds'        => (float)$thr_key,
            'total_races'     => $races,
            'active_races'    => $rwc,   // コンボ残存レース数
            'entry_rate_pct'  => $entry_rate,
            'hits'            => $hits,
            'hit_rate_active' => $hit_rate_of_active, // 参加レース中の的中率
            'hit_rate_all'    => $hit_rate_overall,   // 全レース中の的中率
            'avg_combos'      => $avg_combos,
            'total_cost'      => $cost,
            'total_payout'    => $payout,
            'profit'          => $profit,
            'roi'             => $roi,
        ];
    }
}

// 現行設定(4-6位 / 10倍)を先頭に
usort($results_out, function($a, $b) {
    $pool_order = ['4-6位(現行)', '3-5位', '5-6位', '2-4位'];
    $pi = array_search($a['pool'], $pool_order);
    $pj = array_search($b['pool'], $pool_order);
    if ($pi !== $pj) return $pi - $pj;
    return $a['min_odds'] - $b['min_odds'];
});

echo json_encode([
    'simulation_days'    => $days,
    'race_count_total'   => $race_count_total,
    'date_from'          => date('Y-m-d', strtotime("-{$days} days")),
    'date_to'            => date('Y-m-d'),
    'note'               => 'DB書き込みなし / 読み取り専用シミュレーション。的中率(active)=コンボが残ったレースのみ分母。ROI=回収率(%)',
    'results'            => $results_out,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
