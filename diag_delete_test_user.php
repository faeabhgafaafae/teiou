<?php
/**
 * temp: 監査用テストアカウント削除エンドポイント（実行後に削除）
 */
define('DB_HOST', 'mysql323.phy.lolipop.lan');
define('DB_NAME', 'LAA1670504-12');
define('DB_USER', 'LAA1670504');
define('DB_PASS', 'teiou');
define('API_KEY', 'teio2025');

header('Content-Type: application/json; charset=utf-8');

$api_key = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
if ($api_key !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$action = $_GET['action'] ?? 'check';
$email  = $_GET['email']  ?? '';

if (!$email) {
    http_response_code(400);
    echo json_encode(['error' => 'email は必須です']);
    exit;
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$stmt = $pdo->prepare('SELECT id, email, name, plan, created_at FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['found' => false]);
    exit;
}
$userId = (int)$user['id'];

if ($action === 'check') {
    // このuser_idを参照している全テーブルをinformation_schemaから機械的に洗い出す
    $stmt = $pdo->prepare("
        SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE REFERENCED_TABLE_SCHEMA = ?
          AND REFERENCED_TABLE_NAME = 'users'
    ");
    $stmt->execute([DB_NAME]);
    $fks = $stmt->fetchAll();

    $favStmt = $pdo->prepare('SELECT id, venue_name FROM user_favorites WHERE user_id = ?');
    $favStmt->execute([$userId]);
    $favorites = $favStmt->fetchAll();

    echo json_encode([
        'found'              => true,
        'user'               => $user,
        'foreign_keys_to_users' => $fks,
        'user_favorites'     => $favorites,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete') {
    $pdo->beginTransaction();
    try {
        $del1 = $pdo->prepare('DELETE FROM user_favorites WHERE user_id = ?');
        $del1->execute([$userId]);
        $favDeleted = $del1->rowCount();

        $del2 = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $del2->execute([$userId]);
        $userDeleted = $del2->rowCount();

        $pdo->commit();

        echo json_encode([
            'success'      => true,
            'user_id'      => $userId,
            'favorites_deleted' => $favDeleted,
            'user_deleted' => $userDeleted,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'action は check または delete を指定してください']);
