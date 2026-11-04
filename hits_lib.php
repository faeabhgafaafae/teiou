<?php
/**
 * 的中速報(get_hits.php)の純粋ロジック(DB非依存)。
 *  - 同じレースで複数戦略が的中した行を1件にまとめる(3連単の的中組は1レース1つなので、レース単位でまとめる)
 *  - 3連単のオッズ(確定払戻÷100)と人気(race_payouts の人気順、無ければ odds_3t のオッズ順位)
 */

// 戦略の表示順(他画面の ORDER BY FIELD(...) と同じ)
const HITS_STRATEGY_ORDER = ['的中特化', 'バランス', '一撃重視', '絞り込み'];

/**
 * strategy_results の的中行(race_id, venue, date, race_no, strategy_type, combination)を
 * レース単位にまとめる。並び順は入力の順(最初に出たレースの位置)を保つ。
 * 各レースの strategies は HITS_STRATEGY_ORDER 順・重複なし。
 */
function hits_group_by_race(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $rid = (int)$r['race_id'];
        if (!isset($out[$rid])) {
            $out[$rid] = [
                'race_id'     => $rid,
                'venue'       => $r['venue'],
                'date'        => $r['date'],
                'race_no'     => (int)$r['race_no'],
                'combination' => $r['combination'],
                'strategies'  => [],
            ];
        }
        if (!in_array($r['strategy_type'], $out[$rid]['strategies'], true)) {
            $out[$rid]['strategies'][] = $r['strategy_type'];
        }
    }
    foreach ($out as &$g) {
        usort($g['strategies'], function ($a, $b) {
            $ia = array_search($a, HITS_STRATEGY_ORDER, true);
            $ib = array_search($b, HITS_STRATEGY_ORDER, true);
            return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
        });
    }
    unset($g);
    return array_values($out);
}

/**
 * 的中組のオッズ順位(1=1番人気)を odds_3t のオッズから求める。
 * $odds_map: combo => オッズ。的中組のオッズが無ければ null。
 * 同じオッズの組は同順位(自分より低いオッズの組の数 + 1)。
 */
function hits_popularity_from_odds(array $odds_map, string $combo): ?int {
    if (!isset($odds_map[$combo]) || $odds_map[$combo] <= 0) return null;
    $win = (float)$odds_map[$combo];
    $lower = 0;
    foreach ($odds_map as $o) {
        if ($o !== null && (float)$o > 0 && (float)$o < $win) $lower++;
    }
    return $lower + 1;
}

/**
 * まとめたレースに odds(確定払戻÷100)と popularity を付ける。
 * $payouts:  race_id => ['amount' => int, 'popularity' => ?int](3連単・的中組の行)
 * $odds_3t:  race_id => [combo => オッズ](人気・オッズの代替用。payouts に無い値だけ使う)
 */
function hits_attach_odds(array $groups, array $payouts, array $odds_3t): array {
    foreach ($groups as &$g) {
        $rid   = $g['race_id'];
        $combo = (string)$g['combination'];
        $p     = $payouts[$rid] ?? null;
        $omap  = $odds_3t[$rid] ?? [];

        $odds = null;
        if ($p !== null && (int)$p['amount'] > 0) {
            $odds = round((int)$p['amount'] / 100, 1);
        } elseif (isset($omap[$combo]) && $omap[$combo] > 0) {
            $odds = round((float)$omap[$combo], 1);
        }
        $pop = ($p !== null && $p['popularity'] !== null && (int)$p['popularity'] > 0) ? (int)$p['popularity'] : null;
        if ($pop === null && $combo !== '') {
            $pop = hits_popularity_from_odds($omap, $combo);
        }
        $g['odds']       = $odds;
        $g['popularity'] = $pop;
    }
    unset($g);
    return $groups;
}
