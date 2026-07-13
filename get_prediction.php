<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$date    = $_GET['date']    ?? '';
$venue   = $_GET['venue']   ?? '';
$race_no = $_GET['race_no'] ?? '';

if (!$date || !$venue || !$race_no) {
    json_response(['error' => 'date, venue, race_no は必須です'], 400);
}

// AI予測(スコア・順位)はFree以上で公開。詳細スコア内訳はPremium限定。
$user = current_user();
$plan = $user['plan'] ?? 'free';
$isPremium = ($plan === 'premium');

$pdo = get_db();

$stmt = $pdo->prepare('SELECT id FROM races WHERE date = ? AND venue = ? AND race_no = ?');
$stmt->execute([$date, $venue, (int)$race_no]);
$race = $stmt->fetch();

if (!$race) {
    json_response(['error' => 'レースが見つかりません'], 404);
}

$raceId = (int)$race['id'];

$stmt = $pdo->prepare('
    SELECT p.player_id, p.predicted_rank, p.score_total,
           p.score_ability, p.score_course, p.score_today, p.score_weather,
           e.lane, e.exhibit_time, e.start_timing, e.motor_2rate, pl.name, pl.grade,
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

// Premium限定: スコア内訳の生データを計算して付加する
if ($isPremium && !empty($predictions)) {
    $exhibitTimes = array_filter(array_column($predictions, 'exhibit_time'), fn($v) => $v !== null);
    $exhibitMin = $exhibitTimes ? (float)min($exhibitTimes) : null;
    $exhibitMax = $exhibitTimes ? (float)max($exhibitTimes) : null;

    $stmtW = $pdo->prepare('SELECT wind_speed, wind_dir, wave_height, weather FROM races WHERE id = ?');
    $stmtW->execute([$raceId]);
    $weatherRow    = $stmtW->fetch();
    $windSpeed     = (float)($weatherRow['wind_speed']  ?? 0);
    $waveHeight    = (float)($weatherRow['wave_height'] ?? 0);
    $weatherPenalty = min(1.0, ($windSpeed / 10 + $waveHeight / 30) / 2);

    foreach ($predictions as &$pred) {
        $playerId = (int)$pred['player_id'];
        $lane     = (int)$pred['lane'];

        $stmtL = $pdo->prepare('
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN r2.actual_rank = 1 THEN 1 ELSE 0 END) as rank1
            FROM results r2 JOIN races rc ON r2.race_id = rc.id
            WHERE r2.player_id = ? AND rc.venue = ? AND rc.date >= DATE_SUB(?, INTERVAL 2 YEAR)
        ');
        $stmtL->execute([$playerId, $venue, $date]);
        $local = $stmtL->fetch();
        $winRateNational = (float)($pred['win_rate'] ?? 0);
        $winRateLocal    = (int)$local['total'] > 0
            ? (float)$local['rank1'] / (float)$local['total'] * 100
            : $winRateNational;
        $winRateWeighted = $winRateNational * 0.4 + $winRateLocal * 0.6;
        $scoreAbilityRaw = min(40, $winRateWeighted / 10 * 40);

        $stmtC = $pdo->prepare('
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN r2.actual_rank = 1 THEN 1 ELSE 0 END) as rank1,
                   SUM(CASE WHEN r2.actual_rank <= 2 THEN 1 ELSE 0 END) as rank2,
                   SUM(CASE WHEN r2.actual_rank <= 3 THEN 1 ELSE 0 END) as rank3
            FROM results r2 JOIN races rc ON r2.race_id = rc.id
            WHERE r2.player_id = ? AND r2.lane = ? AND rc.date >= DATE_SUB(?, INTERVAL 2 YEAR)
        ');
        $stmtC->execute([$playerId, $lane, $date]);
        $courseRes = $stmtC->fetch();
        if ((int)$courseRes['total'] > 0) {
            $r1 = (float)$courseRes['rank1'] / (float)$courseRes['total'];
            $r2 = (float)$courseRes['rank2'] / (float)$courseRes['total'];
            $r3 = (float)$courseRes['rank3'] / (float)$courseRes['total'];
            $scoreCourseRaw = ($r1 * 0.6 + $r2 * 0.25 + $r3 * 0.15) * 35;
            $courseWinRate  = round($r1 * 100, 1);
            $courseR2Rate   = round($r2 * 100, 1);
            $courseR3Rate   = round($r3 * 100, 1);
        } else {
            $courseAvg      = [1=>0.50, 2=>0.15, 3=>0.12, 4=>0.10, 5=>0.08, 6=>0.05];
            $scoreCourseRaw = ($courseAvg[$lane] ?? 0.08) * 35;
            $courseWinRate  = null;
            $courseR2Rate   = null;
            $courseR3Rate   = null;
        }

        $exhibitTime  = $pred['exhibit_time'] !== null ? (float)$pred['exhibit_time'] : null;
        $scoreExhibit = 0.0;
        if ($exhibitTime !== null && $exhibitMin !== null && $exhibitMax !== null) {
            $range = $exhibitMax - $exhibitMin;
            $scoreExhibit = $range > 0 ? (($exhibitMax - $exhibitTime) / $range) * 15 : 7.5;
        }

        $startTiming = $pred['start_timing'] !== null ? (float)$pred['start_timing'] : null;
        $scoreSt     = 0.0;
        $isFlying    = false;
        if ($startTiming !== null) {
            if ($startTiming < 0) { $isFlying = true; }
            else { $scoreSt = max(0, (0.30 - $startTiming) / 0.30 * 10); }
        }

        $motor2rate    = $pred['motor_2rate'] !== null ? (float)$pred['motor_2rate'] : null;
        $scoreMotor    = $motor2rate !== null ? min(10, $motor2rate / 60 * 10) : 0.0;
        $scoreTodayRaw = $scoreExhibit + $scoreSt + $scoreMotor;

        if ($lane == 1)     { $scoreWeatherRaw = 5 - $weatherPenalty * 2; }
        elseif ($lane <= 3) { $scoreWeatherRaw = 3 - $weatherPenalty; }
        else                { $scoreWeatherRaw = 2 + $weatherPenalty; }
        $scoreWeatherRaw = max(0, min(5, $scoreWeatherRaw));

        $pred['breakdown'] = [
            'win_rate_national' => round($winRateNational, 2),
            'win_rate_local'    => round($winRateLocal, 2),
            'local_total'       => (int)$local['total'],
            'win_rate_weighted' => round($winRateWeighted, 2),
            'score_ability_raw' => round($scoreAbilityRaw, 2),
            'course_total'      => (int)$courseRes['total'],
            'course_win_rate'   => $courseWinRate,
            'course_r2_rate'    => $courseR2Rate,
            'course_r3_rate'    => $courseR3Rate,
            'score_course_raw'  => round($scoreCourseRaw, 2),
            'score_exhibit_raw' => round($scoreExhibit, 2),
            'score_st_raw'      => round($scoreSt, 2),
            'score_motor_raw'   => round($scoreMotor, 2),
            'score_today_raw'   => round($scoreTodayRaw, 2),
            'score_weather_raw' => round($scoreWeatherRaw, 2),
            'wind_speed'        => $windSpeed,
            'wind_dir'          => $weatherRow['wind_dir'] ?? null,
            'wave_height'       => $waveHeight,
            'is_flying'         => $isFlying,
        ];
    }
    unset($pred);
}

json_response([
    'race_id'     => $raceId,
    'date'        => $date,
    'venue'       => $venue,
    'race_no'     => (int)$race_no,
    'plan'        => $plan,
    'is_premium'  => $isPremium,
    'predictions' => $predictions,
]);
