# api_predict.php 先読みリーク修正と影響範囲 (2026-09-30)

作成日: 2026-09-30
発端: `design_v3w_final_20260930.md` §1(本番v2予測が結果取込後に書き換えられていた)
修正コミット: b5aba40(本番デプロイ済み・CI 59テスト合格)
影響調査: `audit_leak_rewrites.php`(読み取り専用、本番DB実測。生データ `audit_leak_rewrites_20260930.json`)

> 本書の復元方針(§4)は**方針のみで、実際の復元は行っていない**。

## 1. 修正内容

| 対象 | 問題 | 修正 |
|---|---|---|
| `api_predict.php` 地元成績・コース別成績 | 期間下限(2年前)のみ。結果取込後の実行で当該レースと同日以降の着順が混入 | `prediction_guard_lib.php` の `fetch_local_stats` / `fetch_course_stats` に置換。窓は **[レース日−2年, レース日)**(`api_v3_shadow.php`・`export_lr_data_v3.php` と同じ境界) |
| `api_predict.php` 保存 | 結果確定済みでも predictions を upsert、続けて strategies を再生成 | `persist_predictions()` が確定済みレースでは保存せず false。その場合は買い目も再生成せず、応答はレース前の保存済み予測で表示(`overlay_stored_predictions`) |
| `generate_strategies.php` `generate_and_save_strategies()` | 確定済みレースでも strategies を upsert(単独エンドポイント `?race_id=` からも) | 冒頭で `race_is_settled()` なら何も書かず `[]` を返す |
| `boatrace.yml` 20:00前日バッチ | 前日分の `generate_strategies_for_date.py` が結果取込後に全レースを再実行 | **20:00スケジュールでは事前生成ステップをスキップ**(前日分は21:30当日バッチで結果取込前に生成済み)。手動実行(リカバリ)は従来どおり。結果取込み・v3シャドウ・CSV出力は変更なし <br>**2026-10-08 撤回**: スキップすると21:30バッチ失敗日の補完経路が無くなる(10-05で全144Rが買い目なし)。api_predict.php のガードで確定済みレースは書き換わらないため、20:00でも事前生成を実行する形に戻した(`design_v3w_switch_20261001.md` §2.2) |
| `get_prediction.php`(Premiumスコア内訳) | 同種の上限なしクエリ | 同じヘルパーに置換 |
| `get_racelist.php`(出走表の当地勝率・2連率) | 同種の上限なしクエリ | `rc2.date < レース日` を追加 |
| `api_v2_batch.php`(旧v2シャドウ、現在未使用) | 下限のみ+下限が「実行日−2年」 | [対象日−2年, 対象日) に修正。※現在は呼ばれていないが、呼ばれると v3wシャドウの predictions_v2 を上書きする点に注意 |

`strategy_results` は api_predict.php からは書かれない。上書きは「strategies 再生成 → 直後の
`import_results.php` 再清算(upsert)」経由で起きていたため、strategies を保護すれば再清算は同じ値になる
(払戻訂正などによる正当な再清算は従来どおり可能)。

「結果確定済み」= `results` に着順(actual_rank)が1件以上あるレース。当日レースの結果取込みは
21:30バッチのみで、日中の `scrape_live.py` は結果を取り込まないため、21:30の事前生成(結果取込み前)は
ガードに阻まれない。

### 1.1 確認した範囲(依頼1)

- `api_predict.php` 全クエリ: races / entries(当該レースのみ)、player_periods、results×2(上記で修正)。
  他に期間下限のみのクエリはなし。
- `predict_v3_core.php`: DBクエリは `save_shadow_predictions()` の INSERT のみで、集計クエリなし。
  特徴量の集計は `api_v3_shadow.php` 側で、既に全て `rc.date >= ? AND rc.date < ?`(問題なし)。
- 全PHPを横断検索: 上表の3ファイルを追加修正。`get_analysis_venue.php`(場別の全期間傾向)と
  `get_stats.php`(選手の直近6ヶ月・今期成績)は「現在時点」の集計でレース日基準ではないため対象外。
- **残課題(未修正)**: `player_periods` は api_predict / api_v3_shadow / get_prediction / get_racelist
  とも「最新期」を無条件に取る。当日レースでは正しいが、過去レースを再計算すると後の期のデータを使う。
  今回のガードで過去レースの再計算は保存されなくなったため実害は表示に限られる。as-of化するなら
  `export_lr_data_v3.php` の公開日ルール(period=2→5/1、period=1→11/1)に合わせる。

## 2. テスト (`tests/LeakGuardTest.php`、SQLiteインメモリ)

| 検証 | テスト |
|---|---|
| 当日・翌日・他場・2年超の結果が当地成績に入らない | `test_local_stats_use_only_days_before_race` |
| 当日・未来・別枠の結果が枠番別成績に入らない | `test_course_stats_use_only_days_before_race` |
| 結果取込み前後で特徴量が不変(リークの本質) | `test_features_identical_before_and_after_result_import` |
| 未確定レースは保存・更新される / 確定済みは上書きも新規作成もされない | `test_persist_predictions_*`(3件) |
| 未確定は買い目生成される / 確定済みは再生成も新規作成もされない | `test_strategies_*`(3件) |
| 確定済みの表示は保存済み予測を優先(欠損時は計算値) | `test_overlay_*`(2件) |
| 静的: results の日付下限クエリには同数の上限がある / api_predict は persist 経由でのみ保存 | `test_results_date_filters_have_upper_bound` ほか |

各ガード・上限を外すとそれぞれ該当テストが失敗することを確認済み(ミューテーション確認)。
本番DBのMySQL方言(ON DUPLICATE KEY UPDATE 等)はテスト内の `MysqlCompatPdo` がSQLite構文へ読み替える。
全体 59 tests / 343 assertions 合格(ローカル PHP 8.1 と CI の両方)。

本番確認: デプロイ後に結果確定済みの 09-29 戸田1R へ api_predict.php を呼び、正常応答かつ
09-29 の書き換え件数が 0 のままであることを監査エンドポイントで確認。

## 3. 影響範囲(依頼3)

判定: 各レースの `predictions.created_at`(upsertのたびに更新)の最大値が、`results.created_at`
(初回取込み時刻。upsertでは不変)の最小値より後 → 結果を知った状態で上書きされたレース。
strategies / strategy_results は created_at が upsert で更新されないため時刻では直接判定できず、
同じ api_predict.php 呼び出しで再生成され、その後の結果取込みで再清算されたものとして同レースの行を数える。

### 3.1 集計(2026-06-01〜09-30)

| 月 | 確定レース | 予測ありレース | **書き換えレース** | strategies行 | strategy_results行 | v2 top1(書換) | v2 top1(未書換) |
|---|---|---|---|---|---|---|---|
| 2026-06 | 2,581 | 152 | 0 | 0 | 0 | ─ | 39.5% |
| 2026-07 | 4,616 | 3,290 | 266 | 1,064 | 1,064 | 67.7% | 48.2% |
| 2026-08 | 4,607 | 4,438 | 1,812 | 7,248 | 7,248 | 56.3% | 50.0% |
| 2026-09 | 4,453 | 4,448 | 4,004 | 16,016 | 16,004 | 61.7% | 50.0% |
| **計** | 16,257 | 12,328 | **6,082** | **24,328** | **24,316** | **60.4%** | **48.9%** |

predictions は1レース6行のため**約3.6万行**が対象。書き換えレースの的中率(60.4%)と未書換(48.9%)の
11.5pt差はリークの効果そのもの。

### 3.2 日付範囲

| 区分 | 日付 | レース数 | 原因 |
|---|---|---|---|
| 散発(手動・閲覧) | 07-01(36), 07-03(24), 07-04(12), 07-05(24), 07-09(1), 07-13(1), 07-14(156), 07-21(12) | 266 | 過去レースの閲覧・手動実行。07-14は07-15に全レース一括 |
| **バッチ(恒常)** | **08-19, 08-21〜09-04, 09-06〜09-07, 09-09〜09-28** | **5,816** | 20:00前日バッチ(08-19に21:30当日バッチが追加され、前日分が結果取込み済みの状態で再生成されるようになった) |
| 書き換えなし | 08-20, 09-05, 09-08, **09-29** | ─ | 20:00バッチが到達しなかった日。09-29は修正デプロイが間に合った |

- 09-09〜09-15 は 09-18 03時台の手動一括実行(CSVバックフィル)で書き換え。
- 08-26・08-27 は 09-02 にも再書き換えあり(最終書き込みで判定)。
- 監査で見えない影響: **v2シャドウ(07-28〜08-27)の predictions_v2** も、当時 `api_v2_batch.php` が
  結果取込み直後に上限なしで実行されていたためリーク込みだった可能性が高い(v2昇格判定の根拠)。
  predictions_v2 は 09-04 から v3シャドウに転用・上書きされており、DB上では検証も復元もできない。

## 4. 復元方針(依頼3。未実施)

### 4.1 前提

- 実行前に3テーブルをバックアップ(`CREATE TABLE predictions_bk_20260930 AS SELECT * FROM predictions` 等)。
- 対象は §3 の書き換えレース(監査SQLの条件で抽出)に限定し、未書換レースには触れない。
- 復元スクリプトは結果確定済みレースへの書き込みを意図的に行うため、api_predict.php のガードを迂回する
  **管理者専用・dry-run既定**の一回限りツールとして別途用意する(api_key必須、差分を出力してから適用)。

### 4.2 方法A: CSV旧コミットから復元(09-17〜09-28、1,854 / 1,873レース)

`data/lr_v3_{日付}.csv` は同日2回コミットされ、**初回版(21:30当日バッチ、翌日01〜05時)は書き換え前**
(各日の書き換え時刻より前であることを確認済み。初回版のv2 top1は50.9%でクリーン値と一致)。

| 日付 | 初回版コミット | 復元可能レース |
|---|---|---|
| 09-17 | 39f644a | 177 |
| 09-18 | 5675a5e | 179 |
| 09-19 | e3c6097 | 154 |
| 09-20 | b4e059e | 165 |
| 09-21 | 4ae9929 | 120 |
| 09-22 | 460dcef | 154 |
| 09-23 | 78a7e68 | 153 |
| 09-24 | 41eb268 | 155 |
| 09-25 | f6b97d8 | 143 |
| 09-26 | 30e38e0 | 156 |
| 09-27 | b3e0800 | 154 |
| 09-28 | 8f47c1f | 144 |

手順:
1. `git show <初回版>:data/lr_v3_<日付>.csv` の `score_total_current`(=本番 predictions.score_total、
   v2確率×100)を復元値とし、predicted_rank はスコア降順(同値は枠番順。api_predict.php と同じ)で再付与。
2. score_ability / course / today / weather(表示用のv1内訳)はCSVにないため、§4.3の再計算値で埋める。
3. strategies: 復元した確率から `build_strategies_hybrid()` で再生成(現行 STRATEGY_MODEL_MAP=全v2)。
   オッズは odds_3t の現在値(当時の21:30生成時点で直前情報バッチが取得済みのほぼ確定オッズのため差は小さい)。
4. strategy_results: `settlement_lib.php` の `calc_settlement()` で再清算(`backfill_payout.php` と同じ経路)。
5. 検証: 復元後の日別v2 top1 が50%前後(§1.2の初回版値)になること。

CSVに載っていない19レースは方法Bで補う。09-16 は2版とも書き換え後(top1 69.2%)のため方法B。

### 4.3 方法B: 修正後ロジックで再計算(上記以外の約4,230レース)

08-19〜09-16 と7月の散発分はクリーンなスナップショットがない(ルート直下の `lr_v3_*.csv` は09-04出力で
書き換え後)。修正後の api_predict.php と同じ特徴量計算(`fetch_local_stats` / `fetch_course_stats`、
レース日より前のみ)で v2 を再計算して predictions を置き換え、4.2の手順3〜4で strategies /
strategy_results を作り直す。

- 再現性の検証を先に行う: 方法Aの 09-17〜09-28 で「再計算値」と「CSV初回版」の1位予測一致率・
  確率差を測り、十分一致(目安: 1位一致95%以上)してから方法Bを適用する。
- 完全一致しない要因: player_periods が最新期(§1.1残課題。as-ofにすれば解消)、entries の再スクレイプ、
  気象値の更新。いずれも結果を含まないため、再計算値はリークのない推定値として扱える。
- 07-14 以前の散発分(予測ありレースが少ない期間)は、戦略実績の集計対象にするかどうかも含めて判断する。

### 4.4 復元しない選択肢

表示用の集計(§5)から 08-19〜09-28 を除外する、または注記する運用も可能。ただし strategy_results の
累計値(全期間ROI等)は書き換え分を含むため、除外する場合も期間フィルタの実装が要る。

## 5. リーク込みの値を表示している画面(依頼4)

期間に 07-01〜09-28 の書き換え日を含むと、以下は水増しされた値を表示する。

| 画面 | API | 参照 | 表示値 | 備考 |
|---|---|---|---|---|
| performance.php(成績・回収率) | get_performance_summary / daily / races / venue | strategy_results | 戦略別の的中率・回収率・日別推移・レース一覧・場別 | 無料公開のサマリー含む。**影響最大** |
| performance.php(会場横断比較) | get_dashboard_comparison | strategy_results | 全会場・全期間の戦略別成績 | Premium |
| strategy.php / ai-predict.php | strategy_stats | strategy_results | 戦略別の的中率・回収率(期間指定) | 期間未指定時は全期間 |
| my-picks.php「戦略通りに買っていたら」 | get_user_picks_strategy_compare | strategy_results | ユーザー購入との比較 | Premium |
| トップ「今日の的中」 | get_hits(home-races.js) | strategy_results | 的中一覧 | 当日分は21:30時点でクリーン。date指定で過去日を見ると書き換え後 |
| ai-predict.php / predictions.php | get_prediction / api_predict | predictions | 過去レースのAI予測順位・スコア | 書き換え後の保存値。修正後は保存値を表示するため、復元で正しくなる |
| ai-predict.php(AI解説) | gemini_explain | predictions | 予測順位に基づく解説文 | 過去レースで書き換え後に生成されたもの |
| admin.php | analyze_ichigeki / simulate_balance / simulate_ichigeki / admin_v2 | strategy_results / predictions | 管理用分析・v3wシャドウ進捗(v2 top1) | 管理者のみ |
| (API) shadow_eval_v3.php | ─ | predictions / strategy_results | v2 top1・本番戦略実績 | 昇格判定に使用(`design_v3w_final_20260930.md` はクリーン比較で判定済み) |

修正済みで今後は正しくなるもの(過去の保存値には依存しない):
- get_prediction.php の Premium スコア内訳(当地勝率・コース別成績)
- get_racelist.php の出走表(当地勝率・当地2連率)

## 付録

- 修正: `prediction_guard_lib.php`(新規)、`api_predict.php`、`generate_strategies.php`、
  `get_prediction.php`、`get_racelist.php`、`api_v2_batch.php`、`.github/workflows/boatrace.yml`
- テスト: `tests/LeakGuardTest.php`(CIの setup-php に `pdo_sqlite` を明示)
- 監査: `audit_leak_rewrites.php?api_key=...&from=...&to=...`(読み取り専用)、
  生データ `audit_leak_rewrites_20260930.json`(2026-09-30 修正デプロイ後に取得)
