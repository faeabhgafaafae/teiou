<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$BUG_VENUES = ['丸亀','児島','宮島','徳山','下関','若松','芦屋','福岡','唐津','大村'];
$ph = implode(',', array_fill(0, count($BUG_VENUES), '?'));

$step = $_GET['step'] ?? '1';

if ($step === '1') {
    // バグ期間の影響会場でentriesが存在するレース数 (JOINなし)
    $stmt = $pdo->prepare("
        SELECT r.venue,
               COUNT(DISTINCT r.id) AS races,
               COUNT(DISTINCT e.id) AS entries,
               SUM(CASE WHEN e.exhibit_time IS NOT NULL AND e.start_timing IS NOT NULL THEN 1 ELSE 0 END) AS with_both
        FROM entries e
        JOIN races r ON e.race_id = r.id
        WHERE r.date BETWEEN '2026-06-29' AND '2026-07-05'
          AND r.venue IN ($ph)
          AND EXISTS(SELECT 1 FROM results r2 WHERE r2.race_id=r.id AND r2.actual_rank=1)
        GROUP BY r.venue ORDER BY r.venue
    ");
    $stmt->execute($BUG_VENUES);
    echo json_encode(['step'=>1, 'data'=>$stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($step === '2') {
    // 訓練データ件数: player_id JOINあり (バグ期間・バグ会場のみ)
    // → JOINが成立しなければ0になる
    $stmt = $pdo->prepare("
        SELECT r.venue, COUNT(*) AS matched_rows, COUNT(DISTINCT e.race_id) AS matched_races
        FROM entries e
        JOIN races r ON e.race_id = r.id
        JOIN results res ON res.race_id = e.race_id AND res.player_id = e.player_id
        WHERE r.date BETWEEN '2026-06-29' AND '2026-07-05'
          AND r.venue IN ($ph)
          AND e.exhibit_time IS NOT NULL AND e.start_timing IS NOT NULL
        GROUP BY r.venue ORDER BY r.venue
    ");
    $stmt->execute($BUG_VENUES);
    echo json_encode(['step'=>2, 'data'=>$stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($step === '3') {
    // 訓練データ総件数 (バグ会場除外前): 1日ずつ集計 (タイムアウト回避)
    $date = $_GET['date'] ?? '2026-07-10';
    $row = $pdo->query("
        SELECT COUNT(*) AS rows, COUNT(DISTINCT e.race_id) AS races
        FROM entries e
        JOIN races r ON e.race_id = r.id
        JOIN results res ON res.race_id = e.race_id AND res.player_id = e.player_id
        WHERE r.date = '$date'
          AND e.exhibit_time IS NOT NULL AND e.start_timing IS NOT NULL
          AND EXISTS(SELECT 1 FROM results r2 WHERE r2.race_id=r.id AND r2.actual_rank=1)
    ")->fetch();
    echo json_encode(['step'=>3, 'date'=>$date, 'data'=>$row], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($step === '4') {
    // 確認: player_id一致サンプル (正常期間の影響会場)
    $stmt = $pdo->prepare("
        SELECT r.date, r.venue, r.race_no,
               GROUP_CONCAT(DISTINCT e.player_id ORDER BY e.lane) AS entry_pids,
               (SELECT GROUP_CONCAT(DISTINCT res3.player_id ORDER BY res3.actual_rank)
                FROM results res3 WHERE res3.race_id = r.id) AS result_pids
        FROM races r
        JOIN entries e ON e.race_id = r.id
        WHERE r.date BETWEEN '2026-07-06' AND '2026-07-08'
          AND r.venue IN ($ph)
          AND EXISTS(SELECT 1 FROM results r2 WHERE r2.race_id=r.id AND r2.actual_rank=1)
        GROUP BY r.id, r.date, r.venue, r.race_no
        ORDER BY r.date, r.venue, r.race_no
        LIMIT 4
    ");
    $stmt->execute($BUG_VENUES);
    echo json_encode(['step'=>4, 'data'=>$stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'step=1-4 を指定']);
