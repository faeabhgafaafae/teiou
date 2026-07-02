<?php
define('DB_HOST', 'mysql323.phy.lolipop.lan');
define('DB_NAME', 'LAA1670504-12');
define('DB_USER', 'LAA1670504');
define('DB_PASS', 'teiou');

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS odds_multi (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            race_id    INT NOT NULL,
            bet_type   VARCHAR(20) NOT NULL,
            combo      VARCHAR(20) NOT NULL,
            odds       VARCHAR(20) NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_race_bettype_combo (race_id, bet_type, combo),
            KEY idx_race_bettype (race_id, bet_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    echo json_encode(['ok' => true, 'message' => 'odds_multi テーブルを作成しました']);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
