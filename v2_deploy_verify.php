<?php
// v2昇格後の動作確認 (確認後削除)
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

$date  = $_GET['date']    ?? date('Y-m-d');
$venue = $_GET['venue']   ?? '';
$rno   = (int)($_GET['race_no'] ?? 1);

$stmt = $pdo->prepare("SELECT r.id FROM races r WHERE r.date=? AND r.venue=? AND r.race_no=? LIMIT 1");
$stmt->execute([$date, $venue, $rno]);
$race = $stmt->fetch();
if (!$race) { echo json_encode(['error'=>'race not found']); exit; }
$race_id = $race['id'];

// predictions の最新行確認
$stmt2 = $pdo->prepare("
    SELECT p.player_id, e.lane, p.predicted_rank, p.score_total,
           p.score_ability, p.score_course, p.score_today, p.score_weather,
           p.model_version, p.created_at
    FROM predictions p
    JOIN entries e ON e.race_id=p.race_id AND e.player_id=p.player_id
    WHERE p.race_id=?
    ORDER BY p.predicted_rank ASC
");
$stmt2->execute([$race_id]);
$rows = $stmt2->fetchAll();

// strategies 確認
$stmt3 = $pdo->prepare("SELECT strategy_type, combinations FROM strategies WHERE race_id=? ORDER BY strategy_type");
$stmt3->execute([$race_id]);
$strategies = $stmt3->fetchAll();

echo json_encode([
    'date' => $date, 'venue' => $venue, 'race_no' => $rno, 'race_id' => $race_id,
    'predictions' => $rows,
    'strategies'  => array_map(function($s) {
        return ['type'=>$s['strategy_type'], 'combos'=>json_decode($s['combinations'])];
    }, $strategies),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
