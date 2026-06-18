<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method Not Allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$name     = trim($input['name'] ?? '');
$email    = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

if ($name === '' || $email === '' || $password === '') {
    json_response(['error' => '全ての項目を入力してください'], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['error' => 'メールアドレスの形式が正しくありません'], 400);
}

if (mb_strlen($password) < 8) {
    json_response(['error' => 'パスワードは8文字以上で入力してください'], 400);
}

$pdo = get_db();

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    json_response(['error' => 'このメールアドレスは既に登録されています'], 409);
}

$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $pdo->prepare('INSERT INTO users (email, password, name, plan, created_at) VALUES (?, ?, ?, ?, NOW())');
$stmt->execute([$email, $hash, $name, 'free']);

$user_id = $pdo->lastInsertId();
$_SESSION['user_id'] = $user_id;

json_response([
    'ok'   => true,
    'user' => ['id' => (int)$user_id, 'email' => $email, 'name' => $name, 'plan' => 'free'],
], 201);
