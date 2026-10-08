# v3w 本番切り替え(全4戦略 + 予測表示)と切り戻し手順

作成日: 2026-10-01
根拠: `design_v2_true_performance_20260930.md`(リークなし v2 49.2% < 1号艇 55.1% < v3w 57.0%)
切り替えコミット: 41bd58e(2026-10-01 01:07 JST デプロイ、CI 70 tests 合格)

## 1. 何を変えたか

### 1.1 切り替えスイッチ(`model_switch.php`)

| 定数 | 切り替え後 | 切り替え前 | 役割 |
|---|---|---|---|
| `STRATEGY_MODEL_MAP` | 4戦略すべて `'v3w'`(**2026-10-08 から一撃重視のみ `'v2'`**、`design_v3w_postswitch_20261008.md` §6) | すべて `'v2'` | 買い目生成に使う予測 |
| `STRATEGY_MODEL_FALLBACK` | `'v2'` | `'v2'` | v3w 予測が無いレースの代替(model_ref='v2' で記録) |
| `PREDICTION_DISPLAY_MODEL` | `'v3w'` | (新設。旧挙動= `'v2'`) | 予測順位・確率・AI解説の表示に使う予測 |
| `V3W_PREDICTIONS_FROM` | `2026-09-13` | ─ | predictions_v2 がv3wを持つ最初の日。より前は旧v3・旧v2シャドウなので表示は v2 |

`STRATEGY_MODEL_MAP` は generate_strategies.php から model_switch.php に移動した(同じ定数名)。

### 1.2 v3w 予測の生成経路(定数だけでは切り替わらなかった点)

旧構成では v3w 予測(predictions_v2)は `api_v3_shadow.php` が**結果取込みの後**に作るだけだった。
買い目の事前生成(21:30バッチ)や閲覧時の `api_predict.php` の時点では v3w が存在せず、
定数を 'v3w' にしても**全レース v2 にフォールバック**していた。そのため:

- `api_predict.php` がレースごとに v3w も計算し predictions_v2 に保存する(`predict_v3_lib.php`、
  入力は api_v3_shadow.php と同一・レース日より前のデータのみ)。**v2 も従来どおり predictions に保存し続ける。**
- 保存後に `generate_and_save_strategies()` が STRATEGY_MODEL_MAP どおり v3w で買い目を作る。
- 結果確定済みレースでは v2・v3w とも保存しない(9/30 のリーク修正と同じガード)。
- `api_v3_shadow.php` は未生成レースの補完に役割変更。boatrace.yml で**結果取込みの前**に移動し、
  20:00前日バッチでも実行する(21:30失敗日の補完。10/08 に変更、§2.2)。確定済みレースには書かない。

### 1.3 画面表示

| 画面 | API | 変更 |
|---|---|---|
| predict.php / races.php / racelist.php / ホーム(home-races.js) | api_predict.php | predicted_rank・score_total(=1着確率×100)を表示モデルの値に。応答に `model` を追加 |
| ai-predict.php / predictions.php / 成績ページのスコア内訳 | get_prediction.php | 同上(スコア内訳 score_ability 等は従来の v1 内訳のまま) |
| ai-predict.php のAI解説 | gemini_explain.php | 表示モデルの順位で解説を生成。キャッシュは表示モデルのテーブル(v3w=predictions_v2 に `explanation` / `explanation_personal` 列を自動追加)に分けて保存 |
| performance.php | (strategy_results) | 集計期間の説明文を「09-30 のレースから v3w」に更新 |

### 1.4 監視

`model_switch_status.php?api_key=...&from=...&to=...&format=json`(2026-10-08〜 format なしはHTML表示)(読み取り専用): 日別の v2 / v3w 予測保存レース数、
strategies と strategy_results の model_ref 別件数・的中率・回収率、現在の切り替え定数。

## 2. 切り替え後の確認結果

| # | 確認項目 | 結果 |
|---|---|---|
| 1 | 本番で予測が生成される | ✓ 9/30 丸亀1R(結果未取込)で api_predict.php → HTTP 200・0.19秒、`model=v3w`。predictions(v2)・predictions_v2(v3w)とも保存 |
| 2 | 4戦略の買い目が model_ref='v3w' で保存 | ✓ 同レースの strategies 4行が model_ref='v3w'(監視APIで v2:56→55 / v3w:0→1)。strategy_results は §2.1 |
| 3a | 予測順位・確率 | ✓ get_prediction.php `model=v3w`、1位=2号艇(26.83%)、確率合計100.0%。ai-predict.php の画面でも同順(ブラウザ確認) |
| 3b | 確定済み・過去レースの表示 | ✓ 9/29(確定済み)は保存済み v3w、9/05(v3w開始前)は v2 にフォールバック |
| 3c | AI解説 | ✓ Premium ログイン状態で ai-predict.php を表示し、v3w の1位(2号艇 守屋大地選手)を軸にした解説が生成された |
| 3d | 成績ページ・主要ページ | ✓ index / races / predict / ai-predict / predictions / performance / strategy / racelist と主要APIが HTTP 200・PHPエラーなし、performance.php の集計表示・コンソールエラーなし |
| 4 | 20:00 / 21:30 バッチ | §2.1 |

### 2.1 バッチ(2026-10-08 確認、9/30〜10/07 の8日分)

GitHub Actions: 9/30 16:35Z 以降の結果取込みバッチ16回のうち15回成功、1回(10/05 20:39Z = 10/05 の21:30速報バッチ)は
ジョブのステップが1つも実行されないまま15分でキャンセル(ランナー側の中断。コード起因ではない)。

21:30速報バッチの例(10/07): 事前生成 2分21秒 → v3w補完 `races_saved=144, errors=0` → 結果取込み → CSV出力、全ステップ成功。
20:00前日バッチ(10/07 の例): 事前生成・v3w補完はスキップ(当時の設定どおり)→ 結果取込み成功。

`model_switch_status.php`(9/29〜10/07):

| 日付 | レース | v2予測 | v3w予測 | 確定 | strategies model_ref | strategy_results model_ref |
|---|---|---|---|---|---|---|
| 09-29 | 144 | 144 | 144 | 144 | v2: 576 | v2: 576(切り替え前) |
| **09-30** | 144 | 144 | 144 | 144 | **v3w: 576** | **v3w: 576** |
| 10-01 | 168 | 168 | 168 | 168 | v3w: 672 | v3w: 672 |
| 10-02 | 168 | 168 | 168 | 168 | v3w: 672 | v3w: 672 |
| 10-03 | 156 | 156 | 156 | 156 | v3w: 624 | v3w: 624 |
| 10-04 | 156 | 156 | 156 | 156 | v3w: 624 | v3w: 624 |
| **10-05** | 144 | **0** | **0** | 144 | **なし** | **なし** |
| 10-06 | 156 | 156 | 156 | 156 | v3w: 624 | v3w: 624 |
| 10-07 | 144 | 144 | 144 | 144 | v3w: 576 | v3w: 576 |

- 10/05 を除き、全レースで v2・v3w の予測が保存され、全買い目が model_ref='v3w'(フォールバック 0件)、
  清算(import_results.php)も model_ref='v3w' で転記されている。
- 9/30 は利用者閲覧で先に作られていた v2 の買い目 56R も、21:30 事前生成(結果取込み前)で v3w に作り直された。

### 2.2 不具合: 10/05 が予測・買い目なし(修正済み・データは未補完)

原因: 10/05 の21:30速報バッチがキャンセル → 事前生成も結果取込みも実行されず。翌日の20:00前日バッチが
10/05 の結果を取り込んだが、**9/30 のリーク修正で20:00バッチの事前生成をスキップにしていた**ため
結果取込み前の補完が行われなかった。結果取込み後は確定済みガードにより生成されない。この日は利用者の閲覧も
無く(閲覧があればその時点で予測・買い目が保存される)、144R すべてが空のまま残った。

修正(本コミット): boatrace.yml の20:00前日バッチでも事前生成と v3w 補完を実行する。確定済みレースは
api_predict.php / api_v3_shadow.php のガードで書き換わらないため、リーク修正の効果は維持され、21:30 が
失敗した日だけ結果取込み前に補完される(旧来の20:00バッチと同じ補完経路)。

10/05 のデータ(未対応・判断待ち):
- (a) **空のまま残す**: 成績集計から自然に除外される(strategy_results が無い)。推奨。
- (b) 補完する: 特徴量はレース日より前のデータのみで作られるため結果の混入はないが、確定済みレースへの
  書き込みはガードの方針に反するため、管理者専用の一回限りスクリプトで明示的に行う必要がある。

## 3. 切り戻し手順

### 3.1 通常の切り戻し(定数のみ、数分)

v2・v3w の予測は api_predict.php が常に両方保存しているため、定数を戻せば次の生成から v2 に戻る。

1. `model_switch.php` を編集:
   ```php
   const STRATEGY_MODEL_MAP = [
       '的中特化' => 'v2',
       'バランス' => 'v2',
       '一撃重視' => 'v2',
       '絞り込み' => 'v2',
   ];
   const PREDICTION_DISPLAY_MODEL = 'v2';
   ```
   (戦略だけ、表示だけの部分切り戻しも可。戦略単位で 'v2' に戻すこともできる)
2. `vendor/bin/phpunit`(テストは切り替え定数の値を固定していないので 'v2' でも全件通る。確認済み)
3. commit → push(main への push で deploy.yml がテスト→FTPデプロイ)
4. 確認: `model_switch_status.php` の `config` が v2 になっていること。以後に生成されたレースの
   strategies が model_ref='v2' になること。

効果の範囲:
- **未確定レース**: 次に api_predict.php が呼ばれた時点(閲覧・21:30事前生成)で v2 の買い目に作り直される。
- **確定済みレース**: 買い目は作り直されない(リーク修正のガード)。v3w で清算済みの strategy_results は
  model_ref='v3w' のまま残り、期間集計で区別できる。
- **表示**: 定数を戻した時点から全レース v2 の順位・確率。AI解説は v2 用キャッシュ(predictions)を使う。

### 3.2 コードごと切り替え前に戻す場合

定数だけでは解決しない不具合(v3w 計算の例外で api_predict.php が落ちる等)のとき:

```
git revert 41bd58e    # 切り替えコミット(本メモ・表示文言のコミットも必要に応じて revert)
```

- revert 後は api_predict.php が v3w を計算・保存しなくなり、predictions_v2 は 21:30 バッチ
  (api_v3_shadow.php)だけが書く旧構成に戻る。
- 注意: revert すると api_v3_shadow.php の「確定済みレースに書かない」ガードも外れる
  (シャドウ用途に戻るので実害はない)。9/30 のリーク修正(b5aba40)は別コミットなので影響しない。
- predictions_v2 に追加された `explanation` / `explanation_personal` 列は残るが、旧コードは参照しない。

### 3.3 判断の目安

- `model_switch_status.php` で strategies の model_ref に 'v2' が多い日 → v3w 生成が失敗しフォールバックしている
  (api_predict.php の v3w 計算か predictions_v2 保存の失敗)。表示は v2 に戻るだけで壊れないが原因調査。
- v3w の回収率が悪い、という理由での切り戻しは、リーク込みの過去表示値ではなく
  `sim_v2_clean.py` と同じリークなし同士の比較で判断すること(v2 のリークなし実力は 75〜84%)。

## 4. 留保・既知の点

- 9/30 のレースは、利用者の閲覧で先に v2 の買い目が作られた 56R も含め、21:30 事前生成(結果取込み前)で
  v3w に作り直される。したがって **戦略の切り替えは 9/30 のレースから**(performance.php の説明文もそう記載)。
- 過去レース(〜9/29)の strategies / strategy_results は v2(model_ref='v2')のまま。表示の予測順位だけが
  9/13 以降 v3w になるため、過去レースでは「表示の1位」と「当時の買い目の軸」が一致しない場合がある。
- 成績ページの 8/19〜9/28 の値がリーク込みである点は未解消(`design_leak_fix_20260930.md` §4)。
- 絞り込みは v3w がリークなし比較で約 −3pt(有意ではない)。`design_v2_true_performance_20260930.md` §5 の
  推奨では v2 維持としていたが、今回は全戦略 v3w で運用する。必要なら '絞り込み' だけ 'v2' に戻せる。
