<?php
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    json_response(['success' => false, 'error' => 'Not logged in'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);
$venue_name = isset($input['venue']) ? trim($input['venue']) : '';

if (empty($venue_name)) {
    json_response(['success' => false, 'error' => 'No venue'], 400);
}

$pdo = get_db();
$userId = $user['id'];

$stmt = $pdo->prepare('SELECT id FROM user_favorites WHERE user_id = :user_id AND venue_name = :venue_name');
$stmt->execute([':user_id' => $userId, ':venue_name' => $venue_name]);
$favorite = $stmt->fetch();

if ($favorite) {
    $pdo->prepare('DELETE FROM user_favorites WHERE id = :id')->execute([':id' => $favorite['id']]);
    json_response(['success' => true, 'status' => 'removed']);
}

// Freeプランはお気に入り登録数を3件までに制限する(Standard/Premiumは無制限)
if (($user['plan'] ?? 'free') === 'free') {
    $countStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM user_favorites WHERE user_id = :user_id');
    $countStmt->execute([':user_id' => $userId]);
    $count = (int)$countStmt->fetch()['cnt'];
    if ($count >= 3) {
        json_response(['success' => false, 'error' => 'favorite_limit', 'message' => 'Freeプランはお気に入り登録が3件までです']);
    }
}

$pdo->prepare('INSERT INTO user_favorites (user_id, venue_name) VALUES (:user_id, :venue_name)')
    ->execute([':user_id' => $userId, ':venue_name' => $venue_name]);
json_response(['success' => true, 'status' => 'added']);
