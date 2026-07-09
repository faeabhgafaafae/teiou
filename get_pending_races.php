<?php
date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=utf-8');

define('DB_HOST', 'mysql323.phy.lolipop.lan');
define('DB_NAME', 'LAA1670504-12');
define('DB_USER', 'LAA1670504');
define('DB_PASS', 'teiou');
define('API_KEY', 'teio2025');

$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key'], JSON_UNESCAPED_UNICODE);
    exit;
}

$date = $_GET['date'] ?? date('Y-m-d');
$all  = ($_GET['all'] ?? '') === '1';

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($all) {
    $stmt = $pdo->prepare('
        SELECT id AS race_id, date, venue, race_no, scheduled_time,
               TIMESTAMPDIFF(MINUTE, NOW(), CONCAT(date, " ", scheduled_time)) AS minutes_until_deadline
        FROM races
        WHERE date = :date
          AND scheduled_time IS NOT NULL
        ORDER BY CONCAT(date, " ", scheduled_time) ASC
    ');
    $stmt->execute([':date' => $date]);
} else {
    // 締切60分前〜締切5分後のレースを対象にする（締切直後の取り漏らしも拾う）
    // exhibit_time/start_timingがまだNULLのレースを優先して返す（needs_scrape）
    // minutes_since_odds_update: オッズの最終更新からの経過分数(scrape_live.pyのフェーズ2で
    // 直近更新済みレースをスキップする判定に使う。未取得ならNULL)
    $stmt = $pdo->prepare('
        SELECT r.id AS race_id, r.date, r.venue, r.race_no, r.scheduled_time,
               TIMESTAMPDIFF(MINUTE, NOW(), CONCAT(r.date, " ", r.scheduled_time)) AS minutes_until_deadline,
               TIMESTAMPDIFF(MINUTE, r.odds_updated_at, NOW()) AS minutes_since_odds_update,
               EXISTS (
                   SELECT 1 FROM entries e
                   WHERE e.race_id = r.id
                     AND (e.exhibit_time IS NULL OR e.start_timing IS NULL)
               ) AS needs_scrape
        FROM races r
        WHERE r.date = :date
          AND r.scheduled_time IS NOT NULL
          AND CONCAT(r.date, " ", r.scheduled_time) >= NOW() - INTERVAL 5 MINUTE
          AND CONCAT(r.date, " ", r.scheduled_time) <= NOW() + INTERVAL 60 MINUTE
        ORDER BY needs_scrape DESC, CONCAT(r.date, " ", r.scheduled_time) ASC
    ');
    $stmt->execute([':date' => $date]);
}
$races = $stmt->fetchAll();
foreach ($races as &$r) {
    if (array_key_exists('needs_scrape', $r)) {
        $r['needs_scrape'] = (bool)$r['needs_scrape'];
    }
}
unset($r);

echo json_encode([
    'count' => count($races),
    'races' => $races,
], JSON_UNESCAPED_UNICODE);
