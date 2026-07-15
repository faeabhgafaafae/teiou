<?php
/**
 * temp: スキーマ不整合4件を修正するマイグレーションランナー(使用後にneutralize/削除する)
 * ?action=check  : 既存データがFK制約に違反していないか確認(読み取り専用)
 * ?action=apply  : 4件のマイグレーションを適用
 */
$api_key = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
if ($api_key !== 'teio2025') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$pdo = new PDO(
    'mysql:host=mysql323.phy.lolipop.lan;dbname=LAA1670504-12;charset=utf8mb4',
    'LAA1670504', 'teiou',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$action = $_GET['action'] ?? 'check';

if ($action === 'check') {
    // user_picks の孤児レコード(usersに存在しないuser_id)
    $orphanPicks = $pdo->query('
        SELECT up.id, up.user_id FROM user_picks up
        LEFT JOIN users u ON u.id = up.user_id
        WHERE u.id IS NULL
    ')->fetchAll();

    // user_favorites の孤児レコード
    $orphanFavs = $pdo->query('
        SELECT uf.id, uf.user_id FROM user_favorites uf
        LEFT JOIN users u ON u.id = uf.user_id
        WHERE u.id IS NULL
    ')->fetchAll();

    // odds_multi の孤児レコード(racesに存在しないrace_id、FK追加前チェック)
    $orphanOddsMulti = $pdo->query('
        SELECT COUNT(*) AS cnt FROM odds_multi om
        LEFT JOIN races r ON r.id = om.race_id
        WHERE r.id IS NULL
    ')->fetch();

    // user_favorites の現在のcollation
    $collation = $pdo->query("
        SELECT TABLE_COLLATION FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = 'LAA1670504-12' AND TABLE_NAME = 'user_favorites'
    ")->fetch();

    echo json_encode([
        'orphan_user_picks'        => $orphanPicks,
        'orphan_user_favorites'    => $orphanFavs,
        'orphan_odds_multi_count'  => (int)$orphanOddsMulti['cnt'],
        'user_favorites_collation' => $collation['TABLE_COLLATION'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'apply') {
    $results = [];

    // 1. predictions.score_course のCOMMENT修正(35点満点に合わせる)
    try {
        $pdo->exec("
            ALTER TABLE predictions
            MODIFY COLUMN score_course float DEFAULT NULL COMMENT '②コース別補正(35点)'
        ");
        $results['1_score_course_comment'] = 'ok';
    } catch (Exception $e) {
        $results['1_score_course_comment'] = 'error: ' . $e->getMessage();
    }

    // 2. odds_multi.race_id に FK制約を追加
    try {
        $pdo->exec("
            ALTER TABLE odds_multi
            ADD CONSTRAINT fk_odds_multi_race FOREIGN KEY (race_id) REFERENCES races(id)
        ");
        $results['2_odds_multi_fk'] = 'ok';
    } catch (Exception $e) {
        $results['2_odds_multi_fk'] = 'error: ' . $e->getMessage();
    }

    // 3. user_favorites のcollationを他テーブルと統一
    try {
        $pdo->exec("
            ALTER TABLE user_favorites
            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci
        ");
        $results['3_user_favorites_collation'] = 'ok';
    } catch (Exception $e) {
        $results['3_user_favorites_collation'] = 'error: ' . $e->getMessage();
    }

    // 4. user_picks / user_favorites に users への FK制約を追加(ON DELETE CASCADE)
    try {
        $pdo->exec("
            ALTER TABLE user_picks
            ADD CONSTRAINT fk_user_picks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ");
        $results['4a_user_picks_fk'] = 'ok';
    } catch (Exception $e) {
        $results['4a_user_picks_fk'] = 'error: ' . $e->getMessage();
    }
    try {
        $pdo->exec("
            ALTER TABLE user_favorites
            ADD CONSTRAINT fk_user_favorites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ");
        $results['4b_user_favorites_fk'] = 'ok';
    } catch (Exception $e) {
        $results['4b_user_favorites_fk'] = 'error: ' . $e->getMessage();
    }

    echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'dump') {
    header('Content-Type: text/plain; charset=utf-8');

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    sort($tables);

    echo "-- 艇王 DBスキーマダンプ\n";
    echo "-- 生成日時: " . date('Y-m-d H:i:s') . "\n";
    echo "-- テーブル数: " . count($tables) . "\n\n";

    foreach ($tables as $table) {
        $row = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
        $createStmt = $row['Create Table'] ?? '';
        echo "-- ----------------------------\n";
        echo "-- Table: $table\n";
        echo "-- ----------------------------\n";
        echo $createStmt . ";\n\n";
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid action']);
