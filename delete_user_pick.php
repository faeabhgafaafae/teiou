<?php
/**
 * マイ的中トラッカー: 買い目削除API
 * POST /delete_user_pick.php
 * Body JSON: { "id": <pick_id> }
 * 本人の記録のみ削除可能 (WHERE id=? AND user_id=?)
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_login();
if ($user['plan'] !== 'premium') {
    json_response(['error' => 'premium_required', 'message' => 'Premium会員限定機能です'], 403);
}

$body = json_decode(file_get_contents('php://input'), true);
$id = isset($body['id']) ? (int)$body['id'] : 0;
if ($id <= 0) {
    json_response(['error' => 'invalid_id', 'message' => '無効なIDです'], 400);
}

$pdo = get_db();
$stmt = $pdo->prepare('DELETE FROM user_picks WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $user['id']]);

if ($stmt->rowCount() === 0) {
    json_response(['error' => 'not_found', 'message' => '対象の記録が見つかりませんでした'], 404);
}

json_response(['ok' => true]);
