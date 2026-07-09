<?php
/**
 * 艇王 - データ分析: レーサー名検索API
 * GET /search_players.php?keyword=中辻
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$user = current_user();
$plan = $user['plan'] ?? 'free';
if ($plan !== 'premium') {
    json_response(['error' => 'premium_required', 'message' => 'データ分析はPremium会員限定です'], 403);
}

$keyword = trim($_GET['keyword'] ?? '');
if ($keyword === '') {
    json_response(['players' => []]);
}

// 選手名は全角スペース区切りで登録されているため、検索キーワード側もスペースを除去して部分一致させる
$normalized = str_replace([' ', '　'], '', $keyword);

$pdo = get_db();
$stmt = $pdo->prepare("
    SELECT id, name, name_kana, branch, grade
    FROM players
    WHERE REPLACE(REPLACE(name, '　', ''), ' ', '') LIKE CONCAT('%', ?, '%')
       OR REPLACE(REPLACE(name_kana, '　', ''), ' ', '') LIKE CONCAT('%', ?, '%')
    LIMIT 20
");
$stmt->execute([$normalized, $normalized]);
$players = $stmt->fetchAll();

json_response(['players' => $players]);
