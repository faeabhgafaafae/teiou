"""
v3オフライン戦略KPI検証 (design_v3_model_20260903.md §4.1)
実行: python kpi_offline_v3.py

walk-forwardのfoldごとにv3を学習し、検証期間(7/27〜9/2)の各レースについて
v2現行係数とv3のランク順で4戦略の買い目を再現、的中率・回収率を比較する。
オッズ上限等は現行本番設定(バランス100倍/一撃15倍)を使用。
"""
import glob
import warnings
import numpy as np
import pandas as pd
from sklearn.linear_model import LogisticRegression
from sklearn.preprocessing import StandardScaler
from sklearn.pipeline import Pipeline
from itertools import permutations

warnings.filterwarnings('ignore')

BALANCE_MAX_ODDS  = 100.0
ICHIGEKI_MIN_ODDS = 15.0
SMOOTH_K = 10

# ─── 特徴量構築 (run_lr_v3.pyと同一) ─────────────────────────
df = pd.read_csv('lr_data_v3_full.csv')
df = df.dropna(subset=['exhibit_time_rel', 'start_timing']).copy()
for l in range(2, 7):
    df[f'lane_{l}'] = (df['lane'] == l).astype(float)
for g in ['A1', 'A2', 'B1']:
    df[f'grade_{g}'] = (df['grade_period'] == g).astype(float)
df['avg_st_rank'] = df.groupby('race_id')['avg_st'].rank(method='min', ascending=True).fillna(3.5)

LANE_OH = [f'lane_{l}' for l in range(2, 7)]
SET_S4 = LANE_OH + ['global_win_rate', 'local_win_rate',
                    'exhibit_time_rel', 'start_timing', 'motor_2rate_rel',
                    'wind_speed', 'wave_height', 'avg_st', 'avg_st_rank',
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
        p1 = d['lane'].map(prior1)
        p2 = d['lane'].map(prior2)
        d['course_win_rate_sm'] = (d['course_rank1'] + k * p1) / (d['course_count'] + k)
        d['course_in2_sm']      = (d['course_rank2'] + k * p2) / (d['course_count'] + k)
    return train, *parts


FOLDS = [('2026-07-27', '2026-08-09'), ('2026-08-10', '2026-08-23'), ('2026-08-24', '2026-09-02')]

# fold毎にv3を学習し検証期間の予測を蓄積
pred_frames = []
for val_from, val_to in FOLDS:
    train = df[df['date'] < val_from].copy()
    val   = df[(df['date'] >= val_from) & (df['date'] <= val_to)].copy()
    train, val = add_smoothed_course(train, val)

    med = train[SET_S4].median()
    pipe = Pipeline([
        ('scaler', StandardScaler()),
        ('lr', LogisticRegression(C=1.0, max_iter=2000, solver='lbfgs',
                                  class_weight='balanced', random_state=42)),
    ])
    pipe.fit(train[SET_S4].fillna(med).values, train['is_winner'].values)
    val['p_v3'] = pipe.predict_proba(val[SET_S4].fillna(med).values)[:, 1]

    Xv2 = val[V2_FEATURES].fillna(val[V2_FEATURES].median()).values
    val['p_v2'] = 1 / (1 + np.exp(-(V2_INTERCEPT + ((Xv2 - V2_MEANS) / V2_SCALES) @ V2_COEFS)))
    pred_frames.append(val[['race_id', 'date', 'lane', 'p_v2', 'p_v3', 'is_winner']])

preds = pd.concat(pred_frames, ignore_index=True)
print(f"検証対象: {preds['race_id'].nunique()}レース ({preds['date'].min()}..{preds['date'].max()})")

# ─── オッズ・払戻・着順の読み込み ─────────────────────────────
odds = pd.concat([pd.read_csv(f) for f in sorted(glob.glob('odds_2026-*.csv'))], ignore_index=True)
pays = pd.concat([pd.read_csv(f) for f in sorted(glob.glob('payouts_2026-*.csv'))], ignore_index=True)
fin  = pd.concat([pd.read_csv(f) for f in sorted(glob.glob('finish_2026-*.csv'))], ignore_index=True)

odds_map = {}
for rid, grp in odds.groupby('race_id'):
    odds_map[rid] = dict(zip(grp['combo'], grp['odds']))
pay_map = {(r.race_id): {} for r in pays.itertuples()}
for r in pays.itertuples():
    pay_map[r.race_id][r.combo] = r.amount
fin_piv = fin.pivot_table(index='race_id', columns='rank', values='lane', aggfunc='first')
finish_map = {rid: f"{int(row[1])}-{int(row[2])}-{int(row[3])}"
              for rid, row in fin_piv.iterrows()
              if not (np.isnan(row.get(1, np.nan)) or np.isnan(row.get(2, np.nan)) or np.isnan(row.get(3, np.nan)))}


def payout_for(rid, combo):
    if rid in pay_map and combo in pay_map[rid]:
        return pay_map[rid][combo]
    o = odds_map.get(rid, {}).get(combo)
    return int(o * 100) if o is not None else 0


def strategies_for(lanes, odds_r):
    """現行本番ロジック(generate_strategies.php)の再現。lanes=予測順位順の枠番リスト"""
    s = {}
    s['tokka'] = ['-'.join(map(str, p)) for p in permutations(lanes[:3])]
    top4 = lanes[:4]
    bal = []
    for first in top4[:2]:
        rest = [l for l in top4 if l != first]
        for sec in rest:
            for thi in rest:
                if sec != thi:
                    c = f"{first}-{sec}-{thi}"
                    if odds_r and c in odds_r and odds_r[c] > BALANCE_MAX_ODDS:
                        continue
                    bal.append(c)
    s['balance'] = bal
    ichi = []
    if len(lanes) >= 4:
        first = lanes[0]
        for sec in lanes[1:4]:
            for thi in lanes[1:4]:
                if sec != thi:
                    c = f"{first}-{sec}-{thi}"
                    if odds_r and c in odds_r and odds_r[c] < ICHIGEKI_MIN_ODDS:
                        continue
                    ichi.append(c)
    s['ichigeki'] = ichi
    s['shibori'] = ['-'.join(map(str, sorted(lanes[:3])))]
    return s


# ─── シミュレーション ─────────────────────────────────────────
STRATS = ['tokka', 'balance', 'ichigeki', 'shibori']
NAMES  = {'tokka': '的中特化', 'balance': 'バランス', 'ichigeki': '一撃重視', 'shibori': '絞り込み'}
agg = {m: {s: {'races': 0, 'hits': 0, 'cost': 0, 'payout': 0} for s in STRATS} for m in ['v2', 'v3']}

for rid, grp in preds.groupby('race_id'):
    if rid not in finish_map or len(grp) < 4:
        continue
    actual = finish_map[rid]
    odds_r = odds_map.get(rid, {})
    for m in ['v2', 'v3']:
        lanes = grp.sort_values(f'p_{m}', ascending=False)['lane'].astype(int).tolist()
        strat = strategies_for(lanes, odds_r)
        for s, combos in strat.items():
            if not combos:
                continue
            agg[m][s]['races']  += 1
            agg[m][s]['cost']   += len(combos) * 100
            if actual in combos:
                agg[m][s]['hits']   += 1
                agg[m][s]['payout'] += payout_for(rid, actual)

print(f"\n{'戦略':10s} {'モデル':4s} {'対象R':>6s} {'的中率':>7s} {'回収率':>7s} {'平均点数':>8s}")
for s in STRATS:
    for m in ['v2', 'v3']:
        a = agg[m][s]
        hr  = a['hits'] / a['races'] * 100 if a['races'] else 0
        roi = a['payout'] / a['cost'] * 100 if a['cost'] else 0
        pts = a['cost'] / 100 / a['races'] if a['races'] else 0
        print(f"{NAMES[s]:10s} {m:4s} {a['races']:>6d} {hr:>6.1f}% {roi:>6.1f}% {pts:>7.2f}")
