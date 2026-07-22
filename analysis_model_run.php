<?php
/**
 * tmp: AI予想スコアリングモデルの妥当性検証用一時エンドポイント(使用後にneutralize予定)
 * 生データ(row-level)をJSONで返し、統計処理はクライアント側(ローカルPython)で行う。
 *
 * GET /analysis_model_run.php?section=predictions
 *   -> predictions × results × entries(odds) の行単位データ(全期間)
 * GET /analysis_model_run.php?section=combo_odds
 *   -> strategies.combinations で選定された各組番のodds_3t上のオッズ(行単位)
 * GET /analysis_model_run.php?section=hits
 *   -> strategy_results(is_hit=1 かつ payout=0 を除外)の行単位データ
 * GET /analysis_model_run.php?section=payouts_tansho
 *   -> race_payouts の単勝(bet_type='単勝')行単位データ(ベースライン比較用)
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗']);
    exit;
}

$section = $_GET['section'] ?? '';

if ($section === 'predictions') {
    try {
        $stmt = $pdo->query('
            SELECT
                r.id AS race_id, r.date, r.venue, r.race_no,
                res.lane, p.predicted_rank, p.score_total,
                p.score_ability, p.score_course, p.score_today, p.score_weather,
                res.actual_rank
            FROM predictions p
            JOIN races r   ON r.id = p.race_id
            JOIN results res ON res.race_id = p.race_id AND res.player_id = p.player_id
            WHERE res.actual_rank IS NOT NULL
            ORDER BY r.id, res.lane
        ');
        echo json_encode(['rows' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($section === 'combo_odds') {
    try {
        $stmt = $pdo->query('
            SELECT
                s.race_id, r.date, s.strategy_type, jt.combo, o.odds
            FROM strategies s
            JOIN races r ON r.id = s.race_id
            JOIN strategy_results sr ON sr.strategy_id = s.id
            JOIN JSON_TABLE(s.combinations, "$[*]" COLUMNS (combo VARCHAR(10) PATH "$")) AS jt
            LEFT JOIN odds_3t o ON o.race_id = s.race_id AND o.combo = jt.combo COLLATE utf8mb4_0900_ai_ci
            WHERE NOT (sr.is_hit = 1 AND sr.payout = 0)
        ');
        echo json_encode(['rows' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($section === 'hits') {
    $stmt = $pdo->query('
        SELECT
            s.race_id, r.date, s.strategy_type, sr.is_hit, sr.payout, sr.cost
        FROM strategies s
        JOIN races r ON r.id = s.race_id
        JOIN strategy_results sr ON sr.strategy_id = s.id
        WHERE NOT (sr.is_hit = 1 AND sr.payout = 0)
    ');
    echo json_encode(['rows' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($section === 'payouts_tansho') {
    $stmt = $pdo->query('
        SELECT r.id AS race_id, r.date, rp.combo, rp.amount, rp.popularity
        FROM race_payouts rp
        JOIN races r ON r.id = rp.race_id
        WHERE rp.bet_type = "単勝"
    ');
    echo json_encode(['rows' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'unknown section']);
