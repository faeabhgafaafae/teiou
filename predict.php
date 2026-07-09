<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/generate_strategies.php';

define('DB_HOST', 'mysql323.phy.lolipop.lan');
define('DB_USER', 'LAA1670504');
define('DB_PASS', 'teiou');
define('DB_NAME', 'LAA1670504-12');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$date    = $_GET['date']    ?? date('Y-m-d');
$venue   = $_GET['venue']   ?? '';
$race_no = (int)($_GET['race_no'] ?? 0);

if (!$venue || !$race_no) {
    http_response_code(400);
    echo json_encode(['error' => 'venue と race_no は必須です']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, wind_speed, wind_dir, wave_height, weather, temperature, water_temperature
    FROM races
    WHERE date=? AND venue=? AND race_no=?
    LIMIT 1
");
$stmt->execute([$date, $venue, $race_no]);
$race = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$race) {
    echo json_encode(['error' => 'レースが見つかりません']);
    exit;
}

$race_id = $race['id'];

$stmt = $pdo->prepare("
    SELECT e.id, e.lane, e.player_id, e.exhibit_time, e.start_timing, e.motor_2rate, e.odds,
           e.adjust_weight, e.tilt, e.propeller_mark, e.parts_exchange, e.exhibit_course,
           p.name, p.grade
    FROM entries e
    JOIN players p ON e.player_id = p.id
    WHERE e.race_id = ?
    ORDER BY e.lane ASC, e.id DESC
");
$stmt->execute([$race_id]);
$all_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$entries = [];
$seen_lanes = [];
foreach ($all_entries as $e) {
    if (!in_array($e['lane'], $seen_lanes)) {
        $entries[] = $e;
        $seen_lanes[] = $e['lane'];
    }
}

if (empty($entries)) {
    echo json_encode(['error' => '出走表データがありません']);
    exit;
}

$exhibit_times = array_filter(array_column($entries, 'exhibit_time'), fn($v) => $v !== null);
$exhibit_min = $exhibit_times ? min($exhibit_times) : null;
$exhibit_max = $exhibit_times ? max($exhibit_times) : null;

$scores = [];

foreach ($entries as $e) {
    $player_id = $e['player_id'];
    $lane      = $e['lane'];

    $stmt2 = $pdo->prepare("
        SELECT win_rate, fukusho_rate, race_count
        FROM player_periods
        WHERE player_id = ?
        ORDER BY year DESC, period DESC
        LIMIT 1
    ");
    $stmt2->execute([$player_id]);
    $period = $stmt2->fetch(PDO::FETCH_ASSOC);

    $win_rate_national = $period['win_rate'] ?? 0;
    $race_count        = $period['race_count'] ?? 0;

    $stmt2 = $pdo->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN r2.actual_rank = 1 THEN 1 ELSE 0 END) as rank1
        FROM results r2
        JOIN races rc ON r2.race_id = rc.id
        WHERE r2.player_id = ? AND rc.venue = ?
        AND rc.date >= DATE_SUB(?, INTERVAL 2 YEAR)
    ");
    $stmt2->execute([$player_id, $venue, $date]);
    $local = $stmt2->fetch(PDO::FETCH_ASSOC);
    $win_rate_local = ($local['total'] > 0) ? ($local['rank1'] / $local['total'] * 100) : $win_rate_national;

    $win_rate_weighted = $win_rate_national * 0.4 + $win_rate_local * 0.6;
    $score_ability_raw = min(40, $win_rate_weighted / 10 * 40);

    $stmt2 = $pdo->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN r2.actual_rank = 1 THEN 1 ELSE 0 END) as rank1,
               SUM(CASE WHEN r2.actual_rank <= 2 THEN 1 ELSE 0 END) as rank2,
               SUM(CASE WHEN r2.actual_rank <= 3 THEN 1 ELSE 0 END) as rank3
        FROM results r2
        JOIN races rc ON r2.race_id = rc.id
        WHERE r2.player_id = ? AND r2.lane = ?
        AND rc.date >= DATE_SUB(?, INTERVAL 2 YEAR)
    ");
    $stmt2->execute([$player_id, $lane, $date]);
    $course = $stmt2->fetch(PDO::FETCH_ASSOC);

    // コース補正の配点は35点満点(旧20点満点から変更。2026-07シミュレーションでコース優位性が
    // 過小評価されていたことが判明したため引き上げ。他要素との合算は正規化して100点満点を維持する)
    if ($course['total'] > 0) {
        $r1_rate = $course['rank1'] / $course['total'];
        $r2_rate = $course['rank2'] / $course['total'];
        $r3_rate = $course['rank3'] / $course['total'];
        $score_course_raw = ($r1_rate * 0.6 + $r2_rate * 0.25 + $r3_rate * 0.15) * 35;
    } else {
        $course_avg = [1=>0.50, 2=>0.15, 3=>0.12, 4=>0.10, 5=>0.08, 6=>0.05];
        $score_course_raw = ($course_avg[$lane] ?? 0.08) * 35;
    }

    $score_exhibit = 0;
    if ($e['exhibit_time'] !== null && $exhibit_min !== null && $exhibit_max !== null) {
        $range = $exhibit_max - $exhibit_min;
        $score_exhibit = ($range > 0) ? (($exhibit_max - $e['exhibit_time']) / $range) * 15 : 7.5;
    }

    $score_st = 0;
    $is_flying = false;
    if ($e['start_timing'] !== null) {
        if ($e['start_timing'] < 0) {
            $is_flying = true;
        } else {
            $score_st = max(0, (0.30 - $e['start_timing']) / 0.30 * 10);
        }
    }

    $score_motor = 0;
    if ($e['motor_2rate'] !== null) {
        $score_motor = min(10, $e['motor_2rate'] / 60 * 10);
    }

    $score_today_raw = $score_exhibit + $score_st + $score_motor;

    $wind_speed  = $race['wind_speed'] ?? 0;
    $wave_height = $race['wave_height'] ?? 0;
    $weather_penalty = min(1.0, ($wind_speed / 10 + $wave_height / 30) / 2);
    if ($lane == 1) {
        $score_weather_raw = 5 - $weather_penalty * 2;
    } elseif ($lane <= 3) {
        $score_weather_raw = 3 - $weather_penalty;
    } else {
        $score_weather_raw = 2 + $weather_penalty;
    }
    $score_weather_raw = max(0, min(5, $score_weather_raw));

    // 各要素の配点(能力40+コース35+当日情報35+気象5=115点満点)を、
    // 従来通りの100点満点スケールに正規化する。
    // 内訳の合算値が100を超えるとai-predict.phpのスコア内訳バー(width:X%)が
    // はみ出すため、個々のraw値ではなく正規化後の値をpredictionsに保存・返却する。
    $raw_max = 40 + 35 + 35 + 5;
    $norm    = 100 / $raw_max;

    $score_ability = $score_ability_raw * $norm;
    $score_course  = $score_course_raw  * $norm;
    $score_today   = $score_today_raw   * $norm;
    $score_weather = $score_weather_raw * $norm;

    $score_total = $score_ability + $score_course + $score_today + $score_weather;
    if ($is_flying) $score_total -= 10;
    if ($race_count < 10) $score_total *= 0.70;
    $score_total = max(0, $score_total);

    $scores[] = [
        'lane'              => (int)$lane,
        'player_id'         => (int)$player_id,
        'name'              => $e['name'],
        'grade'             => $e['grade'],
        'exhibit_time'      => $e['exhibit_time'],
        'start_timing'      => $e['start_timing'],
        'motor_2rate'       => $e['motor_2rate'],
        'adjust_weight'     => $e['adjust_weight'],
        'tilt'              => $e['tilt'],
        'propeller_mark'    => $e['propeller_mark'],
        'parts_exchange'    => $e['parts_exchange'],
        'exhibit_course'    => $e['exhibit_course'] !== null ? (int)$e['exhibit_course'] : null,
        'win_rate_national' => round($win_rate_national, 2),
        'win_rate_local'    => round($win_rate_local, 2),
        'score_ability'     => round($score_ability, 2),
        'score_course'      => round($score_course, 2),
        'score_today'       => round($score_today, 2),
        'score_weather'     => round($score_weather, 2),
        'score_total'       => round($score_total, 2),
        'is_flying'         => $is_flying,
    ];
}

// スコア降順でソート（同点の場合はlane順）
usort($scores, function($a, $b) {
    if ($b['score_total'] != $a['score_total']) {
        return $b['score_total'] <=> $a['score_total'];
    }
    return $a['lane'] <=> $b['lane'];
});

// 予測順位を付与（参照渡しを使わない）
for ($i = 0; $i < count($scores); $i++) {
    $scores[$i]['predicted_rank'] = $i + 1;
}

// 予測結果をDBに保存
$stmt = $pdo->prepare("
    INSERT INTO predictions
        (race_id, player_id, predicted_rank, score_total,
         score_ability, score_course, score_today, score_weather, created_at)
    VALUES
        (:race_id, :player_id, :predicted_rank, :score_total,
         :score_ability, :score_course, :score_today, :score_weather, NOW())
    ON DUPLICATE KEY UPDATE
        predicted_rank=VALUES(predicted_rank),
        score_total=VALUES(score_total),
        score_ability=VALUES(score_ability),
        score_course=VALUES(score_course),
        score_today=VALUES(score_today),
        score_weather=VALUES(score_weather),
        created_at=NOW()
");

for ($i = 0; $i < count($scores); $i++) {
    $stmt->execute([
        ':race_id'        => $race_id,
        ':player_id'      => $scores[$i]['player_id'],
        ':predicted_rank' => $scores[$i]['predicted_rank'],
        ':score_total'    => $scores[$i]['score_total'],
        ':score_ability'  => $scores[$i]['score_ability'],
        ':score_course'   => $scores[$i]['score_course'],
        ':score_today'    => $scores[$i]['score_today'],
        ':score_weather'  => $scores[$i]['score_weather'],
    ]);
}

// 予測生成のタイミングで戦略買い目を自動生成
try {
    generate_and_save_strategies($pdo, $race_id);
} catch (Exception $e) {
    // strategies テーブル未作成時など非致命的エラーは無視
}

// このAPIは直前情報(exhibit_time等)を無料機能(直前情報タブ・出走表フォールバック等)が
// 参照するため誰でも呼び出せる状態を維持しつつ、AI予測由来のフィールド
// (score_*・predicted_rank)はStandard/Premium限定として出力時のみ除去する。
// DBへの保存・戦略生成は呼び出し元のプランに関わらず常に行う(データを最新に保つため)。
$user   = current_user();
$plan   = $user['plan'] ?? 'free';
$isPaid = $user && in_array($plan, ['standard', 'premium'], true);

$responseScores = $scores;
if (!$isPaid) {
    foreach ($responseScores as &$s) {
        $s['score_ability']  = null;
        $s['score_course']   = null;
        $s['score_today']    = null;
        $s['score_weather']  = null;
        $s['score_total']    = null;
        $s['predicted_rank'] = null;
    }
    unset($s);
    // predicted_rank順のままだとAIの1位候補が並び順から推測できてしまうため、枠番順に戻す
    usort($responseScores, function($a, $b) { return $a['lane'] <=> $b['lane']; });
}

echo json_encode([
    'date'        => $date,
    'venue'       => $venue,
    'race_no'     => $race_no,
    'race_id'     => $race_id,
    'entry_count' => count($entries),
    'ai_locked'   => !$isPaid,
    'weather'     => [
        'wind_speed'        => $race['wind_speed'],
        'wind_dir'          => $race['wind_dir'],
        'wave_height'       => $race['wave_height'],
        'weather'           => $race['weather'],
        'temperature'       => $race['temperature'],
        'water_temperature' => $race['water_temperature'],
    ],
    'predictions' => $responseScores,
], JSON_UNESCAPED_UNICODE);
