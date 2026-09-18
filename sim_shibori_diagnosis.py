# -*- coding: utf-8 -*-
"""
sim_shibori_diagnosis.py — 絞り込み戦略ROI差(v3w)の診断
読み取り専用 / DB接続なし。data/のCSV(2026-09-13〜09-17)のみ使用。
手法は sim_balance_diagnosis.py / sim_ichigeki_diagnosis.py と同一。

 1. v3w自身のROIとオフライン推定(v2 87.5 / v3w05 85.9 ※旧1点ロジック)の乖離確認
 2. -2.0ptの「構造差」「v2上振れ」への分解
 3. 確率フラット化がHarville上位3点の選定に与える影響
    (1号艇アタマ率・平均オッズ・的中率・アタマ艇の多様性)
 4. 点数スイープ N=2/3/4/6 (top3順列6通りからHarville上位N点)
"""
import itertools
import random
from collections import Counter

from sim_v3w_midterm import load_all, v2_score, v3w_score, harville, DATES

OFFLINE = {"v2": 87.5, "v3w": 85.9}  # design_v3_improvement_20260913.md §4.2 (旧1点ロジック・alpha=0.5)


def shibori_combos(probs, lanes, n_pts=3):
    perms = sorted(itertools.permutations(lanes[:3]), key=lambda p: -harville(probs, *p))
    return [p for p in perms[:n_pts]]


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
    # 3. Harville上位3点の構成比較 (+ 1/2 のROI集計)
    # =================================================================
    print("\n" + "=" * 74)
    print("【3】Harville上位3点の構成 (絞り込み: top3順列6通り→上位3点)")
    print("=" * 74)
    agg = {}
    for m in ("v2", "v3w"):
        a = {
            "lane1_first_combos": 0, "total_combos": 0,
            "distinct_firsts": 0, "top3_has_lane1": 0,
            "odds_sum": 0.0, "odds_n": 0,
            "hits": 0, "cost": 0, "ret": 0, "hit_pay_sum": 0,
            "first_counter": Counter(),
        }
        for rid in races:
            probs, lanes = models[rid][m]
            odds_r = odds[rid]
            win_combo, pay = payouts[rid]
            picks = shibori_combos(probs, lanes, 3)
            firsts = set()
            for p in picks:
                a["total_combos"] += 1
                if p[0] == 1:
                    a["lane1_first_combos"] += 1
                firsts.add(p[0])
                c = "-".join(map(str, p))
                o = odds_r.get(c)
                if o is not None:
                    a["odds_sum"] += o
                    a["odds_n"] += 1
            a["distinct_firsts"] += len(firsts)
            a["first_counter"][len(firsts)] += 1
            if 1 in lanes[:3]:
                a["top3_has_lane1"] += 1
            combos = ["-".join(map(str, p)) for p in picks]
            a["cost"] += len(combos) * 100
            if win_combo in combos:
                a["hits"] += 1
                a["ret"] += pay
                a["hit_pay_sum"] += pay
        agg[m] = a

    print(f"\n{'指標':<32}{'v2':>12}{'v3w':>12}")
    def row(label, fmt, f):
        print(f"{label:<32}{fmt.format(f(agg['v2'])):>12}{fmt.format(f(agg['v3w'])):>12}")
    row("1号艇がtop3入りする率", "{:.1%}", lambda a: a["top3_has_lane1"] / n)
    row("3点中1号艇アタマの平均点数", "{:.2f}", lambda a: a["lane1_first_combos"] / n)
    row("3点のアタマ艇種類数(平均)", "{:.2f}", lambda a: a["distinct_firsts"] / n)
    row("アタマ1種のみのレース率", "{:.1%}", lambda a: a["first_counter"][1] / n)
    row("選定3点の平均オッズ", "{:.1f}", lambda a: a["odds_sum"] / a["odds_n"])
    row("的中率", "{:.1%}", lambda a: a["hits"] / n)
    row("的中時平均払戻(100円あたり)", "{:,.0f}円", lambda a: a["hit_pay_sum"] / a["hits"])
    row("ROI", "{:.1%}", lambda a: a["ret"] / a["cost"])

    # =================================================================
    # 1+2. オフライン推定との突き合わせ・分解
    # =================================================================
    print("\n" + "=" * 74)
    print("【1-2】オフライン推定との突き合わせ (絞り込みROI)")
    print("=" * 74)
    roi2 = agg["v2"]["ret"] / agg["v2"]["cost"] * 100
    roi3 = agg["v3w"]["ret"] / agg["v3w"]["cost"] * 100
    print(f"  オフライン(07/27-09/02, 4625R, alpha=0.5, 旧1点ロジック):")
    print(f"    v2 {OFFLINE['v2']}% / v3w05 {OFFLINE['v3w']}% (差 {OFFLINE['v3w']-OFFLINE['v2']:+.1f}pt)")
    print(f"  シャドウ週(09/13-09/17, {n}R, alpha=0.55, 現行HV3点ロジック):")
    print(f"    v2 {roi2:.1f}% / v3w {roi3:.1f}% (差 {roi3-roi2:+.1f}pt)")
    print(f"    → v2側の対オフライン乖離: {roi2-OFFLINE['v2']:+.1f}pt / v3w側の対オフライン乖離: {roi3-OFFLINE['v3w']:+.1f}pt")
    print(f"  ※オフラインは旧1点(枠番昇順)・シャドウはHV3点のためロジック差あり(本文で留保)")
    print(f"  (参考: 同期間の本番v2実績はprob傾斜配分で109.7%)")

    # =================================================================
    # 4. 点数スイープ N=2/3/4/6 + ブートストラップ
    # =================================================================
    print("\n" + "=" * 74)
    print("【4】点数スイープ (top3順列からHarville上位N点・均等100円)")
    print("=" * 74)
    rng = random.Random(42)
    NB = 2000
    print(f"\n{'N':>4} | {'v3w的中':>9}{'v3wROI':>9} | {'v2的中':>8}{'v2ROI':>8} | {'P(v3w>=v2)':>11}")
    for n_pts in (2, 3, 4, 6):
        per = {m: [] for m in ("v2", "v3w")}
        out = {}
        for m in ("v2", "v3w"):
            cost = ret = hits = 0
            for rid in races:
                probs, lanes = models[rid][m]
                win_combo, pay = payouts[rid]
                combos = ["-".join(map(str, p)) for p in shibori_combos(probs, lanes, n_pts)]
                c_ = len(combos) * 100
                h = 1 if win_combo in combos else 0
                r_ = pay if h else 0
                per[m].append((c_, r_))
                cost += c_; ret += r_; hits += h
            out[m] = (hits / n * 100, ret / cost * 100 if cost else 0)
        ge = 0
        for _ in range(NB):
            idx = [rng.randrange(n) for _ in range(n)]
            c2 = sum(per["v2"][i][0] for i in idx); r2 = sum(per["v2"][i][1] for i in idx)
            c3 = sum(per["v3w"][i][0] for i in idx); r3 = sum(per["v3w"][i][1] for i in idx)
            if (r3 / c3 if c3 else 0) >= (r2 / c2 if c2 else 0):
                ge += 1
        print(f"{n_pts:>4} | {out['v3w'][0]:>8.1f}%{out['v3w'][1]:>8.1f}% | "
              f"{out['v2'][0]:>7.1f}%{out['v2'][1]:>7.1f}% | {ge/NB:>11.3f}")


if __name__ == "__main__":
    run()
