<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$dsn = 'mysql:host=mysql323.phy.lolipop.lan;dbname=LAA1670504-12;charset=utf8mb4';
$username = 'LAA1670504';
$password = 'teiou';

try {
    $pdo = new PDO($dsn, $username, $password);

    $stmt = $pdo->prepare("SELECT venue_name FROM user_favorites WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $venues = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode($venues, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([]);
}
