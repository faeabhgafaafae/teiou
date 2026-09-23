<?php
/**
 * 戦略別買い目生成
 * predict.php から require_once して使う。単独呼び出し（?race_id=xxx）も可。
 *
 * 的中特化: 上位4艇の3連単24通りからHarville確率上位9点
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
 * 2026-09-17: 的中特化をHV6点→HV9点に拡大(design_v3_improvement_20260917.md参照)。
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

// ── ハイブリッド構成: 戦略ごとの参照予測モデル ──────────────────────────
// 'v2'  = predictions テーブル(本番ロジスティック回帰。api_predict.php が書き込み)
// 'v3w' = predictions_v2 テーブル(重み付き学習 v3w シャドウ。api_v3_shadow.php が書き込み)
//
// 2026-09-27 の v3w 昇格判定に向けた事前準備(design_shibori_diagnosis_20260919.md の総括)。
// 現時点は全戦略 'v2' で、参照テーブル・買い目・傾斜配分とも現行から一切変わらない。
// 判定後に「的中特化だけを v3w へ切り替える」場合は、下の '的中特化' の値を 'v3w' に
// 変更するだけでよい(この定数1行の変更のみで参照先が切り替わる)。
// 指定モデルの予測が存在しないレースは自動的に STRATEGY_MODEL_FALLBACK へフォールバックする。
const STRATEGY_MODEL_FALLBACK = 'v2';
const STRATEGY_MODEL_MAP = [
    '的中特化' => 'v2',
    'バランス' => 'v2',
    '一撃重視' => 'v2',
    '絞り込み' => 'v2',
];

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

/**
 * 戦略選定+オッズフィルタ+傾斜配分の純粋ロジック(DB非依存)。
 * generate_and_save_strategies() のDB取得結果($lanes/$prob_map/$prob_ok/$odds_map)から
 * 戦略種別ごとの combinations/stakes/stake_scheme/total_cost を計算して返す。
 *
 * @param array $lanes    predicted_rank昇順の枠番配列(先頭が1位予測)
 * @param array $prob_map lane => v2の1着確率(win_probability)
 * @param bool  $prob_ok  $prob_map が全艇分・合計ほぼ1で有効かどうか
 * @param array $odds_map combo('a-b-c') => 3連単オッズ
 * @return array strategy_type => ['combinations'=>, 'stakes'=>, 'stake_scheme'=>, 'total_cost'=>]
 */
function build_strategies(array $lanes, array $prob_map, bool $prob_ok, array $odds_map): array {
    $n = count($lanes);

    $strats = [];

    // 的中特化: 上位4艇の3連単24通りからHarville確率上位9点（オッズフィルタなし）
    // v2確率が使えない場合は従来の上位3艇全順列（最大6点）
    if ($prob_ok && $n >= 4) {
        $strats['的中特化'] = _strat_top_harville($prob_map, array_slice($lanes, 0, 4), 9);
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

    $result = [];
    foreach ($strats as $type => $combos) {
        $stakes = $stakes_map[$type];
        $result[$type] = [
            'combinations' => $combos,
            'stakes'       => $stakes,
            'stake_scheme' => $scheme_map[$type],
            'total_cost'   => $stakes !== null ? array_sum($stakes) : count($combos) * 100,
        ];
    }
    return $result;
}

/**
 * ハイブリッド構成: 戦略ごとに異なる予測モデルを参照してビルドする。
 *
 * 内部では参照モデルごとに build_strategies() を1回だけ呼び(結果をキャッシュ)、
 * $model_map の割り当てに従って戦略を選び取る。よって全戦略が同一モデルを指す
 * 現行設定では build_strategies() 単独呼び出しと combinations/stakes が完全に一致し、
 * 差分は各戦略への 'model_ref'(実際に使用したモデル名)キーの付与のみ。
 *
 * 指定モデルの予測データ($models[$m])が無い/不足するレースでは $fallback のモデルへ
 * 自動フォールバックする(移行期に v3w 予測が未生成のレースでも戦略を落とさない)。
 *
 * @param array  $models    model名 => ['lanes'=>, 'prob_map'=>, 'prob_ok'=>] (nullや未設定=データ無し)
 * @param array  $odds_map  combo('a-b-c') => 3連単オッズ
 * @param array  $model_map strategy_type => 参照モデル名(既定 STRATEGY_MODEL_MAP)
 * @param string $fallback  参照モデル欠損時のフォールバック先(既定 STRATEGY_MODEL_FALLBACK)
 * @return array strategy_type => ['combinations'=>, 'stakes'=>, 'stake_scheme'=>, 'total_cost'=>, 'model_ref'=>]
 */
function build_strategies_hybrid(array $models, array $odds_map,
                                 array $model_map = STRATEGY_MODEL_MAP,
                                 string $fallback = STRATEGY_MODEL_FALLBACK): array {
    $cache = [];
    $build_for = function(string $m) use (&$cache, $models, $odds_map) {
        if (array_key_exists($m, $cache)) return $cache[$m];
        if (!isset($models[$m]) || $models[$m] === null) return $cache[$m] = null;
        $d = $models[$m];
        return $cache[$m] = build_strategies($d['lanes'], $d['prob_map'], $d['prob_ok'], $odds_map);
    };

    $result = [];
    foreach ($model_map as $type => $m) {
        $built = $build_for($m);
        $used  = $m;
        if ($built === null || !isset($built[$type])) {   // 指定モデル欠損 → フォールバック
            $built = $build_for($fallback);
            $used  = $fallback;
        }
        if ($built === null || !isset($built[$type])) continue;
        $entry = $built[$type];
        $entry['model_ref'] = $used;
        $result[$type] = $entry;
    }
    return $result;
}

/**
 * 指定モデルの予測を読み込み ['lanes'=>, 'prob_map'=>, 'prob_ok'=>] を返す。
 * 予測が無い(3艇未満)場合や参照テーブルが存在しない場合は null。
 *
 * 'v2'  : predictions テーブル。score_total は v2昇格(2026-08-27)後 win_probability×100。
 *         全艇 model_version='v2_lr' かつ確率合計≒1 のときのみ prob_ok=true。
 * 'v3w' : predictions_v2 テーブル(シャドウ)。win_probability は 0〜1 で保存済み。
 */
function _load_model_predictions(PDO $pdo, int $race_id, string $model): ?array {
    if ($model === 'v3w') {
        try {
            $stmt = $pdo->prepare('
                SELECT p.predicted_rank, MIN(e.lane) AS lane,
                       MAX(p.win_probability) AS win_probability
                FROM predictions_v2 p
                JOIN entries e ON e.race_id = p.race_id AND e.player_id = p.player_id
                WHERE p.race_id = ?
                GROUP BY p.player_id, p.predicted_rank
                ORDER BY p.predicted_rank ASC
            ');
            $stmt->execute([$race_id]);
        } catch (PDOException $e) {
            return null; // predictions_v2 未存在など
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) < 3) return null;

        $lanes    = array_map('intval', array_column($rows, 'lane'));
        $n        = count($lanes);
        $prob_map = [];
        $prob_ok  = true;
        foreach ($rows as $row) {
            if ($row['win_probability'] === null) { $prob_ok = false; break; }
            $prob_map[(int)$row['lane']] = (float)$row['win_probability'];
        }
        if ($prob_ok) {
            $psum    = array_sum($prob_map);
            $prob_ok = count($prob_map) === $n && $psum > 0.8 && $psum < 1.2;
        }
        return ['lanes' => $lanes, 'prob_map' => $prob_map, 'prob_ok' => $prob_ok];
    }

    // 'v2' (既定): 本番 predictions テーブル
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
    if (count($rows) < 3) return null;

    $lanes    = array_map('intval', array_column($rows, 'lane'));
    $n        = count($lanes);
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
        $psum    = array_sum($prob_map);
        $prob_ok = count($prob_map) === $n && $psum > 0.8 && $psum < 1.2;
    }
    return ['lanes' => $lanes, 'prob_map' => $prob_map, 'prob_ok' => $prob_ok];
}

function generate_and_save_strategies(PDO $pdo, int $race_id): array {
    // STRATEGY_MODEL_MAP が参照する全モデル(+フォールバック)の予測をロードする。
    // 現行は全戦略 'v2' のため predictions のみを1回読む(predictions_v2 は参照しない)。
    $referenced = array_values(array_unique(
        array_merge(array_values(STRATEGY_MODEL_MAP), [STRATEGY_MODEL_FALLBACK])
    ));
    $models = [];
    foreach ($referenced as $m) {
        $models[$m] = _load_model_predictions($pdo, $race_id, $m);
    }
    // フォールバックモデルの予測すら無ければ生成不可(現行同様に空返し)
    if (($models[STRATEGY_MODEL_FALLBACK] ?? null) === null) return [];

    // 3連単オッズを取得（フィルタ用。未取得の場合はフィルタをスキップ）
    $odds_map = [];
    try {
        $os = $pdo->prepare('SELECT combo, odds FROM odds_3t WHERE race_id = ?');
        $os->execute([$race_id]);
        foreach ($os->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $odds_map[$o['combo']] = (float)$o['odds'];
        }
    } catch (PDOException $e) { /* odds未取得時は全組み合わせを許容 */ }

    $built = build_strategies_hybrid($models, $odds_map);

    // stakes/stake_scheme/model_ref カラムの存在確認・追加 (api_predict.phpと同方式。
    // error 1060 = カラム既存 は正常ケース)。利用可能なカラムのみを動的に保存する。
    $add_col = function(string $ddl) use ($pdo): bool {
        try { $pdo->exec($ddl); return true; }
        catch (PDOException $e) { return ((int)$e->errorInfo[1] === 1060); }
    };
    $has_stakes     = $add_col("ALTER TABLE strategies ADD COLUMN stakes JSON DEFAULT NULL");
    $has_stake_cols = $has_stakes && $add_col("ALTER TABLE strategies ADD COLUMN stake_scheme VARCHAR(10) DEFAULT NULL");
    $has_model_col  = $add_col("ALTER TABLE strategies ADD COLUMN model_ref VARCHAR(10) DEFAULT NULL");

    // 保存カラムを動的に組み立て(ON DUPLICATE KEY UPDATE で冪等)
    $cols = ['race_id', 'strategy_type', 'combinations'];
    if ($has_stake_cols) { $cols[] = 'stakes'; $cols[] = 'stake_scheme'; }
    if ($has_model_col)  { $cols[] = 'model_ref'; }
    $ph  = implode(', ', array_fill(0, count($cols), '?'));
    $upd = [];
    foreach ($cols as $c) {
        if ($c !== 'race_id' && $c !== 'strategy_type') $upd[] = "$c = VALUES($c)";
    }
    $upsert = $pdo->prepare(
        'INSERT INTO strategies (' . implode(', ', $cols) . ') VALUES (' . $ph . ')' .
        ' ON DUPLICATE KEY UPDATE ' . implode(', ', $upd)
    );

    $saved = [];
    foreach ($built as $type => $b) {
        $combos    = $b['combinations'];
        $stakes    = $b['stakes'];
        $model_ref = $b['model_ref'] ?? STRATEGY_MODEL_FALLBACK;

        $vals = [$race_id, $type, json_encode($combos, JSON_UNESCAPED_UNICODE)];
        if ($has_stake_cols) {
            $vals[] = $stakes !== null ? json_encode($stakes) : null;
            $vals[] = $b['stake_scheme'];
        }
        if ($has_model_col) {
            $vals[] = $model_ref;
        }
        $upsert->execute($vals);

        $saved[] = [
            'strategy_type' => $type,
            'combo_count'   => count($combos),
            'combinations'  => $combos,
            'stakes'        => $stakes,
            'stake_scheme'  => $b['stake_scheme'],
            'model_ref'     => $model_ref,
            'total_cost'    => $b['total_cost'],
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
