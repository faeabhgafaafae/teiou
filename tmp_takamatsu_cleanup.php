<?php
/**
 * 一時エンドポイント: 架空会場「高松」の孤児レコード調査・バックアップ・削除
 * 確認後に削除すること
 *
 * GET /tmp_takamatsu_cleanup.php?api_key=...&action=inspect  (デフォルト。全関連テーブルのデータをJSONで返す)
 * GET /tmp_takamatsu_cleanup.php?api_key=...&action=delete   (子テーブル→races の順で削除。トランザクション)
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}
$action = $_GET['action'] ?? 'inspect';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗: ' . $e->getMessage()]);
    exit;
}

$raceIds = $pdo->query("SELECT id FROM races WHERE venue = '高松'")->fetchAll(PDO::FETCH_COLUMN);

if (empty($raceIds)) {
    echo json_encode(['message' => '「高松」venueのracesは既に0件です', 'race_ids' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$ph = implode(',', array_fill(0, count($raceIds), '?'));

$childTables = [
    'entries'          => 'race_id',
    'results'          => 'race_id',
    'odds_3t'          => 'race_id',
    'odds_multi'       => 'race_id',
    'predictions'      => 'race_id',
    'race_payouts'     => 'race_id',
    'strategies'       => 'race_id',
    'strategy_results' => 'race_id',
    'user_picks'       => 'race_id',
];

if ($action === 'inspect') {
    $races = [];
    $stmt = $pdo->prepare("SELECT * FROM races WHERE id IN ($ph)");
    $stmt->execute($raceIds);
    $races = $stmt->fetchAll();

    $dump = ['races' => $races];
    $counts = [];
    foreach ($childTables as $table => $col) {
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE `$col` IN ($ph)");
        $stmt->execute($raceIds);
        $rows = $stmt->fetchAll();
        $dump[$table] = $rows;
        $counts[$table] = count($rows);
    }

    echo json_encode([
        'race_count'  => count($raceIds),
        'race_ids'    => $raceIds,
        'child_counts'=> $counts,
        'dump'        => $dump,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete') {
    $deleted = [];
    try {
        $pdo->beginTransaction();
        foreach ($childTables as $table => $col) {
            $stmt = $pdo->prepare("DELETE FROM `$table` WHERE `$col` IN ($ph)");
            $stmt->execute($raceIds);
            $deleted[$table] = $stmt->rowCount();
        }
        $stmt = $pdo->prepare("DELETE FROM races WHERE id IN ($ph)");
        $stmt->execute($raceIds);
        $deleted['races'] = $stmt->rowCount();
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'DELETE失敗(ロールバック済み): ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'deleted' => $deleted,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action']);
