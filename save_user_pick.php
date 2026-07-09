<?php
/**
 * マイ的中トラッカー: 買い目登録API
 * POST /save_user_pick.php
 * Body (JSON): { venue, date, race_no, bet_type, combo, cost }
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_login();
if ($user['plan'] !== 'premium') {
    json_response(['error' => 'premium_required', 'message' => 'マイ的中トラッカーはPremium会員限定です'], 403);
}

$data = json_decode(file_get_contents('php://input'), true);

$venue    = trim($data['venue']    ?? '');
$date     = trim($data['date']     ?? '');
$race_no  = (int)($data['race_no'] ?? 0);
$bet_type = trim($data['bet_type'] ?? '');
$combo    = trim($data['combo']    ?? '');
$cost     = (int)($data['cost']    ?? 0);

if (!$venue || !$date || !$race_no || !$bet_type || !$combo || $cost <= 0) {
    json_response(['error' => '必須項目が不足しています'], 400);
}

$VALID_BET_TYPES = ['3連単', '3連複', '2連単', '2連複', '拡連複', '単勝', '複勝'];
if (!in_array($bet_type, $VALID_BET_TYPES, true)) {
    json_response(['error' => '無効な賭式です'], 400);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_response(['error' => '日付の形式が不正です'], 400);
}

$pdo = get_db();

$stmt = $pdo->prepare('SELECT id FROM races WHERE venue = ? AND date = ? AND race_no = ?');
$stmt->execute([$venue, $date, $race_no]);
$race = $stmt->fetch();
if (!$race) {
    json_response(['error' => 'レースが見つかりません。会場・日付・レース番号を確認してください'], 404);
}

$stmt = $pdo->prepare('
    INSERT INTO user_picks (user_id, race_id, bet_type, combo, cost)
    VALUES (?, ?, ?, ?, ?)
');
$stmt->execute([$user['id'], $race['id'], $bet_type, $combo, $cost]);

json_response(['id' => (int)$pdo->lastInsertId(), 'message' => '買い目を記録しました']);
