<?php
/**
 * temp: スコアリング重み変更シミュレーション用の生データエクスポート（調査後に削除）
 * predictions/entries/results/player_periods を突き合わせ、
 * 435レース分の全エントリの生データをそのまま返す。
 * ランキング再計算・仮説検証はこのエンドポイントの出力を使ってローカルで行う。
 */
define('DB_HOST', 'mysql323.phy.lolipop.lan');
define('DB_NAME', 'LAA1670504-12');
define('DB_USER', 'LAA1670504');
define('DB_PASS', 'teiou');

header('Content-Type: application/json; charset=utf-8');

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$rows = $pdo->query('
    SELECT
        p.race_id,
        p.player_id,
        e.lane,
        p.predicted_rank AS orig_predicted_rank,
        p.score_total     AS orig_score_total,
        p.score_ability,
        p.score_course,
        p.score_today,
        p.score_weather,
        res.actual_rank,
        e.start_timing AS exhibit_start_timing,
        (SELECT pp.race_count FROM player_periods pp
         WHERE pp.player_id = p.player_id
         ORDER BY pp.year DESC, pp.period DESC LIMIT 1) AS race_count
    FROM predictions p
    JOIN entries e  ON e.race_id = p.race_id AND e.player_id = p.player_id
    JOIN results res ON res.race_id = p.race_id AND res.player_id = p.player_id
    JOIN races r ON r.id = p.race_id
    ORDER BY p.race_id, e.lane
')->fetchAll();

echo json_encode(['count' => count($rows), 'rows' => $rows], JSON_UNESCAPED_UNICODE);
