# -*- coding: utf-8 -*-
"""
sim_v3w_postswitch_split.py — 切り替え後期間を一撃重視の切り戻し(2026-10-08)前後で分けた戦略集計
読み取り専用 / DB接続なし。sim_v3w_postswitch.py の補助(入力・ロジックは同じ)。

期間: shadow 09-13〜09-29 / P1 09-30〜10-07(全戦略 v3w)/ P2 10-08〜POST_END(一撃重視のみ v2)
出力:
  - 期間別・モデル別の4戦略 的中率・回収率 [95%CI]
  - 期間別の同一レース v3w − v2 回収率差(対応あり)
  - 一撃重視の本番構成(P1=v3w, P2=v2)と、P2 を v3w のままにした場合の比較
使い方: python sim_v3w_postswitch_split.py SCORED [POST_END]
"""
import sys

import numpy as np

POST_END = sys.argv[2] if len(sys.argv) > 2 else "2026-11-03"
sys.argv = [sys.argv[0], sys.argv[1], "-", POST_END]
from sim_v3w_postswitch import load_scored, load_market, in_p, ci, JNAME, SHADOW  # noqa: E402
from sim_v3w_final import build, settle, boot_weights, ratio, STRATS  # noqa: E402

PERIODS = (("shadow", SHADOW), ("P1", ("2026-09-30", "2026-10-07")), ("P2", ("2026-10-08", POST_END)))


def main():
    S = load_scored()
    odds, pay = load_market()
    A = {}
    for i, (name, p) in enumerate(PERIODS):
        rs = [r for r, s in S.items() if in_p(s["date"], p) and r in odds and r in pay]
        a = {m: {s: [] for s in STRATS} for m in ("v2", "v3w")}
        for r in rs:
            wc, amt = pay[r]
            for m in ("v2", "v3w"):
                probs, lanes = S[r][m]
                bs = build(probs, lanes, odds[r])
                for s in STRATS:
                    a[m][s].append(settle(bs[s], probs, wc, amt))
        A[name] = ({m: {s: np.array(v, float) for s, v in d.items()} for m, d in a.items()},
                   boot_weights(len(rs), seed=41 + i), len(rs))

    def roi(name, m, s):
        a, W, _ = A[name]
        x = a[m][s]
        return ratio(W, x[:, 3], x[:, 2]) * 100

    def point(name, m, s):
        t = A[name][0][m][s].sum(0)
        return t[4] / t[5] * 100, t[3] / t[2] * 100

    print(f"期間: " + " / ".join(f"{n} {p[0]}〜{p[1]} {A[n][2]}R" for n, p in PERIODS) + "(10-05・10-22 は除外)")
    print("\n[1] 期間別・モデル別 4戦略 的中率 / 回収率 [95%CI]")
    for s in STRATS:
        for m in ("v3w", "v2"):
            cells = []
            for name, _ in PERIODS:
                h, r = point(name, m, s)
                lo, hi = ci(roi(name, m, s))
                cells.append(f"{name} {h:5.1f}% / {r:5.1f}% [{lo:5.1f},{hi:5.1f}]")
            print(f"  {JNAME[s]:<5}{m:<4} " + "  ".join(cells))

    print("\n[2] 同一レースの v3w − v2 回収率差(対応あり)")
    for s in STRATS:
        cells = []
        for name, _ in PERIODS:
            d = roi(name, "v3w", s) - roi(name, "v2", s)
            lo, hi = ci(d)
            cells.append(f"{name} {np.mean(d):+5.1f}pt [{lo:+5.1f},{hi:+5.1f}] P>0 {np.mean(d > 0):.2f}")
        print(f"  {JNAME[s]:<5} " + "  ".join(cells))

    print("\n[3] 一撃重視: 本番構成(P1=v3w / P2=v2)の期間比較(独立bootstrap・期間の違いを含む)")
    p1, p2 = roi("P1", "v3w", "ichigeki"), roi("P2", "v2", "ichigeki")
    d = p2 - p1
    lo, hi = ci(d)
    print(f"  P1 v3w {point('P1', 'v3w', 'ichigeki')[1]:.1f}% → P2 v2 {point('P2', 'v2', 'ichigeki')[1]:.1f}%  "
          f"差 {np.mean(d):+.1f}pt [{lo:+.1f},{hi:+.1f}]")
    p2v3 = roi("P2", "v3w", "ichigeki")
    d = p2v3 - p1
    lo, hi = ci(d)
    print(f"  (参考)P2 を v3w のままにした場合 {point('P2', 'v3w', 'ichigeki')[1]:.1f}%  P1比 {np.mean(d):+.1f}pt [{lo:+.1f},{hi:+.1f}]")


if __name__ == "__main__":
    main()
