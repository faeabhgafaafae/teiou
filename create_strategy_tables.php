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
        CREATE TABLE IF NOT EXISTS strategies (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            race_id      INT NOT NULL,
            strategy_type VARCHAR(20) NOT NULL,
            combinations JSON NOT NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_race_strategy (race_id, strategy_type),
            KEY idx_race_id (race_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS strategy_results (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            strategy_id INT NOT NULL,
            race_id     INT NOT NULL,
            is_hit      TINYINT(1) NOT NULL DEFAULT 0,
            payout      INT NOT NULL DEFAULT 0,
            cost        INT NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_strategy_id (strategy_id),
            KEY idx_race_id (race_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    echo json_encode(['ok' => true, 'message' => 'strategies / strategy_results テーブルを作成しました']);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
