<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS
    );
    $stmt = $pdo->prepare("SELECT venue_name FROM user_favorites WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $venues = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode($venues, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([]);
}
