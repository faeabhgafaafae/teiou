<?php
/**
 * temp: odds_updated_at 取得タイミング分布計測(使用後にneutralize/削除する)
 * ?action=raw : 締切が確定済みの直近レースについて、venue/scheduled_time/odds_updated_at/
 *               締切までの差分(分)を生データで返す。集計はクライアント側で行う。
 * ?action=range : 対象になりうる日付範囲(最小日付・最大日付・件数)を確認する
 */
$api_key = $_GET['api_key'] ?? '';
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

$action  = $_GET['action'] ?? 'raw';
$sinceDate = $_GET['since'] ?? date('Y-m-d', strtotime('-3 days'));

if ($action === 'range') {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt, MIN(date) AS min_date, MAX(date) AS max_date
        FROM races
        WHERE date >= ?
          AND scheduled_time IS NOT NULL
          AND TIMESTAMP(date, CONCAT(scheduled_time, ':00')) < NOW()
    ");
    $stmt->execute([$sinceDate]);
    echo json_encode(['now' => date('Y-m-d H:i:s'), 'range' => $stmt->fetch()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'raw') {
    $stmt = $pdo->prepare("
        SELECT
            id, date, venue, race_no, scheduled_time, odds_updated_at,
            TIMESTAMP(date, CONCAT(scheduled_time, ':00')) AS deadline_dt,
            TIMESTAMPDIFF(
                MINUTE,
                odds_updated_at,
                TIMESTAMP(date, CONCAT(scheduled_time, ':00'))
            ) AS minutes_before_deadline
        FROM races
        WHERE date >= ?
          AND scheduled_time IS NOT NULL
          AND TIMESTAMP(date, CONCAT(scheduled_time, ':00')) < NOW()
        ORDER BY date, venue, race_no
    ");
    $stmt->execute([$sinceDate]);
    echo json_encode(['races' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid action']);
