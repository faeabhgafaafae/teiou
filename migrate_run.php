<?php
$api_key = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
if ($api_key !== 'teio2025') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$sql = file_get_contents(__DIR__ . '/migrations/create_user_picks.sql');
if ($sql === false) {
    http_response_code(500);
    echo json_encode(['error' => 'SQLファイル読み込み失敗']);
    exit;
}

$conn = mysqli_connect('mysql323.phy.lolipop.lan', 'LAA1670504', 'teiou', 'LAA1670504-12');
if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗: ' . mysqli_connect_error()]);
    exit;
}

if (mysqli_multi_query($conn, $sql)) {
    do { mysqli_use_result($conn) && mysqli_free_result(mysqli_use_result($conn)); } while (mysqli_more_results($conn) && mysqli_next_result($conn));
    echo json_encode(['success' => true, 'message' => 'create_user_picks.sql 実行完了']);
} else {
    http_response_code(500);
    echo json_encode(['error' => mysqli_error($conn)]);
}
mysqli_close($conn);
