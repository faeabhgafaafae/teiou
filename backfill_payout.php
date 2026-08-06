<?php
/**
 * バックフィル: is_hit=1 & payout=0 の strategy_results を
 * odds_3t テーブルから再計算して UPDATE する
 * POST { api_key: "..." }
 * Returns: { updated, skipped, before_stats, after_stats }
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || ($input['api_key'] ?? '') !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗'], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_stats(PDO $pdo): array {
    $rows = $pdo->query('
        SELECT s.strategy_type,
               COUNT(sr.id)                AS total_races,
               COALESCE(SUM(sr.is_hit),0)  AS hits,
               COALESCE(SUM(sr.cost),0)    AS total_cost,
               COALESCE(SUM(sr.payout),0)  AS total_payout,
               SUM(CASE WHEN sr.is_hit=1 AND sr.payout=0 THEN 1 ELSE 0 END) AS remaining_gap
        FROM strategies s
        JOIN strategy_results sr ON sr.strategy_id = s.id
        GROUP BY s.strategy_type
        ORDER BY FIELD(s.strategy_type,"的中特化","バランス","一撃重視","絞り込み")
    ')->fetchAll();

    return array_map(function ($r) {
        $total  = (int)$r['total_races'];
        $hits   = (int)$r['hits'];
        $cost   = (int)$r['total_cost'];
        $payout = (int)$r['total_payout'];
        $profit = $payout - $cost;
        return [
            'strategy_type' => $r['strategy_type'],
            'total_races'   => $total,
            'hits'          => $hits,
            'hit_rate'      => $total > 0 ? round($hits / $total * 100, 1) : 0,
            'total_cost'    => $cost,
            'total_payout'  => $payout,
            'profit'        => $profit,
            'roi'           => $cost > 0 ? round($profit / $cost * 100, 1) : 0,
            'remaining_gap' => (int)$r['remaining_gap'],
        ];
    }, $rows);
}

// Before
$before_stats = get_stats($pdo);

// is_hit=1 & payout=0 の全件を取得
$gap_rows = $pdo->query('
    SELECT sr.id AS sr_id, sr.race_id
    FROM strategy_results sr
    WHERE sr.is_hit = 1 AND sr.payout = 0
    ORDER BY sr.race_id
')->fetchAll();

$stmt_combo  = $pdo->prepare('
    SELECT GROUP_CONCAT(lane ORDER BY actual_rank SEPARATOR "-") AS combo
    FROM results
    WHERE race_id = ? AND actual_rank IN (1,2,3)
');
$stmt_odds   = $pdo->prepare('SELECT odds FROM odds_3t WHERE race_id = ? AND combo = ? LIMIT 1');
$stmt_update = $pdo->prepare('UPDATE strategy_results SET payout = ? WHERE id = ?');

$updated = 0;
$skipped = 0;

foreach ($gap_rows as $row) {
    $stmt_combo->execute([$row['race_id']]);
    $cr = $stmt_combo->fetch();
    if (!$cr || !$cr['combo']) { $skipped++; continue; }

    $stmt_odds->execute([$row['race_id'], $cr['combo']]);
    $or = $stmt_odds->fetch();
    if (!$or) { $skipped++; continue; } // odds_3t にまだデータなし

    $payout = (int)floor((float)$or['odds'] * 100);
    if ($payout > 0) {
        $stmt_update->execute([$payout, $row['sr_id']]);
        $updated++;
    } else {
        $skipped++;
    }
}

// After
$after_stats = get_stats($pdo);

echo json_encode([
    'updated'      => $updated,
    'skipped'      => $skipped,
    'before_stats' => $before_stats,
    'after_stats'  => $after_stats,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
