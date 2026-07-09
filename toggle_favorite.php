<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$venue_name = isset($input['venue']) ? trim($input['venue']) : '';

if (empty($venue_name)) {
    echo json_encode(['success' => false, 'error' => 'No venue']);
    exit;
}

$dsn = 'mysql:host=mysql323.phy.lolipop.lan;dbname=LAA1670504-12;charset=utf8mb4';
$username = 'LAA1670504';
$password = 'teiou';

try {
    $pdo = new PDO($dsn, $username, $password);

    $stmt = $pdo->prepare("SELECT id FROM user_favorites WHERE user_id = :user_id AND venue_name = :venue_name");
    $stmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':venue_name' => $venue_name
    ]);
    $favorite = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($favorite) {
        $deleteStmt = $pdo->prepare("DELETE FROM user_favorites WHERE id = :id");
        $deleteStmt->execute([':id' => $favorite['id']]);
        echo json_encode(['success' => true, 'status' => 'removed']);
    } else {
        // Freeプランはお気に入り登録数を3件までに制限する(Standard/Premiumは無制限)
        $planStmt = $pdo->prepare("SELECT plan FROM users WHERE id = :user_id");
        $planStmt->execute([':user_id' => $_SESSION['user_id']]);
        $planRow = $planStmt->fetch(PDO::FETCH_ASSOC);
        $plan = $planRow['plan'] ?? 'free';

        if ($plan === 'free') {
            $countStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM user_favorites WHERE user_id = :user_id");
            $countStmt->execute([':user_id' => $_SESSION['user_id']]);
            $count = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['cnt'];

            if ($count >= 3) {
                echo json_encode(['success' => false, 'error' => 'favorite_limit', 'message' => 'Freeプランはお気に入り登録が3件までです']);
                exit;
            }
        }

        $insertStmt = $pdo->prepare("INSERT INTO user_favorites (user_id, venue_name) VALUES (:user_id, :venue_name)");
        $insertStmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':venue_name' => $venue_name
        ]);
        echo json_encode(['success' => true, 'status' => 'added']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
