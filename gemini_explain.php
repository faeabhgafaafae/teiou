<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/model_switch.php';
require_once __DIR__ . '/plan_access.php';
require_once __DIR__ . '/model_explain_lib.php';

// 解説文のプロンプトの版。2 = 統計モデルの寄与(評価を上げた/下げた要因)を渡す版(2026-11-07〜)。
// キャッシュ(explanation / explanation_personal)は同じ版のものだけ使い、古い版(旧 v1 のスコア内訳で
// 書かれた解説)は次に見られたときに作り直す。
const EXPLAIN_PROMPT_VERSION = 3;   // 3: 影響の大小の言い回し・数値の数・推測の禁止を追加(2026-11-07)

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// AI解説は Standard 以上(plan_access.php)。未ログインは 401(画面はログイン誘導)、Free は 403
$user = current_user();
if (!$user) {
    json_response(['error' => 'login_required', 'message' => 'ログインが必要です'], 401);
}
if (!plan_can_view_details($user)) {
    json_response(['error' => 'premium_required', 'message' => 'AI解説はStandard/Premiumプラン限定です'], 403);
}

$race_id = $_GET['race_id'] ?? '';
if (!$race_id) {
    json_response(['error' => 'race_id は必須です'], 400);
}

$type = $_GET['type'] ?? 'overall';


$pdo = get_db();

// 予測順位・スコアは表示モデル(model_switch.php)に合わせる。解説文は順位に依存するため、
// キャッシュも表示モデルのテーブル(v3w=predictions_v2 / v2=predictions)に分けて保存する。
$stmtD = $pdo->prepare('SELECT date FROM races WHERE id = ?');
$stmtD->execute([(int)$race_id]);
$raceDate     = (string)($stmtD->fetchColumn() ?: '');
$displayModel = 'v2';
$display      = load_display_predictions($pdo, (int)$race_id, $raceDate, $displayModel);
$expTable     = prediction_table($displayModel);
$cols = ['explanation TEXT DEFAULT NULL', 'explanation_personal TEXT DEFAULT NULL', 'explanation_ver INT DEFAULT NULL'];
foreach ($cols as $def) {
    if ($expTable !== 'predictions_v2' && strpos($def, 'explanation_ver') !== 0) continue;  // predictions は既存列
    try { $pdo->exec("ALTER TABLE {$expTable} ADD COLUMN {$def}"); }
    catch (PDOException $e) { /* 1060 = カラム既存 */ }
}

// ── 選手データ取得(共通): 予測順位・1着確率と、統計モデルの寄与から求めた評価の要因 ──
function fetch_players(PDO $pdo, int $race_id, array $display, string $model): array {
    $stmt = $pdo->prepare('
        SELECT p.player_id, p.predicted_rank, p.score_total, e.lane, pl.name
        FROM predictions p
        JOIN entries e ON e.race_id = p.race_id AND e.player_id = p.player_id
        JOIN players pl ON pl.id = p.player_id
        WHERE p.race_id = ?
        ORDER BY p.predicted_rank ASC
    ');
    $stmt->execute([$race_id]);
    $rows = apply_display_ranks($stmt->fetchAll(), $display);
    usort($rows, fn($a, $b) => ((int)$a['predicted_rank']) <=> ((int)$b['predicted_rank']));
    try {
        $factors = explain_race($pdo, $race_id, $model);
    } catch (Exception $e) {
        $factors = [];
    }
    $players = [];
    foreach ($rows as $r) {
        $pid = (int)$r['player_id'];
        $players[] = [
            'player_id'   => $pid,
            '予測順位'     => (int)$r['predicted_rank'],
            '枠番'         => (int)$r['lane'],
            '選手名'       => preg_replace('/\s+/', '', $r['name']),
            '1着確率'      => round((float)$r['score_total'], 1) . '%',
            '評価の要因'   => isset($factors[$pid]) ? explain_prompt_line($factors[$pid]) : 'データなし',
        ];
    }
    return $players;
}

// プロンプト共通の説明(旧 v1 の「選手能力・コース補正・当日情報・気象」の配点は使わない)
const EXPLAIN_MODEL_NOTE =
      "【予想の仕組み】\n"
    . "予想は統計モデル(ロジスティック回帰)が出した各艇の1着確率で、確率の高い順が予測順位です。\n"
    . "「評価の要因」は、モデルの計算でその艇の評価をレース平均より上げた要因・下げた要因(影響の大・中・小)です。\n"
    . "解説は必ずこのデータにある要因だけを根拠にし、データにないこと(選手の調子・過去の対戦・天候の影響など)は書かない。\n"
    . "「選手能力」「コース補正」「当日情報」「気象」「スコア」「点」という言葉は使わない。\n"
    . "「影響大」「影響中」「影響小」という表記はそのまま書かず、「大きな強み」「やや不利」など自然な言い方にする。\n"
    . "数値は1人の選手につき2つまで。スタートでの逆転・展開など、データにない予想は書かない。\n\n";

// ── Groq API呼び出し（共通） ──
function call_groq(string $prompt): string {
    $url = 'https://api.groq.com/openai/v1/chat/completions';
    $payload = json_encode([
        'model' => 'openai/gpt-oss-20b',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => 500,
        // gpt-oss-20bは推論モデルでreasoningトークンがmax_tokensを消費するため、
        // effortを下げて本文(content)が空にならないようにする
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
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log('Groq API通信エラー: ' . $curlError);
        json_response(['error' => 'Groq API通信エラー: ' . $curlError], 500);
    }
    if ($httpCode !== 200) {
        $errBody = json_decode($response, true);
        $errType = $errBody['error']['type'] ?? 'unknown';
        $errMsg = $errBody['error']['message'] ?? $response;
        error_log("Groq APIエラー (HTTP {$httpCode}, type={$errType}): {$errMsg}");
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
    $stmt = $pdo->prepare("SELECT explanation_personal FROM {$expTable} WHERE race_id = ? AND explanation_personal IS NOT NULL
                           AND explanation_ver = ? LIMIT 1");
    $stmt->execute([(int)$race_id, EXPLAIN_PROMPT_VERSION]);
    $cached = $stmt->fetch();

    if ($cached) {
        $stmt = $pdo->prepare("
            SELECT p.player_id, pl.name, p.explanation_personal
            FROM {$expTable} p
            JOIN players pl ON pl.id = p.player_id
            WHERE p.race_id = ?
            ORDER BY p.predicted_rank ASC
        ");
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

    $players = fetch_players($pdo, (int)$race_id, $display, $displayModel);
    if (empty($players)) {
        json_response(['error' => '予想データが見つかりません'], 404);
    }

    $prompt = "ボートレースの予想結果です。各選手について、なぜこの予測順位になったかと注意点を、\n"
            . "各選手50文字以内で個別に解説してください。\n\n"
            . EXPLAIN_MODEL_NOTE
            . "【条件】\n"
            . "・選手名のスペースは除去（例: 菊地敬介選手）\n"
            . "・確率の数字は使わない\n"
            . "・導入文不要、いきなり各選手の解説から始める\n"
            . "・以下のJSON形式のみで返答する（他のテキスト不要）:\n"
            . "[{\"player_id\":1,\"explanation\":\"解説文\"}, ...]\n\n"
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
    $stmt = $pdo->prepare("UPDATE {$expTable} SET explanation_personal = ?, explanation_ver = ? WHERE race_id = ? AND player_id = ?");
    foreach ($personals as $p) {
        $pid = (int)($p['player_id'] ?? 0);
        $exp = $p['explanation'] ?? '';
        if ($pid && $exp) {
            $stmt->execute([$exp, EXPLAIN_PROMPT_VERSION, (int)$race_id, $pid]);
        }
    }

    // 保存後に全選手分を返す
    $stmt2 = $pdo->prepare("
        SELECT p.player_id, pl.name, p.explanation_personal
        FROM {$expTable} p
        JOIN players pl ON pl.id = p.player_id
        WHERE p.race_id = ?
        ORDER BY p.predicted_rank ASC
    ");
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
$stmt = $pdo->prepare("SELECT explanation, explanation_ver FROM {$expTable} WHERE race_id = ? LIMIT 1");
$stmt->execute([(int)$race_id]);
$row = $stmt->fetch();

if (!$row) {
    json_response(['error' => '予想データが見つかりません'], 404);
}

if ($row['explanation'] !== null && (int)$row['explanation_ver'] === EXPLAIN_PROMPT_VERSION) {
    json_response(['explanation' => $row['explanation'], 'cached' => true]);
}

$players = fetch_players($pdo, (int)$race_id, $display, $displayModel);
if (empty($players)) {
    json_response(['error' => '予想データが見つかりません'], 404);
}

$prompt = "ボートレースの予想結果を以下に示します。\n"
        . "予想1位の選手が有利な理由と、2位以下で逆転の可能性がある選手について、\n"
        . "ボートレース解説者として端的に述べてください。\n\n"
        . EXPLAIN_MODEL_NOTE
        . "【条件】\n"
        . "・150文字以内に必ず収める\n"
        . "・「〜しよう」「〜してください」など呼びかけ表現は使わない\n"
        . "・確率の数字は使わず、高い・低い・優位・不利などの表現を使う\n"
        . "・選手名+「選手」と呼ぶ\n"
        . "・選手名の間にスペースは入れない（例: 菊地敬介選手）\n"
        . "・ボートレース用語を使う（インコース・アウトコース・スタートタイミング等）\n"
        . "・「〜でしょう」「〜可能です」など曖昧な表現を避ける\n"
        . "・1位の理由を1文、注目選手を1文、計2文程度でまとめる\n"
        . "・「ボートレース解説者として」「予想結果を分析すると」などの導入文は不要\n"
        . "・いきなり解説内容から始める\n\n"
        . "【予想データ】\n"
        . json_encode($players, JSON_UNESCAPED_UNICODE);

$text = call_groq($prompt);

$stmt = $pdo->prepare("UPDATE {$expTable} SET explanation = ?, explanation_ver = ? WHERE race_id = ?");
$stmt->execute([$text, EXPLAIN_PROMPT_VERSION, (int)$race_id]);

json_response(['explanation' => $text]);
