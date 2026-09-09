<?php
/**
 * temp: users.is_adminカラム追加(使用後にneutralize/削除する)
 * ?action=check : is_adminカラムの有無を確認(読み取り専用)
 * ?action=apply : ALTER TABLEでis_adminカラムを追加
 */
require_once __DIR__ . '/config.php';

$api_key = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
if ($api_key !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$action = $_GET['action'] ?? 'check';

if ($action === 'check') {
    $col = $pdo->query("
        SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_admin'
    ")->fetch();
    echo json_encode(['is_admin_column' => $col ?: null], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'apply') {
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0");
        echo json_encode(['result' => 'ok']);
    } catch (Exception $e) {
        echo json_encode(['result' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid action']);
