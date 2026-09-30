<?php
/**
 * tools/score_models_cli.php — 過去レースの v2 / v3w 予測をリーク修正後のロジックで再計算する(CLI専用)。
 * DB接続なし。export_lr_data_v3.php の特徴量CSV(レース日より前のデータのみで集計済み)を入力に、
 * 本番と同じ PredictV2::score_race / PredictV3::score_race を呼ぶ。
 *
 * 入力の組み立ては本番と同一:
 *   v2 : api_predict.php の '_v2' 配列(期別成績の欠損は0、当地成績は件数0ならnull、気象の欠損は0)
 *        当地成績は prediction_guard_lib.php の fetch_local_stats と同じ「レース日より前2年」
 *   v3w: api_v3_shadow.php の $inputs(欠損はnull → PredictV3内で学習中央値補完)
 * 追加で v2leak(修正前 api_predict.php の再現: 当地成績に当該レース自身の着順を加算)も出力する。
 *
 * 使い方: php tools/score_models_cli.php OUT.csv FINISH_GLOB[,FINISH_GLOB...] LR_CSV [LR_CSV ...]
 * 出力列: race_id,date,venue,lane,winner,p_v2,rank_v2,p_v2leak,rank_v2leak,p_v3w,rank_v3w
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../predict_v2_core.php';
require_once __DIR__ . '/../predict_v3_core.php';

[$_, $out_path, $finish_glob] = $argv;
$lr_paths = array_slice($argv, 3);

function read_csv_rows(string $path): Generator {
    $fh = fopen($path, 'r');
    $head = fgetcsv($fh);
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) !== count($head)) continue;
        yield array_combine($head, $row);
    }
    fclose($fh);
}
$num = fn($v) => ($v === '' || $v === null) ? null : (float)$v;

// 着順(1・2着の枠番)
$finish = [];
foreach (array_merge(...array_map('glob', explode(',', $finish_glob))) as $f) {
    foreach (read_csv_rows($f) as $r) {
        $finish[(int)$r['race_id']][(int)$r['rank']] = (int)$r['lane'];
    }
}

// レース単位にまとめる(同一race_idが複数ファイルにあれば後勝ち)
$races = [];
foreach ($lr_paths as $p) {
    $seen = [];
    foreach (read_csv_rows($p) as $r) {
        $rid = (int)$r['race_id'];
        if (!isset($seen[$rid])) { $races[$rid] = []; $seen[$rid] = true; }
        $races[$rid][(int)$r['lane']] = $r;
    }
}
ksort($races);

$out = fopen($out_path, 'w');
fputcsv($out, ['race_id', 'date', 'venue', 'lane', 'winner', 'p_v2', 'rank_v2', 'p_v2leak', 'rank_v2leak', 'p_v3w', 'rank_v3w']);
$n = 0;
foreach ($races as $rid => $rows) {
    if (count($rows) !== 6 || !isset($finish[$rid][1], $finish[$rid][2])) continue;
    ksort($rows);
    $first  = reset($rows);
    $top2   = [$finish[$rid][1], $finish[$rid][2]];

    $v2 = $v2leak = $v3 = [];
    foreach ($rows as $lane => $r) {
        $pid = (int)$r['player_id'];
        $cnt = (int)($num($r['local_race_cnt']) ?? 0);
        $lwr = $cnt > 0 ? $num($r['local_win_rate']) : null;
        $l2r = $cnt > 0 ? $num($r['local_2rate']) : null;
        $base = [
            'lane'            => $lane,
            'player_id'       => $pid,
            'exhibit_time'    => $num($r['exhibit_time_raw']),
            'start_timing'    => $num($r['start_timing']),
            'motor_2rate'     => $num($r['motor_2rate']),
            'global_win_rate' => (float)($num($r['global_win_rate']) ?? 0),
            'global_2rate'    => (float)($num($r['global_2rate']) ?? 0),
            'local_win_rate'  => $lwr,
            'local_2rate'     => $l2r,
        ];
        $v2[] = $base;
        // 修正前: 結果取込後の再計算で当該レース自身が当地成績に入る
        $w  = $finish[$rid][1] === $lane ? 1 : 0;
        $i2 = in_array($lane, $top2, true) ? 1 : 0;
        $v2leak[] = array_merge($base, [
            'local_win_rate' => (($lwr ?? 0) * $cnt + $w) / ($cnt + 1),
            'local_2rate'    => (($l2r ?? 0) * $cnt + $i2) / ($cnt + 1),
        ]);
        $v3[] = [
            'lane'              => $lane,
            'player_id'         => $pid,
            'exhibit_time'      => $num($r['exhibit_time_raw']),
            'start_timing'      => $num($r['start_timing']),
            'motor_2rate'       => $num($r['motor_2rate']),
            'global_win_rate'   => $num($r['global_win_rate']),
            'local_win_rate'    => $lwr,
            'avg_st'            => $num($r['avg_st']),
            'grade'             => $r['grade_period'] !== '' ? $r['grade_period'] : null,
            'course_rank1'      => (int)($num($r['course_rank1']) ?? 0),
            'course_count'      => (int)($num($r['course_count']) ?? 0),
            'course_rank2'      => (int)($num($r['course_rank2']) ?? 0),
            'recent10_avg_rank' => $num($r['recent10_avg_rank']),
            'recent10_win_rate' => $num($r['recent10_win_rate']),
            'recent10_st_mean'  => $num($r['recent10_st_mean']),
        ];
    }
    $w2 = ['wind_speed' => (float)($num($first['wind_speed']) ?? 0),
           'wave_height' => (float)($num($first['wave_height']) ?? 0),
           'temperature' => (float)($num($first['temperature']) ?? 0)];
    $w3 = ['wind_speed' => $num($first['wind_speed']), 'wave_height' => $num($first['wave_height'])];

    $r2  = PredictV2::score_race($v2, $w2);
    $r2l = PredictV2::score_race($v2leak, $w2);
    $r3  = PredictV3::score_race($v3, $w3);
    foreach ($rows as $lane => $r) {
        $pid = (int)$r['player_id'];
        fputcsv($out, [$rid, $r['date'], $r['venue'], $lane, $finish[$rid][1],
            $r2[$pid]['probability'], $r2[$pid]['predicted_rank'],
            $r2l[$pid]['probability'], $r2l[$pid]['predicted_rank'],
            $r3[$pid]['probability'], $r3[$pid]['predicted_rank']]);
    }
    $n++;
}
fclose($out);
fwrite(STDERR, "races scored: $n\n");
