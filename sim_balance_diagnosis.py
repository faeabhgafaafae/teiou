# -*- coding: utf-8 -*-
"""
sim_balance_diagnosis.py — バランス戦略ROI悪化(v3w)の原因切り分け
読み取り専用 / DB接続なし。data/のCSV(2026-09-13〜09-17)のみ使用。

sim_v3w_midterm.py のv2/v3w再現を流用し、以下を分析する:
 1. オッズ上限100倍フィルタの除外率・除外セットの的中実績・通過後平均オッズ(v2 vs v3w)
 2. 上位2艇(1着固定)・上位4艇(流し)の構成差と、フィルタ通過後の買い目への影響
 3. オフライン推定(-1.4pt)とシャドウ実測(-7.3pt)の差の分解
 4. BALANCE_MAX_ODDS = 50/100/150/200/上限なし でのv3w ROI試算
"""
import itertools
from collections import Counter, defaultdict

from sim_v3w_midterm import (
    load_all, v2_score, v3w_score, harville, DATES,
    BALANCE_MAX_ODDS,
)


def balance_prefilter(lanes):
    """フィルタ適用前のバランス構成(top2固定×top4流し、最大12点)"""
    top4 = lanes[:4]
    combos = []
    for first in top4[:2]:
        rest = [l for l in top4 if l != first]
        for sec in rest:
            for thi in rest:
                if sec != thi:
                    combos.append(f"{first}-{sec}-{thi}")
    return combos


def run():
    odds, payouts, finish, entries = load_all()
    races = []
    for rid, rows in entries.items():
        if len(rows) != 6 or rid not in payouts or rid not in odds:
            continue
        if not all(r in finish.get(rid, {}) for r in (1, 2, 3)):
            continue
        races.append(rid)
    races.sort()
    n = len(races)
    print(f"対象: {DATES[0]}〜{DATES[-1]}  {n}レース")

    models = {}
    for rid in races:
        rows = entries[rid]
        models[rid] = {"v2": v2_score(rows), "v3w": v3w_score(rows)}

    # =================================================================
    # 1. オッズ100倍フィルタの挙動比較
    # =================================================================
    print("\n" + "=" * 74)
    print("【1】オッズ上限100倍フィルタの挙動 (バランス: top2固定×top4流し)")
    print("=" * 74)
    agg = {}
    for m in ("v2", "v3w"):
        a = {
            "pre_total": 0, "excluded": 0, "no_odds": 0,
            "kept": 0, "kept_odds_sum": 0.0, "kept_odds_n": 0,
            "pre_hits": 0, "kept_hits": 0, "excl_hits": 0, "excl_lost_pay": 0,
            "kept_cost": 0, "kept_ret": 0,
        }
        for rid in races:
            probs, lanes = models[rid][m]
            odds_r = odds[rid]
            win_combo, pay = payouts[rid]
            pre = balance_prefilter(lanes)
            a["pre_total"] += len(pre)
            if win_combo in pre:
                a["pre_hits"] += 1
            kept = []
            for c in pre:
                o = odds_r.get(c)
                if o is None:
                    a["no_odds"] += 1
                    kept.append(c)          # オッズ欠損は本番同様「除外しない」
                elif o > BALANCE_MAX_ODDS:
                    a["excluded"] += 1
                    if c == win_combo:
                        a["excl_hits"] += 1
                        a["excl_lost_pay"] += pay
                else:
                    kept.append(c)
                    a["kept_odds_sum"] += o
                    a["kept_odds_n"] += 1
            a["kept"] += len(kept)
            a["kept_cost"] += len(kept) * 100
            if win_combo in kept:
                a["kept_hits"] += 1
                a["kept_ret"] += pay
        agg[m] = a

    print(f"\n{'指標':<34}{'v2':>12}{'v3w':>12}")
    for m in ("v2", "v3w"):
        pass
    def row(label, fmt, f):
        print(f"{label:<34}{fmt.format(f(agg['v2'])):>12}{fmt.format(f(agg['v3w'])):>12}")
    row("フィルタ前 買い目総数", "{:,}", lambda a: a["pre_total"])
    row("除外数(オッズ>100)", "{:,}", lambda a: a["excluded"])
    row("除外率", "{:.1%}", lambda a: a["excluded"] / a["pre_total"])
    row("オッズ欠損(除外せず保持)", "{:,}", lambda a: a["no_odds"])
    row("通過後 1R平均点数", "{:.2f}", lambda a: a["kept"] / n)
    row("通過後 平均オッズ", "{:.1f}", lambda a: a["kept_odds_sum"] / a["kept_odds_n"])
    row("フィルタ前セットの的中R数", "{:,}", lambda a: a["pre_hits"])
    row("通過後の的中R数", "{:,}", lambda a: a["kept_hits"])
    row("除外により失った的中R数", "{:,}", lambda a: a["excl_hits"])
    row("失った払戻(100円あたり計)", "{:,}円", lambda a: a["excl_lost_pay"])
    row("通過後 的中率", "{:.1%}", lambda a: a["kept_hits"] / n)
    row("通過後 ROI", "{:.1%}", lambda a: a["kept_ret"] / a["kept_cost"])
    # フィルタ無し比較(除外の損得)
    for m in ("v2", "v3w"):
        a = agg[m]
        cost_nf = a["pre_total"] * 100
        ret_nf = a["kept_ret"] + a["excl_lost_pay"]
        print(f"  ({m}: フィルタ無しなら 的中率 {a['pre_hits']/n:.1%} / ROI {ret_nf/cost_nf:.1%})")

    # =================================================================
    # 2. 確率分布・構成の差
    # =================================================================
    print("\n" + "=" * 74)
    print("【2】上位2艇・上位4艇の構成差 (v2 vs v3w)")
    print("=" * 74)
    stat = {m: {"lane1_r1": 0, "lane1_in_top2": 0, "top2_pairs": Counter(),
                "top4_sets": Counter(), "p1": 0.0, "p2": 0.0, "p12_gap": 0.0,
                "outsider56_in_top4": 0}
            for m in ("v2", "v3w")}
    top4_overlap = 0
    for rid in races:
        sets4 = {}
        for m in ("v2", "v3w"):
            probs, lanes = models[rid][m]
            s = stat[m]
            ps = sorted(probs.values(), reverse=True)
            s["p1"] += ps[0]
            s["p2"] += ps[1]
            s["p12_gap"] += ps[0] - ps[1]
            if lanes[0] == 1:
                s["lane1_r1"] += 1
            if 1 in lanes[:2]:
                s["lane1_in_top2"] += 1
            s["top2_pairs"][tuple(sorted(lanes[:2]))] += 1
            s["top4_sets"][tuple(sorted(lanes[:4]))] += 1
            if 5 in lanes[:4] or 6 in lanes[:4]:
                s["outsider56_in_top4"] += 1
            sets4[m] = set(lanes[:4])
        top4_overlap += len(sets4["v2"] & sets4["v3w"])

    print(f"\n{'指標':<30}{'v2':>12}{'v3w':>12}")
    def row2(label, fmt, f):
        print(f"{label:<30}{fmt.format(f(stat['v2'])):>12}{fmt.format(f(stat['v3w'])):>12}")
    row2("1号艇rank1率", "{:.1%}", lambda s: s["lane1_r1"] / n)
    row2("1号艇top2率", "{:.1%}", lambda s: s["lane1_in_top2"] / n)
    row2("rank1平均確率", "{:.3f}", lambda s: s["p1"] / n)
    row2("rank2平均確率", "{:.3f}", lambda s: s["p2"] / n)
    row2("p1-p2ギャップ平均", "{:.3f}", lambda s: s["p12_gap"] / n)
    row2("5/6号艇がtop4入り率", "{:.1%}", lambda s: s["outsider56_in_top4"] / n)
    print(f"{'top4集合の平均一致艇数(6艇中)':<28}{top4_overlap/n:>22.2f}")
    for m in ("v2", "v3w"):
        top3p = stat[m]["top2_pairs"].most_common(3)
        print(f"  {m} top2ペア上位: " + "  ".join(f"{p}={c/n:.1%}" for p, c in top3p))

    # =================================================================
    # 3. オフライン(-1.4pt)とシャドウ(-7.3pt)の差の分解
    # =================================================================
    print("\n" + "=" * 74)
    print("【3】オフライン推定との突き合わせ (この期間のバランスROI)")
    print("=" * 74)
    print(f"  オフライン(07/27-09/02, 4625R, alpha=0.5): v2 79.3% / v3w05 77.9% (差 -1.4pt)")
    a2, a3 = agg["v2"], agg["v3w"]
    roi2 = a2["kept_ret"] / a2["kept_cost"] * 100
    roi3 = a3["kept_ret"] / a3["kept_cost"] * 100
    print(f"  シャドウ週(09/13-09/17, {n}R, alpha=0.55): v2 {roi2:.1f}% / v3w {roi3:.1f}% (差 {roi3-roi2:+.1f}pt)")
    print(f"    → v2側の対オフライン乖離: {roi2-79.3:+.1f}pt / v3w側の対オフライン乖離: {roi3-77.9:+.1f}pt")

    # =================================================================
    # 4. BALANCE_MAX_ODDS スイープ (v3w / 参考v2)
    # =================================================================
    print("\n" + "=" * 74)
    print("【4】BALANCE_MAX_ODDS スイープ (均等100円)")
    print("=" * 74)
    caps = [50.0, 100.0, 150.0, 200.0, None]
    print(f"\n{'上限':>8} | {'v3w点数':>8}{'v3w的中':>9}{'v3wROI':>9} | {'v2点数':>8}{'v2的中':>8}{'v2ROI':>8}")
    for cap in caps:
        out = {}
        for m in ("v2", "v3w"):
            kept_n = cost = ret = hits = 0
            for rid in races:
                probs, lanes = models[rid][m]
                odds_r = odds[rid]
                win_combo, pay = payouts[rid]
                kept = []
                for c in balance_prefilter(lanes):
                    o = odds_r.get(c)
                    if cap is not None and o is not None and o > cap:
                        continue
                    kept.append(c)
                kept_n += len(kept)
                cost += len(kept) * 100
                if win_combo in kept:
                    hits += 1
                    ret += pay
            out[m] = (kept_n / n, hits / n * 100, ret / cost * 100 if cost else 0)
        lab = "なし" if cap is None else f"{cap:.0f}倍"
        print(f"{lab:>8} | {out['v3w'][0]:>8.2f}{out['v3w'][1]:>8.1f}%{out['v3w'][2]:>8.1f}% | "
              f"{out['v2'][0]:>8.2f}{out['v2'][1]:>7.1f}%{out['v2'][2]:>7.1f}%")


if __name__ == "__main__":
    run()
