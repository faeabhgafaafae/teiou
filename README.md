# 艇王 BOATRACE ROYAL

ボートレースの出走データをリアルタイムで収集し、機械学習モデルで1着確率を推定して**3連単の買い目と賭け金配分を自動生成**するWebサービスです。

---

## 目次

1. [プロジェクト概要](#プロジェクト概要)
2. [技術スタック](#技術スタック)
3. [アーキテクチャ](#アーキテクチャ)
4. [主要機能](#主要機能)
5. [予測モデルの変遷](#予測モデルの変遷)
6. [デプロイ構成](#デプロイ構成)
7. [自動化ジョブ一覧](#自動化ジョブ一覧)
8. [GitHub Secrets](#github-secrets)

---

## プロジェクト概要

ボートレースは全国24場で毎日開催される公営競技です。1レース6艇が出走し、**3連単**（1〜3着の着順通りの予想）は最大120通りの組み合わせがあります。

艇王は以下を自動化します。

- **データ収集**: boatrace.jp・mbrace.or.jp から出走表・直前情報・オッズ・レース結果を自動スクレイピング
- **AI予測**: 選手成績・コース別実績・展示タイム・モーター性能・気象データをロジスティック回帰モデルに入力し、各艇の1着確率を算出
- **買い目生成**: Harville式確率（3連単の着順確率）に基づき、4種類の戦略で買い目を自動生成
- **賭け金配分**: AIの予測確率に比例した傾斜配分（BOATERS方式、1点最低100円保証）
- **成績分析**: 回収率・的中率の累積集計、レーサー別・会場別統計

---

## 技術スタック

| 領域 | 技術 |
|---|---|
| バックエンド | PHP 8.x (手続き型 + クラス) |
| データベース | MySQL 8 (utf8mb4) |
| フロントエンド | Vanilla JavaScript / CSS (フレームワーク不使用) |
| データ収集 | Python 3.12 + requests + beautifulsoup4 |
| CI/CD | GitHub Actions (FTP デプロイ + スケジュール実行) |
| ホスティング | ロリポップ! (共用レンタルサーバー) |
| 機械学習 | Python scikit-learn (ロジスティック回帰) / 係数は PHP に埋め込み |

---

## アーキテクチャ

```
外部データソース
  boatrace.jp  ──────────────────┐
  mbrace.or.jp ──────────────────┤
                                  │  スクレイピング (Python)
                                  ▼
                         ┌─────────────────┐
                         │  GitHub Actions  │  boatrace.yml / live.yml
                         │  (Ubuntu runner) │  (スケジュール・外部トリガー)
                         └────────┬────────┘
                                  │ HTTP POST (JSON)
                                  ▼
                         ┌─────────────────┐
                         │   PHP API 群     │  import_racelist.php
                         │  (ロリポップ)    │  import_beforeinfo.php
                         └────────┬────────┘  import_results.php 等
                                  │ PDO
                                  ▼
                         ┌─────────────────┐
                         │    MySQL DB      │  races / entries / results
                         │   (ロリポップ)   │  predictions / strategies
                         └────────┬────────┘  odds_3t / players 等
                                  │ PDO
                                  ▼
                         ┌─────────────────┐
                         │  PHP API 群      │  api_predict.php
                         │  (読み出し)      │  generate_strategies.php
                         └────────┬────────┘  strategy_detail.php 等
                                  │ fetch() (相対パス・同一オリジン)
                                  ▼
                         ┌─────────────────┐
                         │  フロントエンド   │  ai-predict.php
                         │  (ブラウザ)      │  strategy.php
                         └─────────────────┘  performance.php 等
```

### 主要テーブル

| テーブル | 内容 |
|---|---|
| `races` | レース基本情報 (日付・会場・レース番号・気象) |
| `entries` | 出走表 (枠番・選手・展示タイム・ST・モーター2連率) |
| `players` | 選手マスタ (登番・氏名・級) |
| `player_periods` | 選手期別成績 (勝率・複勝率・コース別成績) |
| `predictions` | AI予測結果 (1着確率 × 100 を score_total に格納) |
| `odds_3t` | 3連単オッズ (120通り × 全レース) |
| `strategies` | 買い目セット (combinations JSON + stakes JSON) |
| `strategy_results` | 清算結果 (is_hit / cost / payout) |
| `results` | レース結果 (着順・タイム・スタートタイミング・進入コース) |
| `users` | 会員情報 (プラン: free / standard / premium) |

---

## 主要機能

### AI予測

各艇の1着確率をロジスティック回帰モデルで算出します。入力特徴量は以下の11次元です。

- **選手能力**: 全国勝率・全国2連率、当地勝率・当地2連率
- **当日情報**: 展示タイム（レース内相対値）・スタートタイミング・モーター2連率（相対値）
- **気象**: 風速・波高・気温

スコアは100点満点に変換して表示し、枠番・スコア・成績推移をカード形式で一覧します。

### 4戦略の買い目生成

Harville式確率（$P(a \to b \to c) = P_a \cdot \frac{P_b}{1-P_a} \cdot \frac{P_c}{1-P_a-P_b}$）で3連単確率を算出し、上位から買い目を選択します。

| 戦略 | 選定方法 | 点数 |
|---|---|---|
| **的中特化** | 上位4艇の順列24通りからHarville上位6点 | 最大6点 |
| **バランス** | 上位2艇固定 × 上位4艇流し | 最大12点 |
| **一撃重視** | 1位固定・中穴狙い | 最大6点 |
| **絞り込み** | 上位3艇の順列6通りからHarville上位3点 | 最大3点 |

### 傾斜配分（2026年9月9日導入）

1点均等（100円）を廃止し、AIの予測確率に比例した傾斜配分を採用しています。

- **基準予算**: 1点平均600円（例: 的中特化6点 → 合計3,600円）
- **最低保証**: 全買い目に最低100円を保証（的中機会ゼロを防止）
- **配分方式**: 確率比例 + Largest Remainder法で100円単位に整数化
- **定数**: `STAKE_SCHEME='prob'`・`STAKE_UNIT=600`・`STAKE_MIN=100`（1箇所で変更可能）

### 成績分析

- 全期間の的中率・回収率・損益を戦略別に集計
- レーサー別成績・コース別勝率・会場別統計
- プラン別: Free（閲覧のみ）→ Standard（AI予測・個別解説）→ Premium（戦略比較ビュー・スコア内訳）

---

## 予測モデルの変遷

```
v1 (手動スコア)  ──────────────────────────────── 〜 2026-08-27
  ・選手能力 40pt + コース補正 35pt + 当日情報 35pt + 気象 5pt
  ・各要素を手動ルールで点数化し合算
  ・予測順位はスコアの大小順

         ↓ 2026-08-27 本番昇格

v2 (ロジスティック回帰)  ──────────────────────── 2026-08-27〜現在(本番)
  ・特徴量 11次元、scikit-learn で学習
  ・係数・切片を PHP クラス (PredictV2) に埋め込み (DB 不要)
  ・学習日: 2026-07-28  Train期間: 2026-06-29〜07-19
  ・Train 1着的中率 59.4% / Test 60.8%  ROC-AUC(test) 0.8505
  ・出力: 1着確率 × 100 を score_total として predictions テーブルに保存
  ・model_version='v2_lr' で v1 と識別

         ↓ シャドウテスト中

v3 (特徴量拡張版)  ─────────────────────────────── 2026-09-04〜(シャドウ)
  ・集計特徴量（同コース過去成績・対戦成績等）を追加
  ・predictions_v2 テーブルのみに書き込み、本番には不干渉
  ・api_v3_shadow.php が毎夜成績取得後に実行
  ・本番昇格基準: シャドウ期間の的中率・ROC-AUC が v2 を安定して上回ること
```

---

## デプロイ構成

### ワークフロー: `deploy.yml`

`main` ブランチへの push（または手動実行）をトリガーに、ロリポップへ FTP デプロイします。

**デプロイ前処理**: GitHub Secret `CONFIG_PHP_CONTENT` の内容を `config.php` として生成（DB接続情報・APIキーを含むため `.gitignore` 対象）

**アップロード除外パターン** (実際の `deploy.yml` 設定):

```
**/.git*
**/.git*/**
**/.github/**
**/*.py
**/*.yml
**/*.md
```

> `.php` / `.html` / `.js` / `.css` / 画像ファイルはすべてアップロードされます。`auth.php` も除外設定はなく通常デプロイされますが、認証情報は `config.php`（Secret から生成）に分離されています。

### 同時実行制御

```yaml
concurrency:
  group: ftp-deploy
  cancel-in-progress: false   # デプロイは中断しない
```

---

## 自動化ジョブ一覧

### `boatrace.yml` — 定期データ取得

| 実行時刻 (JST) | ジョブ | 内容 |
|---|---|---|
| 毎朝 7:00 | `scrape_beforeinfo` | 全24場の出走表・直前情報（展示タイム・ST・モーター等）を取得しDBへ投入 |
| 毎朝 7:00（並列） | `scrape_odds_a/b/c` | `scrape_beforeinfo` 完了後、当日全レースの3連単オッズを3並列で取得（JCD 01〜08 / 09〜16 / 17〜24） |
| 毎日 14:00（並列） | `scrape_odds_evening_a/b/c` | 夕方〜夜レース分のオッズを再取得（朝時点で未確定のオッズの取りこぼし対策） |
| 毎夜 20:00 | `fetch_results` | **前日分**レース結果を取得・清算（strategy_results に is_hit/cost/payout を記録） |
| 毎夜 21:30 | `fetch_results` | **当日分**レース結果の速報取得・清算 |
| 毎夜 21:30 | `backfill_missed_beforeinfo` | live.yml の取りこぼし分の直前情報を一括補完（boatrace.jp は翌朝まで保持） |

`fetch_results` は結果取得の前に `generate_strategies_for_date.py` を実行して当日全レースの買い目を事前生成します（閲覧されていないレースも漏れなく清算するため）。

また `fetch_results` 完了後に `api_v3_shadow.php` を呼び出し、v3モデルのシャドウ予測を生成します。

### `live.yml` — リアルタイム直前情報・オッズ更新

GitHub Actions の schedule は実際には数時間に1回しか発火しません（throttling）。そのため **cron-job.org** から `workflow_dispatch` で外部トリガーし、実質 **15分間隔**で更新しています。

| 設定 | 値 |
|---|---|
| トリガー | `workflow_dispatch`（cron-job.org 経由、15分間隔） |
| timeout | 15分 |
| 同時実行 | `cancel-in-progress: true`（前のrunが完了しなければキャンセル） |
| 冪等性 | `import_beforeinfo.php` は `COALESCE UPDATE` で既取得分を上書きしない |

### `backfill.yml` — 払戻補正（手動実行のみ）

`is_hit=1` かつ `payout=0` の欠損レコードを `odds_3t` から再取得して修正します。

---

## GitHub Secrets

リポジトリの `Settings > Secrets and variables > Actions` で設定してください。

| Secret名 | 内容 |
|---|---|
| `FTP_HOST` | ロリポップのFTPホスト名 |
| `FTP_USER` | FTPユーザー名 |
| `FTP_PASS` | FTPパスワード |
| `CONFIG_PHP_CONTENT` | 本番用 `config.php` の全文（DB接続情報・APIキーを含む） |
| `API_KEY` | PHP API への内部認証キー |
| `API_URL_RACELIST` | 出走表インポートエンドポイント |
| `API_URL_BEFOREINFO` | 直前情報インポートエンドポイント |
| `API_URL_RESULTS` | 成績インポートエンドポイント |
| `API_URL_ODDS` | オッズインポートエンドポイント |
| `API_PENDING` | 開催中レース一覧エンドポイント |
