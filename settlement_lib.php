<?php
/**
 * 清算(的中判定・投票・払戻)の純粋ロジック。
 * import_results.php / backfill_payout.php から利用。DB非依存。
 */

/**
 * 1戦略・1レース分の清算を計算する。
 *
 * @param array      $combos       買い目配列(例 ['1-2-3', ...])。combinations列をjson_decodeしたもの
 * @param array|null $stakes       $combos と同順・同数の金額配列(傾斜配分)。null なら1点100円均等
 * @param string     $winning_combo 実際の勝ち目('1着-2着-3着')
 * @param float|null $winning_odds  勝ち目の3連単オッズ(未取得ならnull)
 * @return array ['is_hit'=>0|1, 'cost'=>int, 'payout'=>int]
 */
function calc_settlement(array $combos, ?array $stakes, string $winning_combo, ?float $winning_odds): array {
    $idx       = array_search($winning_combo, $combos, true);
    $stake_win = 100;
    if ($idx !== false && $stakes !== null) {
        $stake_win = (int)$stakes[$idx];
    }
    $is_hit = ($idx !== false && $stake_win > 0) ? 1 : 0;
    $cost   = $stakes !== null ? (int)array_sum($stakes) : count($combos) * 100;
    $payout = ($is_hit && $winning_odds !== null) ? (int)floor($winning_odds * $stake_win) : 0;
    return ['is_hit' => $is_hit, 'cost' => $cost, 'payout' => $payout];
}
