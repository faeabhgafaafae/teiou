<?php
/**
 * 一時確認用API: analysis.php実装前のスキーマ・データ量調査
 * 確認が終わったら削除すること
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db();

function describe($pdo, $table) {
    try {
        $stmt = $pdo->query('DESCRIBE ' . $table);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

$out = [];
$out['players']        = describe($pdo, 'players');
$out['player_periods']  = describe($pdo, 'player_periods');
$out['results']         = describe($pdo, 'results');
$out['race_payouts']    = describe($pdo, 'race_payouts');
$out['races']           = describe($pdo, 'races');

// course列が実際にいつから入っているか(date別のNOT NULL件数)
$stmt = $pdo->query('
    SELECT r.date, COUNT(*) AS total, SUM(CASE WHEN res.course IS NOT NULL THEN 1 ELSE 0 END) AS course_notnull
    FROM results res
    JOIN races r ON r.id = res.race_id
    GROUP BY r.date
    ORDER BY r.date DESC
    LIMIT 20
');
$out['course_by_date'] = $stmt->fetchAll();

// player_periodsの年度/期のバリエーション
$stmt = $pdo->query('SELECT DISTINCT year, period FROM player_periods ORDER BY year DESC, period DESC LIMIT 10');
$out['player_periods_year_period'] = $stmt->fetchAll();

// resultsの総件数・日付範囲
$stmt = $pdo->query('SELECT MIN(r.date) AS min_date, MAX(r.date) AS max_date, COUNT(*) AS total FROM results res JOIN races r ON r.id=res.race_id');
$out['results_range'] = $stmt->fetch();

// race_payoutsの総件数・日付範囲
$stmt = $pdo->query('SELECT MIN(r.date) AS min_date, MAX(r.date) AS max_date, COUNT(*) AS total FROM race_payouts rp JOIN races r ON r.id=rp.race_id');
$out['payouts_range'] = $stmt->fetch();

// playersの総数
$stmt = $pdo->query('SELECT COUNT(*) AS cnt FROM players');
$out['players_count'] = $stmt->fetch();

json_response($out);
