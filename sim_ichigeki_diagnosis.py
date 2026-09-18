# -*- coding: utf-8 -*-
"""
sim_ichigeki_diagnosis.py — 一撃重視戦略ROI劣勢(v3w)の原因切り分け
読み取り専用 / DB接続なし。data/のCSV(2026-09-13〜09-17)のみ使用。
手法は sim_balance_diagnosis.py と同一(sim_v3w_midterm.pyの再現を流用)。

 1. v3w自身のROIとオフライン推定(v2 97.9 / v3w05 92.5)の乖離確認
 2. -8.2ptの「構造差」「v2上振れ/下振れ」への分解
 3. 確率フラット化が2-4位プール選定とオッズ下限15倍通過買い目へ与える影響
 4. ICHIGEKI_MIN_ODDS = 10/15/20/30/下限なし でのROI試算(モデル依存性の確認)
"""
import random
from collections import Counter

from sim_v3w_midterm import load_all, v2_score, v3w_score, DATES, ICHIGEKI_MIN_ODDS

OFFLINE = {"v2": 97.9, "v3w": 92.5}  # design_v3_improvement_20260913.md §4.2 (alpha=0.5)


def ichigeki_prefilter(lanes):
    """フィルタ適用前の一撃重視構成(1位固定×2-4位流し、最大6点)"""
    first = lanes[0]
    pool = lanes[1:4]
    return [f"{first}-{s}-{t}" for s in pool for t in pool if s != t]


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
    # 1+3. オッズ下限15倍フィルタの挙動とプール構成
    # =================================================================
    print("\n" + "=" * 74)
    print("【1】オッズ下限15倍フィルタの挙動 (一撃重視: 1位固定×2-4位流し)")
    print("=" * 74)
    agg = {}
    for m in ("v2", "v3w"):
        a = {
            "pre_total": 0, "excluded": 0, "no_odds": 0,
            "kept": 0, "kept_odds_sum": 0.0, "kept_odds_n": 0,
            "pre_hits": 0, "kept_hits": 0, "excl_hits": 0, "excl_lost_pay": 0,
            "kept_cost": 0, "kept_ret": 0, "zero_races": 0,
            "first_lane1": 0, "pool56": 0, "pool_counter": Counter(),
        }
        for rid in races:
            probs, lanes = models[rid][m]
            odds_r = odds[rid]
            win_combo, pay = payouts[rid]
            if lanes[0] == 1:
                a["first_lane1"] += 1
            pool = lanes[1:4]
            if 5 in pool or 6 in pool:
                a["pool56"] += 1
            a["pool_counter"][tuple(sorted(pool))] += 1
            pre = ichigeki_prefilter(lanes)
            a["pre_total"] += len(pre)
            if win_combo in pre:
                a["pre_hits"] += 1
            kept = []
            for c in pre:
                o = odds_r.get(c)
                if o is None:
                    a["no_odds"] += 1
                    kept.append(c)          # 本番同様オッズ欠損は除外しない
                elif o < ICHIGEKI_MIN_ODDS:
                    a["excluded"] += 1
                    if c == win_combo:
                        a["excl_hits"] += 1
                        a["excl_lost_pay"] += pay
                else:
                    kept.append(c)
                    a["kept_odds_sum"] += o
                    a["kept_odds_n"] += 1
            if not kept:
                a["zero_races"] += 1
            a["kept"] += len(kept)
            a["kept_cost"] += len(kept) * 100
            if win_combo in kept:
                a["kept_hits"] += 1
                a["kept_ret"] += pay
        agg[m] = a

    print(f"\n{'指標':<34}{'v2':>12}{'v3w':>12}")
    def row(label, fmt, f):
        print(f"{label:<34}{fmt.format(f(agg['v2'])):>12}{fmt.format(f(agg['v3w'])):>12}")
    row("フィルタ前 買い目総数", "{:,}", lambda a: a["pre_total"])
    row("除外数(オッズ<15)", "{:,}", lambda a: a["excluded"])
    row("除外率", "{:.1%}", lambda a: a["excluded"] / a["pre_total"])
    row("オッズ欠損(保持扱い)", "{:,}", lambda a: a["no_odds"])
    row("全点除外レース数", "{:,}", lambda a: a["zero_races"])
    row("通過後 1R平均点数", "{:.2f}", lambda a: a["kept"] / n)
    row("通過後 平均オッズ", "{:.1f}", lambda a: a["kept_odds_sum"] / a["kept_odds_n"])
    row("フィルタ前セットの的中R数", "{:,}", lambda a: a["pre_hits"])
    row("通過後の的中R数", "{:,}", lambda a: a["kept_hits"])
    row("除外により失った的中R数", "{:,}", lambda a: a["excl_hits"])
    row("失った払戻(100円あたり計)", "{:,}円", lambda a: a["excl_lost_pay"])
    valid = lambda a: n - a["zero_races"]
    row("通過後 的中率(有効R比)", "{:.1%}", lambda a: a["kept_hits"] / valid(a))
    row("通過後 ROI", "{:.1%}", lambda a: a["kept_ret"] / a["kept_cost"])
    for m in ("v2", "v3w"):
        a = agg[m]
        cost_nf = a["pre_total"] * 100
        ret_nf = a["kept_ret"] + a["excl_lost_pay"]
        print(f"  ({m}: フィルタ無しなら 的中率 {a['pre_hits']/n:.1%} / ROI {ret_nf/cost_nf:.1%})")

    print("\n" + "=" * 74)
    print("【3】1位固定・2-4位プールの構成差")
    print("=" * 74)
    print(f"\n{'指標':<30}{'v2':>12}{'v3w':>12}")
    def row2(label, fmt, f):
        print(f"{label:<30}{fmt.format(f(agg['v2'])):>12}{fmt.format(f(agg['v3w'])):>12}")
    row2("1着固定=1号艇率", "{:.1%}", lambda a: a["first_lane1"] / n)
    row2("2-4位プールに5/6号艇率", "{:.1%}", lambda a: a["pool56"] / n)
    for m in ("v2", "v3w"):
        top3p = agg[m]["pool_counter"].most_common(3)
        print(f"  {m} プール最頻: " + "  ".join(f"{p}={c/n:.1%}" for p, c in top3p))

    # =================================================================
    # 2. オフライン推定との突き合わせ・分解
    # =================================================================
    print("\n" + "=" * 74)
    print("【2】オフライン推定との突き合わせ (一撃重視ROI)")
    print("=" * 74)
    roi2 = agg["v2"]["kept_ret"] / agg["v2"]["kept_cost"] * 100
    roi3 = agg["v3w"]["kept_ret"] / agg["v3w"]["kept_cost"] * 100
    print(f"  オフライン(07/27-09/02, 4625R, alpha=0.5): v2 {OFFLINE['v2']}% / v3w05 {OFFLINE['v3w']}% (差 {OFFLINE['v3w']-OFFLINE['v2']:+.1f}pt)")
    print(f"  シャドウ週(09/13-09/17, {n}R, alpha=0.55): v2 {roi2:.1f}% / v3w {roi3:.1f}% (差 {roi3-roi2:+.1f}pt)")
    print(f"    → v2側の対オフライン乖離: {roi2-OFFLINE['v2']:+.1f}pt / v3w側の対オフライン乖離: {roi3-OFFLINE['v3w']:+.1f}pt")
    print(f"  (参考: 同期間の本番v2実績はprob傾斜配分で118.1%)")

    # =================================================================
    # 4. ICHIGEKI_MIN_ODDS スイープ + ブートストラップ
    # =================================================================
    print("\n" + "=" * 74)
    print("【4】ICHIGEKI_MIN_ODDS スイープ (均等100円)")
    print("=" * 74)
    caps = [10.0, 15.0, 20.0, 30.0, None]
    rng = random.Random(42)
    NB = 2000
    print(f"\n{'下限':>8} | {'v3w点数':>8}{'v3w的中':>9}{'v3wROI':>9} | {'v2点数':>8}{'v2的中':>8}{'v2ROI':>8} | {'P(v3w>=v2)':>11}")
    for cap in caps:
        per = {m: [] for m in ("v2", "v3w")}
        out = {}
        for m in ("v2", "v3w"):
            kept_n = cost = ret = hits = zero = 0
            for rid in races:
                probs, lanes = models[rid][m]
                odds_r = odds[rid]
                win_combo, pay = payouts[rid]
                kept = []
                for c in ichigeki_prefilter(lanes):
                    o = odds_r.get(c)
                    if cap is not None and o is not None and o < cap:
                        continue
                    kept.append(c)
                c_ = len(kept) * 100
                h = 1 if win_combo in kept else 0
                r_ = pay if h else 0
                per[m].append((c_, r_))
                kept_n += len(kept)
                cost += c_
                ret += r_
                hits += h
                if not kept:
                    zero += 1
            out[m] = (kept_n / n, hits / (n - zero) * 100 if n > zero else 0,
                      ret / cost * 100 if cost else 0)
        ge = 0
        for _ in range(NB):
            idx = [rng.randrange(n) for _ in range(n)]
            c2 = sum(per["v2"][i][0] for i in idx); r2 = sum(per["v2"][i][1] for i in idx)
            c3 = sum(per["v3w"][i][0] for i in idx); r3 = sum(per["v3w"][i][1] for i in idx)
            if (r3 / c3 if c3 else 0) >= (r2 / c2 if c2 else 0):
                ge += 1
        lab = "なし" if cap is None else f"{cap:.0f}倍"
        print(f"{lab:>8} | {out['v3w'][0]:>8.2f}{out['v3w'][1]:>8.1f}%{out['v3w'][2]:>8.1f}% | "
              f"{out['v2'][0]:>8.2f}{out['v2'][1]:>7.1f}%{out['v2'][2]:>7.1f}% | {ge/NB:>11.3f}")


if __name__ == "__main__":
    run()
