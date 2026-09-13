# -*- coding: utf-8 -*-
"""
sim_v3_improved.py (一時検証スクリプト・DB書き込みなし)
v3昇格見送りを受けた改良案のオフライン比較シミュレーション。

比較モデル:
  v2      : 現行v2係数(本番)
  v3      : 現行v3 (S4, class_weight='balanced')
  v3w05   : 改良版v3 重み付き学習 alpha=0.5 (勝者を枠番prior逆数^0.5で重み付け)
  v3w10   : 改良版v3 重み付き学習 alpha=1.0
  blend   : v2/v3確率の幾何平均でランク
  hybrid  : 的中特化・バランス=v3 / 一撃重視・絞り込み=v2 (戦略別使い分け)

walk-forward 3fold (7/27-8/9, 8/10-8/23, 8/24-9/2) で
4戦略の的中率・回収率 + top1的中率を比較。
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

BALANCE_MAX_ODDS  = 100.0
ICHIGEKI_MIN_ODDS = 15.0
SMOOTH_K = 10
WEIGHT_CAP = 12.0   # 重みの上限(6号艇prior~3%の逆数≈32は過大なのでクリップ)

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


def fit_v3(train, sample_weight=None):
    med = train[SET_S4].median()
    pipe = Pipeline([
        ('scaler', StandardScaler()),
        ('lr', LogisticRegression(C=1.0, max_iter=2000, solver='lbfgs',
                                  class_weight='balanced', random_state=42)),
    ])
    pipe.fit(train[SET_S4].fillna(med).values, train['is_winner'].values,
             lr__sample_weight=sample_weight)
    return pipe, med


def longshot_weight(train, alpha):
    """勝者サンプルを枠番prior逆数^alphaで重み付け(高配当的中の学習重視)。敗者=1"""
    prior1 = train.groupby('lane')['is_winner'].mean()
    w = np.ones(len(train))
    is_win = train['is_winner'].values == 1
    pri = train['lane'].map(prior1).values
    w[is_win] = np.minimum(WEIGHT_CAP, (1.0 / np.maximum(pri[is_win], 1e-3)) ** alpha)
    return w


FOLDS = [('2026-07-27', '2026-08-09'), ('2026-08-10', '2026-08-23'), ('2026-08-24', '2026-09-02')]

pred_frames = []
for val_from, val_to in FOLDS:
    train = df[df['date'] < val_from].copy()
    val   = df[(df['date'] >= val_from) & (df['date'] <= val_to)].copy()
    train, val = add_smoothed_course(train, val)

    # v3現行
    pipe, med = fit_v3(train)
    val['p_v3'] = pipe.predict_proba(val[SET_S4].fillna(med).values)[:, 1]

    # v3重み付き
    for alpha, col in [(0.5, 'p_v3w05'), (1.0, 'p_v3w10')]:
        w = longshot_weight(train, alpha)
        pipe_w, med_w = fit_v3(train, sample_weight=w)
        val[col] = pipe_w.predict_proba(val[SET_S4].fillna(med_w).values)[:, 1]

    # v2現行係数
    Xv2 = val[V2_FEATURES].fillna(val[V2_FEATURES].median()).values
    val['p_v2'] = 1 / (1 + np.exp(-(V2_INTERCEPT + ((Xv2 - V2_MEANS) / V2_SCALES) @ V2_COEFS)))

    # ブレンド(幾何平均)
    val['p_blend'] = np.sqrt(val['p_v2'] * val['p_v3'])

    pred_frames.append(val[['race_id', 'date', 'lane', 'is_winner',
                            'p_v2', 'p_v3', 'p_v3w05', 'p_v3w10', 'p_blend']])

preds = pd.concat(pred_frames, ignore_index=True)
print(f"検証対象: {preds['race_id'].nunique()}レース ({preds['date'].min()}..{preds['date'].max()})")

# ─── オッズ・払戻・着順 ──────────────────────────────────────
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


def strategies_for(lanes, odds_r):
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


MODELS = ['v2', 'v3', 'v3w05', 'v3w10', 'blend']
STRATS = ['tokka', 'balance', 'ichigeki', 'shibori']
NAMES  = {'tokka': '的中特化', 'balance': 'バランス', 'ichigeki': '一撃重視', 'shibori': '絞り込み'}

agg   = {m: {s: {'races': 0, 'hits': 0, 'cost': 0, 'payout': 0} for s in STRATS} for m in MODELS}
top1  = {m: [0, 0] for m in MODELS}
lane1_top1 = {m: 0 for m in MODELS}   # rank1に1号艇を置いた回数(本命寄り度の指標)

for rid, grp in preds.groupby('race_id'):
    if rid not in finish_map or len(grp) < 4:
        continue
    actual = finish_map[rid]
    win_lane = int(actual.split('-')[0])
    odds_r = odds_map.get(rid, {})
    for m in MODELS:
        lanes = grp.sort_values(f'p_{m}', ascending=False)['lane'].astype(int).tolist()
        top1[m][1] += 1
        top1[m][0] += 1 if lanes[0] == win_lane else 0
        lane1_top1[m] += 1 if lanes[0] == 1 else 0
        strat = strategies_for(lanes, odds_r)
        for s, combos in strat.items():
            if not combos:
                continue
            agg[m][s]['races']  += 1
            agg[m][s]['cost']   += len(combos) * 100
            if actual in combos:
                agg[m][s]['hits']   += 1
                agg[m][s]['payout'] += payout_for(rid, actual)

print(f"\n=== top1的中率と本命寄り度 ===")
print(f"{'モデル':8s} {'top1的中率':>9s} {'1号艇をrank1にした率':>18s}")
for m in MODELS:
    r = top1[m]
    print(f"{m:8s} {r[0]/r[1]*100:>8.1f}% {lane1_top1[m]/r[1]*100:>17.1f}%")

print(f"\n=== 4戦略 的中率・回収率 ===")
print(f"{'戦略':10s} " + " ".join(f"{m:>16s}" for m in MODELS))
for s in STRATS:
    row = []
    for m in MODELS:
        a = agg[m][s]
        hr  = a['hits'] / a['races'] * 100 if a['races'] else 0
        roi = a['payout'] / a['cost'] * 100 if a['cost'] else 0
        row.append(f"{hr:5.1f}%/{roi:5.1f}%")
    print(f"{NAMES[s]:10s} " + " ".join(f"{r:>16s}" for r in row))

# ハイブリッド: 的中特化・バランス=v3、一撃重視・絞り込み=v2
print(f"\n=== ハイブリッド (的中特化/バランス=v3, 一撃/絞り込み=v2) ===")
total_cost = {m: 0 for m in ['v2', 'v3', 'hybrid']}
total_pay  = {m: 0 for m in ['v2', 'v3', 'hybrid']}
for s in STRATS:
    src = 'v3' if s in ('tokka', 'balance') else 'v2'
    a = agg[src][s]
    hr  = a['hits'] / a['races'] * 100 if a['races'] else 0
    roi = a['payout'] / a['cost'] * 100 if a['cost'] else 0
    print(f"  {NAMES[s]:10s} <- {src:3s}: 的中 {hr:5.1f}%  回収 {roi:5.1f}%")
    for m in ['v2', 'v3']:
        total_cost[m] += agg[m][s]['cost']; total_pay[m] += agg[m][s]['payout']
    total_cost['hybrid'] += a['cost']; total_pay['hybrid'] += a['payout']

print(f"\n=== 4戦略合算の総合回収率(参考) ===")
for m in ['v2', 'v3', 'hybrid']:
    print(f"  {m:8s}: 投資{total_cost[m]/100:>8.0f}点  回収率 {total_pay[m]/total_cost[m]*100:5.1f}%")

# fold別: 一撃重視ROI(最重要指標)の安定性
print(f"\n=== fold別 一撃重視ROI ===")
preds['fold'] = pd.cut(pd.to_datetime(preds['date']),
                       bins=pd.to_datetime(['2026-07-26', '2026-08-09', '2026-08-23', '2026-09-02']),
                       labels=['fold1', 'fold2', 'fold3'])
for fold in ['fold1', 'fold2', 'fold3']:
    sub = preds[preds['fold'] == fold]
    sums = {m: [0, 0] for m in MODELS}  # cost, payout
    for rid, grp in sub.groupby('race_id'):
        if rid not in finish_map or len(grp) < 4:
            continue
        actual = finish_map[rid]
        odds_r = odds_map.get(rid, {})
        for m in MODELS:
            lanes = grp.sort_values(f'p_{m}', ascending=False)['lane'].astype(int).tolist()
            combos = strategies_for(lanes, odds_r)['ichigeki']
            if not combos:
                continue
            sums[m][0] += len(combos) * 100
            if actual in combos:
                sums[m][1] += payout_for(rid, actual)
    line = " ".join(f"{m}={sums[m][1]/sums[m][0]*100:5.1f}%" if sums[m][0] else f"{m}=  n/a" for m in MODELS)
    print(f"  {fold}: {line}")
