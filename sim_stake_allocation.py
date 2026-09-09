# -*- coding: utf-8 -*-
"""
賭け金傾斜配分・点数変更シミュレーション (読み取り専用 / DB接続なし)
BOATERS比較分析用。ローカルCSV(odds/payouts/finish/lr_v3)のみ使用。

v2モデル(predict_v2_core.phpの係数)をPythonで再現し、
3期間(2026-07-27, 08-10, 08-24開始の各2週間)の実データで検証する。
"""
import csv
import itertools
import math
from collections import defaultdict

BASE = r"C:\Users\m1551\Desktop\teiou"
WINDOWS = ["2026-07-27", "2026-08-10", "2026-08-24"]

# ── predict_v2_core.php の学習済み定数 ──────────────────────────
INTERCEPT = -0.710151
MEANS  = [3.498596, 5.125432, 32.040086, 0.159386, 0.326116, 0.501524, 0.091678, 0.489493, 2.631639, 2.119382, 28.307706]
SCALES = [1.709008, 1.365740, 13.847295, 0.121440, 0.174187, 0.362293, 0.105475, 0.362955, 1.417293, 1.546866, 3.305047]
COEFS  = [-1.133264, 0.148167, -0.102042, 0.975016, -0.018183, 0.215581, -0.089471, 0.087079, -0.036640, 0.049179, -0.000086]

BALANCE_MAX_ODDS = 100.0
ICHIGEKI_MIN_ODDS = 15.0


def f(v, default=None):
    if v is None or v == "":
        return default
    try:
        return float(v)
    except ValueError:
        return default


# ── データ読み込み ──────────────────────────────────────────────
def load_all():
    odds = defaultdict(dict)      # race_id -> combo -> odds
    payouts = {}                  # race_id -> (combo, amount)
    finish = defaultdict(dict)    # race_id -> rank -> lane
    entries = defaultdict(list)   # race_id -> [entry dict]

    for w in WINDOWS:
        with open(f"{BASE}\\odds_{w}.csv", newline="", encoding="utf-8") as fh:
            for row in csv.DictReader(fh):
                odds[int(row["race_id"])][row["combo"]] = float(row["odds"])
        with open(f"{BASE}\\payouts_{w}.csv", newline="", encoding="utf-8") as fh:
            for row in csv.DictReader(fh):
                payouts[int(row["race_id"])] = (row["combo"], int(row["amount"]))
        with open(f"{BASE}\\finish_{w}.csv", newline="", encoding="utf-8") as fh:
            for row in csv.DictReader(fh):
                finish[int(row["race_id"])][int(row["rank"])] = int(row["lane"])
        with open(f"{BASE}\\lr_v3_{w}.csv", newline="", encoding="utf-8") as fh:
            for row in csv.DictReader(fh):
                entries[int(row["race_id"])].append(row)
    return odds, payouts, finish, entries


# ── v2確率計算 (predict_v2_core.php::score_race を再現) ────────
def v2_score_race(rows):
    """rows: lr_v3 CSVの同一レース行。lane->prob と rank順lanes を返す"""
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


# ── 現行戦略の買い目生成 (generate_strategies.php を再現) ──────
def gen_strategies(lanes, odds_map):
    strats = {}
    top3 = lanes[:3]
    strats["的中特化"] = ["-".join(map(str, p)) for p in itertools.permutations(top3)]

    top4 = lanes[:4]
    combos_b = []
    for first in top4[:2]:
        rest = [l for l in top4 if l != first]
        for sec in rest:
            for thi in rest:
                if sec != thi:
                    c = f"{first}-{sec}-{thi}"
                    if odds_map and c in odds_map and odds_map[c] > BALANCE_MAX_ODDS:
                        continue
                    combos_b.append(c)
    strats["バランス"] = combos_b

    combos_i = []
    first = lanes[0]
    bottom = lanes[1:4]
    for sec in bottom:
        for thi in bottom:
            if sec != thi:
                c = f"{first}-{sec}-{thi}"
                if odds_map and c in odds_map and odds_map[c] < ICHIGEKI_MIN_ODDS:
                    continue
                combos_i.append(c)
    strats["一撃重視"] = combos_i

    sk = sorted(top3)
    strats["絞り込み"] = [f"{sk[0]}-{sk[1]}-{sk[2]}"]
    return strats


# ── 賭け金配分方式 ──────────────────────────────────────────────
def allocate(combos, weights, budget):
    """budget(円)を100円単位でweightsに比例配分。最低0円(切り捨てられる買い目もある)。
    largest remainder法。全weight=0なら均等。"""
    n = len(combos)
    if n == 0:
        return {}
    units = budget // 100
    wsum = sum(weights)
    if wsum <= 1e-12:
        weights = [1.0] * n
        wsum = float(n)
    ideal = [w / wsum * units for w in weights]
    base = [int(v) for v in ideal]
    rem = units - sum(base)
    order = sorted(range(n), key=lambda i: -(ideal[i] - base[i]))
    for i in order[:rem]:
        base[i] += 1
    return {c: b * 100 for c, b in zip(combos, base)}


def allocate_min100(combos, weights, budget):
    """全買い目に最低100円を保証した上で残りを比例配分"""
    n = len(combos)
    if n == 0 or budget < n * 100:
        return allocate(combos, weights, budget)
    stakes = {c: 100 for c in combos}
    remain = budget - n * 100
    extra = allocate(combos, weights, remain)
    for c in combos:
        stakes[c] += extra.get(c, 0)
    return stakes


def scheme_weights(scheme, combos, probs, odds_map):
    """配分方式ごとの重みを返す"""
    ws = []
    for c in combos:
        a, b, cc = map(int, c.split("-"))
        p = harville(probs, a, b, cc)
        o = odds_map.get(c)
        if scheme == "flat":
            ws.append(1.0)
        elif scheme == "prob":            # 予測確率比例
            ws.append(p)
        elif scheme == "ev":              # EV比例 (EV = p*odds, 正のEV分だけ傾斜)
            ws.append(max(0.0, p * o) if o else 0.0)
        elif scheme == "inv_odds":        # オッズ逆数 (均等払戻狙い)
            ws.append(1.0 / o if o and o > 0 else 0.0)
        elif scheme == "kelly":           # 単純ケリー比率 (負なら0)
            if o and o > 1.0:
                b_net = o - 1.0
                kf = (p * b_net - (1.0 - p)) / b_net
                ws.append(max(0.0, kf))
            else:
                ws.append(0.0)
        else:
            raise ValueError(scheme)
    return ws


# ── シミュレーション本体 ────────────────────────────────────────
def settle(stakes, win_combo, payout_amount):
    cost = sum(stakes.values())
    st = stakes.get(win_combo, 0)
    ret = st // 100 * payout_amount if st > 0 else 0
    return cost, ret, 1 if st > 0 else 0


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
    print(f"対象レース数: {len(races)}")

    # v2確率を全レース分計算 + 検証(rank1的中率)
    v2 = {}
    rank1_hits = 0
    for rid in races:
        probs, lanes = v2_score_race(entries[rid])
        v2[rid] = (probs, lanes)
        if lanes[0] == finish[rid][1]:
            rank1_hits += 1
    print(f"v2 rank1 1着的中率(検証): {rank1_hits/len(races)*100:.1f}% (学習時Test 60.8%)")
    print()

    # ==================================================================
    # 分析1: 賭け金傾斜配分 (現行の買い目構成のまま配分だけ変更)
    # ==================================================================
    schemes = ["flat", "prob", "ev", "inv_odds", "kelly"]
    print("=" * 78)
    print("【分析1】賭け金傾斜配分 (現行買い目のまま)")
    print("  左: 予算=点数x100円・純比例(買い目切捨てあり)")
    print("  右: 予算=点数x600円・全点100円保証+残りを傾斜 (BOATERS型: 1点平均611円)")
    print("=" * 78)
    for strat_name in ["的中特化", "バランス", "一撃重視", "絞り込み"]:
        agg = {s: [0, 0, 0, 0] for s in schemes}        # races, hits, cost, ret
        agg_m = {s: [0, 0, 0, 0] for s in schemes}      # min100保証+6倍予算版
        for rid in races:
            probs, lanes = v2[rid]
            strats = gen_strategies(lanes, odds[rid])
            combos = strats[strat_name]
            if not combos:
                continue
            win_combo, pay = payouts[rid]
            for s in schemes:
                ws = scheme_weights(s, combos, probs, odds[rid])
                st = allocate(combos, ws, len(combos) * 100)
                c, r, h = settle(st, win_combo, pay)
                agg[s][0] += 1; agg[s][1] += h; agg[s][2] += c; agg[s][3] += r
                st2 = allocate_min100(combos, ws, len(combos) * 600)
                c2, r2, h2 = settle(st2, win_combo, pay)
                agg_m[s][0] += 1; agg_m[s][1] += h2; agg_m[s][2] += c2; agg_m[s][3] += r2
        print(f"\n─ {strat_name} ─")
        print(f"{'方式':<12}{'的中率':>8}{'回収率':>8} | {'的中率(600円版)':>14}{'回収率(600円版)':>14}")
        for s in schemes:
            n, h, c, r = agg[s]
            n2, h2, c2, r2 = agg_m[s]
            hr = h / n * 100 if n else 0
            rr = r / c * 100 if c else 0
            hr2 = h2 / n2 * 100 if n2 else 0
            rr2 = r2 / c2 * 100 if c2 else 0
            print(f"{s:<12}{hr:>7.1f}%{rr:>7.1f}% | {hr2:>13.1f}%{rr2:>13.1f}%")

    # ==================================================================
    # 分析2: 的中特化 6点 -> 9点 (top4順列24通りからHarville上位N)
    # ==================================================================
    print()
    print("=" * 78)
    print("【分析2】的中特化の点数拡大 (top4順列からHarville確率上位N点)")
    print("=" * 78)
    print(f"\n{'構成':<28}{'的中率':>8}{'回収率':>8}{'平均コスト':>10}")
    for label, n_pts in [("現行box6(top3全順列)", None), ("Harville上位6点", 6),
                         ("Harville上位9点", 9), ("Harville上位12点", 12)]:
        tot = [0, 0, 0, 0]
        for rid in races:
            probs, lanes = v2[rid]
            if n_pts is None:
                combos = gen_strategies(lanes, odds[rid])["的中特化"]
            else:
                perms = list(itertools.permutations(lanes[:4], 3))
                perms.sort(key=lambda p: -harville(probs, *p))
                combos = ["-".join(map(str, p)) for p in perms[:n_pts]]
            win_combo, pay = payouts[rid]
            st = {c: 100 for c in combos}
            c, r, h = settle(st, win_combo, pay)
            tot[0] += 1; tot[1] += h; tot[2] += c; tot[3] += r
        hr = tot[1] / tot[0] * 100
        rr = tot[3] / tot[2] * 100
        print(f"{label:<28}{hr:>7.1f}%{rr:>7.1f}%{tot[2]/tot[0]:>9.0f}円")

    # ==================================================================
    # 分析3: 絞り込み 1点 -> 3点
    # ==================================================================
    print()
    print("=" * 78)
    print("【分析3】絞り込みの点数拡大")
    print("=" * 78)
    variants = {
        "現行1点(top3枠番順)": "current",
        "top3順列のHarville上位3点": "hv3",
        "枠番順1点+Harville上位2点": "mix3",
        "Harville上位1点のみ": "hv1",
    }
    print(f"\n{'構成':<28}{'的中率':>8}{'回収率':>8}{'平均コスト':>10}")
    for label, mode in variants.items():
        tot = [0, 0, 0, 0]
        for rid in races:
            probs, lanes = v2[rid]
            top3 = lanes[:3]
            perms = list(itertools.permutations(top3))
            perms.sort(key=lambda p: -harville(probs, *p))
            sk = sorted(top3)
            cur = f"{sk[0]}-{sk[1]}-{sk[2]}"
            if mode == "current":
                combos = [cur]
            elif mode == "hv3":
                combos = ["-".join(map(str, p)) for p in perms[:3]]
            elif mode == "hv1":
                combos = ["-".join(map(str, p)) for p in perms[:1]]
            else:  # mix3
                combos = [cur]
                for p in perms:
                    c = "-".join(map(str, p))
                    if c not in combos:
                        combos.append(c)
                    if len(combos) == 3:
                        break
            win_combo, pay = payouts[rid]
            st = {c: 100 for c in combos}
            c, r, h = settle(st, win_combo, pay)
            tot[0] += 1; tot[1] += h; tot[2] += c; tot[3] += r
        hr = tot[1] / tot[0] * 100
        rr = tot[3] / tot[2] * 100
        print(f"{label:<28}{hr:>7.1f}%{rr:>7.1f}%{tot[2]/tot[0]:>9.0f}円")

    # ==================================================================
    # 分析4: 組み合わせ (点数変更 + 最良配分方式)
    # ==================================================================
    print()
    print("=" * 78)
    print("【分析4】組み合わせ: 点数変更 + 傾斜配分(min100保証)")
    print("=" * 78)
    combo_tests = [
        ("的中特化HV6点 + flat", "hit6", "flat", 100),
        ("的中特化HV6点 + prob傾斜600円", "hit6", "prob", 600),
        ("的中特化HV6点 + inv_odds傾斜600円", "hit6", "inv_odds", 600),
        ("的中特化HV9点 + flat", "hit9", "flat", 100),
        ("的中特化HV9点 + prob傾斜600円", "hit9", "prob", 600),
        ("的中特化HV9点 + inv_odds傾斜600円", "hit9", "inv_odds", 600),
        ("絞り込み3点(枠+HV2) + flat", "sk3", "flat", 100),
        ("絞り込み3点(枠+HV2) + prob傾斜600円", "sk3", "prob", 600),
        ("絞り込み3点(枠+HV2) + inv_odds600円", "sk3", "inv_odds", 600),
    ]
    print(f"\n{'構成':<34}{'的中率':>8}{'回収率':>8}{'平均コスト':>10}")
    for label, mode, scheme, unit in combo_tests:
        tot = [0, 0, 0, 0]
        for rid in races:
            probs, lanes = v2[rid]
            if mode == "hit9":
                perms = list(itertools.permutations(lanes[:4], 3))
                perms.sort(key=lambda p: -harville(probs, *p))
                combos = ["-".join(map(str, p)) for p in perms[:9]]
            elif mode == "hit6":
                perms = list(itertools.permutations(lanes[:4], 3))
                perms.sort(key=lambda p: -harville(probs, *p))
                combos = ["-".join(map(str, p)) for p in perms[:6]]
            else:  # sk3: 枠番順1点 + Harville上位2点
                top3 = lanes[:3]
                perms = list(itertools.permutations(top3))
                perms.sort(key=lambda p: -harville(probs, *p))
                sk = sorted(top3)
                combos = [f"{sk[0]}-{sk[1]}-{sk[2]}"]
                for p in perms:
                    cc = "-".join(map(str, p))
                    if cc not in combos:
                        combos.append(cc)
                    if len(combos) == 3:
                        break
            win_combo, pay = payouts[rid]
            budget = len(combos) * unit
            ws = scheme_weights(scheme, combos, probs, odds[rid])
            st = allocate_min100(combos, ws, budget)
            c, r, h = settle(st, win_combo, pay)
            tot[0] += 1; tot[1] += h; tot[2] += c; tot[3] += r
        hr = tot[1] / tot[0] * 100
        rr = tot[3] / tot[2] * 100
        print(f"{label:<34}{hr:>7.1f}%{rr:>7.1f}%{tot[2]/tot[0]:>9.0f}円")


if __name__ == "__main__":
    run()
