# -*- coding: utf-8 -*-
"""
sim_ev_model_20261112.py — 予測モデルと買い目の改善余地の分析(design_ev_model_review_20261112.md)
読み取り専用 / DB接続なし / 本番コード・定数は変更しない。

  calib : [1] v3w の1着確率の当てはまり(帯別・枠別)、市場確率(1/オッズ正規化)との比較、補正の効果
  ev    : [2] 3連単「確率×オッズ」閾値別の的中率・回収率(確定オッズ=参考値 / 締切前スナップショット=本検証)
  model : [3] 特徴量追加・条件付きロジットの学習期間外比較
使い方: python sim_ev_model_20261112.py [calib|ev|model|all]

データ: lr_data_v3_full.csv(06-29〜09-02)+ data/lr_v3_*.csv(09-09〜11-07)、odds_*/payouts_*(ルート直下と data/)
期間  : 本番 v3w の学習期間は 06-29〜09-02。学習期間外 = 09-09〜11-07。
"""
import glob
import itertools
import os
import sys
import warnings

import numpy as np
import pandas as pd
from scipy.optimize import minimize
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.preprocessing import StandardScaler

from sim_v3w_midterm import v3w_score, COURSE_PRIOR1, COURSE_PRIOR2, SMOOTH_K

warnings.filterwarnings("ignore")
ROOT = os.path.dirname(os.path.abspath(__file__))
TRAIN_END = "2026-09-02"          # 本番 v3w の学習期間の最終日
OOS_FROM = "2026-09-09"
SEL = ("2026-09-09", "2026-10-07")   # 閾値・補正を選ぶ期間
VAL = ("2026-10-08", "2026-11-07")   # 別期間での確認
NB = 2000
COMBOS = list(itertools.permutations(range(1, 7), 3))
CSTR = ["-".join(map(str, c)) for c in COMBOS]
CIDX = {c: i for i, c in enumerate(CSTR)}
CA = np.array(COMBOS) - 1          # (120,3) 0始まりの枠


# ── 読み込み ───────────────────────────────────────────────────────────
def load():
    full = pd.read_csv(os.path.join(ROOT, "lr_data_v3_full.csv"), low_memory=False)
    daily = pd.concat([pd.read_csv(f) for f in sorted(glob.glob(os.path.join(ROOT, "data", "lr_v3_*.csv")))])
    df = pd.concat([full, daily], ignore_index=True).sort_values(["race_id", "lane"])
    n = df.groupby("race_id")["lane"].transform("size")
    df = df[n == 6].reset_index(drop=True)
    assert (df["lane"].values.reshape(-1, 6) == np.arange(1, 7)).all()

    of = sorted(glob.glob(os.path.join(ROOT, "odds_2026-*.csv"))) + sorted(glob.glob(os.path.join(ROOT, "data", "odds_*.csv")))
    od = pd.concat([pd.read_csv(f) for f in of])
    od = od[od["odds"] > 0]
    od["ci"] = od["combo"].map(CIDX)
    od = od.dropna(subset=["ci"])
    rids = np.sort(od["race_id"].unique())
    rpos = pd.Series(np.arange(len(rids)), index=rids)
    O = np.full((len(rids), 120), np.nan)
    O[rpos[od["race_id"]].values, od["ci"].astype(int).values] = od["odds"].values
    pf = sorted(glob.glob(os.path.join(ROOT, "payouts_2026-*.csv"))) + sorted(glob.glob(os.path.join(ROOT, "data", "payouts_*.csv")))
    pay = pd.concat([pd.read_csv(f) for f in pf]).drop_duplicates("race_id").set_index("race_id")
    return df, rids, O, pay


def race_frame(df, rids, O, pay):
    """レース単位の表(R行)。odds: (R,120) / win_ci: 的中組の番号(-1=払戻なし)/ amount: 払戻(100円あたり)"""
    R = df.iloc[::6][["race_id", "date", "venue"]].reset_index(drop=True)
    R["winner"] = df["is_winner"].values.reshape(-1, 6).argmax(1)
    pos = pd.Series(np.arange(len(rids)), index=rids).reindex(R["race_id"]).values
    odds = np.full((len(R), 120), np.nan)
    ok = ~np.isnan(pos)
    odds[ok] = O[pos[ok].astype(int)]
    p = pay.reindex(R["race_id"])
    R["win_ci"] = p["combo"].map(CIDX).fillna(-1).astype(int).values
    R["amount"] = p["amount"].fillna(0).values
    # 的中組と1着艇が食い違う行(同着・返還など)は3連単の検証から外す
    bad = (R["win_ci"] >= 0) & (CA[R["win_ci"].clip(0), 0] != R["winner"])
    R.loc[bad, "win_ci"] = -1
    R["n_odds"] = (~np.isnan(odds)).sum(1)
    wo = np.where(R["win_ci"] >= 0, odds[np.arange(len(R)), R["win_ci"].clip(0)], np.nan)
    R["win_odds"] = wo
    # 保存オッズが確定値か: 的中組のオッズ×100 が払戻と一致
    R["is_final"] = np.abs(wo * 100 - R["amount"]) < 1
    return R, odds


def v3w_probs(df):
    out = np.zeros((len(df) // 6, 6))
    recs = df.to_dict("records")
    for i in range(0, len(recs), 6):
        rows = [{k: ("" if (isinstance(v, float) and np.isnan(v)) else v) for k, v in r.items()} for r in recs[i:i + 6]]
        p, _ = v3w_score(rows)
        out[i // 6] = [p[l] for l in range(1, 7)]
    return out


# ── 共通の指標 ─────────────────────────────────────────────────────────
def harville(P, d2=1.0, d3=1.0):
    """(R,6) の1着確率 → (R,120) の3連単確率。d2, d3 は2着・3着の確率を平たくする指数(1 で通常の Harville)"""
    P2, P3 = P ** d2, P ** d3
    a, b, c = CA[:, 0], CA[:, 1], CA[:, 2]
    s2 = P2.sum(1, keepdims=True) - P2[:, a]
    s3 = P3.sum(1, keepdims=True) - P3[:, a] - P3[:, b]
    return P[:, a] * P2[:, b] / np.clip(s2, 1e-12, None) * P3[:, c] / np.clip(s3, 1e-12, None)


def fit_discount(P, win_ci):
    """的中組の対数尤度を最大にする d2, d3"""
    ok = win_ci >= 0
    P, w = P[ok], win_ci[ok]
    rr = np.arange(len(w))

    def f(t):
        return -np.log(np.clip(harville(P, t[0], t[1])[rr, w], 1e-12, None)).mean()
    return tuple(minimize(f, [1.0, 1.0], method="Nelder-Mead").x)


def market_probs(odds):
    """3連単オッズ → 1着の市場確率 (R,6) と 3連単の市場確率 (R,120)。1/オッズを正規化"""
    inv = np.nan_to_num(1.0 / odds)
    q3 = inv / inv.sum(1, keepdims=True)
    q1 = np.zeros((len(odds), 6))
    for k in range(6):
        q1[:, k] = q3[:, CA[:, 0] == k].sum(1)
    return q1, q3


def logloss(P, y):
    return -np.log(np.clip(P[np.arange(len(y)), y], 1e-12, None))


def boot_w(n, seed=7):
    return np.random.default_rng(seed).multinomial(n, np.full(n, 1.0 / n), size=NB).astype(np.float32)


def ci(x):
    return np.percentile(x, 2.5), np.percentile(x, 97.5)


def in_p(R, p):
    return ((R["date"] >= p[0]) & (R["date"] <= p[1])).values


def power_cal(P, g, b=None):
    L = g * np.log(np.clip(P, 1e-12, None)) + (0 if b is None else b)
    L -= L.max(1, keepdims=True)
    e = np.exp(L)
    return e / e.sum(1, keepdims=True)


def fit_cal(P, y, lane_offset=False):
    """p' ∝ p^γ (× 枠別の係数)。対数損失を最小化"""
    def f(t):
        b = np.concatenate([[0.0], t[1:]]) if lane_offset else None
        return logloss(power_cal(P, t[0], b), y).mean()
    x0 = np.concatenate([[1.0], np.zeros(5)]) if lane_offset else np.array([1.0])
    r = minimize(f, x0, method="Nelder-Mead" if not lane_offset else "L-BFGS-B")
    return r.x[0], (np.concatenate([[0.0], r.x[1:]]) if lane_offset else None)


# ══ [1] 確率の当てはまり ════════════════════════════════════════════════
def sec_calib(R, odds, P, Pnew=None):
    print("\n" + "=" * 100 + "\n[1] 確率の当てはまり(学習期間外 09-09〜11-07)\n" + "=" * 100)
    m = (R["date"] >= OOS_FROM).values & (R["n_odds"] == 120).values
    y = R["winner"].values[m]
    Pv = P[m]
    q1, q3 = market_probs(odds[m])
    n = m.sum()
    print(f"対象 {n}R(6艇そろい・3連単オッズ120通りそろい)。保存オッズが確定値と一致するレースは {R['is_final'][m].mean()*100:.1f}%")
    onehot = np.eye(6)[y]
    edges = [0, .05, .10, .15, .20, .30, .40, .50, .60, .70, 1.01]
    for name, X in (("v3w", Pv), ("市場(1/オッズ正規化)", q1)):
        print(f"\n  {name}: 1着確率の帯別(艇単位 {n*6} 件)")
        print(f"  {'帯':<12}{'件数':>7}{'予測平均':>9}{'実際':>8}{'差(実−予)':>11}{'実際の95%CI':>18}")
        x, h = X.ravel(), onehot.ravel()
        ece = 0
        for lo, hi in zip(edges[:-1], edges[1:]):
            s = (x >= lo) & (x < hi)
            if s.sum() == 0:
                continue
            pm, am = x[s].mean(), h[s].mean()
            se = np.sqrt(am * (1 - am) / s.sum())
            ece += s.sum() / len(x) * abs(pm - am)
            print(f"  {lo*100:>3.0f}-{min(hi,1)*100:<4.0f}%  {s.sum():>7}{pm*100:>8.1f}%{am*100:>7.1f}%{(am-pm)*100:>+10.1f}pt"
                  f"   [{(am-1.96*se)*100:.1f}, {(am+1.96*se)*100:.1f}]")
        print(f"  平均ずれ(ECE) {ece*100:.2f}pt")
    print("\n  枠別: 予測平均と実際の1着率")
    print(f"  {'枠':<4}{'実際':>8}{'v3w 予測':>10}{'差':>8}{'市場 予測':>11}{'差':>8}{'v3w が1位にした率':>18}")
    top = Pv.argmax(1)
    for k in range(6):
        a = onehot[:, k].mean()
        print(f"  {k+1:<4}{a*100:>7.1f}%{Pv[:,k].mean()*100:>9.1f}%{(Pv[:,k].mean()-a)*100:>+7.1f}pt"
              f"{q1[:,k].mean()*100:>10.1f}%{(q1[:,k].mean()-a)*100:>+7.1f}pt{(top==k).mean()*100:>16.1f}%")
    # 1位にした艇の予測確率と実際
    pt = Pv.max(1)
    print(f"\n  v3w が1位にした艇: 予測確率の平均 {pt.mean()*100:.1f}% / 実際の1着率 {(top==y).mean()*100:.1f}%")
    s1 = top == 0
    print(f"    うち1号艇を1位にしたレース {s1.sum()}R: 予測 {pt[s1].mean()*100:.1f}% / 実際 {(y[s1]==0).mean()*100:.1f}%")
    print(f"    1号艇以外を1位にしたレース {(~s1).sum()}R: 予測 {pt[~s1].mean()*100:.1f}% / 実際 {(top[~s1]==y[~s1]).mean()*100:.1f}%")

    # 指標と補正
    sel, val = in_p(R, SEL)[m], in_p(R, VAL)[m]
    lane_rate = np.eye(6)[R["winner"].values[(R["date"] <= TRAIN_END).values]].mean(0)
    g1, _ = fit_cal(Pv[sel], y[sel])
    g2, b2 = fit_cal(Pv[sel], y[sel], lane_offset=True)
    print(f"\n  補正(選定期間 {SEL[0]}〜{SEL[1]} で推定): γ のみ = {g1:.3f} / γ+枠別 = {g2:.3f}, 枠別係数(対数) {np.round(b2, 3).tolist()}")
    W = boot_w(val.sum())
    base = logloss(Pv[val], y[val])
    print(f"  確認期間 {VAL[0]}〜{VAL[1]}({val.sum()}R)")
    print(f"  {'':<26}{'対数損失':>9}{'1着的中':>9}{'v3w との対数損失差 [95%CI]':>34}")
    cands = [("v3w(現行)", Pv[val]), ("v3w + γ補正", power_cal(Pv[val], g1)),
             ("v3w + γ+枠別補正", power_cal(Pv[val], g2, b2)),
             ("枠の1着率だけ(学習期間)", np.tile(lane_rate, (val.sum(), 1))),
             ("市場(確定オッズ中心)", q1[val])]
    for name, X in cands:
        ll = logloss(X, y[val])
        d = W @ (ll - base) / val.sum()
        lo, hi = ci(d)
        print(f"  {name:<24}{ll.mean():>9.4f}{(X.argmax(1)==y[val]).mean()*100:>8.1f}%   {(ll-base).mean():>+8.4f} [{lo:+.4f}, {hi:+.4f}]")

    # 3連単の当てはまり(補正・割引の係数は選定期間で推定し、確認期間で見る)
    rr = np.arange(n)
    wc = R["win_ci"].values[m]
    ok = wc >= 0
    hitm = np.zeros((n, 120), bool)
    hitm[rr[ok], wc[ok]] = True
    amt = R["amount"].values[m]
    bands = [0, .002, .005, .01, .02, .05, .10, .20, 1.01]
    Pc = power_cal(Pv, g2, b2)
    dv = fit_discount(Pc[sel], wc[sel])
    print(f"\n  2着・3着の割引指数(v3w 補正後、選定期間で推定): d2={dv[0]:.3f}, d3={dv[1]:.3f}")
    srcs = [("v3w そのまま × Harville", harville(Pv)), ("v3w(γ+枠別補正)× Harville", harville(Pc)),
            ("v3w(γ+枠別補正)× 割引つき Harville", harville(Pc, *dv))]
    dn = None
    if Pnew is not None:
        Pn = Pnew[m]
        dn = fit_discount(Pn[sel], wc[sel])
        print(f"  同(条件付きロジット C1、学習〜09-02): d2={dn[0]:.3f}, d3={dn[1]:.3f}")
        srcs += [("条件付きロジット C1 × Harville", harville(Pn)),
                 ("条件付きロジット C1 × 割引つき Harville", harville(Pn, *dn))]
    srcs.append(("市場(1/オッズ正規化)", q3))
    v = ok & val
    print(f"  3連単の確率の当てはまり(組単位、確認期間 {VAL[0]}〜{VAL[1]} の {v.sum()}R)")
    for name, X in srcs:
        x, h = X[v].ravel(), hitm[v].ravel()
        ll = -np.log(np.clip(X[v][np.arange(v.sum()), wc[v]], 1e-12, None)).mean()
        print(f"  ■ {name}   的中組の対数損失 {ll:.4f}")
        print(f"  {'予測の帯':<14}{'組数':>9}{'予測平均':>10}{'実際':>9}{'実際/予測':>10}")
        for lo, hi in zip(bands[:-1], bands[1:]):
            s = (x >= lo) & (x < hi)
            if s.sum():
                print(f"  {lo*100:>5.1f}-{min(hi,1)*100:<5.1f}%  {s.sum():>9}{x[s].mean()*100:>9.2f}%{h[s].mean()*100:>8.2f}%{h[s].mean()/x[s].mean():>10.2f}")
    print("\n  オッズ帯別に全組を買った場合の回収率(モデル不使用。保存オッズ=ほぼ確定オッズ、払戻は実際の確定払戻)")
    print(f"  {'オッズ帯':<14}{'組数':>9}{'的中':>7}{'回収率':>9}{'95%CI':>18}")
    ob = [1, 5, 10, 20, 50, 100, 200, 500, 1e9]
    o = odds[m][ok]
    W2 = boot_w(ok.sum(), seed=9)
    for lo, hi in zip(ob[:-1], ob[1:]):
        s = (o >= lo) & (o < hi)
        cost = s.sum(1) * 100.0
        ret = (s & hitm[ok]).sum(1) * amt[ok]
        b = (W2 @ ret) / (W2 @ cost)
        print(f"  {lo:>5.0f}-{hi if hi<1e8 else float('inf'):<6.0f}倍 {int(s.sum()):>9}{int((s&hitm[ok]).sum()):>7}{ret.sum()/cost.sum()*100:>8.1f}%"
              f"   [{ci(b)[0]*100:.1f}, {ci(b)[1]*100:.1f}]")
    return (g2, b2, dv, dn)


# ══ [2] 期待値で絞る買い方 ══════════════════════════════════════════════
TAUS = [0.6, 0.8, 0.9, 1.0, 1.1, 1.2, 1.5, 2.0, 3.0]


def ev_table(H, odds, win_ci, amount, mask, taus=TAUS, max_odds=None, min_p=0.0, seed=11, label=""):
    """100円均等。各レースで H×odds >= τ の組を全部買う。払戻は確定払戻(amount)"""
    H, o, wc, amt = H[mask], odds[mask], win_ci[mask], amount[mask]
    n = len(H)
    ev = H * o
    elig = ~np.isnan(o) & (H >= min_p)
    if max_odds:
        elig &= o <= max_odds
    W = boot_w(n, seed)
    rr = np.arange(n)
    rows = []
    for t in taus:
        s = elig & (ev >= t)
        cost = s.sum(1) * 100.0
        hit = s[rr, wc]
        ret = hit * amt
        nb = (cost > 0).sum()
        if cost.sum() == 0:
            rows.append((t, 0, 0, 0, 0, np.nan, np.nan, np.nan)); continue
        b = (W @ ret) / np.maximum(W @ cost, 1)
        rows.append((t, nb, int(s.sum()), s.sum() / max(nb, 1), hit.sum() / max(nb, 1) * 100,
                     ret.sum() / cost.sum() * 100, ci(b)[0] * 100, ci(b)[1] * 100))
    print(f"  {label}({n}R)")
    print(f"  {'閾値':>5}{'買うR':>7}{'点数':>8}{'点/R':>7}{'的中率':>8}{'回収率':>8}{'95%CI':>18}")
    for t, nb, k, kr, hr, roi, lo, hi in rows:
        print(f"  {t:>5.1f}{nb:>7}{k:>8}{kr:>7.1f}{hr:>7.1f}%{roi:>7.1f}%   [{lo:.1f}, {hi:.1f}]")
    return rows


def sec_ev(R, odds, P, cal, Pnew=None, Pwf=None):
    print("\n" + "=" * 100 + "\n[2] 期待値(確率×オッズ)で絞る買い方\n" + "=" * 100)
    g, b, dv, dn = cal
    wc, amt = R["win_ci"].values, R["amount"].values
    base = (R["win_ci"] >= 0).values & (R["n_odds"] >= 100).values
    oos = (R["date"] >= OOS_FROM).values
    print("保存オッズの性質(的中組の保存オッズ×100 と確定払戻の一致)")
    R2 = R[base].copy()
    R2["mon"] = R2["date"].str[:7]
    for k, gdf in R2.groupby("mon"):
        nf = gdf[~gdf["is_final"]]
        r = (nf["win_odds"] * 100 / nf["amount"])
        print(f"  {k}: {len(gdf):>5}R  確定値と一致 {gdf['is_final'].mean()*100:5.1f}%  不一致 {len(nf):>4}R"
              f"(保存オッズ/確定の中央値 {r.median():.2f}、|対数比|の中央値 {np.abs(np.log(r)).median():.2f})")

    Pc = power_cal(P, g, b)
    srcs = [("v3w そのまま × Harville", harville(P)),
            ("v3w 補正後(γ+枠別)× 割引つき Harville", harville(Pc, *dv))]
    if Pnew is not None:
        srcs.append(("条件付きロジット C1(学習〜09-02)× 割引つき Harville", harville(np.nan_to_num(Pnew, nan=1 / 6), *dn)))

    print("\n(A) 参考値: 保存オッズ(92〜98%が確定オッズ)で判定。結果に近い情報で選んでいるため水増しされる")
    print("    補正・割引の係数は選定期間で推定。閾値は選定期間で選び、確認期間で確かめる")
    fin = base & oos
    for name, H in srcs:
        for pname, p in (("選定期間", SEL), ("確認期間", VAL)):
            ev_table(H, odds, wc, amt, fin & in_p(R, p), label=f"[A] {name} / {pname} {p[0]}〜{p[1]}")
    print("\n  [A] オッズ100倍以下の組に限定")
    for name, H in srcs[1:]:
        for pname, p in (("選定期間", SEL), ("確認期間", VAL)):
            ev_table(H, odds, wc, amt, fin & in_p(R, p), max_odds=100, label=f"[A] {name}・100倍以下 / {pname}")

    print("\n(B) 本検証: 保存オッズが確定値と一致しないレース(=締切前に取得したスナップショットが残ったレース)だけ。"
          "\n    判定は保存オッズ、払戻は確定払戻。取得時刻は不明(朝・14時・締切60分前〜のいずれか)")
    nf = base & ~R["is_final"].values
    print(f"  学習期間外(09-09〜)の不一致レース: {int((nf & oos).sum())}R(うち確認期間 {int((nf & in_p(R, VAL)).sum())}R)")
    for name, H in srcs:
        ev_table(H, odds, wc, amt, nf & oos, label=f"[B] {name} / 学習期間外 全体")
    for name, H in srcs[1:]:
        ev_table(H, odds, wc, amt, nf & in_p(R, VAL), label=f"[B] {name} / 確認期間のみ")
    if Pwf is not None:
        m = nf & ~np.isnan(Pwf[:, 0])
        a_all = ~np.isnan(Pwf[:, 0]) & (R["date"] <= "2026-08-23").values
        dw = fit_discount(Pwf[a_all], wc[a_all])
        print(f"\n  [B'] 07-27〜11-07 の不一致レース {int(m.sum())}R。確率は各時点より前のデータだけで学習した条件付きロジット C1。"
              f"\n       割引指数は 07-27〜08-23 の全レースで推定(d2={dw[0]:.3f}, d3={dw[1]:.3f})")
        Hw = harville(np.nan_to_num(Pwf, nan=1 / 6), *dw)
        a = m & (R["date"] <= "2026-08-23").values
        bb = m & (R["date"] > "2026-08-23").values
        ev_table(Hw, odds, wc, amt, a, label="[B'] 選定 07-27〜08-23")
        ev_table(Hw, odds, wc, amt, bb, label="[B'] 確認 08-24〜11-07")
        ev_table(Hw, odds, wc, amt, m, label="[B'] 全体")
        ev_table(Hw, odds, wc, amt, m, max_odds=100, label="[B'] 全体・100倍以下")
        # 同じ確率で、確定オッズのレースを選んだ場合(水増しの大きさの目安)
        f2 = base & R["is_final"].values & ~np.isnan(Pwf[:, 0])
        ev_table(Hw, odds, wc, amt, f2, label="[A'] 同じ確率・同じ期間で、保存オッズが確定値のレース")
    print()
    for lab, mk in (("[A] 学習期間外", fin), ("[B] 不一致・学習期間外", nf & oos), ("[B'] 不一致・07-27〜", nf & (R["date"] >= "2026-07-27").values)):
        cost = (~np.isnan(odds[mk])).sum(1) * 100.0
        print(f"  基準 {lab}: 全組購入の回収率 {amt[mk].sum()/cost.sum()*100:.1f}%({int(mk.sum())}R)")


def strat_compare(R, odds, mask, srcs, title):
    """現行4戦略の買い目ロジックに確率だけ差し替える。srcs: [(名前, (R,6)確率)]、先頭が基準"""
    from sim_v3w_final import build, settle, STRATS, NAMES
    idx = np.where(mask & (R["win_ci"] >= 0).values & (R["n_odds"] >= 100).values)[0]
    arr = {k: {s: [] for s in STRATS} for k, _ in srcs}
    for i in idx:
        od = {CSTR[j]: odds[i, j] for j in range(120) if not np.isnan(odds[i, j])}
        wcs, amt = CSTR[R["win_ci"].values[i]], int(R["amount"].values[i])
        for k, PP in srcs:
            probs = {l + 1: PP[i, l] for l in range(6)}
            lanes = [l for l, _ in sorted(probs.items(), key=lambda kv: -kv[1])]
            bs = build(probs, lanes, od)
            for s in STRATS:
                arr[k][s].append(settle(bs[s], probs, wcs, amt))
    W = boot_w(len(idx), seed=3)
    k0 = srcs[0][0]
    print(f"\n{title}({len(idx)}R、同一レース、確率傾斜配分)")
    print(f"  {'戦略':<8}{'確率':<28}{'的中率':>8}{'回収率':>8}{'95%CI':>16}   {'基準との回収率差 [95%CI]':<28}{'P(≥基準)'}")
    for s in STRATS:
        a0 = np.array(arr[k0][s], float)
        r0 = (W @ a0[:, 3]) / np.maximum(W @ a0[:, 2], 1)
        for k, _ in srcs:
            a = np.array(arr[k][s], float)
            t = a.sum(0)
            r = (W @ a[:, 3]) / np.maximum(W @ a[:, 2], 1)
            line = f"  {NAMES[s]:<8}{k:<28}{t[4]/t[5]*100:>7.1f}%{t[3]/t[2]*100:>7.1f}%   [{ci(r)[0]*100:.1f}, {ci(r)[1]*100:.1f}]"
            if k != k0:
                dd = (r - r0) * 100
                line += f"   {t[3]/t[2]*100 - a0[:,3].sum()/a0[:,2].sum()*100:>+5.1f}pt [{ci(dd)[0]:+.1f}, {ci(dd)[1]:+.1f}]      {np.mean(dd>=0):.2f}"
                dh = (W @ (a[:, 4] - a0[:, 4])) / len(idx) * 100
                line += f"   hit {(a[:,4]-a0[:,4]).mean()*100:+.1f}pt [{ci(dh)[0]:+.1f}, {ci(dh)[1]:+.1f}]"
            print(line)


# ══ [3] モデル比較 ══════════════════════════════════════════════════════
BASE = ["lane_2", "lane_3", "lane_4", "lane_5", "lane_6", "global_win_rate", "local_win_rate", "exhibit_time_rel",
        "start_timing", "motor_2rate_rel", "avg_st", "avg_st_rank", "course_win_rate_sm", "course_in2_sm",
        "recent10_avg_rank", "recent10_win_rate", "recent10_st_mean", "grade_A1", "grade_A2", "grade_B1"]
RACE_CONST = ["wind_speed", "wave_height"]
EXTRA = ["ex_diff", "ex_rank", "st_diff", "st_flying", "mo_diff", "global_2rate", "local_win_sm",
         "l1_gwr", "l1_A1", "l1_B", "l1_exrel", "l1_avgst_rank"] + \
        [f"wind_x_l{k}" for k in range(2, 7)] + [f"wave_x_l{k}" for k in range(2, 7)]
VENUE = ["venue_lane_logit"]


def add_features(df):
    d = df.copy()
    for l in range(2, 7):
        d[f"lane_{l}"] = (d["lane"] == l).astype(float)
    for g in ("A1", "A2", "B1"):
        d[f"grade_{g}"] = (d["grade_period"] == g).astype(float)
    d.loc[d["avg_st"] <= 0.001, "avg_st"] = np.nan
    d["avg_st_rank"] = d.groupby("race_id")["avg_st"].rank(method="min").fillna(3.5)
    p1 = d["lane"].map(dict(enumerate(COURSE_PRIOR1, 1)))
    p2 = d["lane"].map(dict(enumerate(COURSE_PRIOR2, 1)))
    d["course_win_rate_sm"] = (d["course_rank1"] + SMOOTH_K * p1) / (d["course_count"] + SMOOTH_K)
    d["course_in2_sm"] = (d["course_rank2"] + SMOOTH_K * p2) / (d["course_count"] + SMOOTH_K)
    g = d.groupby("race_id")
    d["ex_diff"] = d["exhibit_time_raw"] - g["exhibit_time_raw"].transform("mean")
    d["ex_rank"] = g["exhibit_time_raw"].rank(method="min").fillna(3.5)
    d["st_diff"] = d["start_timing"] - g["start_timing"].transform("mean")
    d["st_flying"] = (d["start_timing"] < 0).astype(float)
    d["mo_diff"] = d["motor_2rate"] - g["motor_2rate"].transform("mean")
    cnt = d["local_race_cnt"].fillna(0)
    d["local_win_sm"] = (d["local_win_rate"].fillna(0) * cnt + 20 * d["global_win_rate"].fillna(5.3) / 32.0) / (cnt + 20)
    l1 = (d["lane"] == 1).astype(float)
    d["l1_gwr"] = l1 * (d["global_win_rate"].fillna(5.3) - 5.3)
    d["l1_A1"] = l1 * d["grade_A1"]
    d["l1_B"] = l1 * d["grade_period"].isin(["B1", "B2"]).astype(float)
    d["l1_exrel"] = l1 * (d["exhibit_time_rel"].fillna(0.5) - 0.5)
    d["l1_avgst_rank"] = l1 * (d["avg_st_rank"] - 3.5)
    for k in range(2, 7):
        d[f"wind_x_l{k}"] = d[f"lane_{k}"] * d["wind_speed"].fillna(3)
        d[f"wave_x_l{k}"] = d[f"lane_{k}"] * d["wave_height"].fillna(2)
    return d


def venue_table(dtr, K=60):
    """学習期間の 場×枠 の1着率(全場の枠別1着率へ K 件ぶん寄せる)→ 全場平均との対数オッズ差"""
    glob_rate = dtr.groupby("lane")["is_winner"].mean()
    t = dtr.groupby(["venue", "lane"])["is_winner"].agg(["sum", "count"]).reset_index()
    pr = t["lane"].map(glob_rate)
    r = (t["sum"] + K * pr) / (t["count"] + K)
    t["venue_lane_logit"] = np.log(r / (1 - r)) - np.log(pr / (1 - pr))
    return t[["venue", "lane", "venue_lane_logit"]]


class CL:
    """条件付きロジット(レース内6艇のソフトマックス)。L2 正則化。PHP へは MEANS/SCALES/COEFS で移せる"""
    def __init__(self, feats, lam=1e-3):
        self.feats, self.lam = feats, lam

    def _x(self, d):
        return ((d[self.feats].fillna(self.med).values - self.mu) / self.sd).reshape(-1, 6, len(self.feats))

    def fit(self, d):
        self.med = d[self.feats].median()
        X0 = d[self.feats].fillna(self.med).values
        self.mu, self.sd = X0.mean(0), X0.std(0) + 1e-9
        X = self._x(d)
        y = d["is_winner"].values.reshape(-1, 6).argmax(1)
        Y = np.eye(6)[y]
        n = len(y)

        def f(w):
            s = X @ w
            s -= s.max(1, keepdims=True)
            e = np.exp(s)
            p = e / e.sum(1, keepdims=True)
            loss = -np.log(p[np.arange(n), y]).mean() + 0.5 * self.lam * w @ w
            grad = np.einsum("rlf,rl->f", X, p - Y) / n + self.lam * w
            return loss, grad
        self.w = minimize(f, np.zeros(len(self.feats)), jac=True, method="L-BFGS-B").x
        return self

    def predict(self, d):
        s = self._x(d) @ self.w
        s -= s.max(1, keepdims=True)
        e = np.exp(s)
        return e / e.sum(1, keepdims=True)


class BinLR:
    """艇ごとの2値ロジスティック回帰 → レース内で合計1に正規化(現行 v3w と同じ方式)。weighted=True で v3w の重み付け"""
    def __init__(self, feats, weighted=False):
        self.feats, self.weighted = feats, weighted

    def fit(self, d):
        self.med = d[self.feats].median()
        self.sc = StandardScaler().fit(d[self.feats].fillna(self.med).values)
        X = self.sc.transform(d[self.feats].fillna(self.med).values)
        sw = None
        kw = {}
        if self.weighted:
            pri = d["lane"].map(d.groupby("lane")["is_winner"].mean()).values
            sw = np.ones(len(d))
            win = d["is_winner"].values == 1
            sw[win] = np.minimum(12.0, (1 / np.maximum(pri[win], 1e-3)) ** 0.55)
            kw = dict(class_weight="balanced")
        self.m = LogisticRegression(C=1.0, max_iter=2000, **kw).fit(X, d["is_winner"].values, sample_weight=sw)
        return self

    def predict(self, d):
        p = self.m.predict_proba(self.sc.transform(d[self.feats].fillna(self.med).values))[:, 1].reshape(-1, 6)
        return p / p.sum(1, keepdims=True)


class GBT:
    """参考(PHP には載せにくい): 勾配ブースティング → レース内正規化"""
    def __init__(self, feats):
        self.feats = feats

    def fit(self, d):
        self.m = HistGradientBoostingClassifier(max_iter=300, learning_rate=0.04, max_leaf_nodes=15,
                                                min_samples_leaf=80, l2_regularization=1.0, random_state=0)
        self.m.fit(d[self.feats].values, d["is_winner"].values)
        return self

    def predict(self, d):
        p = self.m.predict_proba(d[self.feats].values)[:, 1].reshape(-1, 6)
        return p / p.sum(1, keepdims=True)


def prep_train(d):
    """学習用: 展示タイム・展示STが欠けた艇のいるレースを除く(現行の学習と同じ基準をレース単位で)"""
    bad = d["exhibit_time_rel"].isna() | d["start_timing"].isna()
    return d[~bad.groupby(d["race_id"]).transform("any")]


def with_venue(dtr, *parts):
    t = venue_table(dtr)
    return [p.drop(columns=["venue_lane_logit"], errors="ignore").merge(t, on=["venue", "lane"], how="left")
            .fillna({"venue_lane_logit": 0.0}) for p in (dtr, *parts)]


def model_zoo(lam):
    S4 = BASE + RACE_CONST
    return [
        ("B1 v3w と同じ方式で再学習(重み付き2値LR)", lambda: BinLR(S4, weighted=True)),
        ("B2 2値LR・重みなし", lambda: BinLR(S4)),
        ("C1 条件付きロジット(v3w の特徴量)", lambda: CL(BASE, lam)),
        ("C2 条件付きロジット + 追加特徴量", lambda: CL(BASE + EXTRA, lam)),
        ("C3 C2 + 場×枠", lambda: CL(BASE + EXTRA + VENUE, lam)),
        ("G  勾配ブースティング(参考)", lambda: GBT(BASE + RACE_CONST + EXTRA[:7] + VENUE + ["lane"])),
    ]


def sec_model(df, R, odds, P):
    print("\n" + "=" * 100 + "\n[3] モデル側の改善候補(学習期間外で比較)\n" + "=" * 100)
    D = add_features(df)
    # 正則化の強さ: 学習期間の内側(〜08-19 で学習、08-20〜09-02 で検証)で選ぶ
    tr0 = prep_train(D[D["date"] <= "2026-08-19"])
    va0 = D[(D["date"] >= "2026-08-20") & (D["date"] <= TRAIN_END)]
    tr0, va0 = with_venue(tr0, va0)
    yv = va0["is_winner"].values.reshape(-1, 6).argmax(1)
    print("正則化 λ の選択(学習期間の内側、条件付きロジット C3、対数損失)")
    best = None
    for lam in (1e-4, 1e-3, 3e-3, 1e-2, 3e-2):
        ll = logloss(CL(BASE + EXTRA + VENUE, lam).fit(tr0).predict(va0), yv).mean()
        print(f"  λ={lam:<7g} {ll:.4f}")
        if best is None or ll < best[1]:
            best = (lam, ll)
    lam = best[0]
    print(f"  → λ={lam}")

    def run(train_end, test_from, test_to, title):
        tr = prep_train(D[D["date"] <= train_end])
        te = D[(D["date"] >= test_from) & (D["date"] <= test_to)]
        tr, te = with_venue(tr, te)
        rm = ((R["date"] >= test_from) & (R["date"] <= test_to)).values
        y = R["winner"].values[rm]
        n = len(y)
        assert n == len(te) // 6
        W = boot_w(n, seed=5)
        Pv = P[rm]
        ll0, hit0 = logloss(Pv, y), (Pv.argmax(1) == y).astype(float)
        l1 = (y == 0).astype(float)
        full_odds = (R["n_odds"].values[rm] == 120)
        q1, _ = market_probs(odds[rm])
        print(f"\n{title}: 学習 〜{train_end}({tr['race_id'].nunique()}R)/ 評価 {test_from}〜{test_to}({n}R)")
        print(f"  {'モデル':<34}{'1着的中':>8}{'対数損失':>9}{'1号艇1位率':>10}   {'的中率差 vs v3w [95%CI]':<28}{'対数損失差 vs v3w [95%CI]'}")
        print(f"  {'1号艇決め打ち':<34}{l1.mean()*100:>7.1f}%{'─':>9}{'100.0%':>10}")
        print(f"  {'v3w(本番の定数)':<32}{hit0.mean()*100:>7.1f}%{ll0.mean():>9.4f}{(Pv.argmax(1)==0).mean()*100:>9.1f}%")
        preds = {}
        for name, mk in model_zoo(lam):
            Pm = mk().fit(tr).predict(te)
            preds[name] = Pm
            ll, hit = logloss(Pm, y), (Pm.argmax(1) == y).astype(float)
            dh = (W @ (hit - hit0)) / n * 100
            dl = (W @ (ll - ll0)) / n
            print(f"  {name:<32}{hit.mean()*100:>7.1f}%{ll.mean():>9.4f}{(Pm.argmax(1)==0).mean()*100:>9.1f}%"
                  f"   {(hit-hit0).mean()*100:>+5.1f}pt [{ci(dh)[0]:+.1f}, {ci(dh)[1]:+.1f}]"
                  f"      {(ll-ll0).mean():>+.4f} [{ci(dl)[0]:+.4f}, {ci(dl)[1]:+.4f}]")
        k = "C1 条件付きロジット(v3w の特徴量)"
        hit = (preds[k].argmax(1) == y).astype(float)
        d1 = (W @ (hit - l1)) / n * 100
        d0 = (W @ (hit0 - l1)) / n * 100
        print(f"  1号艇決め打ちとの差: v3w {(hit0-l1).mean()*100:+.1f}pt [{ci(d0)[0]:+.1f}, {ci(d0)[1]:+.1f}] / "
              f"C1 {(hit-l1).mean()*100:+.1f}pt [{ci(d1)[0]:+.1f}, {ci(d1)[1]:+.1f}] P(>0)={np.mean(d1>0):.3f}")
        fo = full_odds
        print(f"  市場(確定オッズ中心、{fo.sum()}R): 1着的中 {(q1[fo].argmax(1)==y[fo]).mean()*100:.1f}% / 対数損失 {logloss(q1[fo], y[fo]).mean():.4f}"
              f"(同じレースで v3w {ll0[fo].mean():.4f} / C1 {logloss(preds[k][fo], y[fo]).mean():.4f})")
        return preds, rm

    preds, rm = run(TRAIN_END, OOS_FROM, "2026-11-07", "(a) 本番 v3w と同じ学習期間")
    run(TRAIN_END, VAL[0], VAL[1], "(b-1) 学習 〜09-02 のまま、評価を 10-08〜11-07 に限定")
    run("2026-10-07", VAL[0], VAL[1], "(b-2) 学習を 〜10-07 まで延ばした場合(再学習の効果)")

    # 係数(C3、学習〜09-02)
    tr = prep_train(D[D["date"] <= TRAIN_END])
    tr, = with_venue(tr)
    m = CL(BASE + EXTRA + VENUE, lam).fit(tr)
    print("\nC3 の係数(標準化後、絶対値の大きい順 上位15)")
    for i in np.argsort(-np.abs(m.w))[:15]:
        print(f"  {m.feats[i]:<22}{m.w[i]:+.4f}")

    # [1][2] 用: 学習期間外の確率(学習〜09-02)と、07-27 以降の walk-forward 確率(条件付きロジット C1)
    K1, K2 = "C1 条件付きロジット(v3w の特徴量)", "C2 条件付きロジット + 追加特徴量"
    out = {}
    for key, k in (("C1", K1), ("C2", K2)):
        A = np.full((len(R), 6), np.nan)
        A[rm] = preds[k]
        out[key] = A
    Pwf = out["C1"].copy()
    for a, b in (("2026-07-27", "2026-08-09"), ("2026-08-10", "2026-08-23"), ("2026-08-24", TRAIN_END)):
        prev = (pd.Timestamp(a) - pd.Timedelta(days=1)).strftime("%Y-%m-%d")
        tr = prep_train(D[D["date"] <= prev])
        te = D[(D["date"] >= a) & (D["date"] <= b)]
        Pwf[((R["date"] >= a) & (R["date"] <= b)).values] = CL(BASE, lam).fit(tr).predict(te)
    # PHP へ移す場合の定数(C1、学習〜09-02。参考出力。本番には入れていない)
    m1 = CL(BASE, lam).fit(prep_train(D[D["date"] <= TRAIN_END]))
    print("\nC1 の定数(PHP の MEANS / SCALES / COEFS に相当。切片なし、確率は exp(logit) をレース内で正規化)")
    print("  特徴量: " + ", ".join(m1.feats))
    for nm, v in (("MEANS ", m1.mu), ("SCALES", m1.sd), ("COEFS ", m1.w)):
        print(f"  {nm} = [" + ", ".join(f"{x:.6f}" for x in v) + "]")
    return out, Pwf


def main():
    mode = sys.argv[1] if len(sys.argv) > 1 else "all"
    df, rids, O, pay = load()
    R, odds = race_frame(df, rids, O, pay)
    print(f"レース {len(R)}R({R['date'].min()}〜{R['date'].max()})/ 学習期間外 {int((R['date']>=OOS_FROM).sum())}R")
    P = v3w_probs(df)
    oos = (R["date"] >= OOS_FROM).values
    print(f"v3w(本番定数の再現)1着的中率 学習期間外 {(P[oos].argmax(1)==R['winner'].values[oos]).mean()*100:.1f}% / "
          f"1号艇 {(R['winner'].values[oos]==0).mean()*100:.1f}%")
    Pn, Pwf = sec_model(df, R, odds, P) if mode in ("model", "ev", "all") else (None, None)
    P1 = Pn["C1"] if Pn else None
    cal = sec_calib(R, odds, P, P1) if mode in ("calib", "ev", "all") else None
    if mode in ("ev", "all"):
        g, b, dv, dn = cal
        print("\n" + "=" * 100 + "\n[3'] 現行4戦略の買い目ロジックに確率だけ差し替えた場合\n" + "=" * 100)
        print("※バランス・一撃重視のオッズ条件は保存オッズ(ほぼ確定オッズ)で判定しており、従来の再現値と同じ水増し要因を含む")
        strat_compare(R, odds, oos, [("v3w(現行)", P), ("C1 条件付きロジット", P1), ("C2 +追加特徴量", Pn["C2"])],
                      "学習期間外 09-09〜11-07(C1・C2 は学習〜09-02)")
        strat_compare(R, odds, in_p(R, VAL), [("v3w(現行)", P), ("v3w + γ+枠別補正", power_cal(P, g, b)),
                                               ("C1 条件付きロジット", P1)],
                      f"確認期間 {VAL[0]}〜{VAL[1]}(補正の係数は選定期間で推定)")
        sec_ev(R, odds, P, cal, P1, Pwf)


if __name__ == "__main__":
    main()
