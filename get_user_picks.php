<?php
/**
 * マイ的中トラッカー: 買い目一覧・集計取得API
 * GET /get_user_picks.php
 * is_hit / payout は race_payouts と照合して動的に計算する
 *   matched → is_hit=1, payout=払戻額
 *   race に結果あり・組番なし → is_hit=0, payout=0
 *   結果未入力 → is_hit=null（未確定）
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_login();
if ($user['plan'] !== 'premium') {
    json_response(['error' => 'premium_required', 'message' => 'マイ的中トラッカーはPremium会員限定です'], 403);
}

$pdo = get_db();

$stmt = $pdo->prepare('
    SELECT
        up.id,
        up.race_id,
        up.bet_type,
        up.combo,
        up.cost,
        up.created_at,
        r.venue,
        r.date,
        r.race_no,
        rp_match.amount  AS matched_payout,
        (SELECT COUNT(*) FROM race_payouts rp2 WHERE rp2.race_id = up.race_id) AS result_count
    FROM user_picks up
    JOIN races r ON r.id = up.race_id
    LEFT JOIN race_payouts rp_match
        ON  rp_match.race_id  = up.race_id
        AND rp_match.bet_type = up.bet_type
        AND rp_match.combo    = up.combo
    WHERE up.user_id = ?
    ORDER BY up.created_at DESC
    LIMIT 200
');
$stmt->execute([$user['id']]);
$rows = $stmt->fetchAll();

$picks = [];
$total_decided_cost = 0;
$summary = [
    'total'         => 0,
    'decided'       => 0,
    'hit'           => 0,
    'total_cost'    => 0,
    'total_payout'  => 0,
    'hit_rate'      => null,
    'roi'           => null,
];

foreach ($rows as $row) {
    $isHit  = null;
    $payout = null;

    if ($row['matched_payout'] !== null) {
        $isHit  = 1;
        $payout = (int)$row['matched_payout'];
    } elseif ((int)$row['result_count'] > 0) {
        $isHit  = 0;
        $payout = 0;
    }

    $picks[] = [
        'id'         => (int)$row['id'],
        'race_id'    => (int)$row['race_id'],
        'venue'      => $row['venue'],
        'date'       => $row['date'],
        'race_no'    => (int)$row['race_no'],
        'bet_type'   => $row['bet_type'],
        'combo'      => $row['combo'],
        'cost'       => (int)$row['cost'],
        'is_hit'     => $isHit,
        'payout'     => $payout,
        'created_at' => $row['created_at'],
    ];

    $summary['total']++;
    $summary['total_cost'] += (int)$row['cost'];

    if ($isHit !== null) {
        $summary['decided']++;
        $summary['total_payout'] += (int)($payout ?? 0);
        $total_decided_cost += (int)$row['cost'];
        if ($isHit === 1) $summary['hit']++;
    }
}

if ($summary['decided'] > 0) {
    $summary['hit_rate'] = round($summary['hit'] / $summary['decided'] * 100, 1);
    if ($total_decided_cost > 0) {
        $summary['roi'] = round($summary['total_payout'] / $total_decided_cost * 100 - 100, 1);
    }
}

json_response(['picks' => $picks, 'summary' => $summary]);
