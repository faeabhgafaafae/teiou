# -*- coding: utf-8 -*-
"""
sim_v2_clean.py — リーク修正後ロジックでの v2 真の実力の再評価 (2026-06-29〜09-29)
読み取り専用 / DB接続なし。

入力:
  - tools/score_models_cli.php の出力(本番 PredictV2 / PredictV3 をリーク修正後の特徴量で実行した結果)
      p_v2     : 修正後 api_predict.php 相当(当地成績はレース日より前のみ)
      p_v2leak : 修正前の再現(当地成績に当該レース自身の着順を加算)
      p_v3w    : api_v3_shadow.php 相当
  - 3連単オッズ/払戻: data/ + ルート直下 + 追加export(06-29〜07-26, 09-03〜09-08)
  - audit_leak_rewrites_20260930.json(本番DBの保存済み予測。v1時代の未書換レースのtop1)
使い方: python sim_v2_clean.py SCORED_CSV EXTRA_DIR
"""
import csv
import glob
import json
import subprocess
import sys
from collections import defaultdict

import numpy as np

from sim_v3w_final import build, settle, boot_weights, ratio, NAMES, STRATS, NB

ROOT = r"C:\Users\m1551\Desktop\teiou"
SCORED, EXTRA = sys.argv[1], sys.argv[2]
MODELS = ["v2", "v2leak", "v3w"]

PERIODS = [
    ("全期間", "2026-06-29", "2026-09-29"),
    ("v2学習Train", "2026-06-29", "2026-07-19"),
    ("v2学習Test", "2026-07-20", "2026-07-27"),
    ("v2昇格シャドウ", "2026-07-28", "2026-08-27"),
    ("v2本番期", "2026-08-28", "2026-09-29"),
    ("v3w学習外(OOS)", "2026-09-03", "2026-09-29"),
    ("v3wシャドウ", "2026-09-13", "2026-09-29"),
]

# 画面・判定で使われていた値(リーク込み)
DISPLAY = {
    "v2本番期": {"src": "performance.php get_performance_summary?from=2026-08-28 (2026-09-30取得)",
               "hit": {"tokka": 31.4, "balance": 40.3, "ichigeki": 12.6, "shibori": 14.5},
               "roi": {"tokka": 87.0, "balance": 91.6, "ichigeki": 120.8, "shibori": 97.7}},
    "v3wシャドウ": {"src": "shadow_eval_v3.php strategy_prod_v2 09-13〜09-29",
                "hit": {"tokka": 37.3, "balance": 42.5, "ichigeki": 12.9, "shibori": 18.2},
                "roi": {"tokka": 87.8, "balance": 93.0, "ichigeki": 122.0, "shibori": 100.3}},
}


def load_scored():
    races = defaultdict(dict)
    for r in csv.DictReader(open(SCORED, encoding="utf-8")):
        rid = int(r["race_id"])
        d = races[rid]
        d["date"], d["winner"] = r["date"], int(r["winner"])
        for m in MODELS:
            d.setdefault(m, [{}, {}])
            d[m][0][int(r["lane"])] = float(r[f"p_{m}"])
            d[m][1][int(r["lane"])] = int(r[f"rank_{m}"])
    out = {}
    for rid, d in races.items():
        rec = {"date": d["date"], "winner": d["winner"]}
        for m in MODELS:
            probs, ranks = d[m]
            rec[m] = (probs, [l for l, _ in sorted(ranks.items(), key=lambda kv: kv[1])])
        out[rid] = rec
    return out


def load_market():
    odds, pay = defaultdict(dict), {}
    srcs = [f"{ROOT}\\data", ROOT, EXTRA]
    for s in srcs:
        for f in glob.glob(f"{s}\\odds_*.csv"):
            for r in csv.DictReader(open(f, encoding="utf-8")):
                odds[int(r["race_id"])][r["combo"]] = float(r["odds"])
        for f in glob.glob(f"{s}\\payouts_*.csv"):
            for r in csv.DictReader(open(f, encoding="utf-8")):
                pay[int(r["race_id"])] = (r["combo"], int(r["amount"]))
    return odds, pay


def in_period(date, a, b):
    return a <= date <= b


# ── 検証 ──────────────────────────────────────────────────────────────
def validate(S):
    print("[検証1] 再計算v2 vs 本番のクリーン保存値(lr_v3 CSV初回コミットの score_total_current、09-17〜09-28)")
    agree = n = hit_prod = hit_re = 0
    mae = []
    for day in range(17, 29):
        d = f"2026-09-{day:02d}"
        p = f"data/lr_v3_{d}.csv"
        cs = subprocess.run(["git", "log", "--format=%h", "--", p], cwd=ROOT, capture_output=True, text=True).stdout.split()
        txt = subprocess.run(["git", "show", f"{cs[-1]}:{p}"], cwd=ROOT, capture_output=True, encoding="utf-8").stdout
        prod = defaultdict(dict)
        for r in csv.DictReader(txt.splitlines()):
            if r["score_total_current"]:
                prod[int(r["race_id"])][int(r["lane"])] = float(r["score_total_current"]) / 100
        for rid, pm in prod.items():
            if len(pm) != 6 or rid not in S:
                continue
            probs, lanes = S[rid]["v2"]
            ptop = max(sorted(pm), key=lambda l: pm[l])
            n += 1
            agree += ptop == lanes[0]
            hit_prod += ptop == S[rid]["winner"]
            hit_re += lanes[0] == S[rid]["winner"]
            mae.extend(abs(pm[l] - probs[l]) for l in pm)
    print(f"  {n}R  1位一致 {agree/n*100:.1f}%  確率MAE {np.mean(mae):.4f}  "
          f"top1 本番保存値 {hit_prod/n*100:.1f}% / 再計算 {hit_re/n*100:.1f}%")

    audit = json.load(open(f"{ROOT}\\audit_leak_rewrites_20260930.json", encoding="utf-8"))["daily"]
    print("[検証2] 本番保存値(監査)との日別top1比較")
    by = defaultdict(list)
    for rid, s in S.items():
        by[s["date"]].append(rid)
    clean_days = [d for d in ("2026-09-05", "2026-09-08", "2026-09-29")]
    h = sum(S[r]["v2"][1][0] == S[r]["winner"] for d in clean_days for r in by[d])
    n = sum(len(by[d]) for d in clean_days)
    ph = sum(audit[d]["top1_hits_clean"] for d in clean_days)
    pn = sum(audit[d]["clean_races"] for d in clean_days)
    print(f"  v2本番期の未書換日(09-05/09-08/09-29): 本番保存 {ph/pn*100:.1f}% ({pn}R) / 再計算v2 {h/n*100:.1f}% ({n}R)")
    rw_days = [d for d in audit if "2026-08-28" <= d <= "2026-09-28" and (audit[d]["rewritten_races"] or 0) > 0]
    ph = sum(audit[d]["top1_hits_rewritten"] for d in rw_days)
    pn = sum(audit[d]["rewritten_races"] for d in rw_days)
    rs = [r for d in rw_days for r in by[d]]
    hl = sum(S[r]["v2leak"][1][0] == S[r]["winner"] for r in rs)
    hc = sum(S[r]["v2"][1][0] == S[r]["winner"] for r in rs)
    print(f"  v2本番期の書換日: 本番保存(リーク込み) {ph/pn*100:.1f}% ({pn}R) / 疑似リークv2 {hl/len(rs)*100:.1f}% / "
          f"再計算v2 {hc/len(rs)*100:.1f}% ({len(rs)}R)")
    return audit


# ── top1 ─────────────────────────────────────────────────────────────
def v1_stored(audit, a, b):
    """v1本番期(〜08-26)の保存済み予測のうち未書換レースのtop1(本番DB実測)"""
    h = n = hr = nr = 0
    for d, v in audit.items():
        if a <= d <= min(b, "2026-08-26"):
            h += v["top1_hits_clean"] or 0
            n += v["clean_races"] or 0
            hr += v["top1_hits_rewritten"] or 0
            nr += v["rewritten_races"] or 0
    return (h / n * 100 if n else None, n, hr / nr * 100 if nr else None, nr)


def top1_tables(S, audit):
    print("\n[top1] 1着的中率(同一レース集合)")
    print(f"{'期間':<16}{'R':>6}{'1号艇':>8}{'v2':>8}{'v2リーク':>9}{'v3w':>8}{'v1保存(未書換)':>16}")
    res = {}
    for name, a, b in PERIODS:
        rs = [r for r, s in S.items() if in_period(s["date"], a, b)]
        n = len(rs)
        lane1 = sum(S[r]["winner"] == 1 for r in rs) / n * 100
        t = {m: sum(S[r][m][1][0] == S[r]["winner"] for r in rs) / n * 100 for m in MODELS}
        v1 = v1_stored(audit, a, b) if a <= "2026-08-26" else (None, 0, None, 0)
        v1s = f"{v1[0]:.1f}% ({v1[1]}R)" if v1[0] is not None else "─"
        res[name] = dict(n=n, lane1=lane1, v1=v1, **t)
        print(f"{name:<16}{n:>6}{lane1:>7.1f}%{t['v2']:>7.1f}%{t['v2leak']:>8.1f}%{t['v3w']:>7.1f}%{v1s:>16}")

    print("\n[top1差 bootstrap] 対応ありレースリサンプル 95%CI")
    for name, a, b in PERIODS:
        if name not in ("全期間", "v2昇格シャドウ", "v2本番期", "v3w学習外(OOS)"):
            continue
        rs = [r for r, s in S.items() if in_period(s["date"], a, b)]
        W = boot_weights(len(rs), seed=11)
        one = np.ones(len(rs))
        h = {m: np.array([S[r][m][1][0] == S[r]["winner"] for r in rs], float) for m in ("v2", "v3w")}
        h["lane1"] = np.array([S[r]["winner"] == 1 for r in rs], float)
        for x, y in (("v2", "lane1"), ("v3w", "lane1"), ("v3w", "v2")):
            diff = (ratio(W, h[x], one) - ratio(W, h[y], one)) * 100
            lo, hi = np.percentile(diff, [2.5, 97.5])
            print(f"  {name:<14} {x}−{y}: {(h[x].mean()-h[y].mean())*100:+.1f}pt [{lo:+.1f},{hi:+.1f}] P(>0)={np.mean(diff>0):.3f}")
    return res


# ── 4戦略 ────────────────────────────────────────────────────────────
def strategies(S, odds, pay):
    print("\n[4戦略] 現行本番ロジック(Harville選定・BALANCE 100倍/一撃15倍・prob傾斜配分)")
    out = {}
    for name, a, b in PERIODS:
        if name in ("v2学習Train", "v2学習Test"):
            continue
        rs = [r for r, s in S.items() if in_period(s["date"], a, b) and r in odds and r in pay]
        arr = {m: {s: [] for s in STRATS} for m in MODELS}
        for r in rs:
            wc, amt = pay[r]
            for m in MODELS:
                probs, lanes = S[r][m]
                bs = build(probs, lanes, odds[r])
                for s in STRATS:
                    arr[m][s].append(settle(bs[s], probs, wc, amt))
        arr = {m: {s: np.array(v, float) for s, v in d.items()} for m, d in arr.items()}
        W = boot_weights(len(rs), seed=5)
        disp = DISPLAY.get(name)
        print(f"\n  {name} {a}〜{b}  {len(rs)}R" + (f"  表示値: {disp['src']}" if disp else ""))
        print(f"  {'戦略':<6}{'v2的中':>7}{'v2ROI':>7}{'v2flat':>7}{'リークv2ROI':>11}{'v3w的中':>8}{'v3wROI':>7}"
              f"{'P(v3w≥v2)':>10}" + (f"{'表示的中':>8}{'表示ROI':>8}{'表示−v2':>8}" if disp else ""))
        res = {}
        for s in STRATS:
            m = {}
            for k in MODELS:
                t = arr[k][s].sum(0)
                m[k] = dict(hit=t[4] / t[5] * 100, prob=t[3] / t[2] * 100, flat=t[1] / t[0] * 100)
            p = float(np.mean(ratio(W, arr["v3w"][s][:, 3], arr["v3w"][s][:, 2])
                              >= ratio(W, arr["v2"][s][:, 3], arr["v2"][s][:, 2])))
            ci = np.percentile(ratio(W, arr["v2"][s][:, 3], arr["v2"][s][:, 2]) * 100, [2.5, 97.5])
            res[s] = dict(m=m, p=p, ci_v2=ci)
            line = (f"  {NAMES[s]:<6}{m['v2']['hit']:>6.1f}%{m['v2']['prob']:>6.1f}%{m['v2']['flat']:>6.1f}%"
                    f"{m['v2leak']['prob']:>10.1f}%{m['v3w']['hit']:>7.1f}%{m['v3w']['prob']:>6.1f}%{p:>10.3f}")
            if disp:
                line += f"{disp['hit'][s]:>7.1f}%{disp['roi'][s]:>7.1f}%{disp['roi'][s]-m['v2']['prob']:>+7.1f}"
            print(line + f"   v2 ROI 95%CI[{ci[0]:.1f},{ci[1]:.1f}]")
        out[name] = dict(n=len(rs), res=res)
    return out


def main():
    S = load_scored()
    odds, pay = load_market()
    print(f"再計算レース: {len(S)}R ({min(s['date'] for s in S.values())}〜{max(s['date'] for s in S.values())})")
    audit = validate(S)
    top1_tables(S, audit)
    strategies(S, odds, pay)


if __name__ == "__main__":
    main()
