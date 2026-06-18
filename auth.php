<?php
session_start();

function get_db(): PDO {
    $dsn = 'mysql:host=mysql323.phy.lolipop.lan;dbname=LAA1670504-12;charset=utf8mb4';
    $pdo = new PDO($dsn, 'LAA1670504', 'teiou', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT id, email, name, plan, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
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

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
