# -*- coding: utf-8 -*-
"""
sim_v3w_final.py — v3wシャドウテスト最終判定 (2026-09-13〜09-29 全シャドウ期間)
読み取り専用 / DB接続なし。data/配下のCSV(+ルート直下のオフライン期間CSV)とgit履歴のみ使用。

sim_v3w_midterm.py の再現ロジック(v2/v3wスコア・Harville・現行買い目生成)を流用し、
  [0] 本番v2予測の事後書き換え(リーク)検証
      - data/lr_v3_*.csv は同一日付で2回コミットされる(当日21:30バッチ / 翌日20:00バッチ)。
        score_total_current(=本番predictions.score_total)の新旧でtop1的中率を比較する。
      - ローカルv2に「当該レース自身の結果を地元成績へ混入」させた疑似リーク版を作り、本番値と照合。
  [1] 日別top1(ローカルv2 / 疑似リークv2 / ローカルv3w)
  [2] 4戦略の的中率・ROI(均等100円 と 本番同等prob傾斜配分)、bootstrap P(v3w>=v2)
      全期間 / 中間分析期間(09-13〜09-17) / 後半(09-18〜09-29)
  [3] BALANCE_MAX_ODDS スイープ(50/70/80/100/150/200/上限なし)
      全期間・前後半・オフライン期間(07-27〜09-06、v3w学習期間内のため参考)、
      bootstrapで各上限が最良となる確率と P(50倍>=100倍)
を出力する。
"""
import csv
import datetime as dt
import io
import itertools
import subprocess
from collections import defaultdict

import numpy as np

from sim_v3w_midterm import v2_score, v3w_score, harville, ICHIGEKI_MIN_ODDS

ROOT = r"C:\Users\m1551\Desktop\teiou"
DATA = ROOT + r"\data"


def date_range(a, b):
    d0 = dt.date.fromisoformat(a)
    d1 = dt.date.fromisoformat(b)
    return [(d0 + dt.timedelta(days=i)).isoformat() for i in range((d1 - d0).days + 1)]


SHADOW_DATES = date_range("2026-09-13", "2026-09-29")
MID_DATES = set(date_range("2026-09-13", "2026-09-17"))
OFFLINE_CHUNKS = ["2026-07-27", "2026-08-10", "2026-08-24"]  # ルート直下(2週間単位)

STRATS = ["tokka", "balance", "ichigeki", "shibori"]
NAMES = {"tokka": "的中特化", "balance": "バランス", "ichigeki": "一撃重視", "shibori": "絞り込み"}
MODELS = ["v2", "v3w"]
STAKE_UNIT, STAKE_MIN = 600, 100
NB = 5000
CAPS = [50.0, 70.0, 80.0, 100.0, 150.0, 200.0, None]

# shadow_eval_v3.php?from=2026-09-13&to=2026-09-29 (2026-09-30取得) の日別実測
PROD_DAILY = {
    "2026-09-13": (109, 103, 180), "2026-09-14": (104, 89, 167), "2026-09-15": (89, 83, 156),
    "2026-09-16": (107, 94, 156), "2026-09-17": (119, 105, 180), "2026-09-18": (106, 94, 180),
    "2026-09-19": (96, 81, 155), "2026-09-20": (90, 93, 168), "2026-09-21": (77, 68, 121),
    "2026-09-22": (102, 92, 156), "2026-09-23": (111, 92, 156), "2026-09-24": (100, 93, 156),
    "2026-09-25": (93, 76, 144), "2026-09-26": (101, 92, 156), "2026-09-27": (100, 94, 156),
    "2026-09-28": (88, 80, 144), "2026-09-29": (73, 85, 144),
}


# ── 読み込み ───────────────────────────────────────────────────────────
def _read(path_or_text, is_text=False):
    fh = io.StringIO(path_or_text) if is_text else open(path_or_text, newline="", encoding="utf-8")
    with fh:
        return list(csv.DictReader(fh))


def load(files):
    """files: [(odds, payouts, finish, lr)] のパス群"""
    odds = defaultdict(dict)
    payouts, finish, entries, rdate = {}, defaultdict(dict), defaultdict(list), {}
    for fo, fp, ff, fl in files:
        for r in _read(fo):
            odds[int(r["race_id"])][r["combo"]] = float(r["odds"])
        for r in _read(fp):
            payouts[int(r["race_id"])] = (r["combo"], int(r["amount"]))
        for r in _read(ff):
            finish[int(r["race_id"])][int(r["rank"])] = int(r["lane"])
        for r in _read(fl):
            entries[int(r["race_id"])].append(r)
            rdate[int(r["race_id"])] = r["date"]
    races = sorted(
        rid for rid, rows in entries.items()
        if len(rows) == 6 and rid in payouts and rid in odds
        and all(k in finish.get(rid, {}) for k in (1, 2, 3))
    )
    return dict(odds=odds, payouts=payouts, finish=finish, entries=entries, date=rdate, races=races)


def shadow_files():
    return [(f"{DATA}\\odds_{d}.csv", f"{DATA}\\payouts_{d}.csv", f"{DATA}\\finish_{d}.csv",
             f"{DATA}\\lr_v3_{d}.csv") for d in SHADOW_DATES]


def offline_files():
    return [(f"{ROOT}\\odds_{d}.csv", f"{ROOT}\\payouts_{d}.csv", f"{ROOT}\\finish_{d}.csv",
             f"{ROOT}\\lr_v3_{d}.csv") for d in OFFLINE_CHUNKS]


# ── 疑似リーク版v2: 当該レース自身の着順を地元成績(2年窓)へ混入 ─────────────
def v2_score_leaked(rows, fin):
    top2 = {fin[1], fin[2]}
    leaked = []
    for e in rows:
        e = dict(e)
        lane = int(e["lane"])
        cnt = float(e["local_race_cnt"] or 0)
        lw = float(e["local_win_rate"] or 0)
        l2 = float(e["local_2rate"] or 0)
        e["local_win_rate"] = str((lw * cnt + (1 if fin[1] == lane else 0)) / (cnt + 1))
        e["local_2rate"] = str((l2 * cnt + (1 if lane in top2 else 0)) / (cnt + 1))
        leaked.append(e)
    return v2_score(leaked)


# ── 買い目生成(generate_strategies.php build_strategies と同一) ─────────────
def build(probs, lanes, odds_r, balance_cap=100.0):
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
                    if balance_cap is not None and c in odds_r and odds_r[c] > balance_cap:
                        continue
                    bal.append(c)
    s["balance"] = bal
    ichi = []
    for sec in lanes[1:4]:
        for thi in lanes[1:4]:
            if sec != thi:
                c = f"{lanes[0]}-{sec}-{thi}"
                if c in odds_r and odds_r[c] < ICHIGEKI_MIN_ODDS:
                    continue
                ichi.append(c)
    s["ichigeki"] = ichi
    perms3 = sorted(itertools.permutations(lanes[:3]), key=lambda p: -harville(probs, *p))
    s["shibori"] = ["-".join(map(str, p)) for p in perms3[:3]]
    return s


def allocate(combos, probs):
    """_strat_allocate('prob') の再現: 全点STAKE_MIN保証+残予算をHarville比例(largest remainder)"""
    n = len(combos)
    ws = [harville(probs, *map(int, c.split("-"))) for c in combos]
    stakes = [STAKE_MIN] * n
    remain = (n * STAKE_UNIT - n * STAKE_MIN) // 100
    wsum = sum(ws)
    if wsum <= 1e-12:
        ws, wsum = [1.0] * n, float(n)
    ideal = [w / wsum * remain for w in ws]
    base = [int(x) for x in ideal]
    left = remain - sum(base)
    order = sorted(range(n), key=lambda i: -(ideal[i] - base[i]))
    for i in order[:left]:
        base[i] += 1
    return [stakes[i] + base[i] * 100 for i in range(n)]


def settle(combos, probs, win_combo, pay):
    """(flat_cost, flat_ret, prob_cost, prob_ret, hit, valid)"""
    if not combos:
        return (0, 0, 0, 0, 0, 0)
    hit = 1 if win_combo in combos else 0
    stakes = allocate(combos, probs)
    fr = pay if hit else 0
    pr = pay * stakes[combos.index(win_combo)] // 100 if hit else 0
    return (len(combos) * 100, fr, sum(stakes), pr, hit, 1)


# ── bootstrap: レース単位の対応ありリサンプル(多項重み) ─────────────────
def boot_weights(n, seed=42):
    rng = np.random.default_rng(seed)
    return rng.multinomial(n, np.full(n, 1.0 / n), size=NB).astype(np.float32)


def ratio(W, num, den):
    d = W @ den
    return np.where(d > 0, (W @ num) / np.where(d > 0, d, 1), 0.0)


# ── 集計 ─────────────────────────────────────────────────────────────
def score_all(D):
    sc = {}
    for rid in D["races"]:
        rows = D["entries"][rid]
        sc[rid] = {"v2": v2_score(rows), "v3w": v3w_score(rows),
                   "v2leak": v2_score_leaked(rows, D["finish"][rid])}
    return sc


def strat_arrays(D, sc, races, cap=100.0, strats=STRATS):
    """arr[model][strat] = np.array (len(races), 6)"""
    out = {m: {s: [] for s in strats} for m in MODELS}
    for rid in races:
        wc, pay = D["payouts"][rid]
        for m in MODELS:
            probs, lanes = sc[rid][m]
            b = build(probs, lanes, D["odds"][rid], cap)
            for s in strats:
                out[m][s].append(settle(b[s], probs, wc, pay))
    return {m: {s: np.array(v, dtype=np.float64) for s, v in d.items()} for m, d in out.items()}


def metrics(a):
    fc, fr, pc, pr, h, v = a.sum(0)
    return dict(hit=h / v * 100 if v else 0, flat=fr / fc * 100 if fc else 0,
                prob=pr / pc * 100 if pc else 0, pts=fc / 100 / v if v else 0, n=int(v))


def compare(D, sc, races, label):
    print(f"\n[4戦略比較] {label}  {len(races)}R")
    arr = strat_arrays(D, sc, races)
    W = boot_weights(len(races))
    print(f"{'戦略':<6}{'v2的中':>8}{'v3w的中':>8}{'v2flat':>8}{'v3wflat':>8}{'差':>7}{'P_flat':>8}"
          f"{'v2prob':>8}{'v3wprob':>8}{'差':>7}{'P_prob':>8}{'P_hit':>7}")
    res = {}
    for s in STRATS:
        a2, a3 = arr["v2"][s], arr["v3w"][s]
        m2, m3 = metrics(a2), metrics(a3)
        pf = float(np.mean(ratio(W, a3[:, 1], a3[:, 0]) >= ratio(W, a2[:, 1], a2[:, 0])))
        pp = float(np.mean(ratio(W, a3[:, 3], a3[:, 2]) >= ratio(W, a2[:, 3], a2[:, 2])))
        ph = float(np.mean(ratio(W, a3[:, 4], a3[:, 5]) >= ratio(W, a2[:, 4], a2[:, 5])))
        dflat = m3["flat"] - m2["flat"]
        ci = np.percentile(ratio(W, a3[:, 3], a3[:, 2]) - ratio(W, a2[:, 3], a2[:, 2]), [2.5, 97.5]) * 100
        res[s] = dict(v2=m2, v3w=m3, p_flat=pf, p_prob=pp, p_hit=ph, ci_prob=ci)
        print(f"{NAMES[s]:<6}{m2['hit']:>7.1f}%{m3['hit']:>7.1f}%{m2['flat']:>7.1f}%{m3['flat']:>7.1f}%"
              f"{dflat:>+6.1f}{pf:>8.3f}{m2['prob']:>7.1f}%{m3['prob']:>7.1f}%{m3['prob']-m2['prob']:>+6.1f}"
              f"{pp:>8.3f}{ph:>7.3f}   prob差95%CI[{ci[0]:+.1f},{ci[1]:+.1f}]")
    return res


def balance_sweep(D, sc, races, label, boot=True):
    print(f"\n[BALANCE_MAX_ODDS スイープ] {label}  {len(races)}R")
    per = {}
    for cap in CAPS:
        per[cap] = strat_arrays(D, sc, races, cap, strats=["balance"])
    print(f"{'上限':>6}{'v3w点数':>8}{'v3w的中':>8}{'v3wflat':>8}{'v3wprob':>8}"
          f"{'v2点数':>8}{'v2的中':>8}{'v2flat':>8}{'v2prob':>8}")
    for cap in CAPS:
        m3 = metrics(per[cap]["v3w"]["balance"])
        m2 = metrics(per[cap]["v2"]["balance"])
        tag = "なし" if cap is None else f"{cap:.0f}倍"
        print(f"{tag:>6}{m3['pts']:>8.2f}{m3['hit']:>7.1f}%{m3['flat']:>7.1f}%{m3['prob']:>7.1f}%"
              f"{m2['pts']:>8.2f}{m2['hit']:>7.1f}%{m2['flat']:>7.1f}%{m2['prob']:>7.1f}%")
    if not boot:
        return per
    W = boot_weights(len(races), seed=7)
    for m in MODELS:
        for col, (ri, ci) in (("flat", (1, 0)), ("prob", (3, 2))):
            mat = np.stack([ratio(W, per[c][m]["balance"][:, ri], per[c][m]["balance"][:, ci]) for c in CAPS], 1)
            best = np.bincount(mat.argmax(1), minlength=len(CAPS)) / NB
            p50 = float(np.mean(mat[:, 0] >= mat[:, CAPS.index(100.0)]))
            s = " ".join(f"{('なし' if c is None else int(c))}:{b:.2f}" for c, b in zip(CAPS, best))
            print(f"  {m:>3} {col}: 最良となる確率 {s} | P(50倍>=100倍)={p50:.3f}")
    return per


def leak_check_git(D_all):
    """同一日付lr_v3の新旧コミットでscore_total_current(本番v2)のtop1的中率を比較"""
    print("\n[0] 本番v2予測の事後書き換え検証(data/lr_v3_*.csv の新旧コミット比較)")
    print(f"{'日付':<11}{'初回版top1':>11}{'最終版top1':>11}{'top1変化R':>10}{'R':>5}")
    tot = [0, 0, 0, 0]
    for d in SHADOW_DATES:
        path = f"data/lr_v3_{d}.csv"
        commits = subprocess.run(["git", "log", "--format=%h", "--", path], cwd=ROOT,
                                 capture_output=True, text=True).stdout.split()
        if len(commits) < 2:
            print(f"{d:<11}{'(1版のみ)':>11}")
            continue
        vers = []
        for c in (commits[-1], commits[0]):
            txt = subprocess.run(["git", "show", f"{c}:{path}"], cwd=ROOT, capture_output=True,
                                 encoding="utf-8").stdout
            top = defaultdict(list)
            for r in _read(txt, is_text=True):
                if r["score_total_current"] not in ("", None):
                    top[int(r["race_id"])].append((float(r["score_total_current"]), int(r["lane"])))
            vers.append({rid: max(v)[1] for rid, v in top.items() if len(v) == 6})
        common = [rid for rid in vers[0] if rid in vers[1] and rid in D_all["finish"] and 1 in D_all["finish"][rid]]
        h0 = sum(vers[0][r] == D_all["finish"][r][1] for r in common)
        h1 = sum(vers[1][r] == D_all["finish"][r][1] for r in common)
        ch = sum(vers[0][r] != vers[1][r] for r in common)
        tot = [tot[0] + h0, tot[1] + h1, tot[2] + ch, tot[3] + len(common)]
        if common:
            print(f"{d:<11}{h0/len(common)*100:>10.1f}%{h1/len(common)*100:>10.1f}%{ch:>10}{len(common):>5}")
    if tot[3]:
        print(f"{'計':<11}{tot[0]/tot[3]*100:>10.1f}%{tot[1]/tot[3]*100:>10.1f}%{tot[2]:>10}{tot[3]:>5}")


def daily_top1(D, sc):
    print("\n[1] 日別top1的中率: 本番実測(shadow_eval) vs ローカル再現")
    print(f"{'日付':<11}{'本番v2':>8}{'本番v3w':>8}{'ローカルv2':>10}{'疑似リークv2':>12}{'ローカルv3w':>11}{'R':>5}")
    by = defaultdict(list)
    for rid in D["races"]:
        by[D["date"][rid]].append(rid)
    agg = defaultdict(int)
    for d in SHADOW_DATES:
        rs = by.get(d, [])
        if not rs:
            continue
        h = {m: sum(sc[r][m][1][0] == D["finish"][r][1] for r in rs) for m in ("v2", "v2leak", "v3w")}
        p2, p3, pn = PROD_DAILY[d]
        for k, v in (("p2", p2), ("p3", p3), ("pn", pn), ("n", len(rs))):
            agg[k] += v
        for m in h:
            agg[m] += h[m]
        print(f"{d:<11}{p2/pn*100:>7.1f}%{p3/pn*100:>7.1f}%{h['v2']/len(rs)*100:>9.1f}%"
              f"{h['v2leak']/len(rs)*100:>11.1f}%{h['v3w']/len(rs)*100:>10.1f}%{len(rs):>5}")
    n = agg["n"]
    print(f"{'計':<11}{agg['p2']/agg['pn']*100:>7.1f}%{agg['p3']/agg['pn']*100:>7.1f}%{agg['v2']/n*100:>9.1f}%"
          f"{agg['v2leak']/n*100:>11.1f}%{agg['v3w']/n*100:>10.1f}%{n:>5}")
    # top1 差の bootstrap (ローカル同士・対応あり)
    rs = D["races"]
    W = boot_weights(len(rs), seed=3)
    h2 = np.array([sc[r]["v2"][1][0] == D["finish"][r][1] for r in rs], dtype=np.float64)
    h3 = np.array([sc[r]["v3w"][1][0] == D["finish"][r][1] for r in rs], dtype=np.float64)
    one = np.ones(len(rs))
    diff = (ratio(W, h3, one) - ratio(W, h2, one)) * 100
    lo, hi = np.percentile(diff, [2.5, 97.5])
    print(f"  ローカルv3w−v2 top1差: {(h3.mean()-h2.mean())*100:+.1f}pt  95%CI[{lo:+.1f},{hi:+.1f}]"
          f"  P(v3w>v2)={float(np.mean(diff > 0)):.3f}")
    lane1 = {m: sum(sc[r][m][1][0] == 1 for r in rs) / len(rs) * 100 for m in ("v2", "v3w")}
    act = sum(D["finish"][r][1] == 1 for r in rs) / len(rs) * 100
    print(f"  1号艇rank1率: v2 {lane1['v2']:.1f}% / v3w {lane1['v3w']:.1f}% / 実1号艇1着率 {act:.1f}%")


def main():
    D = load(shadow_files())
    print(f"対象: {SHADOW_DATES[0]}〜{SHADOW_DATES[-1]}  {len(D['races'])}R(6艇特徴量+着順+払戻+オッズ完備)")
    sc = score_all(D)
    leak_check_git(D)
    daily_top1(D, sc)

    mid = [r for r in D["races"] if D["date"][r] in MID_DATES]
    late = [r for r in D["races"] if D["date"][r] not in MID_DATES]
    compare(D, sc, D["races"], "全シャドウ期間 09-13〜09-29")
    compare(D, sc, mid, "中間分析期間 09-13〜09-17(再計算)")
    compare(D, sc, late, "後半 09-18〜09-29")

    balance_sweep(D, sc, D["races"], "全シャドウ期間")
    h1 = [r for r in D["races"] if D["date"][r] <= "2026-09-21"]
    h2 = [r for r in D["races"] if D["date"][r] >= "2026-09-22"]
    balance_sweep(D, sc, h1, "前半 09-13〜09-21", boot=False)
    balance_sweep(D, sc, h2, "後半 09-22〜09-29", boot=False)

    O = load(offline_files())
    osc = {rid: {"v2": v2_score(O["entries"][rid]), "v3w": v3w_score(O["entries"][rid])} for rid in O["races"]}
    balance_sweep(O, osc, O["races"], f"オフライン期間 {min(O['date'].values())}〜{max(O['date'].values())}(v3w学習期間内・参考)")


if __name__ == "__main__":
    main()
