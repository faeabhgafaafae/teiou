# -*- coding: utf-8 -*-
"""
的中特化戦略の点数比較シミュレーション (読み取り専用 / DB接続なし)

sim_stake_allocation.py の v2確率再現・Harville上位N点選定ロジックを流用し、
data/配下に蓄積した 2026-09-09〜2026-09-17 の9日分CSVで
的中特化を 6点 / 9点 / 12点 で比較する。

的中特化の買い目 = 上位4艇の3連単全24通りをHarville確率降順に並べ、上位N点。
配分は均等100円 (flat)。
"""
import csv
import itertools
import math
from collections import defaultdict

BASE = r"C:\Users\m1551\Desktop\teiou\data"
DATES = ["2026-09-09", "2026-09-10", "2026-09-11", "2026-09-12", "2026-09-13",
         "2026-09-14", "2026-09-15", "2026-09-16", "2026-09-17"]

# ── predict_v2_core.php の学習済み定数 (sim_stake_allocation.py と同一) ──
INTERCEPT = -0.710151
MEANS  = [3.498596, 5.125432, 32.040086, 0.159386, 0.326116, 0.501524, 0.091678, 0.489493, 2.631639, 2.119382, 28.307706]
SCALES = [1.709008, 1.365740, 13.847295, 0.121440, 0.174187, 0.362293, 0.105475, 0.362955, 1.417293, 1.546866, 3.305047]
COEFS  = [-1.133264, 0.148167, -0.102042, 0.975016, -0.018183, 0.215581, -0.089471, 0.087079, -0.036640, 0.049179, -0.000086]


def f(v, default=None):
    if v is None or v == "":
        return default
    try:
        return float(v)
    except ValueError:
        return default


def load_all():
    odds = defaultdict(dict)      # race_id -> combo -> odds
    payouts = {}                  # race_id -> (combo, amount)
    finish = defaultdict(dict)    # race_id -> rank -> lane
    entries = defaultdict(list)   # race_id -> [entry dict]

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


def v2_score_race(rows):
    """predict_v2_core.php::score_race を再現。lane->prob と rank順lanes を返す"""
    probs = {}
    for e in rows:
        x = [
            f(e["lane"]),
            f(e["global_win_rate"], MEANS[1]),
            f(e["global_2rate"], MEANS[2]),
            f(e["local_win_rate"], MEANS[3]),
            f(e["local_2rate"], MEANS[4]),
            f(e["exhibit_time_rel"], 0.5),
            f(e["start_timing"], MEANS[6]),
            f(e["motor_2rate_rel"], 0.5),
            f(e["wind_speed"], MEANS[8]),
            f(e["wave_height"], MEANS[9]),
            f(e["temperature"], MEANS[10]),
        ]
        logit = INTERCEPT
        for i in range(11):
            logit += COEFS[i] * (x[i] - MEANS[i]) / SCALES[i]
        probs[int(e["lane"])] = 1.0 / (1.0 + math.exp(-logit))
    s = sum(probs.values())
    probs = {k: v / s for k, v in probs.items()}
    lanes_ranked = [l for l, _ in sorted(probs.items(), key=lambda kv: -kv[1])]
    return probs, lanes_ranked


def harville(probs, a, b, c):
    """3連単 a-b-c のHarville近似確率"""
    pa, pb, pc = probs[a], probs[b], probs[c]
    d1 = 1.0 - pa
    d2 = 1.0 - pa - pb
    if d1 <= 1e-9 or d2 <= 1e-9:
        return 0.0
    return pa * (pb / d1) * (pc / d2)


def tokka_combos(probs, lanes, n_pts):
    """的中特化: 上位4艇の3連単24通りをHarville降順で上位 n_pts 点"""
    perms = list(itertools.permutations(lanes[:4], 3))
    perms.sort(key=lambda p: -harville(probs, *p))
    return ["-".join(map(str, p)) for p in perms[:n_pts]]


def run():
    odds, payouts, finish, entries = load_all()

    # 対象レース: 6艇分の特徴量 + 1-3着 + 払戻 + オッズが揃うもの
    races = []
    for rid, rows in entries.items():
        if len(rows) != 6:
            continue
        if rid not in payouts or rid not in odds:
            continue
        fin = finish.get(rid, {})
        if not all(r in fin for r in (1, 2, 3)):
            continue
        races.append(rid)
    races.sort()

    v2 = {}
    rank1_hits = 0
    for rid in races:
        probs, lanes = v2_score_race(entries[rid])
        v2[rid] = (probs, lanes)
        if lanes[0] == finish[rid][1]:
            rank1_hits += 1

    print(f"対象期間: {DATES[0]} 〜 {DATES[-1]} ({len(DATES)}日分)")
    print(f"対象レース数: {len(races)}")
    print(f"v2 rank1 1着的中率(検証): {rank1_hits/len(races)*100:.1f}%")
    print()
    print("=" * 72)
    print("【的中特化】点数比較 (上位4艇の3連単をHarville確率上位N点・均等100円)")
    print("=" * 72)
    print(f"\n{'点数':>4}{'的中率':>9}{'回収率':>9}{'1レース購入額':>14}{'点あたりコスト':>14}{'1的中あたり投資':>16}")
    results = []
    for n_pts in [6, 9, 12]:
        tot_races = tot_hits = tot_cost = tot_ret = 0
        for rid in races:
            probs, lanes = v2[rid]
            combos = tokka_combos(probs, lanes, n_pts)
            win_combo, pay = payouts[rid]
            cost = len(combos) * 100
            hit = 1 if win_combo in combos else 0
            ret = pay if hit else 0   # 均等100円 → 払戻はamount(=100円あたり)そのまま
            tot_races += 1
            tot_hits += hit
            tot_cost += cost
            tot_ret += ret
        hr = tot_hits / tot_races * 100
        rr = tot_ret / tot_cost * 100
        avg_cost = tot_cost / tot_races
        per_point = tot_cost / (tot_races * n_pts)         # =100 (flat)
        cost_per_hit = tot_cost / tot_hits if tot_hits else float("inf")
        results.append((n_pts, hr, rr, avg_cost, per_point, cost_per_hit))
        print(f"{n_pts:>4}{hr:>8.1f}%{rr:>8.1f}%{avg_cost:>12.0f}円{per_point:>12.0f}円{cost_per_hit:>14.0f}円")

    print()
    print("─ 6点→9点→12点 の増分 ─")
    base = results[0]
    for n_pts, hr, rr, *_ in results:
        print(f"  {n_pts:>2}点: 的中率 {hr:5.1f}% ({hr-base[1]:+.1f}pt)  回収率 {rr:5.1f}% ({rr-base[2]:+.1f}pt)")
    return results


if __name__ == "__main__":
    run()
