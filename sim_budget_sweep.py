# -*- coding: utf-8 -*-
"""
傾斜配分の予算スイープ・方式再検証 (読み取り専用 / DB接続なし)
新戦略ロジック(絞り込みHV3・的中特化HV6)前提で、
1点あたり予算 100〜2000円 × 配分方式(prob/ev/inv_odds/kelly) を検証する。

前提: 全買い目に最低100円保証(BOATERS型)。的中率は予算に依らず一定なので
回収率のみを比較する。
"""
import itertools
from collections import defaultdict

from sim_stake_allocation import (
    WINDOWS, load_all, v2_score_race, harville, gen_strategies,
    allocate_min100, scheme_weights, settle,
)

UNITS = [100, 200, 300, 400, 600, 800, 1000, 1500, 2000]
SCHEMES = ["prob", "ev", "inv_odds", "kelly"]


def new_logic_combos(strat_name, probs, lanes, odds_map):
    """本番反映済みの新ロジック(2026-09-09)で買い目を返す"""
    if strat_name == "的中特化":
        perms = list(itertools.permutations(lanes[:4], 3))
        perms.sort(key=lambda p: -harville(probs, *p))
        return ["-".join(map(str, p)) for p in perms[:6]]
    if strat_name == "絞り込み":
        perms = list(itertools.permutations(lanes[:3]))
        perms.sort(key=lambda p: -harville(probs, *p))
        return ["-".join(map(str, p)) for p in perms[:3]]
    # バランス・一撃重視は現行のまま
    return gen_strategies(lanes, odds_map)[strat_name]


def run():
    odds, payouts, finish, entries = load_all()

    races = []
    window_of = {}
    for rid, rows in entries.items():
        if len(rows) != 6 or rid not in payouts or rid not in odds:
            continue
        fin = finish.get(rid, {})
        if not all(r in fin for r in (1, 2, 3)):
            continue
        races.append(rid)
        window_of[rid] = rows[0]["date"]
    races.sort()
    print(f"対象レース数: {len(races)}")

    v2 = {rid: v2_score_race(entries[rid]) for rid in races}

    # ── 予算スイープ ────────────────────────────────────────────
    for strat_name in ["的中特化", "バランス", "一撃重視", "絞り込み"]:
        print()
        print("=" * 86)
        print(f"【{strat_name}(新ロジック)】1点あたり予算別の回収率 (最低100円保証+傾斜)")
        print("=" * 86)

        # flat基準と的中率
        tot = [0, 0, 0, 0]
        for rid in races:
            probs, lanes = v2[rid]
            combos = new_logic_combos(strat_name, probs, lanes, odds[rid])
            if not combos:
                continue
            win_combo, pay = payouts[rid]
            st = {c: 100 for c in combos}
            c, r, h = settle(st, win_combo, pay)
            tot[0] += 1; tot[1] += h; tot[2] += c; tot[3] += r
        flat_rr = tot[3] / tot[2] * 100
        avg_pts = tot[2] / tot[0] / 100
        print(f"flat(均等100円)基準: 的中率 {tot[1]/tot[0]*100:.1f}% / 回収率 {flat_rr:.2f}% / 平均{avg_pts:.1f}点")
        print(f"\n{'1点予算':>8} | " + " | ".join(f"{s:>10}" for s in SCHEMES))
        for unit in UNITS:
            cells = []
            for scheme in SCHEMES:
                agg = [0, 0]
                for rid in races:
                    probs, lanes = v2[rid]
                    combos = new_logic_combos(strat_name, probs, lanes, odds[rid])
                    if not combos:
                        continue
                    win_combo, pay = payouts[rid]
                    ws = scheme_weights(scheme, combos, probs, odds[rid])
                    st = allocate_min100(combos, ws, len(combos) * unit)
                    c, r, _ = settle(st, win_combo, pay)
                    agg[0] += c; agg[1] += r
                rr = agg[1] / agg[0] * 100
                cells.append(f"{rr:>9.2f}%")
            print(f"{unit:>7}円 | " + " | ".join(cells))

    # ── 期間別安定性 (推奨方式のみ、1点600円) ──────────────────
    print()
    print("=" * 86)
    print("【期間別安定性】各戦略の有力方式 (1点600円・最低100円保証) を2週間窓ごとに検証")
    print("=" * 86)
    picks = [
        ("的中特化", "prob"), ("的中特化", "inv_odds"),
        ("バランス", "prob"), ("バランス", "kelly"),
        ("一撃重視", "kelly"), ("一撃重視", "prob"),
        ("絞り込み", "prob"), ("絞り込み", "kelly"),
    ]
    print(f"\n{'戦略/方式':<24}" + "".join(f"{w:>14}" for w in WINDOWS) + f"{'全体':>10}{'flat全体':>10}")
    for strat_name, scheme in picks:
        cells = []
        agg_w = defaultdict(lambda: [0, 0])
        flat_w = [0, 0]
        for rid in races:
            probs, lanes = v2[rid]
            combos = new_logic_combos(strat_name, probs, lanes, odds[rid])
            if not combos:
                continue
            win_combo, pay = payouts[rid]
            ws = scheme_weights(scheme, combos, probs, odds[rid])
            st = allocate_min100(combos, ws, len(combos) * 600)
            c, r, _ = settle(st, win_combo, pay)
            # 属する窓 = dateが最も近い窓開始日
            d = window_of[rid]
            w = max((x for x in WINDOWS if x <= d), default=WINDOWS[0])
            agg_w[w][0] += c; agg_w[w][1] += r
            stf = {cc: 100 for cc in combos}
            cf, rf, _ = settle(stf, win_combo, pay)
            flat_w[0] += cf; flat_w[1] += rf
        for w in WINDOWS:
            c, r = agg_w[w]
            cells.append(f"{r/c*100:>13.2f}%" if c else f"{'--':>14}")
        c_all = sum(v[0] for v in agg_w.values())
        r_all = sum(v[1] for v in agg_w.values())
        print(f"{strat_name}/{scheme:<12}" + "".join(cells)
              + f"{r_all/c_all*100:>9.2f}%{flat_w[1]/flat_w[0]*100:>9.2f}%")

    # ── 傾斜の中身の可視化 (代表例: 配分がどれくらい偏るか) ────────
    print()
    print("=" * 86)
    print("【配分例】的中特化(HV6・1点600円=計3600円) prob傾斜の代表レース3例")
    print("=" * 86)
    shown = 0
    for rid in races:
        probs, lanes = v2[rid]
        combos = new_logic_combos("的中特化", probs, lanes, odds[rid])
        if len(combos) != 6:
            continue
        ws = scheme_weights("prob", combos, probs, odds[rid])
        st = allocate_min100(combos, ws, 3600)
        print(f"race {rid}: " + "  ".join(f"{c}={v}円" for c, v in st.items()))
        shown += 1
        if shown >= 3:
            break


if __name__ == "__main__":
    run()
