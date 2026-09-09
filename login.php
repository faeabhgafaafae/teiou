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

// --- ブルートフォース対策 (DB-based レートリミット) ---
// ロリポップ共用サーバーはAPCu/Redis不可のためDBで管理する
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `login_attempts` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `ip_hash` VARCHAR(64) NOT NULL,
        `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_ip_time` (`ip_hash`, `attempted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) { /* 既存テーブルなら無視 */ }

$ip       = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ip_hash  = hash('sha256', $ip);
$window   = date('Y-m-d H:i:s', strtotime('-15 minutes'));

$stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND attempted_at > ?');
$stmt->execute([$ip_hash, $window]);
if ((int)$stmt->fetchColumn() >= 10) {
    json_response(['error' => 'ログイン試行が多すぎます。15分後にお試しください。'], 429);
}

// 古いレコードを低確率でクリーンアップ（テーブルが肥大化しないよう）
if (rand(1, 100) === 1) {
    $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
}
// --- ここまで ---

$stmt = $pdo->prepare('SELECT id, email, name, plan, password FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    // 失敗を記録
    $pdo->prepare('INSERT INTO login_attempts (ip_hash, attempted_at) VALUES (?, NOW())')
        ->execute([$ip_hash]);
    json_response(['error' => 'メールアドレスまたはパスワードが正しくありません'], 401);
}

// 成功: 同IPの直近失敗ログを削除
$pdo->prepare('DELETE FROM login_attempts WHERE ip_hash = ? AND attempted_at > ?')
    ->execute([$ip_hash, $window]);

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];

unset($user['password']);
json_response(['ok' => true, 'user' => $user]);
