# -*- coding: utf-8 -*-
"""
sim_v3w_midterm.py — v3wシャドウテスト中間分析 (2026-09-13〜09-17)
読み取り専用 / DB接続なし。data/配下のCSVのみ使用。

- v2 (predict_v2_core.php) と v3w (predict_v3_core.php alpha=0.55) を
  PHP実装の定数からPythonで再現し、同一レース集合で比較する。
- 検証: ローカル再現のtop1的中率を shadow_eval_v3.php の実測(v2 59.0 / v3w 56.5)と照合。
- 戦略シムは現行本番ロジック(generate_strategies.php):
    的中特化 = top4順列24通りからHarville上位9点
    バランス = top2×top4流し(オッズ>100除外)
    一撃重視 = top1×2-4位流し(オッズ<15除外)
    絞り込み = top3順列6通りからHarville上位3点
  賭け金は均等100円(モデル差の分離のため傾斜なし)。
- レース単位ブートストラップ5000回で P(v3w ROI >= v2 ROI) を算出(hard gate P>=0.25)。
"""
import csv
import itertools
import math
import random
from collections import defaultdict

BASE = r"C:\Users\m1551\Desktop\teiou\data"
DATES = ["2026-09-13", "2026-09-14", "2026-09-15", "2026-09-16", "2026-09-17"]

# ── v2 定数 (predict_v2_core.php) ──────────────────────────────────
V2_INTERCEPT = -0.710151
V2_MEANS  = [3.498596, 5.125432, 32.040086, 0.159386, 0.326116, 0.501524, 0.091678, 0.489493, 2.631639, 2.119382, 28.307706]
V2_SCALES = [1.709008, 1.365740, 13.847295, 0.121440, 0.174187, 0.362293, 0.105475, 0.362955, 1.417293, 1.546866, 3.305047]
V2_COEFS  = [-1.133264, 0.148167, -0.102042, 0.975016, -0.018183, 0.215581, -0.089471, 0.087079, -0.036640, 0.049179, -0.000086]

# ── v3w 定数 (predict_v3_core.php, alpha=0.55) ────────────────────
V3_INTERCEPT = -0.265615
V3_MEANS  = [0.166691, 0.166460, 0.166670, 0.166691, 0.166796, 5.141671, 0.160294, 0.501120, 0.089596, 0.484614, 2.797768, 2.401627, 0.166010, 3.099372, 0.166014, 0.333736, 3.493505, 0.165521, 0.160242, 0.225415, 0.230270, 0.479539]
V3_SCALES = [0.372700, 0.372493, 0.372681, 0.372700, 0.372794, 1.397779, 0.127588, 0.362921, 0.105427, 0.362020, 1.530566, 1.636638, 0.028202, 1.593095, 0.190919, 0.225379, 0.837246, 0.145866, 0.030617, 0.417855, 0.421005, 0.499581]
V3_COEFS  = [-0.069172, -0.017283, -0.021722, -0.041679, -0.153101, 0.275808, 0.085769, 0.261738, -0.037867, 0.108655, 0.000960, 0.038150, 0.196626, -0.289201, 0.256137, 0.343948, -0.165501, -0.002185, -0.069512, 0.100863, 0.075784, 0.127338]
V3_MEDIANS = [0.0, 0.0, 0.0, 0.0, 0.0, 5.3, 0.1471, 0.5, 0.08, 0.4766, 3.0, 2.0, 0.16, 3.0, 0.096013, 0.285352, 3.4, 0.1, 0.158, 0.0, 0.0, 0.0]
SMOOTH_K = 10
COURSE_PRIOR1 = [0.537133, 0.138822, 0.138384, 0.102522, 0.064052, 0.030872]
COURSE_PRIOR2 = [0.760562, 0.394687, 0.349163, 0.267412, 0.181237, 0.112783]

BALANCE_MAX_ODDS = 100.0
ICHIGEKI_MIN_ODDS = 15.0


def f(v, default=None):
    if v is None or v == "":
        return default
    try:
        return float(v)
    except ValueError:
        return default


def load_all():
    odds = defaultdict(dict)
    payouts = {}
    finish = defaultdict(dict)
    entries = defaultdict(list)
    for d in DATES:
        with open(f"{BASE}\\odds_{d}.csv", newline="", encoding="utf-8") as fh:
            for row in csv.DictReader(fh):
                odds[int(row["race_id"])][row["combo"]] = float(row["odds"])
        with open(f"{BASE}\\payouts_{d}.csv", newline="", encoding="utf-8") as fh:
            for row in csv.DictReader(fh):
                payouts[int(row["race_id"])] = (row["combo"], int(row["amount"]))
        with open(f"{BASE}\\finish_{d}.csv", newline="", encoding="utf-8") as fh:
            for row in csv.DictReader(fh):
                finish[int(row["race_id"])][int(row["rank"])] = int(row["lane"])
        with open(f"{BASE}\\lr_v3_{d}.csv", newline="", encoding="utf-8") as fh:
            for row in csv.DictReader(fh):
                entries[int(row["race_id"])].append(row)
    return odds, payouts, finish, entries


def v2_score(rows):
    probs = {}
    for e in rows:
        x = [f(e["lane"]), f(e["global_win_rate"], V2_MEANS[1]), f(e["global_2rate"], V2_MEANS[2]),
             f(e["local_win_rate"], V2_MEANS[3]), f(e["local_2rate"], V2_MEANS[4]),
             f(e["exhibit_time_rel"], 0.5), f(e["start_timing"], V2_MEANS[6]),
             f(e["motor_2rate_rel"], 0.5), f(e["wind_speed"], V2_MEANS[8]),
             f(e["wave_height"], V2_MEANS[9]), f(e["temperature"], V2_MEANS[10])]
        logit = V2_INTERCEPT
        for i in range(11):
            logit += V2_COEFS[i] * (x[i] - V2_MEANS[i]) / V2_SCALES[i]
        probs[int(e["lane"])] = 1.0 / (1.0 + math.exp(-logit))
    s = sum(probs.values())
    probs = {k: v / s for k, v in probs.items()}
    lanes = [l for l, _ in sorted(probs.items(), key=lambda kv: -kv[1])]
    return probs, lanes


def v3w_score(rows):
    """predict_v3_core.php::score_race を再現(lr_v3 CSVの前計算済み特徴量を使用)"""
    M = V3_MEDIANS
    # avg_st_rank: レース内順位(method=min相当)。avg_st<=0.001は欠損→rank3.5
    st_list = []
    for e in rows:
        v = f(e["avg_st"])
        st_list.append(v if (v is not None and v > 0.001) else None)
    st_rank = []
    for i, v in enumerate(st_list):
        if v is None:
            st_rank.append(3.5)
        else:
            st_rank.append(1.0 + sum(1 for j, w in enumerate(st_list) if j != i and w is not None and w < v))

    probs = {}
    for i, e in enumerate(rows):
        lane = int(e["lane"])
        c_cnt = max(0, int(f(e["course_count"], 0)))
        c_r1 = max(0, int(f(e["course_rank1"], 0)))
        c_r2 = max(0, int(f(e["course_rank2"], 0)))
        p1 = COURSE_PRIOR1[lane - 1]
        p2 = COURSE_PRIOR2[lane - 1]
        course_win = (c_r1 + SMOOTH_K * p1) / (c_cnt + SMOOTH_K)
        course_in2 = (c_r2 + SMOOTH_K * p2) / (c_cnt + SMOOTH_K)
        grade = e.get("grade_period") or ""
        x = [
            1.0 if lane == 2 else 0.0, 1.0 if lane == 3 else 0.0, 1.0 if lane == 4 else 0.0,
            1.0 if lane == 5 else 0.0, 1.0 if lane == 6 else 0.0,
            f(e["global_win_rate"], M[5]), f(e["local_win_rate"], M[6]),
            f(e["exhibit_time_rel"], M[7]), f(e["start_timing"], M[8]),
            f(e["motor_2rate_rel"], M[9]), f(e["wind_speed"], M[10]), f(e["wave_height"], M[11]),
            st_list[i] if st_list[i] is not None else M[12], st_rank[i],
            course_win, course_in2,
            f(e["recent10_avg_rank"], M[16]), f(e["recent10_win_rate"], M[17]),
            f(e["recent10_st_mean"], M[18]),
            1.0 if grade == "A1" else 0.0, 1.0 if grade == "A2" else 0.0, 1.0 if grade == "B1" else 0.0,
        ]
        logit = V3_INTERCEPT
        for k in range(22):
            logit += V3_COEFS[k] * (x[k] - V3_MEANS[k]) / V3_SCALES[k]
        probs[lane] = 1.0 / (1.0 + math.exp(-logit))
    s = sum(probs.values())
    probs = {k: v / s for k, v in probs.items()}
    lanes = [l for l, _ in sorted(probs.items(), key=lambda kv: -kv[1])]
    return probs, lanes


def harville(probs, a, b, c):
    pa, pb, pc = probs[a], probs[b], probs[c]
    d1, d2 = 1.0 - pa, 1.0 - pa - pb
    if d1 <= 1e-9 or d2 <= 1e-9:
        return 0.0
    return pa * (pb / d1) * (pc / d2)


def gen_current_strategies(probs, lanes, odds_r):
    """現行本番ロジック(generate_strategies.php 2026-09-17時点)"""
    s = {}
    perms4 = sorted(itertools.permutations(lanes[:4], 3), key=lambda p: -harville(probs, *p))
    s["tokka"] = ["-".join(map(str, p)) for p in perms4[:9]]
    top4 = lanes[:4]
    bal = []
    for first in top4[:2]:
        rest = [l for l in top4 if l != first]
        for sec in rest:
            for thi in rest:
                if sec != thi:
                    c = f"{first}-{sec}-{thi}"
                    if c in odds_r and odds_r[c] > BALANCE_MAX_ODDS:
                        continue
                    bal.append(c)
    s["balance"] = bal
    ichi = []
    first = lanes[0]
    for sec in lanes[1:4]:
        for thi in lanes[1:4]:
            if sec != thi:
                c = f"{first}-{sec}-{thi}"
                if c in odds_r and odds_r[c] < ICHIGEKI_MIN_ODDS:
                    continue
                ichi.append(c)
    s["ichigeki"] = ichi
    perms3 = sorted(itertools.permutations(lanes[:3]), key=lambda p: -harville(probs, *p))
    s["shibori"] = ["-".join(map(str, p)) for p in perms3[:3]]
    return s


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
    print(f"対象: {DATES[0]}〜{DATES[-1]}  {n}レース(6艇特徴量+着順+払戻+オッズ完備)")

    models = {}
    stats = {m: {"top1": 0, "lane1_rank1": 0} for m in ("v2", "v3w")}
    lane1_wins = 0
    for rid in races:
        rows = entries[rid]
        models[rid] = {"v2": v2_score(rows), "v3w": v3w_score(rows)}
        win = finish[rid][1]
        if win == 1:
            lane1_wins += 1
        for m in ("v2", "v3w"):
            _, lanes = models[rid][m]
            if lanes[0] == win:
                stats[m]["top1"] += 1
            if lanes[0] == 1:
                stats[m]["lane1_rank1"] += 1

    print(f"\n[再現検証] shadow_eval_v3.php実測: v2 59.0% / v3w 56.5% (839R)")
    for m in ("v2", "v3w"):
        print(f"  ローカル再現 {m:>3}: top1 {stats[m]['top1']/n*100:.1f}%  1号艇rank1率 {stats[m]['lane1_rank1']/n*100:.1f}%")
    print(f"  実際の1号艇1着率: {lane1_wins/n*100:.1f}%")

    # ── 現行本番ロジックでの4戦略シム(両モデル・均等100円) ──────────
    per_race = {m: {s: [] for s in ("tokka", "balance", "ichigeki", "shibori")} for m in ("v2", "v3w")}
    for rid in races:
        win_combo, pay = payouts[rid]
        for m in ("v2", "v3w"):
            probs, lanes = models[rid][m]
            sets = gen_current_strategies(probs, lanes, odds[rid])
            for s, combos in sets.items():
                if not combos:
                    per_race[m][s].append((0, 0, 0))
                    continue
                cost = len(combos) * 100
                hit = 1 if win_combo in combos else 0
                ret = pay if hit else 0
                per_race[m][s].append((cost, ret, hit))

    names = {"tokka": "的中特化", "balance": "バランス", "ichigeki": "一撃重視", "shibori": "絞り込み"}
    print(f"\n[現行本番ロジック・均等100円シム] {n}R")
    print(f"{'戦略':<8}{'v2的中':>8}{'v2ROI':>8}{'v3w的中':>9}{'v3wROI':>8}{'ROI差':>8}")
    summary = {}
    for s in names:
        out = {}
        for m in ("v2", "v3w"):
            arr = per_race[m][s]
            cost = sum(a[0] for a in arr)
            ret = sum(a[1] for a in arr)
            hits = sum(a[2] for a in arr)
            valid = sum(1 for a in arr if a[0] > 0)
            out[m] = (hits / valid * 100 if valid else 0, ret / cost * 100 if cost else 0)
        summary[s] = out
        d = out["v3w"][1] - out["v2"][1]
        print(f"{names[s]:<8}{out['v2'][0]:>7.1f}%{out['v2'][1]:>7.1f}%{out['v3w'][0]:>8.1f}%{out['v3w'][1]:>7.1f}%{d:>+7.1f}pt")

    # ── ブートストラップ P(v3w ROI >= v2 ROI) ───────────────────────
    rng = random.Random(42)
    NB = 5000
    ge = {s: 0 for s in names}
    for _ in range(NB):
        idx = [rng.randrange(n) for _ in range(n)]
        for s in names:
            c2 = r2 = c3 = r3 = 0
            for i in idx:
                a2 = per_race["v2"][s][i]
                a3 = per_race["v3w"][s][i]
                c2 += a2[0]; r2 += a2[1]
                c3 += a3[0]; r3 += a3[1]
            roi2 = r2 / c2 if c2 else 0
            roi3 = r3 / c3 if c3 else 0
            if roi3 >= roi2:
                ge[s] += 1
    print(f"\n[ブートストラップ{NB}回] hard gate: P(v3w>=v2) >= 0.25")
    for s in names:
        p = ge[s] / NB
        print(f"  {names[s]:<8} P = {p:.3f}  {'合格' if p >= 0.25 else '不合格'}")


if __name__ == "__main__":
    run()
