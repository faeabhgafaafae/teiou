<?php
/**
 * マイ的中トラッカー: 「戦略通りに購入していた場合」との比較API (Premium限定)
 * GET /get_user_picks_strategy_compare.php
 *
 * ユーザーが購入した「確定済み(race_payoutsあり)」レース群について、
 *   - 実際の購入の収支(user_picks × race_payouts、get_user_picks.phpと同じ照合方式)
 *   - 同じレース群を4戦略それぞれの推奨買い目(strategy_results)で購入した場合の収支
 * を並べて返す。読み取り専用。プラン制限は my-picks 全体(Premium限定)に準拠。
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_login();
if ($user['plan'] !== 'premium') {
    json_response(['error' => 'premium_required', 'message' => 'この機能はPremium会員限定です'], 403);
}

$pdo = get_db();
$uid = (int)$user['id'];

// 対象レース = ユーザーが購入し、かつ結果確定済み(race_payoutsが存在する)レース。
// user_picks / strategy_results 双方でこの同一条件を使い、比較対象を揃える。
$settledRaceSubquery =
    'SELECT DISTINCT up.race_id FROM user_picks up
     WHERE up.user_id = ?
       AND EXISTS (SELECT 1 FROM race_payouts rp WHERE rp.race_id = up.race_id)';

// 1) 実際の購入をレース単位で集計(1レースで3連単の的中は最大1点なので amount 合算で可)
$stmt = $pdo->prepare('
    SELECT
        up.race_id,
        SUM(up.cost)                                  AS cost,
        COALESCE(SUM(rp.amount), 0)                   AS payout,
        MAX(CASE WHEN rp.amount IS NOT NULL THEN 1 ELSE 0 END) AS is_hit
    FROM user_picks up
    LEFT JOIN race_payouts rp
        ON  rp.race_id  = up.race_id
        AND rp.bet_type = up.bet_type
        AND rp.combo    = up.combo
    WHERE up.user_id = ?
      AND EXISTS (SELECT 1 FROM race_payouts rp2 WHERE rp2.race_id = up.race_id)
    GROUP BY up.race_id
');
$stmt->execute([$uid]);

$a_races = $a_hits = $a_cost = $a_payout = 0;
foreach ($stmt->fetchAll() as $r) {
    $a_races++;
    $a_hits   += (int)$r['is_hit'];
    $a_cost   += (int)$r['cost'];
    $a_payout += (int)$r['payout'];
}

// 2) 同じレース群を4戦略の推奨買い目(strategy_results)で購入した場合を集計
$strategies = [];
$coverage = 0;
if ($a_races > 0) {
    $stmt2 = $pdo->prepare('
        SELECT
            s.strategy_type,
            COUNT(*)                    AS total_races,
            COALESCE(SUM(sr.is_hit), 0) AS hits,
            COALESCE(SUM(sr.cost), 0)   AS total_cost,
            COALESCE(SUM(sr.payout), 0) AS total_payout
        FROM strategy_results sr
        JOIN strategies s ON s.id = sr.strategy_id
        WHERE sr.race_id IN (' . $settledRaceSubquery . ')
        GROUP BY s.strategy_type
    ');
    $stmt2->execute([$uid]);
    $strategies = $stmt2->fetchAll();

    $covStmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT sr.race_id) FROM strategy_results sr
         WHERE sr.race_id IN (' . $settledRaceSubquery . ')'
    );
    $covStmt->execute([$uid]);
    $coverage = (int)$covStmt->fetchColumn();
}

function pack_perf(int $races, int $hits, int $cost, int $payout): array {
    return [
        'total_races'  => $races,
        'hits'         => $hits,
        'total_cost'   => $cost,
        'total_payout' => $payout,
        'profit'       => $payout - $cost,
        'hit_rate'     => $races > 0 ? round($hits / $races * 100, 1) : null,
        'roi'          => $cost  > 0 ? round($payout / $cost * 100 - 100, 1) : null,
    ];
}

$order = ['的中特化', 'バランス', '一撃重視', '絞り込み'];
$stratOut = [];
foreach ($strategies as $s) {
    $stratOut[] = array_merge(
        ['strategy_type' => $s['strategy_type']],
        pack_perf((int)$s['total_races'], (int)$s['hits'], (int)$s['total_cost'], (int)$s['total_payout'])
    );
}
usort($stratOut, function ($x, $y) use ($order) {
    $ix = array_search($x['strategy_type'], $order, true);
    $iy = array_search($y['strategy_type'], $order, true);
    return ($ix === false ? 99 : $ix) <=> ($iy === false ? 99 : $iy);
});

json_response([
    'total_races'       => $a_races,
    'strategy_coverage' => $coverage,
    'actual'            => pack_perf($a_races, $a_hits, $a_cost, $a_payout),
    'strategies'        => $stratOut,
]);
