"""
ロジスティック回帰によるボートレース1着予測モデル検証スクリプト
実行: python run_lr.py <csvファイルパス>
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

CSV_PATH = sys.argv[1] if len(sys.argv) > 1 else 'lr_data.csv'

# ─── 1. データ読み込み ─────────────────────────────────────
df = pd.read_csv(CSV_PATH)
print(f"Total rows loaded: {len(df)}")
print(f"Races: {df['race_id'].nunique()}")
print(f"Date range: {df['date'].min()} ~ {df['date'].max()}")
print()

# ─── 2. 特徴量候補と欠損状況 ────────────────────────────────
FEAT_CANDIDATES = [
    'lane',
    'global_win_rate', 'global_2rate',
    'local_win_rate',  'local_2rate',
    'exhibit_time_rel',
    'start_timing',
    'motor_2rate_rel',
    'wind_speed', 'wave_height', 'temperature',
]
TARGET = 'is_winner'

print("=== 特徴量 欠損率 ===")
for f in FEAT_CANDIDATES:
    n_miss = df[f].isna().sum()
    print(f"  {f:22s}: {n_miss:5d} / {len(df)} ({n_miss/len(df)*100:.1f}%)")
print()

# ─── 3. 使用特徴量の選定（欠損 < 30%のもの） ─────────────────
FEATURES = [f for f in FEAT_CANDIDATES if df[f].isna().mean() < 0.30]
print(f"採用特徴量 ({len(FEATURES)}件): {FEATURES}\n")

# 欠損は中央値で補完（exhibit_time_rel / start_timing は同レース内で見ると
# 欠損レースは全員NULL→その行をまるごと除外する方が誤りが少ない）
# → exhibit_time_rel/start_timingが欠損している行は除外
REQUIRED = ['exhibit_time_rel', 'start_timing']
df_clean = df.dropna(subset=[r for r in REQUIRED if r in FEATURES]).copy()

# その他特徴量は中央値補完
for f in FEATURES:
    if f not in REQUIRED:
        df_clean[f] = df_clean[f].fillna(df_clean[f].median())

print(f"欠損除外後: {len(df_clean)} rows, {df_clean['race_id'].nunique()} races")
print()

# ─── 4. 時系列分割 (古い80% → train, 新しい20% → test) ──────
dates = sorted(df_clean['date'].unique())
cutoff_idx = int(len(dates) * 0.8)
cutoff_date = dates[cutoff_idx]
print(f"Train: {dates[0]} ~ {dates[cutoff_idx-1]}")
print(f"Test : {cutoff_date} ~ {dates[-1]}")

train = df_clean[df_clean['date'] < cutoff_date].copy()
test  = df_clean[df_clean['date'] >= cutoff_date].copy()
print(f"Train rows: {len(train)} ({train['race_id'].nunique()} races)")
print(f"Test  rows: {len(test)}  ({test['race_id'].nunique()} races)")
print()

X_train = train[FEATURES].values
y_train = train[TARGET].values
X_test  = test[FEATURES].values
y_test  = test[TARGET].values

# ─── 5. モデル学習 ──────────────────────────────────────────
pipe = Pipeline([
    ('scaler', StandardScaler()),
    ('lr', LogisticRegression(
        C=1.0, max_iter=1000, solver='lbfgs',
        class_weight='balanced',  # 1着は1/6 → クラス不均衡補正
        random_state=42
    ))
])
pipe.fit(X_train, y_train)

# ─── 6. 係数確認 (特徴量の重み) ──────────────────────────────
coefs = pipe.named_steps['lr'].coef_[0]
print("=== ロジスティック回帰 係数 (標準化後) ===")
coef_df = pd.DataFrame({'feature': FEATURES, 'coef': coefs})
coef_df = coef_df.reindex(coef_df['coef'].abs().sort_values(ascending=False).index)
for _, row in coef_df.iterrows():
    bar = '#' * int(abs(row['coef']) * 10)
    sign = '+' if row['coef'] >= 0 else '-'
    print(f"  {row['feature']:22s}: {sign}{abs(row['coef']):.4f}  {bar}")
print()

# ─── 7. 分類指標 (行単位) ────────────────────────────────────
def eval_binary(X, y, label):
    proba = pipe.predict_proba(X)[:, 1]
    auc = roc_auc_score(y, proba)
    ll  = log_loss(y, proba)
    print(f"  [{label}] ROC-AUC: {auc:.4f}  LogLoss: {ll:.4f}")
    return proba

print("=== 分類指標 (行単位) ===")
proba_train = eval_binary(X_train, y_train, 'train')
proba_test  = eval_binary(X_test,  y_test,  'test ')
print()

# ─── 8. レース単位 1着的中率 ─────────────────────────────────
def race_accuracy(df_part, proba, label):
    df_part = df_part.copy()
    df_part['proba'] = proba
    # 各レースで最高確率の選手を予想1位に
    idx_best = df_part.groupby('race_id')['proba'].idxmax()
    pred1    = df_part.loc[idx_best]
    hit      = int(pred1['is_winner'].sum())
    total    = len(idx_best)
    rate     = hit / total * 100
    print(f"  [{label}] predicted_rank=1 → 1着的中: {hit}/{total} ({rate:.1f}%)")
    return hit, total

print("=== レース単位 1着的中率 ===")
hit_tr, tot_tr = race_accuracy(train, proba_train, 'train')
hit_te, tot_te = race_accuracy(test,  proba_test,  'test ')

# 1号艇ベースライン
lane1_train = int((train[train['lane'] == 1]['is_winner']).sum())
lane1_test  = int((test[test['lane'] == 1]['is_winner']).sum())
print(f"  [train] 1号艇決め打ち           : {lane1_train}/{train['race_id'].nunique()} ({lane1_train/train['race_id'].nunique()*100:.1f}%)")
print(f"  [test ] 1号艇決め打ち           : {lane1_test}/{test['race_id'].nunique()}  ({lane1_test/test['race_id'].nunique()*100:.1f}%)")
print()

# ─── 9. 現行モデル(score_total_current)との比較 ──────────────
def score_total_accuracy(df_part, label):
    df_part = df_part.dropna(subset=['score_total_current'])
    if len(df_part) == 0:
        print(f"  [{label}] score_total_currentがなし(skip)")
        return
    idx_best = df_part.groupby('race_id')['score_total_current'].idxmax()
    pred1    = df_part.loc[idx_best]
    hit   = int(pred1['is_winner'].sum())
    total = len(idx_best)
    print(f"  [{label}] 現行score_total決め打ち: {hit}/{total} ({hit/total*100:.1f}%)")

print("=== 現行モデルとの比較 ===")
score_total_accuracy(train, 'train')
score_total_accuracy(test,  'test ')
print()

# ─── 10. 過学習チェック要約 ──────────────────────────────────
print("=== サマリー ===")
print(f"  Train 1着的中率: {hit_tr/tot_tr*100:.1f}%")
print(f"  Test  1着的中率: {hit_te/tot_te*100:.1f}%")
print(f"  差 (train-test): {(hit_tr/tot_tr - hit_te/tot_te)*100:.1f}pt")
diff_check = abs(hit_tr/tot_tr - hit_te/tot_te) * 100
if diff_check < 3:
    print("  → 過学習なし (train/test差 <3pt)")
elif diff_check < 6:
    print("  → 軽微な過学習の可能性 (train/test差 3-6pt)")
else:
    print("  ⚠️ 過学習の疑いあり (train/test差 >6pt)")
print()
print("完了")
