<?php
/**
 * 戦略別買い目生成
 * predict.php から require_once して使う。単独呼び出し（?race_id=xxx）も可。
 *
 * 的中特化: 上位4艇の3連単24通りからHarville確率上位6点
 *           (v2確率が取れない場合は従来の上位3艇全順列にフォールバック)
 * バランス: 上位2艇を1着に各固定 × 上位4艇の2,3着総流し（最大12点）
 *           オッズ上限フィルタあり: BALANCE_MAX_ODDS 超の組み合わせは除外
 * 一撃重視: 1位固定 × 2〜4位の2,3着総流し（最大6点）
 *           オッズ下限フィルタあり: ICHIGEKI_MIN_ODDS 未満の組み合わせは除外
 * 絞り込み: 上位3艇の全順列6通りからHarville確率上位3点
 *           (v2確率が取れない場合は従来の枠番昇順1点にフォールバック)
 *
 * 2026-09-09: 的中特化・絞り込みをHarville確率ベースの選定に変更。
 * 3期間5,061レースのシミュレーション(sim_stake_allocation.py)で
 *   絞り込み 1点→3点: 的中率 8.5%→16.1% / 回収率 83.0%→84.1%
 *   的中特化 box6→HV6: 的中率 21.4%→25.9% / 回収率 77.0%→79.5%
 * v2確率は predictions.score_total (v2昇格後は win_probability×100) を使用。
 *
 * 2026-09-09: 賭け金傾斜配分を導入(STAKE_SCHEME/STAKE_UNIT参照)。
 * 各買い目の金額を strategies.stakes (JSON、combinationsと同順) に保存し、
 * 清算(import_results.php/backfill_payout.php)がstake単位で計算する。
 * stakes=NULL の行(過去分・フォールバック時)は従来の1点100円均等として扱う。
 */

// オッズフィルタ閾値（必要に応じて調整）
// 2026-09-03: バランス上限を25→100倍に変更。25倍上限はv2順位の中穴的中(25-100倍帯)を
// 捨てて回収率を毀損していた(balance_simulation_report_20260903.md参照。
// シム上 的中率30.6%→40.8% / 回収率79.1%→82.8%)。100倍は万舟テールガードとして残す。
const BALANCE_MAX_ODDS  = 100.0;  // バランス: これを超える3連単オッズは除外
const ICHIGEKI_MIN_ODDS = 15.0;  // 一撃重視: これを下回る3連単オッズは除外

// 賭け金傾斜配分 (2026-09-09導入。sim_budget_sweep.py で検証)
// STAKE_SCHEME: 'flat'=均等100円(傾斜なし) / 'prob'=Harville確率比例 /
//               'kelly'=ケリー比率 / 'inv_odds'=オッズ逆数
// probは全戦略で回収率+0.2〜2.3pt・期間ブレ小。kellyはバランス等で
// 伸びが大きい(+5.8pt)が2週間窓単位のブレが大きいため不採用(切替可)。
const STAKE_SCHEME = 'prob';
const STAKE_UNIT   = 600;   // 1点あたり予算(円)。シムで600円前後から改善が頭打ち
const STAKE_MIN    = 100;   // 全買い目への最低保証(円)。的中率を落とさないため

/**
 * 3連単 a-b-c のHarville近似確率。
 * $prob は lane => 1着確率(レース内合計≒1)のマップ。
 */
function _strat_harville(array $prob, int $a, int $b, int $c): float {
    $pa = $prob[$a] ?? 0.0;
    $pb = $prob[$b] ?? 0.0;
    $pc = $prob[$c] ?? 0.0;
    $d1 = 1.0 - $pa;
    $d2 = 1.0 - $pa - $pb;
    if ($d1 <= 1e-9 || $d2 <= 1e-9) return 0.0;
    return $pa * ($pb / $d1) * ($pc / $d2);
}

/**
 * $pool のレーンから作れる3連単全通りをHarville確率の高い順に $limit 点返す。
 */
function _strat_top_harville(array $prob, array $pool, int $limit): array {
    $cand = [];
    foreach ($pool as $a) {
        foreach ($pool as $b) {
            foreach ($pool as $c) {
                if ($a !== $b && $a !== $c && $b !== $c) {
                    $cand["$a-$b-$c"] = _strat_harville($prob, $a, $b, $c);
                }
            }
        }
    }
    arsort($cand);
    return array_slice(array_keys($cand), 0, $limit);
}

/**
 * 配分方式ごとの買い目重みを返す(combosと同順)。
 */
function _strat_stake_weights(string $scheme, array $combos, array $prob, array $odds_map): array {
    $ws = [];
    foreach ($combos as $c) {
        [$a, $b, $cc] = array_map('intval', explode('-', $c));
        $p = _strat_harville($prob, $a, $b, $cc);
        $o = $odds_map[$c] ?? null;
        switch ($scheme) {
            case 'prob':
                $ws[] = $p;
                break;
            case 'inv_odds':
                $ws[] = ($o !== null && $o > 0) ? 1.0 / $o : 0.0;
                break;
            case 'kelly':
                if ($o !== null && $o > 1.0) {
                    $bn   = $o - 1.0;
                    $ws[] = max(0.0, ($p * $bn - (1.0 - $p)) / $bn);
                } else {
                    $ws[] = 0.0;
                }
                break;
            default: // flat
                $ws[] = 1.0;
        }
    }
    return $ws;
}

/**
 * 予算(円)を100円単位で配分する。全買い目にSTAKE_MINを保証した上で、
 * 残りを重み比例(largest remainder法)で傾斜配分。combosと同順の配列を返す。
 */
function _strat_allocate(array $combos, array $ws, int $budget): array {
    $n = count($combos);
    if ($n === 0) return [];
    $stakes       = array_fill(0, $n, STAKE_MIN);
    $remain_units = intdiv($budget - $n * STAKE_MIN, 100);
    if ($remain_units > 0) {
        $wsum = array_sum($ws);
        if ($wsum <= 1e-12) {
            $ws   = array_fill(0, $n, 1.0);
            $wsum = (float)$n;
        }
        $ideal = [];
        $base  = [];
        $used  = 0;
        foreach ($ws as $i => $w) {
            $x         = $w / $wsum * $remain_units;
            $ideal[$i] = $x;
            $base[$i]  = (int)floor($x);
            $used     += $base[$i];
        }
        $left  = $remain_units - $used;
        $order = array_keys($ideal);
        usort($order, function($a, $b) use ($ideal, $base) {
            return ($ideal[$b] - $base[$b]) <=> ($ideal[$a] - $base[$a]);
        });
        for ($i = 0; $i < $left; $i++) {
            $base[$order[$i]]++;
        }
        foreach ($base as $i => $u) {
            $stakes[$i] += $u * 100;
        }
    }
    return $stakes;
}

function _strat_permutations(array $arr) {
    if (count($arr) <= 1) return [implode('-', $arr)];
    $result = [];
    $n = count($arr);
    for ($i = 0; $i < $n; $i++) {
        $item = $arr[$i];
        $rest = array_merge(array_slice($arr, 0, $i), array_slice($arr, $i + 1));
        foreach (_strat_permutations($rest) as $perm) {
            $result[] = $item . '-' . $perm;
        }
    }
    return $result;
}

function generate_and_save_strategies(PDO $pdo, int $race_id): array {
    // score_total は v2昇格(2026-08-27)後は win_probability×100 が入る
    // (api_predict.php参照)。model_version='v2_lr' で判別する。
    try {
        $stmt = $pdo->prepare('
            SELECT p.predicted_rank, MIN(e.lane) as lane,
                   MAX(p.score_total) AS score_total,
                   MAX(p.model_version) AS model_version
            FROM predictions p
            JOIN entries e ON e.race_id = p.race_id AND e.player_id = p.player_id
            WHERE p.race_id = ?
            GROUP BY p.player_id, p.predicted_rank
            ORDER BY p.predicted_rank ASC
        ');
        $stmt->execute([$race_id]);
    } catch (PDOException $e) {
        // model_version カラム未追加の環境向けフォールバック
        $stmt = $pdo->prepare('
            SELECT p.predicted_rank, MIN(e.lane) as lane,
                   MAX(p.score_total) AS score_total,
                   NULL AS model_version
            FROM predictions p
            JOIN entries e ON e.race_id = p.race_id AND e.player_id = p.player_id
            WHERE p.race_id = ?
            GROUP BY p.player_id, p.predicted_rank
            ORDER BY p.predicted_rank ASC
        ');
        $stmt->execute([$race_id]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) < 3) return [];

    $lanes = array_map('intval', array_column($rows, 'lane'));
    $n     = count($lanes);

    // v2の1着確率マップ(lane => probability)。全艇がv2予測で、確率の
    // 合計がほぼ1のときのみ有効(v1スコアが混在した場合はフォールバック)。
    $prob_map = [];
    $prob_ok  = true;
    foreach ($rows as $row) {
        if (($row['model_version'] ?? null) !== 'v2_lr' || $row['score_total'] === null) {
            $prob_ok = false;
            break;
        }
        $prob_map[(int)$row['lane']] = (float)$row['score_total'] / 100.0;
    }
    if ($prob_ok) {
        $psum = array_sum($prob_map);
        $prob_ok = count($prob_map) === $n && $psum > 0.8 && $psum < 1.2;
    }

    // 3連単オッズを取得（フィルタ用。未取得の場合はフィルタをスキップ）
    $odds_map = [];
    try {
        $os = $pdo->prepare('SELECT combo, odds FROM odds_3t WHERE race_id = ?');
        $os->execute([$race_id]);
        foreach ($os->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $odds_map[$o['combo']] = (float)$o['odds'];
        }
    } catch (PDOException $e) { /* odds未取得時は全組み合わせを許容 */ }

    $strats = [];

    // 的中特化: 上位4艇の3連単24通りからHarville確率上位6点（オッズフィルタなし）
    // v2確率が使えない場合は従来の上位3艇全順列（最大6点）
    if ($prob_ok && $n >= 4) {
        $strats['的中特化'] = _strat_top_harville($prob_map, array_slice($lanes, 0, 4), 6);
    } else {
        $strats['的中特化'] = _strat_permutations(array_slice($lanes, 0, 3));
    }

    // バランス: 上位2艇を1着に各固定、上位4艇から2,3着総流し（最大12点）
    // BALANCE_MAX_ODDS 超のオッズ組み合わせは除外（オッズデータがある場合のみ適用）
    $top4 = array_slice($lanes, 0, min(4, $n));
    $combos_b = [];
    foreach (array_slice($top4, 0, 2) as $first) {
        $rest = array_values(array_filter($top4, function($l) use ($first) { return $l !== $first; }));
        foreach ($rest as $sec) {
            foreach ($rest as $thi) {
                if ($sec !== $thi) {
                    $combo = $first . '-' . $sec . '-' . $thi;
                    if ($odds_map && isset($odds_map[$combo]) && $odds_map[$combo] > BALANCE_MAX_ODDS) {
                        continue;
                    }
                    $combos_b[] = $combo;
                }
            }
        }
    }
    $strats['バランス'] = $combos_b;

    // 一撃重視: 1位固定、2〜4位から2,3着（最大6点）
    // ICHIGEKI_MIN_ODDS 未満のオッズ組み合わせは除外（オッズデータがある場合のみ適用）
    $combos_i = [];
    if ($n >= 4) {
        $first  = $lanes[0];
        $bottom = array_slice($lanes, 1, 3);
        foreach ($bottom as $sec) {
            foreach ($bottom as $thi) {
                if ($sec !== $thi) {
                    $combo = $first . '-' . $sec . '-' . $thi;
                    if ($odds_map && isset($odds_map[$combo]) && $odds_map[$combo] < ICHIGEKI_MIN_ODDS) {
                        continue;
                    }
                    $combos_i[] = $combo;
                }
            }
        }
    }
    $strats['一撃重視'] = $combos_i;

    // 絞り込み: 上位3艇の全順列6通りからHarville確率上位3点。
    // v2確率が使えない場合は従来の枠番昇順1点にフォールバック
    // (枠番順は2026-07シミュレーションでpredicted_rank順より的中率が高かった順序)。
    if ($prob_ok) {
        $strats['絞り込み'] = _strat_top_harville($prob_map, array_slice($lanes, 0, 3), 3);
    } else {
        $shiborikomi_lanes = array_slice($lanes, 0, 3);
        sort($shiborikomi_lanes);
        $strats['絞り込み'] = [$shiborikomi_lanes[0] . '-' . $shiborikomi_lanes[1] . '-' . $shiborikomi_lanes[2]];
    }

    // 賭け金傾斜配分: v2確率が有効かつ傾斜方式のときのみ計算。
    // 無効時は stakes=null (清算側で1点100円均等として扱う)
    $stakes_map = [];
    $scheme_map = [];
    foreach ($strats as $type => $combos) {
        if (STAKE_SCHEME !== 'flat' && $prob_ok && count($combos) > 0) {
            $ws                = _strat_stake_weights(STAKE_SCHEME, $combos, $prob_map, $odds_map);
            $stakes_map[$type] = _strat_allocate($combos, $ws, count($combos) * STAKE_UNIT);
            $scheme_map[$type] = STAKE_SCHEME;
        } else {
            $stakes_map[$type] = null;
            $scheme_map[$type] = 'flat';
        }
    }

    // stakes/stake_scheme カラムの存在確認・追加 (api_predict.phpと同方式。
    // error 1060 = カラム既存 は正常ケース)
    $has_stake_cols = true;
    try {
        $pdo->exec("ALTER TABLE strategies ADD COLUMN stakes JSON DEFAULT NULL");
    } catch (PDOException $e) {
        $has_stake_cols = ((int)$e->errorInfo[1] === 1060);
    }
    if ($has_stake_cols) {
        try {
            $pdo->exec("ALTER TABLE strategies ADD COLUMN stake_scheme VARCHAR(10) DEFAULT NULL");
        } catch (PDOException $e) {
            $has_stake_cols = ((int)$e->errorInfo[1] === 1060);
        }
    }

    // DB保存（ON DUPLICATE KEY UPDATE で冪等）
    if ($has_stake_cols) {
        $upsert = $pdo->prepare('
            INSERT INTO strategies (race_id, strategy_type, combinations, stakes, stake_scheme)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                combinations = VALUES(combinations),
                stakes       = VALUES(stakes),
                stake_scheme = VALUES(stake_scheme)
        ');
    } else {
        $upsert = $pdo->prepare('
            INSERT INTO strategies (race_id, strategy_type, combinations)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE combinations = VALUES(combinations)
        ');
    }

    $saved = [];
    foreach ($strats as $type => $combos) {
        $stakes = $stakes_map[$type];
        if ($has_stake_cols) {
            $upsert->execute([
                $race_id, $type,
                json_encode($combos, JSON_UNESCAPED_UNICODE),
                $stakes !== null ? json_encode($stakes) : null,
                $scheme_map[$type],
            ]);
        } else {
            $upsert->execute([$race_id, $type, json_encode($combos, JSON_UNESCAPED_UNICODE)]);
        }
        $saved[] = [
            'strategy_type' => $type,
            'combo_count'   => count($combos),
            'combinations'  => $combos,
            'stakes'        => $stakes,
            'stake_scheme'  => $scheme_map[$type],
            'total_cost'    => $stakes !== null ? array_sum($stakes) : count($combos) * 100,
        ];
    }

    return $saved;
}

// ─── 単独エンドポイントとして呼ばれた場合 ─────────────────────
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    require_once __DIR__ . '/auth.php';
    header('Content-Type: application/json; charset=utf-8');

    $race_id = (int)($_GET['race_id'] ?? 0);
    if (!$race_id) {
        http_response_code(400);
        echo json_encode(['error' => 'race_id は必須です'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = get_db();
    $strategies = generate_and_save_strategies($pdo, $race_id);
    echo json_encode(['race_id' => $race_id, 'strategies' => $strategies], JSON_UNESCAPED_UNICODE);
}
