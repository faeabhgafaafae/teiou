<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_login();

$input = json_decode(file_get_contents('php://input'), true);
$plan = $input['plan'] ?? '';

if (!in_array($plan, ['free', 'standard', 'premium'], true)) {
    json_response(['error' => 'planは free, standard, premium のいずれかを指定してください'], 400);
}

$pdo = get_db();
$stmt = $pdo->prepare('UPDATE users SET plan = ? WHERE id = ?');
$stmt->execute([$plan, $user['id']]);

json_response(['success' => true, 'plan' => $plan]);
