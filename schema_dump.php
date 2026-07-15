<?php
/**
 * temp: DBスキーマ一括ダンプエンドポイント(使用後にneutralize/削除する)
 */
$api_key = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
if ($api_key !== 'teio2025') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$conn = mysqli_connect('mysql323.phy.lolipop.lan', 'LAA1670504', 'teiou', 'LAA1670504-12');
if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗: ' . mysqli_connect_error()]);
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

$tables = [];
$res = mysqli_query($conn, 'SHOW TABLES');
while ($row = mysqli_fetch_row($res)) {
    $tables[] = $row[0];
}
sort($tables);

header('Content-Type: text/plain; charset=utf-8');

echo "-- 艇王 DBスキーマダンプ\n";
echo "-- 生成日時: " . date('Y-m-d H:i:s') . "\n";
echo "-- テーブル数: " . count($tables) . "\n\n";

foreach ($tables as $table) {
    $escaped = mysqli_real_escape_string($conn, $table);
    $r = mysqli_query($conn, "SHOW CREATE TABLE `$escaped`");
    $row = mysqli_fetch_assoc($r);
    $createStmt = $row['Create Table'] ?? '';
    echo "-- ----------------------------\n";
    echo "-- Table: $table\n";
    echo "-- ----------------------------\n";
    echo $createStmt . ";\n\n";
}

mysqli_close($conn);
