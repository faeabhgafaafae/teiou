<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$step = $_GET['step'] ?? '1';

if ($step === '1') {
    // A. 全訓練データ件数
    $a = $pdo->query("
        SELECT COUNT(*) AS rows, COUNT(DISTINCT e.race_id) AS races,
               MIN(r.date) AS min_date, MAX(r.date) AS max_date
        FROM entries e
        JOIN races r ON e.race_id = r.id
        JOIN results res ON res.race_id = e.race_id AND res.player_id = e.player_id
        WHERE r.date BETWEEN '2026-06-29' AND '2026-07-19'
          AND e.exhibit_time IS NOT NULL AND e.start_timing IS NOT NULL
          AND EXISTS(SELECT 1 FROM results r2 WHERE r2.race_id=r.id AND r2.actual_rank=1)
    ")->fetch();
    echo json_encode(['step' => 1, 'total_training' => $a], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($step === '2') {
    // B. バグ期間・影響会場のみのJOIN成立件数
    $BUG_VENUES = ['丸亀','児島','宮島','徳山','下関','若松','芦屋','福岡','唐津','大村'];
    $ph = implode(',', array_fill(0, count($BUG_VENUES), '?'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS rows, COUNT(DISTINCT e.race_id) AS races
        FROM entries e
        JOIN races r ON e.race_id = r.id
        JOIN results res ON res.race_id = e.race_id AND res.player_id = e.player_id
        WHERE r.date BETWEEN '2026-06-29' AND '2026-07-05'
          AND r.venue IN ($ph)
          AND e.exhibit_time IS NOT NULL AND e.start_timing IS NOT NULL
          AND EXISTS(SELECT 1 FROM results r2 WHERE r2.race_id=r.id AND r2.actual_rank=1)
    ");
    $stmt->execute($BUG_VENUES);
    $b = $stmt->fetch();
    echo json_encode(['step' => 2, 'bug_period_joined' => $b], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($step === '3') {
    // C. バグ期間の影響会場でentriesが存在するレース
    $BUG_VENUES = ['丸亀','児島','宮島','徳山','下関','若松','芦屋','福岡','唐津','大村'];
    $ph = implode(',', array_fill(0, count($BUG_VENUES), '?'));
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT r.id) AS races, COUNT(DISTINCT e.id) AS entries
        FROM entries e
        JOIN races r ON e.race_id = r.id
        WHERE r.date BETWEEN '2026-06-29' AND '2026-07-05'
          AND r.venue IN ($ph)
          AND e.exhibit_time IS NOT NULL AND e.start_timing IS NOT NULL
          AND EXISTS(SELECT 1 FROM results r2 WHERE r2.race_id=r.id AND r2.actual_rank=1)
    ");
    $stmt->execute($BUG_VENUES);
    $c = $stmt->fetch();
    echo json_encode(['step' => 3, 'bug_entries_with_exhibit' => $c], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($step === '4') {
    // D. period x venue_type クロス集計
    $BUG_VENUES = ['丸亀','児島','宮島','徳山','下関','若松','芦屋','福岡','唐津','大村'];
    $ph = implode(',', array_fill(0, count($BUG_VENUES), '?'));
    $stmt = $pdo->prepare("
        SELECT
            CASE WHEN r.date <= '2026-07-05' THEN 'bug_6/29-7/5' ELSE 'clean_7/6-7/19' END AS period,
            CASE WHEN r.venue IN ($ph) THEN 'affected' ELSE 'normal' END AS venue_type,
            COUNT(*) AS rows,
            COUNT(DISTINCT e.race_id) AS races
        FROM entries e
        JOIN races r ON e.race_id = r.id
        JOIN results res ON res.race_id = e.race_id AND res.player_id = e.player_id
        WHERE r.date BETWEEN '2026-06-29' AND '2026-07-19'
          AND e.exhibit_time IS NOT NULL AND e.start_timing IS NOT NULL
          AND EXISTS(SELECT 1 FROM results r2 WHERE r2.race_id=r.id AND r2.actual_rank=1)
        GROUP BY period, venue_type
        ORDER BY period, venue_type
    ");
    $stmt->execute($BUG_VENUES);
    $e = $stmt->fetchAll();
    echo json_encode(['step' => 4, 'cross_table' => $e], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($step === '5') {
    // E. player_id不一致サンプル
    $BUG_VENUES = ['丸亀','児島','宮島','徳山','下関','若松','芦屋','福岡','唐津','大村'];
    $ph = implode(',', array_fill(0, count($BUG_VENUES), '?'));
    $stmt = $pdo->prepare("
        SELECT r.date, r.venue, r.race_no,
               GROUP_CONCAT(DISTINCT e.player_id ORDER BY e.lane) AS entry_pids,
               (SELECT GROUP_CONCAT(DISTINCT res3.player_id ORDER BY res3.actual_rank)
                FROM results res3 WHERE res3.race_id = r.id) AS result_pids
        FROM races r
        JOIN entries e ON e.race_id = r.id
        WHERE r.date BETWEEN '2026-06-29' AND '2026-07-01'
          AND r.venue IN ($ph)
          AND EXISTS(SELECT 1 FROM results r2 WHERE r2.race_id=r.id AND r2.actual_rank=1)
        GROUP BY r.id, r.date, r.venue, r.race_no
        ORDER BY r.date, r.venue, r.race_no
        LIMIT 4
    ");
    $stmt->execute($BUG_VENUES);
    $d = $stmt->fetchAll();
    echo json_encode(['step' => 5, 'player_samples' => $d], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'step=1-5 を指定してください']);
