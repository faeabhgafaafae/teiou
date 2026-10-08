<?php
/**
 * モデル運用状況の集計(読み取り専用)。model_switch_status.php(JSON API)と admin.php(管理画面)で共用する。
 * いずれも日付 => 集計値 の配列を返す。SQL は MySQL / SQLite 共通の構文のみ(PHPUnit で SQLite を使うため)。
 */

/** 日別: レース数・予測保存レース数(v2=predictions / v3w=predictions_v2)・結果確定レース数 */
function ms_prediction_coverage(PDO $pdo, string $from, string $to): array {
    $stmt = $pdo->prepare("
        SELECT r.date,
               COUNT(*)                     AS races,
               SUM(v2.race_id IS NOT NULL)  AS races_with_v2,
               SUM(v3.race_id IS NOT NULL)  AS races_with_v3w,
               SUM(res.race_id IS NOT NULL) AS races_settled
        FROM races r
        LEFT JOIN (SELECT DISTINCT race_id FROM predictions)    v2  ON v2.race_id  = r.id
        LEFT JOIN (SELECT DISTINCT race_id FROM predictions_v2) v3  ON v3.race_id  = r.id
        LEFT JOIN (SELECT DISTINCT race_id FROM results WHERE actual_rank IS NOT NULL) res ON res.race_id = r.id
        WHERE r.date BETWEEN ? AND ?
        GROUP BY r.date ORDER BY r.date
    ");
    $stmt->execute([$from, $to]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $d = $row['date'];
        unset($row['date']);
        $out[$d] = array_map('intval', $row);
    }
    return $out;
}

/** 日別 × 戦略 × model_ref: strategies の件数 */
function ms_strategy_counts(PDO $pdo, string $from, string $to): array {
    $stmt = $pdo->prepare("
        SELECT r.date, s.strategy_type, COALESCE(s.model_ref, 'NULL') AS model_ref, COUNT(*) AS n
        FROM strategies s JOIN races r ON r.id = s.race_id
        WHERE r.date BETWEEN ? AND ?
        GROUP BY r.date, s.strategy_type, s.model_ref
    ");
    $stmt->execute([$from, $to]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['date']][$row['strategy_type']][$row['model_ref']] = (int)$row['n'];
    }
    return $out;
}

/** 日別 × 戦略 × model_ref: strategy_results の件数・的中・投資額・払戻額・的中率・回収率 */
function ms_strategy_results(PDO $pdo, string $from, string $to): array {
    $stmt = $pdo->prepare("
        SELECT r.date, s.strategy_type, COALESCE(sr.model_ref, 'NULL') AS model_ref,
               COUNT(*) AS n, SUM(sr.is_hit) AS hits, SUM(sr.cost) AS cost, SUM(sr.payout) AS payout
        FROM strategy_results sr
        JOIN strategies s ON s.id = sr.strategy_id
        JOIN races r ON r.id = sr.race_id
        WHERE r.date BETWEEN ? AND ?
        GROUP BY r.date, s.strategy_type, sr.model_ref
    ");
    $stmt->execute([$from, $to]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['date']][$row['strategy_type']][$row['model_ref']] =
            ms_result_kpi((int)$row['n'], (int)$row['hits'], (int)$row['cost'], (int)$row['payout']);
    }
    return $out;
}

/** 件数・的中・投資額・払戻額から的中率・回収率を付けた配列 */
function ms_result_kpi(int $n, int $hits, int $cost, int $payout): array {
    return [
        'n'        => $n,
        'hits'     => $hits,
        'cost'     => $cost,
        'payout'   => $payout,
        'hit_rate' => $n > 0 ? round($hits / $n * 100, 1) : null,
        'roi'      => $cost > 0 ? round($payout / $cost * 100, 1) : null,
    ];
}

/**
 * ms_strategy_results() の日別結果を、戦略 × model_ref で期間合計する。
 * @return array 戦略 => model_ref => ms_result_kpi
 */
function ms_sum_strategy_results(array $daily): array {
    $acc = [];
    foreach ($daily as $types) {
        foreach ($types as $type => $refs) {
            foreach ($refs as $ref => $k) {
                $a = $acc[$type][$ref] ?? [0, 0, 0, 0];
                $acc[$type][$ref] = [$a[0] + $k['n'], $a[1] + $k['hits'], $a[2] + $k['cost'], $a[3] + $k['payout']];
            }
        }
    }
    $out = [];
    foreach ($acc as $type => $refs) {
        foreach ($refs as $ref => [$n, $h, $c, $p]) {
            $out[$type][$ref] = ms_result_kpi($n, $h, $c, $p);
        }
    }
    return $out;
}

/**
 * 日別: 保存済み予測(結果取込み前に保存された値)の1着的中。
 * v2・v3w とも予測があり結果確定済みのレースのみ。同着は枠番の小さい方を勝者とする。
 * @return array date => [races, v2_hits, v3w_hits, lane1_wins, v2_lane1_rank1, v3w_lane1_rank1]
 */
function ms_top1_daily(PDO $pdo, string $from, string $to): array {
    $top = function (string $table): string {
        return "SELECT p.race_id, MIN(e.lane) AS lane
                FROM {$table} p JOIN entries e ON e.race_id = p.race_id AND e.player_id = p.player_id
                WHERE p.predicted_rank = 1 GROUP BY p.race_id";
    };
    $top_v2  = $top('predictions');
    $top_v3w = $top('predictions_v2');
    $stmt = $pdo->prepare("
        SELECT r.date,
               COUNT(*)              AS races,
               SUM(w.lane = p2.lane) AS v2_hits,
               SUM(w.lane = p3.lane) AS v3w_hits,
               SUM(w.lane = 1)       AS lane1_wins,
               SUM(p2.lane = 1)      AS v2_lane1_rank1,
               SUM(p3.lane = 1)      AS v3w_lane1_rank1
        FROM races r
        JOIN (SELECT race_id, MIN(lane) AS lane FROM results WHERE actual_rank = 1 GROUP BY race_id) w ON w.race_id = r.id
        JOIN ({$top_v2})  p2 ON p2.race_id = r.id
        JOIN ({$top_v3w}) p3 ON p3.race_id = r.id
        WHERE r.date BETWEEN ? AND ?
        GROUP BY r.date ORDER BY r.date
    ");
    $stmt->execute([$from, $to]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $d = $row['date'];
        unset($row['date']);
        $out[$d] = array_map('intval', $row);
    }
    return $out;
}

/** ms_top1_daily() の期間合計 */
function ms_sum_top1(array $daily): array {
    $sum = ['races' => 0, 'v2_hits' => 0, 'v3w_hits' => 0, 'lane1_wins' => 0, 'v2_lane1_rank1' => 0, 'v3w_lane1_rank1' => 0];
    foreach ($daily as $t) {
        foreach ($sum as $k => $v) $sum[$k] = $v + ($t[$k] ?? 0);
    }
    return $sum;
}
