<?php
// tmp: is_admin不具合の原因調査用(読み取り専用、確認後削除)
require_once __DIR__ . '/config.php';

$api_key = $_GET['api_key'] ?? '';
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

$col = $pdo->query("
    SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_admin'
")->fetch();

$rows = $pdo->query('SELECT id, email, plan, is_admin FROM users ORDER BY id')->fetchAll();

echo json_encode(['column' => $col, 'users' => $rows], JSON_UNESCAPED_UNICODE);
