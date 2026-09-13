# -*- coding: utf-8 -*-
"""
sim_v3_bootstrap.py (一時検証スクリプト)
一撃重視ROIのモデル間差がノイズか実力かをレース単位ブートストラップで検定。
sim_v3_improved.py と同じ予測を再計算し、v2 vs v3 / v2 vs v3w05 のROI差の95%CIを出す。
"""
import sys, io, glob, warnings
import numpy as np
import pandas as pd
from sklearn.linear_model import LogisticRegression
from sklearn.preprocessing import StandardScaler
from sklearn.pipeline import Pipeline
from itertools import permutations

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
warnings.filterwarnings('ignore')

BALANCE_MAX_ODDS, ICHIGEKI_MIN_ODDS, SMOOTH_K, WEIGHT_CAP = 100.0, 15.0, 10, 12.0

df = pd.read_csv('lr_data_v3_full.csv')
df = df.dropna(subset=['exhibit_time_rel', 'start_timing']).copy()
for l in range(2, 7):
    df[f'lane_{l}'] = (df['lane'] == l).astype(float)
for g in ['A1', 'A2', 'B1']:
    df[f'grade_{g}'] = (df['grade_period'] == g).astype(float)
df['avg_st_rank'] = df.groupby('race_id')['avg_st'].rank(method='min', ascending=True).fillna(3.5)

LANE_OH = [f'lane_{l}' for l in range(2, 7)]
SET_S4 = LANE_OH + ['global_win_rate', 'local_win_rate', 'exhibit_time_rel', 'start_timing',
                    'motor_2rate_rel', 'wind_speed', 'wave_height', 'avg_st', 'avg_st_rank',
                    'course_win_rate_sm', 'course_in2_sm',
                    'recent10_avg_rank', 'recent10_win_rate', 'recent10_st_mean',
                    'grade_A1', 'grade_A2', 'grade_B1']
V2_FEATURES = ['lane', 'global_win_rate', 'global_2rate', 'local_win_rate', 'local_2rate',
               'exhibit_time_rel', 'start_timing', 'motor_2rate_rel',
               'wind_speed', 'wave_height', 'temperature']
V2_INTERCEPT = -0.710151
V2_MEANS  = np.array([3.498596, 5.125432, 32.040086, 0.159386, 0.326116, 0.501524, 0.091678, 0.489493, 2.631639, 2.119382, 28.307706])
V2_SCALES = np.array([1.709008, 1.365740, 13.847295, 0.121440, 0.174187, 0.362293, 0.105475, 0.362955, 1.417293, 1.546866, 3.305047])
V2_COEFS  = np.array([-1.133264, 0.148167, -0.102042, 0.975016, -0.018183, 0.215581, -0.089471, 0.087079, -0.036640, 0.049179, -0.000086])


def add_smoothed_course(train, *parts, k=SMOOTH_K):
    prior1 = train.groupby('lane')['is_winner'].mean()
    prior2 = (train.groupby('lane')
                   .apply(lambda g: g['course_rank2'].sum() / max(1, g['course_count'].sum())))
    for d in (train, *parts):
        p1 = d['lane'].map(prior1); p2 = d['lane'].map(prior2)
        d['course_win_rate_sm'] = (d['course_rank1'] + k * p1) / (d['course_count'] + k)
        d['course_in2_sm']      = (d['course_rank2'] + k * p2) / (d['course_count'] + k)
    return train, *parts


def fit_v3(train, sample_weight=None):
    med = train[SET_S4].median()
    pipe = Pipeline([('scaler', StandardScaler()),
                     ('lr', LogisticRegression(C=1.0, max_iter=2000, solver='lbfgs',
                                               class_weight='balanced', random_state=42))])
    pipe.fit(train[SET_S4].fillna(med).values, train['is_winner'].values,
             lr__sample_weight=sample_weight)
    return pipe, med


FOLDS = [('2026-07-27', '2026-08-09'), ('2026-08-10', '2026-08-23'), ('2026-08-24', '2026-09-02')]
pred_frames = []
for val_from, val_to in FOLDS:
    train = df[df['date'] < val_from].copy()
    val   = df[(df['date'] >= val_from) & (df['date'] <= val_to)].copy()
    train, val = add_smoothed_course(train, val)
    pipe, med = fit_v3(train)
    val['p_v3'] = pipe.predict_proba(val[SET_S4].fillna(med).values)[:, 1]
    prior1 = train.groupby('lane')['is_winner'].mean()
    w = np.ones(len(train)); iw = train['is_winner'].values == 1
    pri = train['lane'].map(prior1).values
    w[iw] = np.minimum(WEIGHT_CAP, (1.0 / np.maximum(pri[iw], 1e-3)) ** 0.5)
    pipe_w, med_w = fit_v3(train, sample_weight=w)
    val['p_v3w05'] = pipe_w.predict_proba(val[SET_S4].fillna(med_w).values)[:, 1]
    Xv2 = val[V2_FEATURES].fillna(val[V2_FEATURES].median()).values
    val['p_v2'] = 1 / (1 + np.exp(-(V2_INTERCEPT + ((Xv2 - V2_MEANS) / V2_SCALES) @ V2_COEFS)))
    pred_frames.append(val[['race_id', 'date', 'lane', 'is_winner', 'p_v2', 'p_v3', 'p_v3w05']])
preds = pd.concat(pred_frames, ignore_index=True)

odds = pd.concat([pd.read_csv(f) for f in sorted(glob.glob('odds_2026-*.csv'))], ignore_index=True)
pays = pd.concat([pd.read_csv(f) for f in sorted(glob.glob('payouts_2026-*.csv'))], ignore_index=True)
fin  = pd.concat([pd.read_csv(f) for f in sorted(glob.glob('finish_2026-*.csv'))], ignore_index=True)
odds_map = {rid: dict(zip(g['combo'], g['odds'])) for rid, g in odds.groupby('race_id')}
pay_map = {}
for r in pays.itertuples():
    pay_map.setdefault(r.race_id, {})[r.combo] = r.amount
fin_piv = fin.pivot_table(index='race_id', columns='rank', values='lane', aggfunc='first')
finish_map = {rid: f"{int(row[1])}-{int(row[2])}-{int(row[3])}"
              for rid, row in fin_piv.iterrows()
              if not (np.isnan(row.get(1, np.nan)) or np.isnan(row.get(2, np.nan)) or np.isnan(row.get(3, np.nan)))}


def payout_for(rid, combo):
    if rid in pay_map and combo in pay_map[rid]:
        return pay_map[rid][combo]
    o = odds_map.get(rid, {}).get(combo)
    return int(o * 100) if o is not None else 0


def ichigeki_combos(lanes, odds_r):
    out = []
    first = lanes[0]
    for sec in lanes[1:4]:
        for thi in lanes[1:4]:
            if sec != thi:
                c = f"{first}-{sec}-{thi}"
                if odds_r and c in odds_r and odds_r[c] < ICHIGEKI_MIN_ODDS:
                    continue
                out.append(c)
    return out


MODELS = ['v2', 'v3', 'v3w05']
rows = []  # race_id, model, cost, payout
for rid, grp in preds.groupby('race_id'):
    if rid not in finish_map or len(grp) < 4:
        continue
    actual = finish_map[rid]; odds_r = odds_map.get(rid, {})
    rec = {'race_id': rid}
    for m in MODELS:
        lanes = grp.sort_values(f'p_{m}', ascending=False)['lane'].astype(int).tolist()
        combos = ichigeki_combos(lanes, odds_r)
        rec[f'{m}_cost'] = len(combos) * 100
        rec[f'{m}_pay']  = payout_for(rid, actual) if actual in combos else 0
    rows.append(rec)
race_df = pd.DataFrame(rows)
print(f"一撃重視 対象レース: {len(race_df)}")
for m in MODELS:
    roi = race_df[f'{m}_pay'].sum() / race_df[f'{m}_cost'].sum() * 100
    print(f"  {m:6s}: ROI {roi:5.1f}%")

rng = np.random.default_rng(42)
N_BOOT = 5000
n = len(race_df)
diffs = {('v2', 'v3'): [], ('v2', 'v3w05'): []}
cost = {m: race_df[f'{m}_cost'].values for m in MODELS}
pay  = {m: race_df[f'{m}_pay'].values for m in MODELS}
for _ in range(N_BOOT):
    idx = rng.integers(0, n, n)
    for a, b in diffs:
        ra = pay[a][idx].sum() / max(1, cost[a][idx].sum()) * 100
        rb = pay[b][idx].sum() / max(1, cost[b][idx].sum()) * 100
        diffs[(a, b)].append(ra - rb)
print("\n=== 一撃重視ROI差のブートストラップ95%CI (レース単位リサンプル) ===")
for (a, b), d in diffs.items():
    d = np.array(d)
    lo, hi = np.percentile(d, [2.5, 97.5])
    p_worse = (d <= 0).mean()
    print(f"  {a} - {b}: 平均 {d.mean():+5.1f}pt  95%CI [{lo:+5.1f}, {hi:+5.1f}]  P({b}が同等以上)={p_worse:.3f}")
