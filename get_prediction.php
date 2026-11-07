<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/prediction_guard_lib.php';
require_once __DIR__ . '/model_switch.php';
require_once __DIR__ . '/plan_access.php';
require_once __DIR__ . '/model_explain_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$date    = $_GET['date']    ?? '';
$venue   = $_GET['venue']   ?? '';
$race_no = $_GET['race_no'] ?? '';

if (!$date || !$venue || !$race_no) {
    json_response(['error' => 'date, venue, race_no は必須です'], 400);
}

// 公開範囲(plan_access.php): 予測順位は全員、予測確率・予想の根拠は Standard 以上
$user = current_user();
$plan = $user['plan'] ?? 'free';
$canViewDetails = plan_can_view_details($user);

$pdo = get_db();

$stmt = $pdo->prepare('SELECT id FROM races WHERE date = ? AND venue = ? AND race_no = ?');
$stmt->execute([$date, $venue, (int)$race_no]);
$race = $stmt->fetch();

if (!$race) {
    json_response(['error' => 'レースが見つかりません'], 404);
}

$raceId = (int)$race['id'];

try {
    $stmt = $pdo->prepare('
        SELECT p.player_id, p.predicted_rank, p.score_total,
               p.score_ability, p.score_course, p.score_today, p.score_weather,
               e.lane, e.exhibit_time, e.start_timing, e.motor_2rate, e.exhibit_course,
               IF(e.start_timing IS NOT NULL AND e.start_timing < 0, 1, 0) AS is_flying,
               pl.name, pl.grade,
               pp.win_rate, pp.fukusho_rate,
               pp.c1_rank1, pp.c1_count, pp.c1_fukusho,
               pp.c2_rank1, pp.c2_count, pp.c2_fukusho,
               pp.c3_rank1, pp.c3_count, pp.c3_fukusho,
               pp.c4_rank1, pp.c4_count, pp.c4_fukusho,
               pp.c5_rank1, pp.c5_count, pp.c5_fukusho,
               pp.c6_rank1, pp.c6_count, pp.c6_fukusho
        FROM predictions p
        JOIN entries e ON e.race_id = p.race_id AND e.player_id = p.player_id
        JOIN players pl ON pl.id = p.player_id
        LEFT JOIN player_periods pp
          ON pp.player_id = p.player_id
          AND pp.id = (
            SELECT id FROM player_periods
            WHERE player_id = p.player_id
            ORDER BY year DESC, period DESC
            LIMIT 1
          )
        WHERE p.race_id = ?
        ORDER BY p.predicted_rank ASC
    ');
    $stmt->execute([$raceId]);
    $predictions = $stmt->fetchAll();
    // 予測順位・スコア(1着確率×100)は表示モデル(model_switch.php)の値に差し替える。
    $displayModel = 'v2';
    $predictions  = apply_display_ranks(
        $predictions, load_display_predictions($pdo, $raceId, $date, $displayModel)
    );
} catch (PDOException $e) {
    json_response(['error' => 'データベースエラーが発生しました'], 500);
}

// 予想の根拠(model_explain_lib.php): 表示モデル(v3w / v2)の各特徴量の寄与から、その艇の評価を上げた・
// 下げた要因。旧 v1 の配点(選手能力40点など)の内訳は廃止(2026-11-07)。Standard 以上のみ。
if ($canViewDetails && !empty($predictions)) {
    try {
        $factors = explain_race($pdo, $raceId, $displayModel);
    } catch (Exception $e) {
        $factors = [];
    }
    foreach ($predictions as &$pred) {
        unset($pred['score_ability'], $pred['score_course'], $pred['score_today'], $pred['score_weather']);
        $pred['factors'] = $factors[(int)$pred['player_id']] ?? null;
    }
    unset($pred);
} else {
    $predictions = strip_prediction_details($predictions);
}
usort($predictions, fn($a, $b) => ((int)$a['predicted_rank']) <=> ((int)$b['predicted_rank']));

json_response([
    'race_id'          => $raceId,
    'date'             => $date,
    'venue'            => $venue,
    'race_no'          => (int)$race_no,
    'plan'             => $plan,
    'can_view_details' => $canViewDetails,
    'model'            => $displayModel,
    'predictions'      => $predictions,
]);
