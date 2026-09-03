<?php
/**
 * predict_v3_core.php
 * ロジスティック回帰モデル v3 コア (design_v3_model_20260903.md)。
 * 学習: run_lr_v3.py / データ: export_lr_data_v3.php (先読みリーク修正済み)
 *
 * v2(predict_v2_core.php)からの変更点:
 *   - 枠番one-hot化(lane_2〜lane_6、基準=1号艇)
 *   - 追加特徴量: avg_st + レース内ST順位 / コース別成績(resultsベース・ベイズ平滑化)
 *                 直近10走トレンド / 級別one-hot(基準=B2)
 *   - 死に特徴量削除: temperature / global_2rate / local_2rate
 *   - 欠損補完は学習データ中央値(IMPUTE_MEDIANS)で統一
 *
 * このファイルは昇格まで本番から参照されない(api_predict.phpはPredictV2を使用)。
 * シャドウ運用: api_v3_shadow.php から呼ばれ predictions_v2 テーブルへ保存。
 */
class PredictV3
{
    // ── 学習済み定数 (run_lr_v3.py --php の出力。再学習時はここを差し替え) ──
    // 学習日: 2026-09-04  データ: 2026-06-29~2026-09-02 (8024R)
    // CV fold平均: v2現行 AUC=0.7866/top1 48.4% -> v3 AUC=0.8306/top1 54.7%
    // 特徴量順:
    //   0-4 : lane_2..lane_6 (one-hot, 基準=1号艇)
    //   5   : global_win_rate(%)   6: local_win_rate(ratio)
    //   7   : exhibit_time_rel     8: start_timing(展示ST)  9: motor_2rate_rel
    //   10  : wind_speed          11: wave_height
    //   12  : avg_st              13: avg_st_rank(レース内1-6)
    //   14  : course_win_rate_sm  15: course_in2_sm (平滑化済みコース別成績)
    //   16  : recent10_avg_rank   17: recent10_win_rate  18: recent10_st_mean
    //   19-21: grade_A1, grade_A2, grade_B1 (one-hot, 基準=B2)

    private const N_FEAT    = 22;
    private const INTERCEPT = -0.567411;
    private const MEANS  = [0.166691, 0.166460, 0.166670, 0.166691, 0.166796, 5.141671, 0.160294, 0.501120, 0.089596, 0.484614, 2.797768, 2.401627, 0.166010, 3.099372, 0.166014, 0.333736, 3.493505, 0.165521, 0.160242, 0.225415, 0.230270, 0.479539];
    private const SCALES = [0.372700, 0.372493, 0.372681, 0.372700, 0.372794, 1.397779, 0.127588, 0.362921, 0.105427, 0.362020, 1.530566, 1.636638, 0.028202, 1.593095, 0.190919, 0.225379, 0.837246, 0.145866, 0.030617, 0.417855, 0.421005, 0.499581];
    private const COEFS  = [-0.360053, -0.313801, -0.384830, -0.505300, -0.760755, 0.260845, 0.083374, 0.258149, -0.038528, 0.110728, -0.010679, 0.039810, 0.192208, -0.285065, 0.280814, 0.283598, -0.168681, -0.005029, -0.061135, 0.077574, 0.050631, 0.086844];

    // コース別成績のベイズ平滑化: (rank_n + K*prior) / (count + K)
    private const SMOOTH_K      = 10;
    private const COURSE_PRIOR1 = [0.537133, 0.138822, 0.138384, 0.102522, 0.064052, 0.030872]; // 枠番1-6の1着率prior
    private const COURSE_PRIOR2 = [0.760562, 0.394687, 0.349163, 0.267412, 0.181237, 0.112783]; // 枠番1-6の2着内率prior

    // 欠損補完値(学習データ中央値、特徴量順)
    private const IMPUTE_MEDIANS = [0.000000, 0.000000, 0.000000, 0.000000, 0.000000, 5.300000, 0.147100, 0.500000, 0.080000, 0.476600, 3.000000, 2.000000, 0.160000, 3.000000, 0.096013, 0.285352, 3.400000, 0.100000, 0.158000, 0.000000, 0.000000, 0.000000];

    /**
     * レース内全エントリーの1着確率を計算して返す。
     *
     * @param array $entries 各要素:
     *   ['lane'=>int, 'player_id'=>int,
     *    'exhibit_time'=>float|null, 'start_timing'=>float|null, 'motor_2rate'=>float|null,
     *    'global_win_rate'=>float|null(%), 'local_win_rate'=>float|null(ratio),
     *    'avg_st'=>float|null(期別平均ST。0以下は欠損扱い),
     *    'grade'=>string|null(A1/A2/B1/B2),
     *    'course_rank1'=>int, 'course_count'=>int, 'course_rank2'=>int (枠番別成績・レース日以前),
     *    'recent10_avg_rank'=>float|null, 'recent10_win_rate'=>float|null,
     *    'recent10_st_mean'=>float|null]
     * @param array $weather ['wind_speed'=>, 'wave_height'=>]
     * @return array [player_id => ['lane'=>, 'player_id'=>, 'probability'=>, 'predicted_rank'=>]]
     */
    public static function score_race(array $entries, array $weather): array
    {
        if (empty($entries)) return [];

        $M = self::IMPUTE_MEDIANS;

        // レース内 min/max (展示タイム・モーター相対化。v2と同一ロジック)
        $ex_vals = array_filter(array_column($entries, 'exhibit_time'), fn($v) => $v !== null);
        $mo_vals = array_filter(array_column($entries, 'motor_2rate'),  fn($v) => $v !== null);
        $ex_min  = $ex_vals ? (float)min($ex_vals) : null;
        $ex_max  = $ex_vals ? (float)max($ex_vals) : null;
        $mo_min  = $mo_vals ? (float)min($mo_vals) : null;
        $mo_max  = $mo_vals ? (float)max($mo_vals) : null;

        // avg_st_rank: レース内順位(1=最速、同値は最小順位、欠損は3.5)
        // run_lr_v3.py の rank(method='min') と同一挙動
        $st_list = [];
        foreach ($entries as $i => $e) {
            $v = $e['avg_st'] ?? null;
            $st_list[$i] = ($v !== null && (float)$v > 0.001) ? (float)$v : null;
        }
        $st_rank = [];
        foreach ($st_list as $i => $v) {
            if ($v === null) { $st_rank[$i] = 3.5; continue; }
            $rank = 1;
            foreach ($st_list as $j => $w) {
                if ($j !== $i && $w !== null && $w < $v) $rank++;
            }
            $st_rank[$i] = (float)$rank;
        }

        $raw_probs = [];
        foreach ($entries as $i => $e) {
            $lane = (int)$e['lane'];

            // 展示タイム相対 (0=最遅, 1=最速)
            $ex_rel = $M[7];
            if ($e['exhibit_time'] !== null && $ex_min !== null) {
                $range  = $ex_max - $ex_min;
                $ex_rel = $range > 1e-6 ? ($ex_max - (float)$e['exhibit_time']) / $range : 0.5;
            }

            // モーター相対
            $mo_rel = $M[9];
            if ($e['motor_2rate'] !== null && $mo_min !== null) {
                $range_m = $mo_max - $mo_min;
                $mo_rel  = $range_m > 1e-6 ? ((float)$e['motor_2rate'] - $mo_min) / $range_m : 0.5;
            }

            // コース別成績 (ベイズ平滑化。count=0でもpriorに收縮するため常に定義される)
            $c_cnt = max(0, (int)($e['course_count'] ?? 0));
            $c_r1  = max(0, (int)($e['course_rank1'] ?? 0));
            $c_r2  = max(0, (int)($e['course_rank2'] ?? 0));
            $p1    = self::COURSE_PRIOR1[$lane - 1] ?? 0.1;
            $p2    = self::COURSE_PRIOR2[$lane - 1] ?? 0.2;
            $course_win = ($c_r1 + self::SMOOTH_K * $p1) / ($c_cnt + self::SMOOTH_K);
            $course_in2 = ($c_r2 + self::SMOOTH_K * $p2) / ($c_cnt + self::SMOOTH_K);

            $grade = $e['grade'] ?? null;

            $x = [
                $lane === 2 ? 1.0 : 0.0,
                $lane === 3 ? 1.0 : 0.0,
                $lane === 4 ? 1.0 : 0.0,
                $lane === 5 ? 1.0 : 0.0,
                $lane === 6 ? 1.0 : 0.0,
                $e['global_win_rate'] !== null ? (float)$e['global_win_rate'] : $M[5],
                $e['local_win_rate']  !== null ? (float)$e['local_win_rate']  : $M[6],
                $ex_rel,
                $e['start_timing'] !== null ? (float)$e['start_timing'] : $M[8],
                $mo_rel,
                isset($weather['wind_speed'])  && $weather['wind_speed']  !== null ? (float)$weather['wind_speed']  : $M[10],
                isset($weather['wave_height']) && $weather['wave_height'] !== null ? (float)$weather['wave_height'] : $M[11],
                $st_list[$i] !== null ? $st_list[$i] : $M[12],
                $st_rank[$i],
                $course_win,
                $course_in2,
                $e['recent10_avg_rank'] !== null ? (float)$e['recent10_avg_rank'] : $M[16],
                $e['recent10_win_rate'] !== null ? (float)$e['recent10_win_rate'] : $M[17],
                $e['recent10_st_mean']  !== null ? (float)$e['recent10_st_mean']  : $M[18],
                $grade === 'A1' ? 1.0 : 0.0,
                $grade === 'A2' ? 1.0 : 0.0,
                $grade === 'B1' ? 1.0 : 0.0,
            ];

            $logit = self::INTERCEPT;
            for ($k = 0; $k < self::N_FEAT; $k++) {
                $logit += self::COEFS[$k] * ($x[$k] - self::MEANS[$k]) / self::SCALES[$k];
            }
            $raw_probs[(int)$e['player_id']] = [
                'lane'     => $lane,
                'prob_raw' => 1.0 / (1.0 + exp(-$logit)),
            ];
        }

        // レース内確率を正規化して合計=1に (v2と同一)
        $sum = array_sum(array_column($raw_probs, 'prob_raw'));
        $results = [];
        foreach ($raw_probs as $pid => $r) {
            $results[$pid] = [
                'lane'        => $r['lane'],
                'player_id'   => $pid,
                'probability' => $sum > 1e-9 ? round($r['prob_raw'] / $sum, 4) : round(1 / count($raw_probs), 4),
            ];
        }

        uasort($results, fn($a, $b) => $b['probability'] <=> $a['probability']);
        $rank = 1;
        foreach ($results as &$r) {
            $r['predicted_rank'] = $rank++;
        }
        unset($r);

        return $results;
    }

    /**
     * v3シャドウ予測を predictions_v2 テーブルに保存(転用。design §3.4)。
     * 本番のpredictionsテーブルには一切書き込まない。
     */
    public static function save_shadow_predictions(PDO $pdo, int $race_id, array $results): void
    {
        if (empty($results)) return;

        $stmt = $pdo->prepare("
            INSERT INTO predictions_v2
                (race_id, player_id, predicted_rank, win_probability, created_at)
            VALUES
                (:race_id, :player_id, :predicted_rank, :win_probability, NOW())
            ON DUPLICATE KEY UPDATE
                predicted_rank  = VALUES(predicted_rank),
                win_probability = VALUES(win_probability),
                created_at      = NOW()
        ");

        foreach ($results as $r) {
            $stmt->execute([
                ':race_id'         => $race_id,
                ':player_id'       => $r['player_id'],
                ':predicted_rank'  => $r['predicted_rank'],
                ':win_probability' => $r['probability'],
            ]);
        }
    }
}
