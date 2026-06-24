<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$race_id = $_GET['race_id'] ?? '';
if (!$race_id) {
    json_response(['error' => 'race_id は必須です'], 400);
}

$pdo = get_db();

// キャッシュ確認
$stmt = $pdo->prepare('SELECT explanation FROM predictions WHERE race_id = ? LIMIT 1');
$stmt->execute([(int)$race_id]);
$row = $stmt->fetch();

if (!$row) {
    json_response(['error' => '予想データが見つかりません'], 404);
}

if ($row['explanation'] !== null) {
    json_response(['explanation' => $row['explanation'], 'cached' => true]);
}

// 選手データ取得
$stmt = $pdo->prepare('
    SELECT p.predicted_rank, p.score_total,
           p.score_ability, p.score_course, p.score_today, p.score_weather,
           e.lane, pl.name
    FROM predictions p
    JOIN entries e ON e.race_id = p.race_id AND e.player_id = p.player_id
    JOIN players pl ON pl.id = p.player_id
    WHERE p.race_id = ?
    ORDER BY p.predicted_rank ASC
');
$stmt->execute([(int)$race_id]);
$rows = $stmt->fetchAll();

$players = [];
foreach ($rows as $r) {
    $players[] = [
        'rank'          => (int)$r['predicted_rank'],
        'lane'          => (int)$r['lane'],
        'name'          => $r['name'],
        'score_total'   => (float)$r['score_total'],
        'score_ability' => (float)$r['score_ability'],
        'score_course'  => (float)$r['score_course'],
        'score_today'   => (float)$r['score_today'],
        'score_weather' => (float)$r['score_weather'],
    ];
}

$prompt = "あなたはボートレースの専門解説者です。\n以下のAI予想結果をもとに、なぜこの順位になったか・注意点を\n200文字程度で日本語で簡潔に解説してください。\n\n" . json_encode($players, JSON_UNESCAPED_UNICODE);

// Groq API呼び出し
$url = 'https://api.groq.com/openai/v1/chat/completions';

$payload = json_encode([
    'model' => 'llama-3.1-8b-instant',
    'messages' => [
        ['role' => 'user', 'content' => $prompt]
    ],
    'max_tokens' => 500,
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

if ($curlError) {
    json_response(['error' => 'Groq API通信エラー: ' . $curlError], 500);
}

if ($httpCode !== 200) {
    json_response(['error' => 'Groq APIエラー (HTTP ' . $httpCode . ')'], 500);
}

$result = json_decode($response, true);
$text = $result['choices'][0]['message']['content'] ?? null;

if (!$text) {
    json_response(['error' => 'Groq APIから解説を取得できませんでした'], 500);
}

// キャッシュ保存
$stmt = $pdo->prepare('UPDATE predictions SET explanation = ? WHERE race_id = ?');
$stmt->execute([$text, (int)$race_id]);

json_response(['explanation' => $text]);
