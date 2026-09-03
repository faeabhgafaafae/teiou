"""
v3ロジスティック回帰 学習・検証スクリプト (design_v3_model_20260903.md §3.2)
実行: python run_lr_v3.py lr_data_v3_full.csv [--php]

- walk-forward 3-fold CV + ablation(特徴量群の段階追加)
- 現行v2係数のベースライン評価(同一テストデータ・リーク修正済み特徴量)
- --php: 全データ再fitした本番用PHP定数を出力(PredictV3用)
"""
import sys
import warnings
import numpy as np
import pandas as pd
from sklearn.linear_model import LogisticRegression
from sklearn.preprocessing import StandardScaler
from sklearn.metrics import roc_auc_score, log_loss
from sklearn.pipeline import Pipeline

warnings.filterwarnings('ignore')

CSV_PATH = sys.argv[1] if len(sys.argv) > 1 else 'lr_data_v3_full.csv'
SMOOTH_K = 10          # コース別成績のベイズ平滑化(CVで選択した値をここに固定)
C_REG    = 1.0

df = pd.read_csv(CSV_PATH)
print(f"rows={len(df)} races={df['race_id'].nunique()} ({df['date'].min()}..{df['date'].max()})")

# ─── 前処理 ──────────────────────────────────────────────────
# REQUIRED: 展示タイム/展示STが欠損の行は除外(現行v2と同じ)
df = df.dropna(subset=['exhibit_time_rel', 'start_timing']).copy()
print(f"REQUIRED除外後: rows={len(df)} races={df['race_id'].nunique()}")

# lane one-hot (基準=1号艇)
for l in range(2, 7):
    df[f'lane_{l}'] = (df['lane'] == l).astype(float)

# grade one-hot (基準=B2)
for g in ['A1', 'A2', 'B1']:
    df[f'grade_{g}'] = (df['grade_period'] == g).astype(float)

# avg_st_rank: レース内順位(1=最速)。欠損は中間の3.5
df['avg_st_rank'] = df.groupby('race_id')['avg_st'].rank(method='min', ascending=True)
df['avg_st_rank'] = df['avg_st_rank'].fillna(3.5)

TARGET = 'is_winner'

# ─── 特徴量セット定義(ablation用) ────────────────────────────
SET_S0 = ['lane', 'global_win_rate', 'global_2rate', 'local_win_rate', 'local_2rate',
          'exhibit_time_rel', 'start_timing', 'motor_2rate_rel',
          'wind_speed', 'wave_height', 'temperature']          # 現行v2構成の再学習
LANE_OH = [f'lane_{l}' for l in range(2, 7)]
SET_S1 = LANE_OH + ['global_win_rate', 'local_win_rate',
                    'exhibit_time_rel', 'start_timing', 'motor_2rate_rel',
                    'wind_speed', 'wave_height']               # one-hot + 死に特徴量除去
SET_S2 = SET_S1 + ['avg_st', 'avg_st_rank']                    # +期別ST
SET_S3 = SET_S2 + ['course_win_rate_sm', 'course_in2_sm']      # +コース別成績
SET_S4 = SET_S3 + ['recent10_avg_rank', 'recent10_win_rate', 'recent10_st_mean',
                   'grade_A1', 'grade_A2', 'grade_B1']         # +直近トレンド+級別(=v3全部)

ABLATION = [('S0:v2構成再学習', SET_S0), ('S1:onehot+整理', SET_S1),
            ('S2:+avg_st', SET_S2), ('S3:+コース別', SET_S3), ('S4:+直近+級別(v3)', SET_S4)]

# ─── 現行v2係数(predict_v2_core.php) ─────────────────────────
V2_FEATURES = SET_S0
V2_INTERCEPT = -0.710151
V2_MEANS  = np.array([3.498596, 5.125432, 32.040086, 0.159386, 0.326116, 0.501524, 0.091678, 0.489493, 2.631639, 2.119382, 28.307706])
V2_SCALES = np.array([1.709008, 1.365740, 13.847295, 0.121440, 0.174187, 0.362293, 0.105475, 0.362955, 1.417293, 1.546866, 3.305047])
V2_COEFS  = np.array([-1.133264, 0.148167, -0.102042, 0.975016, -0.018183, 0.215581, -0.089471, 0.087079, -0.036640, 0.049179, -0.000086])


def add_smoothed_course(train, *parts, k=SMOOTH_K):
    """コース別成績の平滑化。priorはtrainの枠番別1着/2着率から計算(リーク防止)"""
    prior1 = train.groupby('lane')['is_winner'].mean()
    # 2着内率のprior: course_rank2/course_countのtrain平均で近似
    prior2 = (train.groupby('lane')
                   .apply(lambda g: (g['course_rank2'].sum()) / max(1, g['course_count'].sum())))
    for d in (train, *parts):
        p1 = d['lane'].map(prior1)
        p2 = d['lane'].map(prior2)
        d['course_win_rate_sm'] = (d['course_rank1'] + k * p1) / (d['course_count'] + k)
        d['course_in2_sm']      = (d['course_rank2'] + k * p2) / (d['course_count'] + k)
    return train, *parts


def impute(train, *parts, features):
    """中央値補完(統計量はtrainのみから計算)"""
    med = train[features].median()
    return (train[features].fillna(med).values,
            *[p[features].fillna(med).values for p in parts])


def race_top1_acc(d, proba):
    d = d.copy()
    d['p'] = proba
    best = d.loc[d.groupby('race_id')['p'].idxmax()]
    return best['is_winner'].sum() / len(best) * 100


def race_top3set_acc(d, proba):
    """予測上位3艇が実際の1-3着(順不同)と完全一致する率(的中特化の上限性能)"""
    d = d.copy()
    d['p'] = proba
    d['pred_rank']  = d.groupby('race_id')['p'].rank(ascending=False, method='first')
    # actual_rankがない場合はis_winner+着順代理不可 → course列は無いのでresults順位が必要
    return None  # actual 1-3着はCSVに含めていないため4.1のオフライン検証(Day3)で実施


def eval_model(pipe, d, X, label):
    proba = pipe.predict_proba(X)[:, 1]
    auc = roc_auc_score(d[TARGET], proba)
    ll  = log_loss(d[TARGET], proba)
    acc = race_top1_acc(d, proba)
    return auc, ll, acc


def eval_v2_baseline(d):
    """現行v2係数をそのまま適用(同一データで公平比較)"""
    X = d[V2_FEATURES].fillna(d[V2_FEATURES].median()).values
    logit = V2_INTERCEPT + ((X - V2_MEANS) / V2_SCALES) @ V2_COEFS
    proba = 1 / (1 + np.exp(-logit))
    auc = roc_auc_score(d[TARGET], proba)
    acc = race_top1_acc(d, proba)
    return auc, acc


# ─── walk-forward 3-fold ─────────────────────────────────────
FOLDS = [
    ('fold1', '2026-07-27', '2026-08-09'),
    ('fold2', '2026-08-10', '2026-08-23'),
    ('fold3', '2026-08-24', '2026-09-02'),
]

print(f"\n=== walk-forward CV (平滑化k={SMOOTH_K}, C={C_REG}) ===")
results = {}   # name -> list of (auc, acc)
v2_results = []

for fold_name, val_from, val_to in FOLDS:
    train = df[df['date'] < val_from].copy()
    val   = df[(df['date'] >= val_from) & (df['date'] <= val_to)].copy()
    train, val = add_smoothed_course(train, val)

    v2_auc, v2_acc = eval_v2_baseline(val)
    v2_results.append((v2_auc, v2_acc))
    print(f"\n[{fold_name}] train {train['race_id'].nunique()}R (~{val_from}) / val {val['race_id'].nunique()}R ({val_from}..{val_to})")
    print(f"  {'v2現行係数':24s}: AUC={v2_auc:.4f}  top1的中={v2_acc:.1f}%")

    for name, feats in ABLATION:
        X_tr, X_va = impute(train, val, features=feats)
        pipe = Pipeline([
            ('scaler', StandardScaler()),
            ('lr', LogisticRegression(C=C_REG, max_iter=2000, solver='lbfgs',
                                      class_weight='balanced', random_state=42)),
        ])
        pipe.fit(X_tr, train[TARGET].values)
        auc, ll, acc = eval_model(pipe, val, X_va, name)
        results.setdefault(name, []).append((auc, acc))
        print(f"  {name:24s}: AUC={auc:.4f}  top1的中={acc:.1f}%")

print("\n=== fold平均 ===")
v2m = np.mean(v2_results, axis=0)
print(f"  {'v2現行係数':24s}: AUC={v2m[0]:.4f}  top1的中={v2m[1]:.1f}%")
for name, vals in results.items():
    m = np.mean(vals, axis=0)
    print(f"  {name:24s}: AUC={m[0]:.4f}  top1的中={m[1]:.1f}%")

# ─── 最終モデル: S4を全データでfit ────────────────────────────
print("\n=== 最終モデル(S4)を全データでfit ===")
full = df.copy()
full, = add_smoothed_course(full)
FINAL_FEATURES = SET_S4
X_full, = impute(full, features=FINAL_FEATURES)
final_pipe = Pipeline([
    ('scaler', StandardScaler()),
    ('lr', LogisticRegression(C=C_REG, max_iter=2000, solver='lbfgs',
                              class_weight='balanced', random_state=42)),
])
final_pipe.fit(X_full, full[TARGET].values)

coefs = final_pipe.named_steps['lr'].coef_[0]
coef_df = pd.DataFrame({'feature': FINAL_FEATURES, 'coef': coefs})
coef_df = coef_df.reindex(coef_df['coef'].abs().sort_values(ascending=False).index)
print("係数(標準化後):")
for _, row in coef_df.iterrows():
    print(f"  {row['feature']:22s}: {'+' if row['coef']>=0 else '-'}{abs(row['coef']):.4f}")

# ─── PHP定数出力 ─────────────────────────────────────────────
if '--php' in sys.argv:
    scaler = final_pipe.named_steps['scaler']
    lr     = final_pipe.named_steps['lr']
    prior1 = full.groupby('lane')['is_winner'].mean()
    prior2 = (full.groupby('lane')
                  .apply(lambda g: g['course_rank2'].sum() / max(1, g['course_count'].sum())))
    med    = full[FINAL_FEATURES].median()

    def php_arr(vals, fmt='%.6f'):
        return '[' + ', '.join(fmt % v for v in vals) + ']'

    print("\n// ===== predict_v3_core.php 埋め込み用定数 =====")
    print(f"// 学習日: {pd.Timestamp.now().strftime('%Y-%m-%d')}  データ: {df['date'].min()}~{df['date'].max()} ({df['race_id'].nunique()}R)")
    print(f"// CV fold平均: v2現行 AUC={v2m[0]:.4f}/top1 {v2m[1]:.1f}% -> S4 AUC={np.mean(results['S4:+直近+級別(v3)'],axis=0)[0]:.4f}/top1 {np.mean(results['S4:+直近+級別(v3)'],axis=0)[1]:.1f}%")
    print(f"// 特徴量順: {FINAL_FEATURES}")
    print(f"private const INTERCEPT = {lr.intercept_[0]:.6f};")
    print(f"private const MEANS  = {php_arr(scaler.mean_)};")
    print(f"private const SCALES = {php_arr(scaler.scale_)};")
    print(f"private const COEFS  = {php_arr(lr.coef_[0])};")
    print(f"private const SMOOTH_K = {SMOOTH_K};")
    print(f"private const COURSE_PRIOR1 = {php_arr([prior1.get(l, 0.1) for l in range(1,7)])};  // 枠番1-6の1着率prior")
    print(f"private const COURSE_PRIOR2 = {php_arr([prior2.get(l, 0.2) for l in range(1,7)])};  // 枠番1-6の2着内率prior")
    print(f"private const IMPUTE_MEDIANS = {php_arr(med.values)};  // 特徴量順の中央値(欠損補完用)")
