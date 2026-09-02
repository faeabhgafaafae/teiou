<?php
// gpt-oss-20bへのモデル変更が実際に動作するか確認する一時エンドポイント(確認後削除予定)
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$date    = $_GET['date']    ?? '2026-08-27';
$venue   = $_GET['venue']   ?? '平和島';
$race_no = $_GET['race_no'] ?? '1';

$pdo = get_db();

$stmt = $pdo->prepare('SELECT id FROM races WHERE date = ? AND venue = ? AND race_no = ?');
$stmt->execute([$date, $venue, (int)$race_no]);
$race = $stmt->fetch();

if (!$race) {
    echo json_encode(['error' => 'レースが見つかりません', 'date' => $date, 'venue' => $venue, 'race_no' => $race_no], JSON_UNESCAPED_UNICODE);
    exit;
}
$raceId = (int)$race['id'];

$stmt = $pdo->prepare('
    SELECT p.player_id, p.predicted_rank, p.score_total,
           p.score_ability, p.score_course, p.score_today, p.score_weather,
           e.lane, pl.name
    FROM predictions p
    JOIN entries e ON e.race_id = p.race_id AND e.player_id = p.player_id
    JOIN players pl ON pl.id = p.player_id
    WHERE p.race_id = ?
    ORDER BY p.predicted_rank ASC
');
$stmt->execute([$raceId]);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    echo json_encode(['error' => '予想データが見つかりません', 'race_id' => $raceId], JSON_UNESCAPED_UNICODE);
    exit;
}

$players = [];
foreach ($rows as $r) {
    $players[] = [
        'player_id'     => (int)$r['player_id'],
        'rank'          => (int)$r['predicted_rank'],
        'lane'          => (int)$r['lane'],
        'name'          => preg_replace('/\s+/', '', $r['name']),
        'score_total'   => (float)$r['score_total'],
    ];
}

$prompt = "以下はボートレースのAI予想データです。1位の選手が有利な理由を1文で述べてください。\n"
        . json_encode($players, JSON_UNESCAPED_UNICODE);

$url = 'https://api.groq.com/openai/v1/chat/completions';
$payload = json_encode([
    'model' => 'openai/gpt-oss-20b',
    'messages' => [['role' => 'user', 'content' => $prompt]],
    'max_tokens' => 200,
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . GROQ_API_KEY,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$result = json_decode($response, true);
$text = $result['choices'][0]['message']['content'] ?? null;

echo json_encode([
    'race_id' => $raceId,
    'player_count' => count($players),
    'http_code' => $httpCode,
    'curl_error' => $curlError ?: null,
    'groq_error' => $result['error'] ?? null,
    'explanation_text' => $text,
    'raw_response' => $result,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
