<?php
/**
 * predict_v2_core.php
 * ロジスティック回帰モデル v2 コア。
 * 既存の api_predict.php / predictions テーブルには一切影響を与えない。
 *
 * 使い方:
 *   require_once __DIR__ . '/predict_v2_core.php';
 *   $results = PredictV2::score_race($entries, $weather);
 *   PredictV2::save_predictions($pdo, $race_id, $results);
 */
class PredictV2
{
    // ── 学習済み定数 ──────────────────────────────────────────────────
    // 学習日: 2026-07-28
    // Train期間: 2026-06-29 ~ 2026-07-19
    // Train 1着的中率: 59.4%  Test: 60.8%
    // ROC-AUC(test): 0.8505
    // 特徴量順:
    //   0:lane  1:global_win_rate(%)  2:global_2rate(%)
    //   3:local_win_rate(ratio)  4:local_2rate(ratio)
    //   5:exhibit_time_rel  6:start_timing
    //   7:motor_2rate_rel  8:wind_speed  9:wave_height  10:temperature
    // 再学習時は run_lr.py --php の出力でここを差し替える

    private const INTERCEPT = -0.710151;
    private const MEANS  = [3.498596, 5.125432, 32.040086, 0.159386, 0.326116, 0.501524, 0.091678, 0.489493, 2.631639, 2.119382, 28.307706];
    private const SCALES = [1.709008, 1.365740, 13.847295, 0.121440, 0.174187, 0.362293, 0.105475, 0.362955, 1.417293, 1.546866, 3.305047];
    private const COEFS  = [-1.133264, 0.148167, -0.102042, 0.975016, -0.018183, 0.215581, -0.089471, 0.087079, -0.036640, 0.049179, -0.000086];

    // global_win_rate/global_2rate: % スケール (例: 4.35 / 32.1)
    // local_win_rate/local_2rate  : ratio スケール (例: 0.197 / 0.329)

    /**
     * レース内全エントリーの1着確率を計算して返す。
     *
     * @param array $entries  各要素:
     *   ['lane'=>int, 'player_id'=>int,
     *    'exhibit_time'=>float|null, 'start_timing'=>float|null, 'motor_2rate'=>float|null,
     *    'global_win_rate'=>float(%),  'global_2rate'=>float(%),
     *    'local_win_rate'=>float|null(ratio), 'local_2rate'=>float|null(ratio)]
     * @param array $weather  ['wind_speed'=>, 'wave_height'=>, 'temperature'=>]
     * @return array          [player_id => ['lane'=>, 'player_id'=>, 'probability'=>, 'predicted_rank'=>]]
     */
    public static function score_race(array $entries, array $weather): array
    {
        if (empty($entries)) return [];

        // レース内 min/max (相対化用)
        $ex_vals = array_filter(array_column($entries, 'exhibit_time'), fn($v) => $v !== null);
        $mo_vals = array_filter(array_column($entries, 'motor_2rate'),  fn($v) => $v !== null);
        $ex_min  = $ex_vals ? (float)min($ex_vals) : null;
        $ex_max  = $ex_vals ? (float)max($ex_vals) : null;
        $mo_min  = $mo_vals ? (float)min($mo_vals) : null;
        $mo_max  = $mo_vals ? (float)max($mo_vals) : null;

        $M = self::MEANS;  // 可読性のため短縮

        $raw_probs = [];
        foreach ($entries as $e) {
            // exhibit_time_rel (0=最遅, 1=最速)
            $ex_rel = 0.5;
            if ($e['exhibit_time'] !== null && $ex_min !== null) {
                $range  = ($ex_max - $ex_min);
                $ex_rel = $range > 1e-6 ? ($ex_max - (float)$e['exhibit_time']) / $range : 0.5;
            }

            // motor_2rate_rel
            $mo_rel = 0.5;
            if ($e['motor_2rate'] !== null && $mo_min !== null) {
                $range_m = ($mo_max - $mo_min);
                $mo_rel  = $range_m > 1e-6 ? ((float)$e['motor_2rate'] - $mo_min) / $range_m : 0.5;
            }

            // start_timing: NULL なら学習時の平均値で補完
            $st = $e['start_timing'] !== null ? (float)$e['start_timing'] : $M[6];

            // local_win_rate/local_2rate: NULL なら学習時の平均値
            $lwr = $e['local_win_rate'] !== null ? (float)$e['local_win_rate'] : $M[3];
            $l2r = $e['local_2rate']    !== null ? (float)$e['local_2rate']    : $M[4];

            $x = [
                (float)$e['lane'],
                (float)($e['global_win_rate'] ?? $M[1]),
                (float)($e['global_2rate']    ?? $M[2]),
                $lwr,
                $l2r,
                $ex_rel,
                $st,
                $mo_rel,
                (float)($weather['wind_speed']  ?? $M[8]),
                (float)($weather['wave_height'] ?? $M[9]),
                (float)($weather['temperature'] ?? $M[10]),
            ];

            // 標準化 + 線形結合
            $logit = self::INTERCEPT;
            for ($i = 0; $i < 11; $i++) {
                $logit += self::COEFS[$i] * ($x[$i] - self::MEANS[$i]) / self::SCALES[$i];
            }
            $raw_probs[(int)$e['player_id']] = [
                'lane'      => (int)$e['lane'],
                'player_id' => (int)$e['player_id'],
                'prob_raw'  => 1.0 / (1.0 + exp(-$logit)),
            ];
        }

        // レース内確率を正規化して合計=1に
        $sum = array_sum(array_column($raw_probs, 'prob_raw'));
        $results = [];
        foreach ($raw_probs as $pid => $r) {
            $results[$pid] = [
                'lane'        => $r['lane'],
                'player_id'   => $pid,
                'probability' => $sum > 1e-9 ? round($r['prob_raw'] / $sum, 4) : round(1 / count($raw_probs), 4),
            ];
        }

        // 確率降順でランク付け
        uasort($results, fn($a, $b) => $b['probability'] <=> $a['probability']);
        $rank = 1;
        foreach ($results as &$r) {
            $r['predicted_rank'] = $rank++;
        }
        unset($r);

        return $results;
    }

    /**
     * v2予測結果を predictions_v2 テーブルに保存。
     * テーブルが存在しない場合は自動作成する。
     */
    public static function save_predictions(PDO $pdo, int $race_id, array $results): void
    {
        if (empty($results)) return;

        // テーブルが存在しない場合は作成
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS predictions_v2 (
                id           INT NOT NULL AUTO_INCREMENT,
                race_id      INT NOT NULL,
                player_id    INT NOT NULL,
                predicted_rank   TINYINT     NOT NULL,
                win_probability  DECIMAL(6,4) NOT NULL,
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_race_player (race_id, player_id),
                KEY idx_race (race_id),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

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
