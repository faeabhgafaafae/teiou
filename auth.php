<?php
session_start();
require_once __DIR__ . '/config.php';

function get_db(): PDO {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT id, email, name, plan, is_admin, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    if ($user) {
        $user['is_admin'] = (bool)$user['is_admin'];
    }
    return $user;
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'ログインが必要です']);
        exit;
    }
    return $user;
}

function is_admin_user(): bool {
    $user = current_user();
    return $user['is_admin'] ?? false;
}

// 管理者専用JSON APIで使う。管理者以外は403 JSONを返して終了する
function require_admin_json(): void {
    if (!is_admin_user()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => '管理者権限が必要です'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// バッチ処理用のAPI_KEYと、管理画面からの管理者セッションの両方を許可する
function require_admin_or_api_key(string $provided_key): void {
    if ($provided_key === API_KEY || is_admin_user()) {
        return;
    }
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
