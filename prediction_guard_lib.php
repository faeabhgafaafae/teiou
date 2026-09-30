<?php
/**
 * 予測特徴量の時点制約(先読みリーク防止)と、結果確定済みレースの上書き防止。
 * api_predict.php / get_prediction.php / generate_strategies.php から利用。
 * SQLは MySQL / SQLite 共通の構文のみを使う(PHPUnitでSQLiteインメモリDBを使うため)。
 *
 * 2026-09-30 修正 (design_leak_fix_20260930.md):
 *   api_predict.php の地元成績・コース別成績は期間の下限(2年前)しか指定しておらず、
 *   結果取込後に再実行されると(翌日20:00バッチ・過去レースの閲覧)当該レース自身と
 *   同日以降の着順が特徴量に混入し、predictions / strategies が上書きされていた。
 *   → 集計窓を「レース日の2年前以上・レース日より前」に固定し(api_v3_shadow.php /
 *     export_lr_data_v3.php と同じ境界)、結果確定済みレースへの書き込みを禁止する。
 */

const STATS_WINDOW_YEARS = 2;

/**
 * 成績集計窓 [from, to) を返す。to はレース日そのもの(当日のレースは含まない)。
 */
function stats_window(string $race_date): array {
    return [date('Y-m-d', strtotime($race_date . ' -' . STATS_WINDOW_YEARS . ' years')), $race_date];
}

/**
 * 選手の当地(開催場)成績。レース日より前の2年分のみ。
 * @return array ['total'=>int, 'rank1'=>int, 'rank2'=>int]
 */
function fetch_local_stats(PDO $pdo, int $player_id, string $venue, string $race_date): array {
    [$from, $to] = stats_window($race_date);
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN r2.actual_rank = 1 THEN 1 ELSE 0 END) AS rank1,
               SUM(CASE WHEN r2.actual_rank <= 2 THEN 1 ELSE 0 END) AS rank2
        FROM results r2
        JOIN races rc ON r2.race_id = rc.id
        WHERE r2.player_id = ? AND rc.venue = ?
          AND rc.date >= ? AND rc.date < ?
    ");
    $stmt->execute([$player_id, $venue, $from, $to]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'total' => (int)($row['total'] ?? 0),
        'rank1' => (int)($row['rank1'] ?? 0),
        'rank2' => (int)($row['rank2'] ?? 0),
    ];
}

/**
 * 選手の枠番別成績。レース日より前の2年分のみ。
 * @return array ['total'=>int, 'rank1'=>int, 'rank2'=>int, 'rank3'=>int]
 */
function fetch_course_stats(PDO $pdo, int $player_id, int $lane, string $race_date): array {
    [$from, $to] = stats_window($race_date);
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN r2.actual_rank = 1 THEN 1 ELSE 0 END) AS rank1,
               SUM(CASE WHEN r2.actual_rank <= 2 THEN 1 ELSE 0 END) AS rank2,
               SUM(CASE WHEN r2.actual_rank <= 3 THEN 1 ELSE 0 END) AS rank3
        FROM results r2
        JOIN races rc ON r2.race_id = rc.id
        WHERE r2.player_id = ? AND r2.lane = ?
          AND rc.date >= ? AND rc.date < ?
    ");
    $stmt->execute([$player_id, $lane, $from, $to]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'total' => (int)($row['total'] ?? 0),
        'rank1' => (int)($row['rank1'] ?? 0),
        'rank2' => (int)($row['rank2'] ?? 0),
        'rank3' => (int)($row['rank3'] ?? 0),
    ];
}

/**
 * 結果(着順)が1件でも取り込まれていれば「結果確定済み」とみなす。
 * 着順が入った時点で、以後の再計算は結果を知った状態の値になりうるため。
 */
function race_is_settled(PDO $pdo, int $race_id): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM results WHERE race_id = ? AND actual_rank IS NOT NULL LIMIT 1');
    $stmt->execute([$race_id]);
    return $stmt->fetchColumn() !== false;
}

/**
 * api_predict.php の計算結果を predictions に保存する。
 * 結果確定済みレースは保存せず false を返す(レース前に記録した予測を保持する)。
 *
 * @param array $scores api_predict.php の $scores(player_id/predicted_rank/score_* を含む)
 * @return bool 保存したら true
 */
function persist_predictions(PDO $pdo, int $race_id, array $scores): bool {
    if (race_is_settled($pdo, $race_id)) return false;

    // model_version カラムの存在確認・追加。
    // ALTER TABLE が権限エラー等で失敗した場合は model_version なしでINSERTする。
    // (error 1060 = カラム既存 は正常ケース)
    $has_model_version = true;
    try {
        $pdo->exec("ALTER TABLE predictions ADD COLUMN model_version VARCHAR(10) DEFAULT NULL");
    } catch (PDOException $e) {
        $has_model_version = ((int)$e->errorInfo[1] === 1060);
    }

    $cols = ['race_id', 'player_id', 'predicted_rank', 'score_total',
             'score_ability', 'score_course', 'score_today', 'score_weather'];
    if ($has_model_version) $cols[] = 'model_version';
    $upd = [];
    foreach ($cols as $c) {
        if ($c !== 'race_id' && $c !== 'player_id') $upd[] = "$c=VALUES($c)";
    }
    $upd[] = 'created_at=NOW()';
    $stmt = $pdo->prepare(
        'INSERT INTO predictions (' . implode(', ', $cols) . ', created_at)' .
        ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', NOW())' .
        ' ON DUPLICATE KEY UPDATE ' . implode(', ', $upd)
    );

    foreach ($scores as $s) {
        $vals = [$race_id, $s['player_id'], $s['predicted_rank'], $s['score_total'],
                 $s['score_ability'], $s['score_course'], $s['score_today'], $s['score_weather']];
        if ($has_model_version) $vals[] = 'v2_lr';
        $stmt->execute($vals);
    }
    return true;
}

/**
 * 保存済み予測を player_id => row で返す。
 */
function fetch_stored_predictions(PDO $pdo, int $race_id): array {
    $stmt = $pdo->prepare('
        SELECT player_id, predicted_rank, score_total, score_ability, score_course, score_today, score_weather
        FROM predictions WHERE race_id = ?
    ');
    $stmt->execute([$race_id]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['player_id']] = $row;
    }
    return $out;
}

/**
 * 結果確定済みレースの表示用: 全艇分の保存済み予測があれば順位・スコアをそれで置き換え、
 * predicted_rank昇順(同順位は枠番順)に並べ直す。1艇でも欠けていれば $scores をそのまま返す。
 * (保存済み予測 = 戦略買い目・的中実績の根拠になった値なので、表示もそれに揃える)
 */
function overlay_stored_predictions(array $scores, array $stored): array {
    foreach ($scores as $s) {
        if (!isset($stored[(int)$s['player_id']])) return $scores;
    }
    foreach ($scores as &$s) {
        $row = $stored[(int)$s['player_id']];
        $s['predicted_rank'] = (int)$row['predicted_rank'];
        foreach (['score_total', 'score_ability', 'score_course', 'score_today', 'score_weather'] as $k) {
            $s[$k] = $row[$k] !== null ? round((float)$row[$k], 2) : null;
        }
    }
    unset($s);
    usort($scores, function($a, $b) {
        if ($a['predicted_rank'] !== $b['predicted_rank']) {
            return $a['predicted_rank'] <=> $b['predicted_rank'];
        }
        return $a['lane'] <=> $b['lane'];
    });
    return $scores;
}
