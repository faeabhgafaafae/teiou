<?php
/**
 * 戦略別買い目生成
 * predict.php から require_once して使う。単独呼び出し（?race_id=xxx）も可。
 *
 * 的中特化: 上位3艇の全順列（最大6点）
 * バランス: 上位2艇を1着に各固定 × 上位4艇の2,3着総流し（最大12点）
 * 一撃重視: 1位固定 × 4〜6位の2,3着総流し（最大6点）
 * 絞り込み: 上位3艇を枠番の若い順に並べた1点買い
 */

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
    $stmt = $pdo->prepare('
        SELECT p.predicted_rank, MIN(e.lane) as lane
        FROM predictions p
        JOIN entries e ON e.race_id = p.race_id AND e.player_id = p.player_id
        WHERE p.race_id = ?
        GROUP BY p.player_id, p.predicted_rank
        ORDER BY p.predicted_rank ASC
    ');
    $stmt->execute([$race_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) < 3) return [];

    $lanes = array_map('intval', array_column($rows, 'lane'));
    $n     = count($lanes);
    $strats = [];

    // 的中特化: 上位3艇の全順列（最大6点）
    $strats['的中特化'] = _strat_permutations(array_slice($lanes, 0, 3));

    // バランス: 上位2艇を1着に各固定、上位4艇から2,3着総流し（最大12点）
    $top4 = array_slice($lanes, 0, min(4, $n));
    $combos_b = [];
    foreach (array_slice($top4, 0, 2) as $first) {
        $rest = array_values(array_filter($top4, function($l) use ($first) { return $l !== $first; }));
        foreach ($rest as $sec) {
            foreach ($rest as $thi) {
                if ($sec !== $thi) {
                    $combos_b[] = $first . '-' . $sec . '-' . $thi;
                }
            }
        }
    }
    $strats['バランス'] = $combos_b;

    // 一撃重視: 1位固定、4〜6位から2,3着（最大6点）
    $combos_i = [];
    if ($n >= 4) {
        $first  = $lanes[0];
        $bottom = array_slice($lanes, 3);
        foreach ($bottom as $sec) {
            foreach ($bottom as $thi) {
                if ($sec !== $thi) {
                    $combos_i[] = $first . '-' . $sec . '-' . $thi;
                }
            }
        }
    }
    $strats['一撃重視'] = $combos_i;

    // 絞り込み: 上位3艇(選定はpredicted_rank基準のまま)を枠番の若い順に並べた1点のみ。
    // 2026-07シミュレーションで、同じ上位3艇でもpredicted_rank順より枠番順の方が
    // 3着までの的中率が高いことが確認されたため、順序決定ロジックを枠番ベースに変更。
    $shiborikomi_lanes = array_slice($lanes, 0, 3);
    sort($shiborikomi_lanes);
    $strats['絞り込み'] = [$shiborikomi_lanes[0] . '-' . $shiborikomi_lanes[1] . '-' . $shiborikomi_lanes[2]];

    // DB保存（ON DUPLICATE KEY UPDATE で冪等）
    $upsert = $pdo->prepare('
        INSERT INTO strategies (race_id, strategy_type, combinations)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE combinations = VALUES(combinations)
    ');

    $saved = [];
    foreach ($strats as $type => $combos) {
        $upsert->execute([$race_id, $type, json_encode($combos, JSON_UNESCAPED_UNICODE)]);
        $saved[] = [
            'strategy_type' => $type,
            'combo_count'   => count($combos),
            'combinations'  => $combos,
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
