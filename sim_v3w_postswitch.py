# -*- coding: utf-8 -*-
"""
sim_v3w_postswitch.py — v3w 本番切り替え後(2026-09-30〜、10-05 欠損)の実績評価
読み取り専用 / DB接続なし。

入力:
  SCORED   : tools/score_models_cli.php の出力(09-13〜POST_END、本番と同じ PredictV2/PredictV3 をリークなし特徴量で実行)
  STATUS   : (任意)model_switch_status.php?from=2026-09-13&to=POST_END&format=json の出力(本番の保存済み予測・清算実績。
             api_key が必要。無い環境では "-" を渡すと再現値のみで集計する)
  POST_END : 切り替え後期間の最終日(既定 2026-10-07)
本番値(STATUS)があれば主、ローカル再現(SCORED)を信頼区間と期間比較に使う。
使い方: python sim_v3w_postswitch.py SCORED [STATUS|-] [POST_END]
"""
import csv
import datetime as dt
import glob
import json
import os
import sys
from collections import defaultdict

import numpy as np

from sim_v3w_final import build, settle, boot_weights, ratio, STRATS

ROOT = os.path.dirname(os.path.abspath(__file__))
SCORED = sys.argv[1]
STATUS = sys.argv[2] if len(sys.argv) > 2 and sys.argv[2] != "-" else None
SHADOW = ("2026-09-13", "2026-09-29")
POST = ("2026-09-30", sys.argv[3] if len(sys.argv) > 3 else "2026-10-07")
JNAME = {"tokka": "的中特化", "balance": "バランス", "ichigeki": "一撃重視", "shibori": "絞り込み"}


def load_scored():
    races = defaultdict(lambda: {"v2": [{}, {}], "v3w": [{}, {}]})
    for r in csv.DictReader(open(SCORED, encoding="utf-8")):
        d = races[int(r["race_id"])]
        d["date"], d["winner"] = r["date"], int(r["winner"])
        for m in ("v2", "v3w"):
            d[m][0][int(r["lane"])] = float(r[f"p_{m}"])
            d[m][1][int(r["lane"])] = int(r[f"rank_{m}"])
    out = {}
    for rid, d in races.items():
        rec = {"date": d["date"], "winner": d["winner"]}
        for m in ("v2", "v3w"):
            probs, ranks = d[m]
            rec[m] = (probs, [l for l, _ in sorted(ranks.items(), key=lambda kv: kv[1])])
        out[rid] = rec
    return out


def load_market():
    odds, pay = defaultdict(dict), {}
    for f in glob.glob(os.path.join(ROOT, "data", "odds_*.csv")):
        for r in csv.DictReader(open(f, encoding="utf-8")):
            odds[int(r["race_id"])][r["combo"]] = float(r["odds"])
    for f in glob.glob(os.path.join(ROOT, "data", "payouts_*.csv")):
        for r in csv.DictReader(open(f, encoding="utf-8")):
            pay[int(r["race_id"])] = (r["combo"], int(r["amount"]))
    return odds, pay


def in_p(date, p):
    return p[0] <= date <= p[1]


def ci(x):
    return np.percentile(x, 2.5), np.percentile(x, 97.5)


# ── 本番(STATUS がある場合のみ)───────────────────────────────────────
def production(status):
    d = json.load(open(status, encoding="utf-8"))["daily"]
    out = {}
    for name, p in (("shadow", SHADOW), ("post", POST)):
        t = defaultdict(int)
        sr = defaultdict(lambda: [0, 0, 0, 0])
        days = []
        for k, v in sorted(d.items()):
            if not in_p(k, p) or "top1" not in v:
                continue
            days.append((k, v["top1"]))
            for a, b in v["top1"].items():
                t[a] += b
            if isinstance(v["strategy_results"], dict):
                for st, m in v["strategy_results"].items():
                    for ref, x in m.items():
                        if ref == "v3w":
                            a = sr[st]
                            a[0] += x["n"]; a[1] += x["hits"]; a[2] += x["cost"]; a[3] += x["payout"]
        out[name] = dict(top1=dict(t), days=days, sr=dict(sr))
    return out


def report_production(P):
    print("[本番] 保存済み予測の1着的中率(結果取込み前に保存された値)")
    print(f"{'日付':<11}{'R':>5}{'v2':>8}{'v3w':>8}{'1号艇':>8}{'v3w 1号艇1位率':>16}")
    for k, t in P["post"]["days"]:
        n = t["races"]
        print(f"{k:<11}{n:>5}{t['v2_hits']/n*100:>7.1f}%{t['v3w_hits']/n*100:>7.1f}%{t['lane1_wins']/n*100:>7.1f}%"
              f"{t['v3w_lane1_rank1']/n*100:>15.1f}%")
    for name in ("post", "shadow"):
        t = P[name]["top1"]; n = t["races"]
        print(f"  {name:<7}{n:>5}R  v2 {t['v2_hits']/n*100:.1f}%  v3w {t['v3w_hits']/n*100:.1f}%  "
              f"1号艇 {t['lane1_wins']/n*100:.1f}%  v3w 1号艇1位 {t['v3w_lane1_rank1']/n*100:.1f}%  "
              f"v2 1号艇1位 {t['v2_lane1_rank1']/n*100:.1f}%")
    print("  ※shadow期間の本番v2はリーク込み(〜09-28)のため比較に使わない")
    print("[本番] v3w 戦略清算(切り替え後)")
    for st, a in P["post"]["sr"].items():
        print(f"  {st:<6}{a[0]:>5}R  的中 {a[1]/a[0]*100:.1f}%  回収率 {a[3]/a[2]*100:.1f}%")


# ── 再現(信頼区間・期間比較)──────────────────────────────────────────
def week_of(date):
    d = dt.date.fromisoformat(date)
    return (d - dt.timedelta(days=d.weekday())).isoformat()


def report_reproduction(S, odds, pay):
    R = {name: [r for r, s in S.items() if in_p(s["date"], p)] for name, p in (("shadow", SHADOW), ("post", POST))}
    W = {name: boot_weights(len(rs), seed=21 + i) for i, (name, rs) in enumerate(R.items())}

    def vec(rs, f):
        return np.array([f(S[r]) for r in rs], float)

    metr = {
        "v3w": lambda s: s["v3w"][1][0] == s["winner"],
        "v2": lambda s: s["v2"][1][0] == s["winner"],
        "lane1": lambda s: s["winner"] == 1,
        "v3w_l1": lambda s: s["v3w"][1][0] == 1,
    }

    print(f"\n[再現] 日別 1着的中率(post)")
    by = defaultdict(list)
    for r in R["post"]:
        by[S[r]["date"]].append(r)
    for d in sorted(by):
        rs = by[d]
        print(f"  {d} {len(rs):>4}R  " + "  ".join(f"{k} {vec(rs, f).mean()*100:5.1f}%" for k, f in metr.items()))

    print(f"\n[再現] 1着的中率と95%CI  shadow {len(R['shadow'])}R / post {len(R['post'])}R")
    boots = {}
    for name, rs in R.items():
        one = np.ones(len(rs))
        boots[name] = {k: ratio(W[name], vec(rs, f), one) * 100 for k, f in metr.items()}
        print(f"  {name:<7}" + "  ".join(
            f"{k} {vec(rs, f).mean()*100:.1f}% [{ci(boots[name][k])[0]:.1f},{ci(boots[name][k])[1]:.1f}]"
            for k, f in metr.items()))
    for x, y in (("v3w", "lane1"), ("v3w", "v2")):
        dd = boots["post"][x] - boots["post"][y]
        lo, hi = ci(dd)
        print(f"  post {x}−{y}: {np.mean(dd):+.1f}pt [{lo:+.1f},{hi:+.1f}] P(>0)={np.mean(dd > 0):.3f}")
    for k in ("v3w", "lane1", "v3w_l1"):
        dd = boots["post"][k] - boots["shadow"][k]
        lo, hi = ci(dd)
        print(f"  post−shadow {k}: {np.mean(dd):+.1f}pt [{lo:+.1f},{hi:+.1f}]")
    for name, rs in R.items():
        nl = [r for r in rs if S[r]["winner"] != 1]
        l1 = [r for r in rs if S[r]["winner"] == 1]
        h = sum(S[r]["v3w"][1][0] == S[r]["winner"] for r in nl)
        h1 = sum(S[r]["v3w"][1][0] == 1 for r in l1)
        print(f"  {name:<7} 1号艇1着レース {len(l1)}R でv3w的中 {h1/len(l1)*100:.1f}% / それ以外 {len(nl)}R で {h/len(nl)*100:.1f}%")

    print("\n[再現] 週次(月曜起点) v3w 1着的中率・1号艇1位率・実1号艇1着率")
    wk = defaultdict(list)
    for r in R["shadow"] + R["post"]:
        wk[week_of(S[r]["date"])].append(r)
    for w in sorted(wk):
        rs = wk[w]
        print(f"  {w}週 {len(rs):>4}R  v3w {vec(rs, metr['v3w']).mean()*100:5.1f}%  "
              f"1号艇1位率 {vec(rs, metr['v3w_l1']).mean()*100:5.1f}%  実1号艇1着 {vec(rs, metr['lane1']).mean()*100:5.1f}%")

    print("\n[再現] 4戦略(現行本番ロジック・prob傾斜配分)")
    arr = {}
    for name, rs in R.items():
        rs2 = [r for r in rs if r in odds and r in pay]
        a = {m: {s: [] for s in STRATS} for m in ("v2", "v3w")}
        for r in rs2:
            wc, amt = pay[r]
            for m in ("v2", "v3w"):
                probs, lanes = S[r][m]
                bs = build(probs, lanes, odds[r])
                for s in STRATS:
                    a[m][s].append(settle(bs[s], probs, wc, amt))
        arr[name] = ({m: {s: np.array(v, float) for s, v in d.items()} for m, d in a.items()},
                     boot_weights(len(rs2), seed=31), len(rs2))
    print("  (a) v3w のシャドウ期間比(回収率95%CI、期間差は独立bootstrap)")
    for s in STRATS:
        out, bs = [], {}
        for name in ("shadow", "post"):
            a, Wb, n = arr[name]
            t = a["v3w"][s].sum(0)
            bs[name] = ratio(Wb, a["v3w"][s][:, 3], a["v3w"][s][:, 2]) * 100
            lo, hi = ci(bs[name])
            out.append(f"{name} {n}R 的中 {t[4]/t[5]*100:.1f}% 回収率 {t[3]/t[2]*100:.1f}% [{lo:.1f},{hi:.1f}]")
        dd = bs["post"] - bs["shadow"]
        lo, hi = ci(dd)
        print(f"  {JNAME[s]:<6}" + " / ".join(out) + f"  差 {np.mean(dd):+.1f}pt [{lo:+.1f},{hi:+.1f}]")
    print("  (b) 切り替え後期間の v3w vs v2(同一レース・対応あり。戦略単位で v2 に戻すかの判断材料)")
    a, Wb, n = arr["post"]
    for s in STRATS:
        m = {}
        for k in ("v2", "v3w"):
            t = a[k][s].sum(0)
            m[k] = (t[4] / t[5] * 100, t[3] / t[2] * 100)
        r3 = ratio(Wb, a["v3w"][s][:, 3], a["v3w"][s][:, 2])
        r2 = ratio(Wb, a["v2"][s][:, 3], a["v2"][s][:, 2])
        lo, hi = ci((r3 - r2) * 100)
        print(f"  {JNAME[s]:<6}{n}R  v2 的中 {m['v2'][0]:.1f}% 回収率 {m['v2'][1]:.1f}% / v3w 的中 {m['v3w'][0]:.1f}% "
              f"回収率 {m['v3w'][1]:.1f}%  差 {m['v3w'][1]-m['v2'][1]:+.1f}pt [{lo:+.1f},{hi:+.1f}] "
              f"P(v3w≥v2)={np.mean(r3 >= r2):.3f}")


def main():
    S = load_scored()
    odds, pay = load_market()
    print(f"期間: shadow {SHADOW[0]}〜{SHADOW[1]} / post {POST[0]}〜{POST[1]}(10-05 は欠損)")
    if STATUS:
        report_production(production(STATUS))
    else:
        print("[本番] STATUS なし: 本番の保存済み予測・清算は未参照(以下はすべて再現値)")
    report_reproduction(S, odds, pay)


if __name__ == "__main__":
    main()
