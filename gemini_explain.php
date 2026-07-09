<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$race_id = $_GET['race_id'] ?? '';
if (!$race_id) {
    json_response(['error' => 'race_id は必須です'], 400);
}

$type = $_GET['type'] ?? 'overall';
$pdo = get_db();

// ── 選手データ取得（共通） ──
function fetch_players(PDO $pdo, int $race_id): array {
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
    $stmt->execute([$race_id]);
    $rows = $stmt->fetchAll();
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
    return $players;
}

// ── Groq API呼び出し（共通） ──
function call_groq(string $prompt): string {
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
    return $text;
}

// ══════════════════════════════════════════
// type=personal: 個別選手解説
// ══════════════════════════════════════════
if ($type === 'personal') {
    // キャッシュ確認
    $stmt = $pdo->prepare('SELECT explanation_personal FROM predictions WHERE race_id = ? AND explanation_personal IS NOT NULL LIMIT 1');
    $stmt->execute([(int)$race_id]);
    $cached = $stmt->fetch();

    if ($cached) {
        $stmt = $pdo->prepare('
            SELECT p.player_id, pl.name, p.explanation_personal
            FROM predictions p
            JOIN players pl ON pl.id = p.player_id
            WHERE p.race_id = ?
            ORDER BY p.predicted_rank ASC
        ');
        $stmt->execute([(int)$race_id]);
        $personals = [];
        foreach ($stmt->fetchAll() as $r) {
            $personals[] = [
                'player_id'   => (int)$r['player_id'],
                'name'        => preg_replace('/\s+/', '', $r['name']),
                'explanation' => $r['explanation_personal'],
            ];
        }
        json_response(['personals' => $personals, 'cached' => true]);
    }

    $players = fetch_players($pdo, (int)$race_id);
    if (empty($players)) {
        json_response(['error' => '予想データが見つかりません'], 404);
    }

    $prompt = "ボートレースのAI予想結果です。\n"
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

    $text = call_groq($prompt);

    // JSONパース（```json ... ``` で囲まれている場合に対応）
    $jsonText = $text;
    if (preg_match('/\[[\s\S]*\]/', $text, $m)) {
        $jsonText = $m[0];
    }
    $personals = json_decode($jsonText, true);

    if (!is_array($personals)) {
        json_response(['error' => 'Groq APIのレスポンスをパースできませんでした'], 500);
    }

    // DB保存
    $stmt = $pdo->prepare('UPDATE predictions SET explanation_personal = ? WHERE race_id = ? AND player_id = ?');
    foreach ($personals as $p) {
        $pid = (int)($p['player_id'] ?? 0);
        $exp = $p['explanation'] ?? '';
        if ($pid && $exp) {
            $stmt->execute([$exp, (int)$race_id, $pid]);
        }
    }

    // 保存後に全選手分を返す
    $stmt2 = $pdo->prepare('
        SELECT p.player_id, pl.name, p.explanation_personal
        FROM predictions p
        JOIN players pl ON pl.id = p.player_id
        WHERE p.race_id = ?
        ORDER BY p.predicted_rank ASC
    ');
    $stmt2->execute([(int)$race_id]);
    $result = [];
    foreach ($stmt2->fetchAll() as $r) {
        $result[] = [
            'player_id'   => (int)$r['player_id'],
            'name'        => preg_replace('/\s+/', '', $r['name']),
            'explanation' => $r['explanation_personal'],
        ];
    }
    json_response(['personals' => $result]);
}

// ══════════════════════════════════════════
// type=overall: 全体解説（既存）
// ══════════════════════════════════════════
$stmt = $pdo->prepare('SELECT explanation FROM predictions WHERE race_id = ? LIMIT 1');
$stmt->execute([(int)$race_id]);
$row = $stmt->fetch();

if (!$row) {
    json_response(['error' => '予想データが見つかりません'], 404);
}

if ($row['explanation'] !== null) {
    json_response(['explanation' => $row['explanation'], 'cached' => true]);
}

$players = fetch_players($pdo, (int)$race_id);
if (empty($players)) {
    json_response(['error' => '予想データが見つかりません'], 404);
}

$prompt = "ボートレースのAI予想結果を以下に示します。\n"
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

$text = call_groq($prompt);

$stmt = $pdo->prepare('UPDATE predictions SET explanation = ? WHERE race_id = ?');
$stmt->execute([$text, (int)$race_id]);

json_response(['explanation' => $text]);
