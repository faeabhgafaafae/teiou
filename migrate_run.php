<?php
/**
 * 一時実行用マイグレーションスクリプト
 * migrations/create_race_payouts.sql を実行する。
 * 実行後は必ずサーバー・リポジトリの両方から削除すること。
 */

define('DB_HOST', 'mysql323.phy.lolipop.lan');
define('DB_NAME', 'LAA1670504-12');
define('DB_USER', 'LAA1670504');
define('DB_PASS', 'teiou');
define('API_KEY', 'teio2025');

header('Content-Type: application/json; charset=utf-8');

$api_key = $_POST['api_key'] ?? $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

$sql_path = __DIR__ . '/migrations/create_race_payouts.sql';
if (!file_exists($sql_path)) {
    http_response_code(404);
    echo json_encode(['error' => 'migrations/create_race_payouts.sql が見つかりません']);
    exit;
}
$sql = file_get_contents($sql_path);

$mysqli = mysqli_init();
if (!mysqli_real_connect($mysqli, DB_HOST, DB_USER, DB_PASS, DB_NAME)) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗: ' . mysqli_connect_error()]);
    exit;
}
mysqli_set_charset($mysqli, 'utf8mb4');

$errors = [];
$ok = true;

if (mysqli_multi_query($mysqli, $sql)) {
    do {
        if ($result = mysqli_store_result($mysqli)) {
            mysqli_free_result($result);
        }
        if (mysqli_error($mysqli)) {
            $ok = false;
            $errors[] = mysqli_error($mysqli);
        }
    } while (mysqli_more_results($mysqli) && mysqli_next_result($mysqli));
} else {
    $ok = false;
    $errors[] = mysqli_error($mysqli);
}

mysqli_close($mysqli);

echo json_encode([
    'ok'      => $ok,
    'errors'  => $errors,
    'message' => $ok
        ? 'race_payouts テーブルを作成しました'
        : 'マイグレーション実行中にエラーが発生しました（テーブルが既に存在する場合もここに出ます）',
], JSON_UNESCAPED_UNICODE);
