# -*- coding: utf-8 -*-
"""
strategy_sim.py — 4戦略シミュレーション + ROI-CVゲート共通モジュール
(design_v3_improvement_20260913.md §2)

現行本番の買い目生成ロジック(generate_strategies.php)を再現し、
候補モデルとv2ベースラインの戦略別ROIをレース単位ブートストラップで比較する。

ゲート基準:
  - hard gate: 全戦略で P(候補ROI >= v2ROI) >= P_THRESHOLD (0.25)
  - soft warning: ROI点推定が -3pt 超の戦略に注記
  - soft warning: 本命寄り度 = 1号艇rank1率 > 実際の1号艇1着率 + LANE1_MARGIN (15pt)
    (2026-09-13判断: ROI実害はhard gateが直接測定するため警告のみに降格。
     v2自身も実勝率+11.7ptであり15pt hard基準は過剰だった)

必要データ: odds_2026-*.csv / payouts_2026-*.csv / finish_2026-*.csv
(export_odds_payouts_v3.php で出力したfold検証期間分)
"""
import glob
import os
import numpy as np
import pandas as pd
from itertools import permutations

BALANCE_MAX_ODDS  = 100.0
ICHIGEKI_MIN_ODDS = 15.0
P_THRESHOLD  = 0.25   # hard gate: P(候補ROI >= v2ROI) の下限
LANE1_MARGIN = 0.15   # 本命寄り度: 実勝率 + 15pt を超えたらsoft警告
SOFT_WARN_PT = 3.0    # soft warning: ROI点推定の悪化幅

STRATS = ['tokka', 'balance', 'ichigeki', 'shibori']
NAMES  = {'tokka': '的中特化', 'balance': 'バランス', 'ichigeki': '一撃重視', 'shibori': '絞り込み'}


def load_market_data(data_dir='.'):
    """オッズ・払戻・着順CSVを読み込み {odds_map, pay_map, finish_map} を返す"""
    odds = pd.concat([pd.read_csv(f) for f in sorted(glob.glob(os.path.join(data_dir, 'odds_2026-*.csv')))],
                     ignore_index=True)
    pays = pd.concat([pd.read_csv(f) for f in sorted(glob.glob(os.path.join(data_dir, 'payouts_2026-*.csv')))],
                     ignore_index=True)
    fin  = pd.concat([pd.read_csv(f) for f in sorted(glob.glob(os.path.join(data_dir, 'finish_2026-*.csv')))],
                     ignore_index=True)
    odds_map = {rid: dict(zip(g['combo'], g['odds'])) for rid, g in odds.groupby('race_id')}
    pay_map = {}
    for r in pays.itertuples():
        pay_map.setdefault(r.race_id, {})[r.combo] = r.amount
    fin_piv = fin.pivot_table(index='race_id', columns='rank', values='lane', aggfunc='first')
    finish_map = {rid: f"{int(row[1])}-{int(row[2])}-{int(row[3])}"
                  for rid, row in fin_piv.iterrows()
                  if not (np.isnan(row.get(1, np.nan)) or np.isnan(row.get(2, np.nan))
                          or np.isnan(row.get(3, np.nan)))}
    return {'odds': odds_map, 'pay': pay_map, 'finish': finish_map}


def payout_for(market, rid, combo):
    if rid in market['pay'] and combo in market['pay'][rid]:
        return market['pay'][rid][combo]
    o = market['odds'].get(rid, {}).get(combo)
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


def build_race_table(preds, model_cols, market):
    """レース単位の戦略別cost/payout + rank1枠番 + 勝者枠番のテーブルを構築。

    preds: race_id, lane, 確率列(model_cols)を持つDataFrame(1行=1艇)
    返り値: 1行=1レースのDataFrame
    """
    rows = []
    for rid, grp in preds.groupby('race_id'):
        if rid not in market['finish'] or len(grp) < 4:
            continue
        actual = market['finish'][rid]
        odds_r = market['odds'].get(rid, {})
        rec = {'race_id': rid, 'win_lane': int(actual.split('-')[0])}
        for m in model_cols:
            lanes = grp.sort_values(m, ascending=False)['lane'].astype(int).tolist()
            rec[f'{m}_rank1'] = lanes[0]
            for s, combos in strategies_for(lanes, odds_r).items():
                rec[f'{m}_{s}_cost'] = len(combos) * 100
                rec[f'{m}_{s}_pay']  = payout_for(market, rid, actual) if actual in combos else 0
        rows.append(rec)
    return pd.DataFrame(rows)


def evaluate_gate(race_df, cand, base='p_v2', n_boot=5000, seed=42):
    """候補モデルcandをベースラインbaseに対してROI-CVゲート判定する。

    返り値dict:
      strategies: {戦略: {roi_cand, roi_base, diff, p_ge, hard_pass, soft_warn}}
      lane1_rate, lane1_win_rate, lane1_pass, passed(総合)
    """
    rng = np.random.default_rng(seed)
    n = len(race_df)
    out = {'strategies': {}}

    cost = {(m, s): race_df[f'{m}_{s}_cost'].values for m in (cand, base) for s in STRATS}
    pay  = {(m, s): race_df[f'{m}_{s}_pay'].values for m in (cand, base) for s in STRATS}

    ge_counts = {s: 0 for s in STRATS}
    for _ in range(n_boot):
        idx = rng.integers(0, n, n)
        for s in STRATS:
            rc = pay[(cand, s)][idx].sum() / max(1, cost[(cand, s)][idx].sum())
            rb = pay[(base, s)][idx].sum() / max(1, cost[(base, s)][idx].sum())
            if rc >= rb:
                ge_counts[s] += 1

    hard_all = True
    for s in STRATS:
        roi_c = pay[(cand, s)].sum() / max(1, cost[(cand, s)].sum()) * 100
        roi_b = pay[(base, s)].sum() / max(1, cost[(base, s)].sum()) * 100
        p_ge  = ge_counts[s] / n_boot
        hard  = p_ge >= P_THRESHOLD
        hard_all &= hard
        out['strategies'][s] = {
            'roi_cand': roi_c, 'roi_base': roi_b, 'diff': roi_c - roi_b,
            'p_ge': p_ge, 'hard_pass': hard,
            'soft_warn': (roi_c - roi_b) < -SOFT_WARN_PT,
        }

    lane1_rate     = (race_df[f'{cand}_rank1'] == 1).mean()
    lane1_win_rate = (race_df['win_lane'] == 1).mean()
    lane1_warn     = lane1_rate > lane1_win_rate + LANE1_MARGIN
    out.update({'lane1_rate': lane1_rate, 'lane1_win_rate': lane1_win_rate,
                'lane1_warn': lane1_warn, 'passed': hard_all})
    return out


def print_gate_report(result, label):
    status = '合格' if result['passed'] else '不合格'
    print(f"\n--- ROI-CVゲート判定 [{label}] : {status} ---")
    print(f"  {'戦略':10s} {'候補ROI':>8s} {'v2 ROI':>8s} {'差':>7s} {'P(>=v2)':>8s}  判定")
    for s in STRATS:
        r = result['strategies'][s]
        mark = 'OK' if r['hard_pass'] else 'NG'
        warn = ' (soft警告: -3pt超)' if r['soft_warn'] and r['hard_pass'] else ''
        print(f"  {NAMES[s]:10s} {r['roi_cand']:7.1f}% {r['roi_base']:7.1f}% "
              f"{r['diff']:+6.1f}pt {r['p_ge']:8.3f}  {mark}{warn}")
    l_mark = 'soft警告' if result['lane1_warn'] else 'OK'
    print(f"  本命寄り度: 1号艇rank1率 {result['lane1_rate']*100:.1f}% "
          f"(実勝率 {result['lane1_win_rate']*100:.1f}% + {LANE1_MARGIN*100:.0f}pt 超で警告) {l_mark}")
