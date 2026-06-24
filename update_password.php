<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// ログイン状態のチェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'ログインしていません。']);
    exit;
}

// 入力データの取得
$input = json_decode(file_get_contents('php://input'), true);
$new_password = isset($input['password']) ? trim($input['password']) : '';

// 8文字以上の制限に変更
if (strlen($new_password) < 8) {
    http_response_code(400);
    echo json_encode(['error' => 'パスワードは8文字以上で入力してください。']);
    exit;
}

// データベース接続設定（ロリポップの環境等に合わせて修正してください）
$dsn = 'mysql:host=localhost;dbname=ユーザーのDB名;charset=utf8mb4';
$username = 'LAA1670504';
$password = 'teiou';

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // パスワードを安全にハッシュ化
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    // ログイン中のユーザーのパスワードを更新
    $stmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :id");
    $stmt->execute([
        ':password' => $hashed_password,
        ':id' => $_SESSION['user_id']
    ]);

    echo json_encode(['success' => true, 'message' => 'パスワードを更新しました。']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'データベースエラーが発生しました。']);
}
