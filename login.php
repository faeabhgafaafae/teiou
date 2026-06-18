<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method Not Allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$email    = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

if ($email === '' || $password === '') {
    json_response(['error' => 'メールアドレスとパスワードを入力してください'], 400);
}

$pdo = get_db();
$stmt = $pdo->prepare('SELECT id, email, name, plan, password FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    json_response(['error' => 'メールアドレスまたはパスワードが正しくありません'], 401);
}

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];

unset($user['password']);
json_response(['ok' => true, 'user' => $user]);
