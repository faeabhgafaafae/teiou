<?php
// 一時シミュレーションスクリプト(実行後に削除)
if (($_GET['k'] ?? '') !== 'teiou2026') { http_response_code(403); exit; }
require_once __DIR__ . '/config.php';
$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

header('Content-Type: text/plain; charset=utf-8');

// ─── 1. バックフィル対象期間の欠損率 ─────────────────────
$miss = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN e.exhibit_time IS NULL THEN 1 ELSE 0 END) as ex_null,
        SUM(CASE WHEN e.start_timing IS NULL THEN 1 ELSE 0 END) as st_null
    FROM entries e
    JOIN races r ON e.race_id = r.id
    WHERE r.date BETWEEN '2026-07-02' AND '2026-07-14'
")->fetch();
echo "=== exhibit_time/start_timing 欠損率 After バックフィル ===\n";
echo "期間: 2026-07-02〜2026-07-14\n";
echo sprintf("total entries  : %d\n", $miss['total']);
echo sprintf("exhibit_time欠損: %d (%.1f%%)\n", $miss['ex_null'], $miss['total']>0 ? $miss['ex_null']/$miss['total']*100 : 0);
echo sprintf("start_timing欠損: %d (%.1f%%)\n", $miss['st_null'], $miss['total']>0 ? $miss['st_null']/$miss['total']*100 : 0);

// 全期間
$miss2 = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN e.exhibit_time IS NULL THEN 1 ELSE 0 END) as ex_null,
        SUM(CASE WHEN e.start_timing IS NULL THEN 1 ELSE 0 END) as st_null
    FROM entries e
    JOIN races r ON e.race_id = r.id
    WHERE r.date >= '2026-06-01'
")->fetch();
echo "\n=== 全期間 (2026-06-01〜) ===\n";
echo sprintf("total entries  : %d\n", $miss2['total']);
echo sprintf("exhibit_time欠損: %d (%.1f%%)\n", $miss2['ex_null'], $miss2['total']>0 ? $miss2['ex_null']/$miss2['total']*100 : 0);
echo sprintf("start_timing欠損: %d (%.1f%%)\n", $miss2['st_null'], $miss2['total']>0 ? $miss2['st_null']/$miss2['total']*100 : 0);

// ─── 2. score_today シミュレーション ─────────────────────
// predictionsテーブルのscore_ability/course/weatherは再利用し、
// score_todayだけ現行/新案で差し替えて比較する
// norm = 100/115 (raw_max=40+35+35+5=115, today_rawも35点満点のまま)
$NORM = 100 / 115;

$races_stmt = $pdo->query("
    SELECT DISTINCT r.id as race_id
    FROM races r
    JOIN entries e ON e.race_id = r.id
    JOIN results res ON res.race_id = r.id AND res.actual_rank = 1
    JOIN predictions pr ON pr.race_id = r.id
    WHERE r.date BETWEEN '2026-06-01' AND '2026-07-27'
");
$race_ids = array_column($races_stmt->fetchAll(), 'race_id');

echo "\n=== score_today シミュレーション ===\n";
echo sprintf("対象レース: %d (2026-06-01〜2026-07-27)\n", count($race_ids));

$cur_hit = 0;
$new_hit = 0;
$lane1_hit = 0;
$total = 0;

// 相関係数用
$cur_rank_pairs = [];
$new_rank_pairs = [];

// 三連単率(上位/下位20%)用
$cur_top20_san  = 0; $cur_top20_tot  = 0;
$cur_bot20_san  = 0; $cur_bot20_tot  = 0;
$new_top20_san  = 0; $new_top20_tot  = 0;
$new_bot20_san  = 0; $new_bot20_tot  = 0;

$entry_stmt   = $pdo->prepare("SELECT lane, exhibit_time, start_timing, motor_2rate FROM entries WHERE race_id=? ORDER BY lane");
$result_stmt  = $pdo->prepare("SELECT lane FROM results WHERE race_id=? AND actual_rank=1 LIMIT 1");
$san_stmt     = $pdo->prepare("SELECT GROUP_CONCAT(lane ORDER BY actual_rank) as combo FROM results WHERE race_id=? AND actual_rank<=3 GROUP BY race_id");
$pred_stmt    = $pdo->prepare("SELECT player_id, score_ability, score_course, score_weather FROM predictions WHERE race_id=? ORDER BY score_total DESC");
$player_stmt  = $pdo->prepare("SELECT p.id as player_id FROM entries e JOIN players p ON e.player_id=p.id WHERE e.race_id=? AND e.lane=? LIMIT 1");

foreach ($race_ids as $race_id) {
    $entry_stmt->execute([$race_id]);
    $entries = $entry_stmt->fetchAll();
    if (count($entries) < 2) continue;

    $result_stmt->execute([$race_id]);
    $winner = $result_stmt->fetch();
    if (!$winner) continue;
    $winner_lane = (int)$winner['lane'];

    // 予測DB
    $pred_stmt->execute([$race_id]);
    $preds_db = $pred_stmt->fetchAll();
    $pred_map = [];
    foreach ($preds_db as $pd) {
        $pred_map[$pd['player_id']] = $pd;
    }
    if (empty($pred_map)) continue;

    $exhibit_vals = array_filter(array_column($entries, 'exhibit_time'), fn($v) => $v !== null);
    $ex_min = $exhibit_vals ? min($exhibit_vals) : null;
    $ex_max = $exhibit_vals ? max($exhibit_vals) : null;
    $ex_range = ($ex_min !== null && $ex_max !== null) ? ($ex_max - $ex_min) : 0;

    $motor_vals = array_filter(array_column($entries, 'motor_2rate'), fn($v) => $v !== null);
    $mo_min = $motor_vals ? min($motor_vals) : null;
    $mo_max = $motor_vals ? max($motor_vals) : null;
    $mo_range = ($mo_min !== null && $mo_max !== null) ? ($mo_max - $mo_min) : 0;

    $scores_cur = [];
    $scores_new = [];

    foreach ($entries as $e) {
        $lane = (int)$e['lane'];

        // 選手IDを取得してpred_mapから能力/コース/気象スコアを引く
        $player_stmt->execute([$race_id, $lane]);
        $prow = $player_stmt->fetch();
        if (!$prow || !isset($pred_map[$prow['player_id']])) continue;
        $pd = $pred_map[$prow['player_id']];
        $base = (float)$pd['score_ability'] + (float)$pd['score_course'] + (float)$pd['score_weather'];

        // 現行 score_today_raw (exhibit15+st10+motor10)
        $s_ex_cur = 0;
        if ($e['exhibit_time'] !== null && $ex_range > 0) {
            $s_ex_cur = (($ex_max - $e['exhibit_time']) / $ex_range) * 15;
        } elseif ($e['exhibit_time'] !== null) { $s_ex_cur = 7.5; }

        $s_st_cur = 0;
        if ($e['start_timing'] !== null && (float)$e['start_timing'] >= 0) {
            $s_st_cur = max(0, (0.30 - (float)$e['start_timing']) / 0.30 * 10);
        }

        $s_mo_cur = 0;
        if ($e['motor_2rate'] !== null) {
            $s_mo_cur = min(10, (float)$e['motor_2rate'] / 60 * 10);
        }

        $today_cur = ($s_ex_cur + $s_st_cur + $s_mo_cur) * $NORM;
        $scores_cur[$lane] = $base + $today_cur;

        // 新案 score_today_raw (exhibit21+motor相対14)
        $s_ex_new = 0;
        if ($e['exhibit_time'] !== null && $ex_range > 0) {
            $s_ex_new = (($ex_max - $e['exhibit_time']) / $ex_range) * 21;
        } elseif ($e['exhibit_time'] !== null) { $s_ex_new = 10.5; }

        $s_mo_new = 0;
        if ($e['motor_2rate'] !== null && $mo_range > 0) {
            $s_mo_new = ((float)$e['motor_2rate'] - $mo_min) / $mo_range * 14;
        } elseif ($e['motor_2rate'] !== null) { $s_mo_new = 7; }

        $today_new = ($s_ex_new + $s_mo_new) * $NORM;
        $scores_new[$lane] = $base + $today_new;
    }

    if (empty($scores_cur) || empty($scores_new)) continue;

    arsort($scores_cur);
    arsort($scores_new);
    $pred_cur_lane = (int)array_key_first($scores_cur);
    $pred_new_lane = (int)array_key_first($scores_new);

    // 1着率
    if ($pred_cur_lane === $winner_lane) $cur_hit++;
    if ($pred_new_lane === $winner_lane) $new_hit++;
    if ($winner_lane === 1) $lane1_hit++;

    // 三連単データ
    $san_stmt->execute([$race_id]);
    $san_row = $san_stmt->fetch();
    $is_san = ($san_row && strlen($san_row['combo']) > 0);

    // 現行スコアで全艇ランク付け（predicted_rank相関用）
    $ranks_cur = [];
    $rank_i = 1;
    foreach (array_keys($scores_cur) as $l) { $ranks_cur[$l] = $rank_i++; }

    // 上位/下位スコア（中央値比較用）
    $cur_top_score = max($scores_cur);
    $cur_bot_score = min($scores_cur);

    $total++;
    $cur_rank_pairs[] = [$ranks_cur[$winner_lane] ?? 6, 1];

    // 新案ランク
    $ranks_new = [];
    $rank_j = 1;
    foreach (array_keys($scores_new) as $l) { $ranks_new[$l] = $rank_j++; }
    $new_rank_pairs[] = [$ranks_new[$winner_lane] ?? 6, 1];
}

// Spearman相関の代わりに、predicted_rank=1の選手が実際に1位になった率を計算
$cur_rate  = $total > 0 ? round($cur_hit  / $total * 100, 1) : 0;
$new_rate  = $total > 0 ? round($new_hit  / $total * 100, 1) : 0;
$lane1_rate = $total > 0 ? round($lane1_hit / $total * 100, 1) : 0;

echo sprintf("現行 predicted_rank=1 → 実際1着率: %d/%d (%.1f%%)\n", $cur_hit, $total, $cur_rate);
echo sprintf("新案 predicted_rank=1 → 実際1着率: %d/%d (%.1f%%)\n", $new_hit, $total, $new_rate);
echo sprintf("1号艇決め打ち1着率             : %d/%d (%.1f%%)\n", $lane1_hit, $total, $lane1_rate);

// predicted_rank=1の選手の実際順位分布
echo "\n=== 現行 predicted_rank=1 の実際順位分布 ===\n";
$cur_dist = array_fill(1, 6, 0);
foreach ($cur_rank_pairs as $p) { if (isset($cur_dist[$p[0]])) $cur_dist[$p[0]]++; }
foreach ($cur_dist as $rank => $cnt) {
    echo sprintf("  実際%d位: %d回 (%.1f%%)\n", $rank, $cnt, $total>0?$cnt/$total*100:0);
}

echo "\n=== 新案 predicted_rank=1 の実際順位分布 ===\n";
$new_dist = array_fill(1, 6, 0);
foreach ($new_rank_pairs as $p) { if (isset($new_dist[$p[0]])) $new_dist[$p[0]]++; }
foreach ($new_dist as $rank => $cnt) {
    echo sprintf("  実際%d位: %d回 (%.1f%%)\n", $rank, $cnt, $total>0?$cnt/$total*100:0);
}

echo "\n完了\n";
