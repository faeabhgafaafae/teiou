<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'ログインしていません。']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$new_password = isset($input['password']) ? trim($input['password']) : '';

if (strlen($new_password) < 8) {
    http_response_code(400);
    echo json_encode(['error' => 'パスワードは8文字以上で入力してください。']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :id");
    $stmt->execute([
        ':password' => $hashed_password,
        ':id'       => $_SESSION['user_id']
    ]);

    echo json_encode(['success' => true, 'message' => 'パスワードを更新しました。']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'データベースエラーが発生しました。']);
}
