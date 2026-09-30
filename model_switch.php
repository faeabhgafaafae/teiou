<?php
/**
 * 本番モデルの切り替えスイッチ(戦略の参照モデル・画面表示の参照モデル)。
 *
 * 2026-10-01: 全戦略・画面表示を v3w へ切り替え(design_v3w_switch_20261001.md)。
 * 予測は v2(predictions)・v3w(predictions_v2)とも api_predict.php が常に両方保存しているため、
 * ここの定数を 'v2' に戻すだけで即座に切り替え前の状態へ戻せる(テストもどちらの値でも通る)。
 *
 *   'v2'  = predictions テーブル(ロジスティック回帰 v2)
 *   'v3w' = predictions_v2 テーブル(重み付き学習 v3w、predict_v3_core.php)
 */

// 戦略 → 買い目生成に使う予測モデル
const STRATEGY_MODEL_MAP = [
    '的中特化' => 'v3w',
    'バランス' => 'v3w',
    '一撃重視' => 'v3w',
    '絞り込み' => 'v3w',
];
// 指定モデルの予測が無いレースはこちらで生成する(strategies.model_ref に実際の参照先が残る)
const STRATEGY_MODEL_FALLBACK = 'v2';

// 画面表示(予測順位・確率・AI解説)に使う予測モデル
const PREDICTION_DISPLAY_MODEL = 'v3w';

// predictions_v2 が v3w の予測を持つ最初の日。これより前の行は旧v3(09-04〜09-12)・
// 旧v2シャドウ(07-28〜08-27)で別モデルのため、表示では v2 を使う。
const V3W_PREDICTIONS_FROM = '2026-09-13';

/** モデル名 → 予測テーブル */
function prediction_table(string $model): string {
    return $model === 'v3w' ? 'predictions_v2' : 'predictions';
}

/**
 * 表示用の予測を player_id => ['predicted_rank'=>int, 'score_total'=>float] で返し、実際に使ったモデルを $used に入れる。
 * $model が v3w でも、対象日が V3W_PREDICTIONS_FROM より前、または v3w の保存が無いレースは v2 を返す。
 * score_total は v2 と同じく「1着確率 × 100」。
 */
function load_display_predictions(PDO $pdo, int $race_id, string $race_date, ?string &$used = null,
                                  string $model = PREDICTION_DISPLAY_MODEL): array {
    if ($model === 'v3w' && $race_date >= V3W_PREDICTIONS_FROM) {
        try {
            $stmt = $pdo->prepare('SELECT player_id, predicted_rank, win_probability FROM predictions_v2 WHERE race_id = ?');
            $stmt->execute([$race_id]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['player_id']] = [
                    'predicted_rank' => (int)$r['predicted_rank'],
                    'score_total'    => round((float)$r['win_probability'] * 100, 2),
                ];
            }
            if ($out) { $used = 'v3w'; return $out; }
        } catch (PDOException $e) { /* predictions_v2 未作成 → v2 */ }
    }
    $stmt = $pdo->prepare('SELECT player_id, predicted_rank, score_total FROM predictions WHERE race_id = ?');
    $stmt->execute([$race_id]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['player_id']] = [
            'predicted_rank' => (int)$r['predicted_rank'],
            'score_total'    => $r['score_total'] !== null ? round((float)$r['score_total'], 2) : null,
        ];
    }
    $used = 'v2';
    return $out;
}

/**
 * 行配列(player_id と lane を持つ)の predicted_rank / score_total を表示用予測で置き換え、
 * 順位順(同順位は枠番順)に並べ直す。1行でも表示用予測が欠けていれば元の配列を返す。
 */
function apply_display_ranks(array $rows, array $display): array {
    foreach ($rows as $r) {
        if (!isset($display[(int)$r['player_id']])) return $rows;
    }
    foreach ($rows as &$r) {
        $d = $display[(int)$r['player_id']];
        $r['predicted_rank'] = $d['predicted_rank'];
        $r['score_total']    = $d['score_total'];
    }
    unset($r);
    usort($rows, function($a, $b) {
        if ((int)$a['predicted_rank'] !== (int)$b['predicted_rank']) {
            return (int)$a['predicted_rank'] <=> (int)$b['predicted_rank'];
        }
        return (int)$a['lane'] <=> (int)$b['lane'];
    });
    return $rows;
}
