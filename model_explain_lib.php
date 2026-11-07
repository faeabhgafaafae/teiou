<?php
/**
 * model_explain_lib.php — 予想の根拠(各艇の評価を上げた要因・下げた要因)(2026-11-07〜)
 *
 * 予想はロジスティック回帰(v3w / v2)なので、各特徴量の寄与 = 係数 × 標準化した値 が、その艇のロジット
 * (1着確率の元になる値)への加算分になる。1着確率はレース内の6艇で正規化するため、全艇に共通の分
 * (風・波など)は順位に影響しない。そこで特徴量をまとめた要因ごとに「レース平均との差」を取り、
 * プラスが大きいものを「評価を上げた要因」、マイナスが大きいものを「評価を下げた要因」とする。
 *
 * 以前の「スコア内訳」(選手能力40点・コース補正35点…)は旧 v1 の手動スコアの配点で、実際の予想とは
 * 計算が別だった(design_site_audit_20261105.md #4, #22)。get_prediction.php(画面)と
 * gemini_explain.php(AI解説のプロンプト)で共用する。
 */
require_once __DIR__ . '/predict_v2_core.php';
require_once __DIR__ . '/predict_v3_core.php';
require_once __DIR__ . '/predict_v3_lib.php';
require_once __DIR__ . '/prediction_guard_lib.php';

// 要因 => [ラベル, 特徴量のインデックス]。レース共通の特徴量(風・波・気温)は差が0なので載せない
const EXPLAIN_GROUPS = [
    'v3w' => [
        // 枠番の有利不利は、枠番そのもの(one-hot)より「その枠番での成績」(枠番ごとの1着率を事前値にした
        // 平滑化)に多く表れるため、2つをまとめて1つの要因にする(分けると枠番だけの小さな残差が出て分かりにくい)
        'course'    => ['枠番(進入コース)・その枠番での成績', [0, 1, 2, 3, 4, 14, 15]],
        'global'    => ['全国勝率',                   [5]],
        'local'     => ['当地の1着率',                [6]],
        'exhibit'   => ['展示タイム(レース内の比較)', [7]],
        'start'     => ['展示のスタート(ST)',         [8]],
        'motor'     => ['モーター2連率(レース内の比較)', [9]],
        'avg_st'    => ['今期の平均ST',               [12, 13]],
        'recent10'  => ['直近10走の成績',             [16, 17, 18]],
        'grade'     => ['級別',                       [19, 20, 21]],
    ],
    'v2' => [
        'lane'      => ['枠番(進入コース)',           [0]],
        'global'    => ['全国勝率・2連率',            [1, 2]],
        'local'     => ['当地成績',                   [3, 4]],
        'exhibit'   => ['展示タイム(レース内の比較)', [5]],
        'start'     => ['展示のスタート(ST)',         [6]],
        'motor'     => ['モーター2連率(レース内の比較)', [7]],
    ],
];
const EXPLAIN_MIN_EFFECT = 0.05;   // これ未満の差は要因として表示しない(ロジット)

/**
 * 寄与(contributions)を要因ごとにまとめ、レース平均との差を返す。
 * @param array $contrib [player_id => float[]](PredictV3/V2::contributions)
 * @return array [player_id => [group => 差]]
 */
function explain_group_effects(string $model, array $contrib): array {
    $groups = EXPLAIN_GROUPS[$model] ?? [];
    $sums = [];
    foreach ($contrib as $pid => $c) {
        foreach ($groups as $g => [$_, $idx]) {
            $sums[$pid][$g] = array_sum(array_map(fn($k) => $c[$k] ?? 0.0, $idx));
        }
    }
    $n = count($sums);
    if ($n === 0) return [];
    $out = [];
    foreach ($groups as $g => $_) {
        $mean = array_sum(array_column($sums, $g)) / $n;
        foreach ($sums as $pid => $s) $out[$pid][$g] = $s[$g] - $mean;
    }
    return $out;
}

/** 要因ごとの説明用の値(画面とプロンプトに出す、元のデータ) */
function explain_detail(string $g, array $e): string {
    $pct = fn($v) => $v === null ? 'データなし' : sprintf('%.0f%%', $v * 100);
    switch ($g) {
        case 'lane':     return $e['lane'] . '号艇';
        case 'global':   return isset($e['global_win_rate']) && $e['global_win_rate'] !== null ? sprintf('勝率 %.2f', $e['global_win_rate']) : 'データなし';
        case 'local':    return '1着率 ' . $pct($e['local_win_rate'] ?? null);
        case 'exhibit':  return $e['exhibit_time'] !== null ? sprintf('%.2f秒', $e['exhibit_time']) : '展示なし';
        case 'start':    return $e['start_timing'] === null ? '展示なし' : ($e['start_timing'] < 0 ? 'F(フライング)' : sprintf('%.2f', $e['start_timing']));
        case 'motor':    return $e['motor_2rate'] !== null ? sprintf('%.1f%%', $e['motor_2rate']) : 'データなし';
        case 'avg_st':   return isset($e['avg_st']) && $e['avg_st'] ? sprintf('%.2f', $e['avg_st']) : 'データなし';
        case 'course':   return $e['lane'] . '号艇' . (isset($e['course_count']) && $e['course_count'] > 0
                            ? sprintf('・この枠番で%d走1着%d回', $e['course_count'], $e['course_rank1']) : '');
        case 'recent10': return isset($e['recent10_avg_rank']) && $e['recent10_avg_rank'] !== null
                            ? sprintf('平均%.1f着', $e['recent10_avg_rank']) : 'データなし';
        case 'grade':    return $e['grade'] ?? '-';
    }
    return '';
}

/**
 * 1レース分の要因。$inputs は score_race() と同じ入力(v3w: build_v3_inputs、v2: explain_v2_inputs)。
 * @return array [player_id => ['up' => [['key','label','effect','detail'], ...], 'down' => [...]]]
 */
function explain_factors(string $model, array $inputs, array $weather, int $top = 3): array {
    $contrib = $model === 'v3w' ? PredictV3::contributions($inputs, $weather) : PredictV2::contributions($inputs, $weather);
    $effects = explain_group_effects($model, $contrib);
    $byPid = [];
    foreach ($inputs as $e) $byPid[(int)$e['player_id']] = $e;
    $out = [];
    foreach ($effects as $pid => $eff) {
        $items = [];
        foreach ($eff as $g => $v) {
            if (abs($v) < EXPLAIN_MIN_EFFECT) continue;
            $items[] = ['key' => $g, 'label' => EXPLAIN_GROUPS[$model][$g][0], 'effect' => round($v, 3),
                        'detail' => explain_detail($g, $byPid[$pid] ?? [])];
        }
        $up = array_values(array_filter($items, fn($i) => $i['effect'] > 0));
        $down = array_values(array_filter($items, fn($i) => $i['effect'] < 0));
        usort($up, fn($a, $b) => $b['effect'] <=> $a['effect']);
        usort($down, fn($a, $b) => $a['effect'] <=> $b['effect']);
        $out[$pid] = ['up' => array_slice($up, 0, $top), 'down' => array_slice($down, 0, $top)];
    }
    return $out;
}

/** 影響の強さ(画面・プロンプト用) */
function explain_strength(float $effect): string {
    $a = abs($effect);
    return $a >= 0.3 ? '大' : ($a >= 0.15 ? '中' : '小');
}

/** v2 の入力(api_predict.php の '_v2' と同じ組み立て) */
function explain_v2_inputs(PDO $pdo, array $entries, string $date, string $venue): array {
    $out = [];
    $st = $pdo->prepare('SELECT win_rate, fukusho_rate FROM player_periods WHERE player_id = ? ORDER BY year DESC, period DESC LIMIT 1');
    foreach ($entries as $e) {
        $pid = (int)$e['player_id'];
        $st->execute([$pid]);
        $p = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $local = fetch_local_stats($pdo, $pid, $venue, $date);
        $out[] = [
            'lane' => (int)$e['lane'], 'player_id' => $pid,
            'exhibit_time' => $e['exhibit_time'] !== null ? (float)$e['exhibit_time'] : null,
            'start_timing' => $e['start_timing'] !== null ? (float)$e['start_timing'] : null,
            'motor_2rate'  => $e['motor_2rate']  !== null ? (float)$e['motor_2rate']  : null,
            'global_win_rate' => (float)($p['win_rate'] ?? 0),
            'global_2rate'    => (float)($p['fukusho_rate'] ?? 0),
            'local_win_rate'  => $local['total'] > 0 ? $local['rank1'] / $local['total'] : null,
            'local_2rate'     => $local['total'] > 0 ? $local['rank2'] / $local['total'] : null,
        ];
    }
    return $out;
}

/**
 * DB から1レース分の要因を求める(表示モデル $model = 'v3w' / 'v2')。
 * 特徴量はレース日より前のデータだけで集計する(予想時と同じ。prediction_guard_lib.php)。
 */
function explain_race(PDO $pdo, int $race_id, string $model): array {
    $st = $pdo->prepare('SELECT date, venue, wind_speed, wave_height, temperature FROM races WHERE id = ?');
    $st->execute([$race_id]);
    $race = $st->fetch(PDO::FETCH_ASSOC);
    if (!$race) return [];
    $st = $pdo->prepare('SELECT lane, player_id, exhibit_time, start_timing, motor_2rate FROM entries WHERE race_id = ? ORDER BY lane');
    $st->execute([$race_id]);
    $entries = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$entries) return [];
    $weather = ['wind_speed' => $race['wind_speed'], 'wave_height' => $race['wave_height'], 'temperature' => $race['temperature']];
    $inputs = $model === 'v3w'
        ? build_v3_inputs($pdo, $entries, $race['date'], $race['venue'])
        : explain_v2_inputs($pdo, $entries, $race['date'], $race['venue']);
    return explain_factors($model, $inputs, $weather);
}

/** AI解説のプロンプト用の1艇分の文章(例「上げた要因: 枠番(1号艇・大)…」) */
function explain_prompt_line(array $f): string {
    $fmt = fn($items) => $items ? implode('、', array_map(fn($i) => $i['label'] . '(' . $i['detail'] . '・影響' . explain_strength($i['effect']) . ')', $items)) : 'なし';
    return '評価を上げた要因: ' . $fmt($f['up'] ?? []) . ' / 評価を下げた要因: ' . $fmt($f['down'] ?? []);
}
