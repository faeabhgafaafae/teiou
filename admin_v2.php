<?php
/**
 * admin_v2.php  開発者専用 v2/v3シャドウテスト比較ダッシュボード
 * ユーザー向けには公開しない。is_admin判定を通過した場合のみ表示。
 *
 * 2026-09-04のv3シャドウテスト開始に伴い、比較対象を v1 vs v2 から
 * v2(本番) vs v3(シャドウ) vs 1号艇ベースラインに更新。
 * v2/v3の集計ロジックはshadow_eval_v3.phpの実装をそのまま再利用する
 * (自ホストのAPIをapi_key付きで呼び出し、重複実装を避ける)。
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

// 管理者チェック（users.is_adminで判定。auth.phpのcurrent_user()を使用）
$user = current_user();
if (!$user || !$user['is_admin']) {
    http_response_code(403);
    echo '<p>管理者権限が必要です</p>';
    exit;
}

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// ── v2/v3の集計はshadow_eval_v3.phpを再利用(重複実装を避ける) ──────────
// v3シャドウテストは2026-09-04開始のため、それより前を含めて広めに取得する
$shadow_from = '2026-09-01';
$shadow_to   = date('Y-m-d');
$shadow_url  = 'https://2410049.moo.jp/shadow_eval_v3.php?api_key=' . urlencode(API_KEY)
    . '&from=' . urlencode($shadow_from) . '&to=' . urlencode($shadow_to);

$ch = curl_init($shadow_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
$shadow_json = curl_exec($ch);
curl_close($ch);
$shadow = $shadow_json ? json_decode($shadow_json, true) : null;
$has_shadow = $shadow && empty($shadow['error']);
$daily = $has_shadow ? $shadow['daily'] : [];
krsort($daily);

// ── 1号艇ベースライン(shadow_eval_v3.phpの対象日と揃えて算出) ──────────
$baseline_map = [];
if ($daily) {
    $dates = array_keys($daily);
    $ph = implode(',', array_fill(0, count($dates), '?'));
    $stmt = $pdo->prepare("
        SELECT r.date,
               COUNT(DISTINCT res.race_id)                            AS races,
               SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END)   AS hits
        FROM results res
        JOIN races r ON r.id = res.race_id
        WHERE res.lane = 1 AND r.date IN ($ph)
        GROUP BY r.date
    ");
    $stmt->execute($dates);
    foreach ($stmt->fetchAll() as $row) {
        $baseline_map[$row['date']] = $row;
    }
}
$baseline_total_races = array_sum(array_column($baseline_map, 'races'));
$baseline_total_hits  = array_sum(array_column($baseline_map, 'hits'));

// ── 参考: v1(旧モデル、model_version未設定の過去行)全期間実績 ──────────
$v1_row = $pdo->query("
    SELECT COUNT(DISTINCT p.race_id) AS races,
           SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END) AS hits
    FROM predictions p
    JOIN races r      ON r.id = p.race_id
    JOIN results res  ON res.race_id = p.race_id AND res.player_id = p.player_id
    WHERE p.predicted_rank = 1
      AND p.model_version IS NULL
      AND r.date >= '2026-06-01'
")->fetch();
$v1_total_races = (int)($v1_row['races'] ?? 0);
$v1_total_hits  = (int)($v1_row['hits']  ?? 0);

function pct($hit, $total) {
    if ($total <= 0) return '-';
    return sprintf('%.1f%%', $hit / $total * 100);
}
function diff_class($a, $b) {
    if ($a === null || $b === null) return '';
    return $a > $b ? 'better' : ($a < $b ? 'worse' : '');
}

$v2_total = $has_shadow ? $shadow['top1']['v2'] : ['races' => 0, 'hit_rate' => 0];
$v3_total = $has_shadow ? $shadow['top1']['v3'] : ['races' => 0, 'hit_rate' => 0];

// 昇格基準①: シャドウ1着的中率がv2同期間実績を上回るか(自動判定できる部分のみ)
$criterion1_pass = $has_shadow && $v3_total['races'] > 0 && $v2_total['races'] > 0
    && $v3_total['hit_rate'] > $v2_total['hit_rate'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>艇王 v2/v3シャドウテスト比較</title>
<link rel="stylesheet" href="style.css">
<style>
body { font-family: sans-serif; font-size: 13px; }
.adm-wrap { max-width: 900px; margin: 24px auto; padding: 0 16px; }
h1 { font-size: 18px; font-weight: 700; margin-bottom: 8px; }
h2.sub { font-size: 14px; font-weight: 700; margin: 24px 0 8px; color: #333; }
.summary-cards { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
.scard { background: #f7f8fa; border: 1px solid #e0e3e8; border-radius: 8px; padding: 12px 16px; min-width: 160px; }
.scard-label { font-size: 11px; color: #888; margin-bottom: 4px; }
.scard-val { font-size: 22px; font-weight: 700; color: #0055a4; }
.scard-sub { font-size: 11px; color: #888; }
.scard.v3  { border-color: #0055a4; background: #f0f5ff; }
.scard.baseline { border-color: #718096; }
table { width: 100%; border-collapse: collapse; }
th { background: #f7f8fa; font-size: 11px; font-weight: 700; color: #888; padding: 8px 10px; text-align: center; border-bottom: 2px solid #e0e3e8; white-space: nowrap; }
td { padding: 8px 10px; text-align: center; border-bottom: 1px solid #f0f0f0; }
tr:hover td { background: #fafbfc; }
.better { color: #16a34a; font-weight: 700; }
.worse  { color: #dc2626; }
.no-data { color: #ccc; }
.note { font-size: 11px; color: #888; margin-top: 12px; line-height: 1.6; }
.criteria-box { background: #fffbeb; border: 1px solid #f59e0b; border-left: 4px solid #f59e0b; border-radius: 6px; padding: 10px 14px; margin-bottom: 16px; font-size: 12px; line-height: 1.7; color: #444; }
.criteria-box strong { color: #92400e; }
.criteria-box ul { margin: 4px 0 0 0; padding-left: 18px; }
.criteria-box li { margin-bottom: 2px; }
.pass { color: #16a34a; font-weight: 700; }
.pending { color: #a0724b; font-weight: 700; }
.reference-box { background: #f7f8fa; border: 1px solid #e0e3e8; border-radius: 8px; padding: 12px 16px; margin-top: 24px; }
.reference-box .scard-label { font-weight: 700; color: #555; }
.error-box { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 12px 16px; color: #dc2626; margin-bottom: 16px; }
</style>
</head>
<body>

  <?php include 'header.php'; ?>

<div class="dashboard-container">

  <script>var ACTIVE_NAV = 'admin';</script>
  <?php include 'sidebar.php'; ?>

  <main class="main-content">
  <div class="adm-wrap">
  <h1>🔬 v2/v3 シャドウテスト比較</h1>
  <p class="note">
    v2 = 本番稼働中モデル(ロジスティック回帰、2026-08-27昇格) ／
    v3 = シャドウテスト中モデル(特徴量拡張版、2026-09-04〜) ／
    ベースライン = 1号艇決め打ち
  </p>

  <?php if (!$has_shadow): ?>
  <div class="error-box">
    shadow_eval_v3.php からのデータ取得に失敗しました。対象期間(<?= htmlspecialchars($shadow_from) ?>〜<?= htmlspecialchars($shadow_to) ?>)にレースデータがまだ無い可能性があります。
  </div>
  <?php endif; ?>

  <!-- サマリーカード -->
  <div class="summary-cards">
    <div class="scard v3">
      <div class="scard-label">v3(シャドウ) 1着的中率</div>
      <div class="scard-val"><?= $v3_total['races'] > 0 ? $v3_total['hit_rate'] . '%' : '-' ?></div>
      <div class="scard-sub"><?= $v3_total['races'] > 0 ? round($v3_total['hit_rate'] / 100 * $v3_total['races']) . '/' . $v3_total['races'] . ' レース' : 'データなし' ?></div>
    </div>
    <div class="scard">
      <div class="scard-label">v2(本番) 1着的中率</div>
      <div class="scard-val"><?= $v2_total['races'] > 0 ? $v2_total['hit_rate'] . '%' : '-' ?></div>
      <div class="scard-sub"><?= $v2_total['races'] > 0 ? round($v2_total['hit_rate'] / 100 * $v2_total['races']) . '/' . $v2_total['races'] . ' レース' : 'データなし' ?></div>
    </div>
    <div class="scard baseline">
      <div class="scard-label">1号艇ベースライン</div>
      <div class="scard-val"><?= pct($baseline_total_hits, $baseline_total_races) ?></div>
      <div class="scard-sub"><?= $baseline_total_hits ?>/<?= $baseline_total_races ?> レース</div>
    </div>
  </div>

  <!-- v3 昇格基準(design_v3_model_20260903.md §4.2) -->
  <div class="criteria-box">
    <strong>📋 v3 昇格基準(3つすべて満たすこと)</strong>
    <ul>
      <li>
        ① シャドウ1着的中率がv2同期間実績を上回ること
        — <?php if (!$has_shadow || $v3_total['races'] === 0): ?><span class="pending">判定不可(データ不足)</span>
           <?php elseif ($criterion1_pass): ?><span class="pass">達成(v3 <?= $v3_total['hit_rate'] ?>% &gt; v2 <?= $v2_total['hit_rate'] ?>%)</span>
           <?php else: ?><span class="pending">未達成(v3 <?= $v3_total['hit_rate'] ?>% ≤ v2 <?= $v2_total['hit_rate'] ?>%)</span><?php endif; ?>
      </li>
      <li>② シャドウ1着的中率がオフライン推定値の <strong>±3pt以内</strong> であること(乖離が大きい場合は実装バグ・リーク残りを疑う) — <span class="pending">要手動確認(run_lr_v3.pyのablationレポートと照合)</span></li>
      <li>③ v3順位での戦略シミュレーション(4戦略)が現行実績を悪化させないこと — 下表「戦略KPI比較」参照</li>
    </ul>
  </div>

  <!-- [開発ログ 2026-08-27] v2(LR) 本番昇格判定クリア・切り替え完了
       昇格判定期間: 2026-07-28 〜 2026-08-27 (30日間)
       結果: v2=60.6% / 1号艇ベースライン=54.5% / v1=51.3%
       ※ 2026-09-03のリーク修正調査で、この60.6%はlocal_win_rateの
          先読みリークによる過大評価と判明(design_v3_model_20260903.md §0)。
          リーク修正済み評価では v2=48.4%。以後の評価はv3系(リーク修正済み)を正とする。

     [開発ログ 2026-09-04] v3シャドウテスト開始
       特徴量拡張(枠番one-hot・avg_st・コース別成績・直近10走・grade)。
       predictions_v2テーブルをv3シャドウ書き込み用に転用(v2のシャドウ運用は終了済み)。
       1週間・約1,000レースで昇格判定予定(design_v3_model_20260903.md §4.2)。 -->

  <!-- 日別比較テーブル -->
  <h2 class="sub">日別 1着的中率比較</h2>
  <table>
    <thead>
      <tr>
        <th>日付</th>
        <th>v2 レース数</th>
        <th>v2 1着的中率</th>
        <th>v3 レース数</th>
        <th>v3 1着的中率</th>
        <th>差 (v3-v2)</th>
        <th>ベースライン</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($daily as $d => $row): ?>
      <?php
        $v2_r = (int)($row['v2_races'] ?? 0);
        $v2_h = (int)($row['v2_hits']  ?? 0);
        $v3_r = (int)($row['v3_races'] ?? 0);
        $v3_h = (int)($row['v3_hits']  ?? 0);
        $bl   = $baseline_map[$d] ?? null;
        $bl_r = $bl ? (int)$bl['races'] : 0;
        $bl_h = $bl ? (int)$bl['hits']  : 0;
        $v2_rate = $v2_r > 0 ? $v2_h / $v2_r : null;
        $v3_rate = $v3_r > 0 ? $v3_h / $v3_r : null;
        $diff    = ($v2_rate !== null && $v3_rate !== null) ? $v3_rate - $v2_rate : null;
        $dc      = diff_class($v3_rate, $v2_rate);
      ?>
      <tr>
        <td><?= htmlspecialchars($d) ?></td>
        <td><?= $v2_r ?: '-' ?></td>
        <td><?= pct($v2_h, $v2_r) ?></td>
        <td><?= $v3_r ? $v3_r : '<span class="no-data">-</span>' ?></td>
        <td class="<?= $dc ?>"><?= pct($v3_h, $v3_r) ?></td>
        <td class="<?= $dc ?>">
          <?= $diff !== null ? sprintf('%+.1f pt', $diff * 100) : '<span class="no-data">-</span>' ?>
        </td>
        <td><?= pct($bl_h, $bl_r) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$daily): ?>
      <tr><td colspan="7" class="no-data">データがありません</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <!-- 戦略KPI比較(昇格基準③) -->
  <h2 class="sub">戦略KPI比較(v3順位シミュレーション vs v2本番実績、<?= htmlspecialchars($shadow_from) ?>〜<?= htmlspecialchars($shadow_to) ?>)</h2>
  <table>
    <thead>
      <tr>
        <th>戦略</th>
        <th>v3シミュレーション 的中率</th>
        <th>v3シミュレーション ROI</th>
        <th>v2本番実績 的中率</th>
        <th>v2本番実績 ROI</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($has_shadow): ?>
      <?php foreach (['的中特化', 'バランス', '一撃重視', '絞り込み'] as $name): ?>
        <?php
          $sim  = $shadow['strategy_sim_v3'][$name]  ?? null;
          $prod = $shadow['strategy_prod_v2'][$name] ?? null;
        ?>
        <tr>
          <td><?= htmlspecialchars($name) ?></td>
          <td><?= $sim ? $sim['hit_rate'] . '%' : '<span class="no-data">-</span>' ?></td>
          <td><?= $sim ? $sim['roi'] . '%' : '<span class="no-data">-</span>' ?></td>
          <td><?= $prod ? $prod['hit_rate'] . '%' : '<span class="no-data">-</span>' ?></td>
          <td><?= $prod ? $prod['roi'] . '%' : '<span class="no-data">-</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr><td colspan="5" class="no-data">データがありません</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <p class="note">
    ※ v2/v3の日別・戦略シミュレーション集計は shadow_eval_v3.php のロジックをそのまま利用しています。<br>
    ※ v3の予測は毎晩の日次バッチ経由で predictions_v2 テーブル(シャドウ用に転用)に自動記録されます。<br>
    ※ 結果が未確定のレース(当日中など)は集計対象外になります。<br>
    ※ v2レース数とv3レース数が異なる場合、シャドウバッチが一部スキップしたレースがあります。
  </p>

  <!-- 参考: v1(旧モデル)実績 -->
  <div class="reference-box">
    <div class="scard-label">参考(旧モデル): v1 全期間 1着的中率</div>
    <div class="scard-val" style="font-size:16px;"><?= pct($v1_total_hits, $v1_total_races) ?></div>
    <div class="scard-sub"><?= $v1_total_hits ?>/<?= $v1_total_races ?> レース(2026-06-01〜2026-08-27、v2昇格前の手動スコアモデル。現在の昇格判定には使用しない)</div>
  </div>

  </div>
  </main>

</div>
<script>
// app.js を読み込まないページなので #headerDate・#headerLogo をここで設定する
window.addEventListener('DOMContentLoaded', function() {
  var _d = new Date();
  var _days = ['日','月','火','水','木','金','土'];
  var _dateStr = _d.getFullYear() + '年' + (_d.getMonth()+1) + '月' + _d.getDate() + '日 (' + _days[_d.getDay()] + ')';
  var _headerDateEl = document.getElementById('headerDate');
  if (_headerDateEl) _headerDateEl.textContent = _dateStr;
  var _headerLogoEl = document.getElementById('headerLogo');
  if (_headerLogoEl) _headerLogoEl.addEventListener('click', function() { location.href = 'index.php'; });
});
</script>
</body>
</html>
