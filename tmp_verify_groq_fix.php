<?php
// gemini_explain.phpと同一プロンプトでの動作確認用エンドポイント(確認後削除予定)
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
        'score_ability' => (float)$r['score_ability'],
        'score_course'  => (float)$r['score_course'],
        'score_today'   => (float)$r['score_today'],
        'score_weather' => (float)$r['score_weather'],
    ];
}

function call_groq_test(string $prompt): array {
    $url = 'https://api.groq.com/openai/v1/chat/completions';
    $payload = json_encode([
        'model' => 'openai/gpt-oss-20b',
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_tokens' => 500,
        'reasoning_effort' => 'low',
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
    curl_close($ch);
    $result = json_decode($response, true);
    return [
        'http_code' => $httpCode,
        'text' => $result['choices'][0]['message']['content'] ?? null,
        'finish_reason' => $result['choices'][0]['finish_reason'] ?? null,
    ];
}

// ── type=overall と同一プロンプト ──
$promptOverall = "ボートレースのAI予想結果を以下に示します。\n"
        . "予想1位の選手が有利な理由と、2位以下で逆転の可能性がある選手について、\n"
        . "ボートレース解説者として端的に述べてください。\n\n"
        . "【条件】\n"
        . "・150文字以内に必ず収める\n"
        . "・「〜しよう」「〜してください」など呼びかけ表現は使わない\n"
        . "・スコアの数字は使わず、高い・低い・優位・不利などの表現を使う\n"
        . "・選手名+「選手」と呼ぶ\n"
        . "・選手名の間にスペースは入れない（例: 菊地敬介選手）\n"
        . "・ボートレース用語を使う（インコース・アウトコース・スタートタイミング等）\n"
        . "・「選手能力」「コース補正」「当日情報」「気象」の4項目に触れる\n"
        . "・「〜でしょう」「〜可能です」など曖昧な表現を避ける\n"
        . "・1位の理由を1文、注目選手を1文、計2文程度でまとめる\n"
        . "・「ボートレース解説者として」「予想結果を分析すると」などの導入文は不要\n"
        . "・いきなり解説内容から始める\n\n"
        . "【スコア説明】\n"
        . "score_total: 総合スコア（100点満点）\n"
        . "score_ability: 選手能力スコア（最大40点・全国/当地勝率ベース）\n"
        . "score_course: コース補正スコア（最大35点・枠番の有利不利）\n"
        . "score_today: 当日情報スコア（最大35点・展示タイム/スタートタイミング/モーター2連率）\n"
        . "score_weather: 気象スコア（最大5点・風速/波高）\n\n"
        . "【予想データ】\n"
        . json_encode($players, JSON_UNESCAPED_UNICODE);

$overallResult = call_groq_test($promptOverall);

// ── type=personal と同一プロンプト ──
$promptPersonal = "ボートレースのAI予想結果です。\n"
        . "各選手について、なぜこのスコアになったか・注意点を\n"
        . "各選手50文字以内で個別に解説してください。\n\n"
        . "【条件】\n"
        . "・選手名のスペースは除去（例: 菊地敬介選手）\n"
        . "・導入文不要、いきなり各選手の解説から始める\n"
        . "・以下のJSON形式のみで返答する（他のテキスト不要）:\n"
        . "[{\"player_id\":1,\"explanation\":\"解説文\"}, ...]\n\n"
        . "【スコア説明】\n"
        . "score_ability: 選手能力（最大40点・全国/当地勝率ベース）\n"
        . "score_course: コース補正（最大35点・枠番の有利不利）\n"
        . "score_today: 当日情報（最大35点・展示タイム/ST/モーター）\n"
        . "score_weather: 気象（最大5点・風速/波高）\n\n"
        . "【予想データ】\n"
        . json_encode($players, JSON_UNESCAPED_UNICODE);

$personalResult = call_groq_test($promptPersonal);

$jsonText = $personalResult['text'];
$personalsParsed = null;
if ($jsonText && preg_match('/\[[\s\S]*\]/', $jsonText, $m)) {
    $personalsParsed = json_decode($m[0], true);
}

echo json_encode([
    'race_id' => $raceId,
    'player_count' => count($players),
    'overall' => $overallResult,
    'personal' => [
        'raw' => $personalResult,
        'parsed_ok' => is_array($personalsParsed),
        'parsed_count' => is_array($personalsParsed) ? count($personalsParsed) : 0,
        'parsed_sample' => is_array($personalsParsed) ? array_slice($personalsParsed, 0, 2) : null,
    ],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
