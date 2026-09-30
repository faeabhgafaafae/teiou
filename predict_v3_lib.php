<?php
/**
 * v3w 予測の入力組み立て(1レース単位)と保存。
 * api_predict.php(本番の予測生成)と api_v3_shadow.php(日次バッチ)で共用する。
 * 集計系特徴量はすべて「レース日より前」の results のみ(prediction_guard_lib.php と同じ境界)。
 *
 * 2026-10-01: v3w を本番モデルへ切り替えるため、api_v3_shadow.php の日次一括処理にしか無かった
 * 入力組み立てを1レース単位で呼べるようにした(design_v3w_switch_20261001.md)。
 */
require_once __DIR__ . '/predict_v3_core.php';
require_once __DIR__ . '/prediction_guard_lib.php';

const V3_RECENT_DAYS = 180;

/**
 * 直近10走の [平均着順, 1着率, 平均ST]。3走未満は信頼性不足として全て null(学習時と同一基準)。
 * @param array $rows [[rank, st|null], ...] 日付昇順
 */
function v3_recent10_stats(array $rows): array {
    $n = count($rows);
    if ($n < 3) return [null, null, null];
    $slice = array_slice($rows, max(0, $n - 10));
    $cnt = count($slice);
    $sum_rank = 0; $wins = 0; $st_sum = 0.0; $st_cnt = 0;
    foreach ($slice as [$rank, $st]) {
        $sum_rank += $rank;
        if ($rank === 1) $wins++;
        if ($st !== null) { $st_sum += $st; $st_cnt++; }
    }
    return [
        round($sum_rank / $cnt, 4),
        round($wins / $cnt, 4),
        $st_cnt > 0 ? round($st_sum / $st_cnt, 4) : null,
    ];
}

/** レース日より前180日の着順・ST(着順ありのみ)を日付昇順で */
function fetch_recent_results(PDO $pdo, int $player_id, string $race_date): array {
    $from = date('Y-m-d', strtotime($race_date . ' -' . V3_RECENT_DAYS . ' days'));
    $stmt = $pdo->prepare('
        SELECT res.actual_rank, res.start_timing
        FROM results res
        JOIN races rc ON res.race_id = rc.id
        WHERE res.player_id = ?
          AND rc.date >= ? AND rc.date < ?
          AND res.actual_rank IS NOT NULL
        ORDER BY rc.date, rc.id
    ');
    $stmt->execute([$player_id, $from, $race_date]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [(int)$r['actual_rank'], $r['start_timing'] !== null ? (float)$r['start_timing'] : null];
    }
    return $rows;
}

/** 最新期の期別成績(api_v3_shadow.php と同じく最新期を採用) */
function fetch_player_period_latest(PDO $pdo, int $player_id): ?array {
    $stmt = $pdo->prepare('
        SELECT win_rate, avg_st, grade FROM player_periods
        WHERE player_id = ? ORDER BY year DESC, period DESC LIMIT 1
    ');
    $stmt->execute([$player_id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    return [
        'win_rate' => $r['win_rate'] !== null ? (float)$r['win_rate'] : null,
        'avg_st'   => $r['avg_st']   !== null ? (float)$r['avg_st']   : null,
        'grade'    => $r['grade'],
    ];
}

/**
 * PredictV3::score_race の入力を組み立てる。
 * @param array $entries 各要素 ['player_id','lane','exhibit_time','start_timing','motor_2rate'](1枠1行)
 */
function build_v3_inputs(PDO $pdo, array $entries, string $race_date, string $venue): array {
    $out = [];
    foreach ($entries as $e) {
        $pid    = (int)$e['player_id'];
        $lane   = (int)$e['lane'];
        $pp     = fetch_player_period_latest($pdo, $pid);
        $local  = fetch_local_stats($pdo, $pid, $venue, $race_date);
        $course = fetch_course_stats($pdo, $pid, $lane, $race_date);
        [$r10_rank, $r10_win, $r10_st] = v3_recent10_stats(fetch_recent_results($pdo, $pid, $race_date));
        $out[] = [
            'lane'              => $lane,
            'player_id'         => $pid,
            'exhibit_time'      => $e['exhibit_time'] !== null ? (float)$e['exhibit_time'] : null,
            'start_timing'      => $e['start_timing'] !== null ? (float)$e['start_timing'] : null,
            'motor_2rate'       => $e['motor_2rate']  !== null ? (float)$e['motor_2rate']  : null,
            'global_win_rate'   => $pp ? $pp['win_rate'] : null,
            'local_win_rate'    => $local['total'] > 0 ? $local['rank1'] / $local['total'] : null,
            'avg_st'            => $pp ? $pp['avg_st'] : null,
            'grade'             => $pp ? $pp['grade'] : null,
            'course_rank1'      => $course['rank1'],
            'course_count'      => $course['total'],
            'course_rank2'      => $course['rank2'],
            'recent10_avg_rank' => $r10_rank,
            'recent10_win_rate' => $r10_win,
            'recent10_st_mean'  => $r10_st,
        ];
    }
    return $out;
}

/**
 * v3w 予測を predictions_v2 に保存する。結果確定済みレースは保存せず false(predictions と同じ方針)。
 */
function persist_v3_predictions(PDO $pdo, int $race_id, array $results): bool {
    if (!$results || race_is_settled($pdo, $race_id)) return false;
    PredictV3::save_shadow_predictions($pdo, $race_id, $results);
    return true;
}
