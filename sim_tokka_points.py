# -*- coding: utf-8 -*-
"""
sim_tokka_points.py — 的中特化の点数見直し試算(v3w、リークなし再現)
読み取り専用 / DB接続なし。sim_v3w_postswitch.py と同じ入力・同じ清算(prob傾斜配分)を使う。

案: 上位K艇(K=4: 24通り / K=5: 60通り)の3連単から Harville 上位N点(N=9/12/15/18)
比較: 同じレースのバランス(現行ロジック)。回収率差・的中率差はレース単位の対応ありbootstrap。
使い方: python sim_tokka_points.py SCORED [FROM] [TO]
"""
import itertools
import sys

import numpy as np

FROM = sys.argv[2] if len(sys.argv) > 2 else "2026-09-30"
TO = sys.argv[3] if len(sys.argv) > 3 else "2026-11-03"
sys.argv = [sys.argv[0], sys.argv[1], "-", TO]
from sim_v3w_postswitch import load_scored, load_market, in_p, ci  # noqa: E402
from sim_v3w_final import build, settle, boot_weights, ratio, harville  # noqa: E402

POINTS = (9, 12, 15, 18)
POOLS = (4, 5)


def tokka(probs, lanes, k, n):
    perms = sorted(itertools.permutations(lanes[:k], 3), key=lambda p: -harville(probs, *p))
    return ["-".join(map(str, p)) for p in perms[:n]]


def main():
    S = load_scored()
    odds, pay = load_market()
    rs = sorted(r for r, s in S.items() if in_p(s["date"], (FROM, TO)) and r in odds and r in pay)
    cases = {"バランス(現行)": []}
    cases.update({f"上位{k}艇 {n}点": [] for k in POOLS for n in POINTS})
    npts = {c: [] for c in cases}
    for r in rs:
        wc, amt = pay[r]
        probs, lanes = S[r]["v3w"]
        bal = build(probs, lanes, odds[r])["balance"]
        cases["バランス(現行)"].append(settle(bal, probs, wc, amt))
        npts["バランス(現行)"].append(len(bal))
        for k in POOLS:
            for n in POINTS:
                c = tokka(probs, lanes, k, n)
                cases[f"上位{k}艇 {n}点"].append(settle(c, probs, wc, amt))
                npts[f"上位{k}艇 {n}点"].append(len(c))
    A = {c: np.array(v, float) for c, v in cases.items()}
    W = boot_weights(len(rs), seed=51)
    one = np.ones(len(rs))
    b = A["バランス(現行)"]
    b_roi = ratio(W, b[:, 3], b[:, 2]) * 100
    b_hit = ratio(W, b[:, 4], one) * 100

    print(f"期間 {FROM}〜{TO}(10-05・10-22 除外) {len(rs)}R / v3w 再現 / prob傾斜配分(1点あたり600円)")
    print(f"{'案':<14}{'平均点数':>6}{'的中率':>8}{'回収率 [95%CI]':>22}{'的中差 vs バランス':>24}{'回収率差 vs バランス':>26}{'P(差≥-5)':>10}")
    for c, x in A.items():
        t = x.sum(0)
        hit, roi = t[4] / len(rs) * 100, t[3] / t[2] * 100
        r_b = ratio(W, x[:, 3], x[:, 2]) * 100
        lo, hi = ci(r_b)
        line = f"{c:<14}{np.mean(npts[c]):>6.1f}{hit:>7.1f}%{roi:>8.1f}% [{lo:5.1f},{hi:5.1f}]"
        if c != "バランス(現行)":
            dh = ratio(W, x[:, 4], one) * 100 - b_hit
            dr = r_b - b_roi
            hl, hh = ci(dh)
            rl, rh = ci(dr)
            line += (f"  {np.mean(dh):+5.1f}pt [{hl:+5.1f},{hh:+5.1f}]"
                     f"  {np.mean(dr):+5.1f}pt [{rl:+5.1f},{rh:+5.1f}]  {np.mean(dr >= -5):.2f}")
        print(line)

    # 現行(上位4艇9点)の P1/P2 別(本番換算の基準確認用)
    print("\n期間別(上位4艇9点 / バランス)")
    for name, p in (("P1", ("2026-09-30", "2026-10-07")), ("P2", ("2026-10-08", TO))):
        idx = [i for i, r in enumerate(rs) if in_p(S[r]["date"], p)]
        for c in ("上位4艇 9点", "バランス(現行)"):
            t = A[c][idx].sum(0)
            print(f"  {name} {len(idx)}R {c:<12} 的中 {t[4]/len(idx)*100:.1f}% 回収率 {t[3]/t[2]*100:.1f}%")


if __name__ == "__main__":
    main()
