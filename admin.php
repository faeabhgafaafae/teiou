<?php
/**
 * admin.php  管理画面トップ(ステータスダッシュボード+ツールリンク一覧)
 *
 * 2026-09-14: リンク一覧のみ→ダッシュボード化。
 *   1. 本日のデータ取得状況(races/entries/odds_updated_atの軽量集計のみ)
 *   2. ジョブ実行状況(GitHub Actions API・公開リポジトリのため未認証。
 *      ページ表示をブロックしないようクライアント側fetchで遅延取得)
 *   3. v3wシャドウテスト進捗(predictions/predictions_v2の直接集計。
 *      shadow_eval_v3.phpはオッズ全件を読むため重く、ここでは使わない)
 *   4. 4戦略の直近7日成績 vs 全期間
 */
require_once __DIR__ . '/auth.php';
$user = current_user();
if (!$user || !$user['is_admin']) {
    http_response_code(403);
    echo '<p>管理者権限が必要です</p>';
    exit;
}

$today = date('Y-m-d');

// v3wシャドウテスト設定(admin_v2.phpと同期)
const SHADOW_START = '2026-09-13';
const SHADOW_JUDGE = '2026-09-27';

$db_error = null;
$data = [
    'races' => 0, 'venues' => 0,
    'exhibit_total' => 0, 'exhibit_filled' => 0,
    'odds_races' => 0, 'before_last' => null, 'odds_last' => null,
    'shadow_races' => 0, 'v2' => ['races' => 0, 'hits' => 0], 'v3w' => ['races' => 0, 'hits' => 0],
    'strat_7d' => [], 'strat_all' => [],
];

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

    // ── 1. 本日のデータ取得状況(すべてrace単位の軽量集計) ──────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS races, COUNT(DISTINCT venue) AS venues,
               SUM(odds_updated_at IS NOT NULL) AS odds_races,
               MAX(before_updated_at) AS before_last,
               MAX(odds_updated_at)   AS odds_last
        FROM races WHERE date = ?");
    $stmt->execute([$today]);
    $r = $stmt->fetch();
    $data['races']       = (int)$r['races'];
    $data['venues']      = (int)$r['venues'];
    $data['odds_races']  = (int)$r['odds_races'];
    $data['before_last'] = $r['before_last'];
    $data['odds_last']   = $r['odds_last'];

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total, SUM(e.exhibit_time IS NOT NULL) AS filled
        FROM entries e JOIN races r ON r.id = e.race_id
        WHERE r.date = ?");
    $stmt->execute([$today]);
    $r = $stmt->fetch();
    $data['exhibit_total']  = (int)$r['total'];
    $data['exhibit_filled'] = (int)$r['filled'];

    // ── 3. v3wシャドウテスト進捗(シャドウ開始日以降のみ) ────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT p.race_id) AS races
        FROM predictions_v2 p JOIN races r ON r.id = p.race_id
        WHERE r.date >= ?");
    $stmt->execute([SHADOW_START]);
    $data['shadow_races'] = (int)$stmt->fetch()['races'];

    // 1着的中率(結果確定レースのみ)。v2=predictions(本番)、v3w=predictions_v2
    foreach (['v2' => 'predictions', 'v3w' => 'predictions_v2'] as $key => $table) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS races, SUM(res.actual_rank = 1) AS hits
            FROM $table p
            JOIN races r     ON r.id = p.race_id
            JOIN results res ON res.race_id = p.race_id AND res.player_id = p.player_id
            WHERE p.predicted_rank = 1 AND r.date >= ?");
        $stmt->execute([SHADOW_START]);
        $r = $stmt->fetch();
        $data[$key] = ['races' => (int)$r['races'], 'hits' => (int)$r['hits']];
    }

    // ── 4. 4戦略の直近7日 vs 全期間 ─────────────────────────────────────
    $strat_sql = "
        SELECT s.strategy_type,
               COUNT(sr.id) AS races, COALESCE(SUM(sr.is_hit),0) AS hits,
               COALESCE(SUM(sr.cost),0) AS cost, COALESCE(SUM(sr.payout),0) AS payout
        FROM strategy_results sr
        JOIN strategies s ON s.id = sr.strategy_id
        JOIN races r      ON r.id = s.race_id
        %s
        GROUP BY s.strategy_type";
    $stmt = $pdo->prepare(sprintf($strat_sql, 'WHERE r.date >= ?'));
    $stmt->execute([date('Y-m-d', strtotime('-6 days'))]);
    foreach ($stmt->fetchAll() as $row) $data['strat_7d'][$row['strategy_type']] = $row;
    foreach ($pdo->query(sprintf($strat_sql, ''))->fetchAll() as $row) {
        $data['strat_all'][$row['strategy_type']] = $row;
    }
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

// ── 表示用ヘルパ ─────────────────────────────────────────────────────
function rate_pct($num, $den) { return $den > 0 ? round($num / $den * 100, 1) : null; }
function rate_class($pct) {
    if ($pct === null) return 'na';
    return $pct >= 95 ? 'ok' : ($pct >= 80 ? 'warn' : 'danger');
}
function hhmm($ts) { return $ts ? date('H:i', strtotime($ts)) : '-'; }
function strat_kpi(array $rows, string $type): array {
    $r = $rows[$type] ?? null;
    if (!$r || $r['races'] == 0) return ['races' => 0, 'hit' => null, 'roi' => null];
    return [
        'races' => (int)$r['races'],
        'hit'   => round($r['hits'] / $r['races'] * 100, 1),
        'roi'   => $r['cost'] > 0 ? round($r['payout'] / $r['cost'] * 100, 1) : null,
    ];
}

$exhibit_pct = rate_pct($data['exhibit_filled'], $data['exhibit_total']);
$odds_pct    = rate_pct($data['odds_races'], $data['races']);
$v2_pct      = rate_pct($data['v2']['hits'],  $data['v2']['races']);
$v3w_pct     = rate_pct($data['v3w']['hits'], $data['v3w']['races']);

$shadow_day  = (int)floor((strtotime($today) - strtotime(SHADOW_START)) / 86400) + 1;
$judge_left  = max(0, (int)ceil((strtotime(SHADOW_JUDGE) - strtotime($today)) / 86400));

$STRAT_NAMES = ['的中特化' => '的中特化', 'バランス' => 'バランス', '一撃重視' => '一撃重視', '絞り込み' => '絞り込み'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>艇王 - 管理画面</title>
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
.back-btn { color: #0055a4; text-decoration: none; font-size: 20px; line-height: 1; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: background 0.15s; }
.back-btn:hover { background: #e8f0fd; }
.page-title-row { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.container { max-width: 900px; margin: 0 auto; padding: 20px 16px; }

h2.sub { font-size: 14px; font-weight: 700; margin: 22px 0 10px; color: #333; }
h2.sub:first-of-type { margin-top: 4px; }
.dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; }
.dcard { background: #f7f8fa; border: 1px solid #e0e3e8; border-radius: 8px; padding: 12px 14px; }
.dcard-label { font-size: 11px; color: #888; margin-bottom: 4px; }
.dcard-val { font-size: 22px; font-weight: 700; color: #0055a4; line-height: 1.2; }
.dcard-sub { font-size: 11px; color: #888; margin-top: 3px; }
.dcard.ok     .dcard-val { color: #16a34a; }
.dcard.warn   { border-color: #f59e0b; background: #fffbeb; }
.dcard.warn   .dcard-val { color: #b45309; }
.dcard.danger { border-color: #fca5a5; background: #fef2f2; }
.dcard.danger .dcard-val { color: #dc2626; }
.dcard.v3     { border-color: #0055a4; background: #f0f5ff; }
.better { color: #16a34a; font-weight: 700; }
.worse  { color: #dc2626; }
.no-data { color: #ccc; }
.note { font-size: 11px; color: #888; margin-top: 8px; line-height: 1.6; }
.error-box { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 12px 16px; color: #dc2626; margin-bottom: 16px; font-size: 12px; }

.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 8px; border: 1px solid #e0e3e8; }
table.dash { width: 100%; min-width: 480px; border-collapse: collapse; background: #fff; }
table.dash th { background: #f7f8fa; font-size: 11px; font-weight: 700; color: #888; padding: 7px 10px; text-align: center; border-bottom: 2px solid #e0e3e8; white-space: nowrap; }
table.dash td { padding: 7px 10px; text-align: center; font-size: 13px; border-bottom: 1px solid #f0f0f0; }
table.dash tr:last-child td { border-bottom: none; }

.job-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }
.job-badge.success { background: #dcfce7; color: #16a34a; }
.job-badge.failure { background: #fee2e2; color: #dc2626; }
.job-badge.cancelled { background: #f1f5f9; color: #64748b; }
.job-badge.running { background: #e0f2fe; color: #0369a1; }
.job-badge.unknown { background: #f1f5f9; color: #94a3b8; }

.tool-group { margin-bottom: 22px; }
.tool-group h3 { font-size: 11px; font-weight: 700; color: #888; letter-spacing: 0.04em; margin-bottom: 8px; border-left: 2px solid #cbd5e1; padding-left: 6px; }
.tool-link { display: block; padding: 12px 14px; border: 1px solid #e0e3e8; border-radius: 8px; margin-bottom: 8px; text-decoration: none; color: #222; background: #fff; transition: background 0.15s; }
.tool-link:hover { background: #f7f8fa; }
.tool-link strong { display: block; font-size: 14px; margin-bottom: 2px; color: #0055a4; }
.tool-link span { font-size: 12px; color: #777; }
.tool-note { padding: 12px 14px; border: 1px dashed #e0e3e8; border-radius: 8px; margin-bottom: 8px; background: #fafbfc; }
.tool-note strong { display: block; font-size: 14px; margin-bottom: 2px; color: #555; }
.tool-note span { font-size: 12px; color: #777; }
</style>
</head>
<body>

  <?php include 'header.php'; ?>

<div class="dashboard-container">

  <script>var ACTIVE_NAV = 'admin';</script>
  <?php include 'sidebar.php'; ?>

  <main class="main-content">
  <div class="container">

  <div class="page-title-row">
    <a class="back-btn" href="index.php">&larr;</a>
    <h2 class="section-title">管理画面</h2>
  </div>

  <?php if ($db_error): ?>
  <div class="error-box">DB集計に失敗しました: <?= htmlspecialchars($db_error) ?></div>
  <?php endif; ?>

  <!-- ── 1. 本日のデータ取得状況 ─────────────────────────────── -->
  <h2 class="sub">📡 本日のデータ取得状況(<?= htmlspecialchars($today) ?>)</h2>
  <div class="dash-grid">
    <div class="dcard">
      <div class="dcard-label">開催</div>
      <div class="dcard-val"><?= $data['venues'] ?>会場</div>
      <div class="dcard-sub"><?= $data['races'] ?>レース</div>
    </div>
    <div class="dcard <?= rate_class($exhibit_pct) ?>">
      <div class="dcard-label">直前情報(展示タイム)取得率</div>
      <div class="dcard-val"><?= $exhibit_pct !== null ? $exhibit_pct . '%' : '-' ?></div>
      <div class="dcard-sub"><?= $data['exhibit_filled'] ?>/<?= $data['exhibit_total'] ?>艇 ・ 最終 <?= hhmm($data['before_last']) ?></div>
    </div>
    <div class="dcard <?= rate_class($odds_pct) ?>">
      <div class="dcard-label">オッズ取得率</div>
      <div class="dcard-val"><?= $odds_pct !== null ? $odds_pct . '%' : '-' ?></div>
      <div class="dcard-sub"><?= $data['odds_races'] ?>/<?= $data['races'] ?>レース ・ 最終 <?= hhmm($data['odds_last']) ?></div>
    </div>
  </div>
  <p class="note">※ 直前情報・オッズはレース直前に埋まるため、開催中の時間帯は100%未満が正常です。夜間に80%を切っている場合は取得ジョブの失敗を疑ってください。</p>

  <!-- ── 2. ジョブ実行状況(GitHub Actions・クライアント側で遅延取得) ── -->
  <h2 class="sub">⚙️ 直近のジョブ実行状況</h2>
  <div class="table-wrap">
    <table class="dash" id="jobTable">
      <thead>
        <tr><th>ワークフロー</th><th>最新の結果</th><th>最終成功</th><th>直近10回</th></tr>
      </thead>
      <tbody>
        <tr data-wf="boatrace.yml"><td>boatrace.yml(日次データ取得)</td><td colspan="3" class="no-data">読み込み中…</td></tr>
        <tr data-wf="live.yml"><td>live.yml(直前情報・オッズ)</td><td colspan="3" class="no-data">読み込み中…</td></tr>
      </tbody>
    </table>
  </div>
  <p class="note">※ GitHub Actions APIから取得(公開リポジトリ・未認証、60回/時の制限あり)。取得失敗時はDBの「最終」時刻からジョブの生死を推測してください。</p>

  <!-- ── 3. v3wシャドウテスト進捗 ────────────────────────────── -->
  <h2 class="sub">🔬 v3wシャドウテスト進捗(alpha=0.55、<?= SHADOW_START ?>開始)</h2>
  <div class="dash-grid">
    <div class="dcard">
      <div class="dcard-label">経過</div>
      <div class="dcard-val"><?= $shadow_day ?>日目</div>
      <div class="dcard-sub">蓄積 <?= number_format($data['shadow_races']) ?>レース</div>
    </div>
    <div class="dcard v3">
      <div class="dcard-label">v3w 1着的中率</div>
      <div class="dcard-val"><?= $v3w_pct !== null ? $v3w_pct . '%' : '-' ?></div>
      <div class="dcard-sub"><?= $data['v3w']['hits'] ?>/<?= $data['v3w']['races'] ?>レース(結果確定分)</div>
    </div>
    <div class="dcard">
      <div class="dcard-label">v2 1着的中率(同期間)</div>
      <div class="dcard-val"><?= $v2_pct !== null ? $v2_pct . '%' : '-' ?></div>
      <div class="dcard-sub">
        <?php if ($v2_pct !== null && $v3w_pct !== null): ?>
          差 <span class="<?= $v3w_pct > $v2_pct ? 'better' : ($v3w_pct < $v2_pct ? 'worse' : '') ?>"><?= sprintf('%+.1f', $v3w_pct - $v2_pct) ?>pt</span>
        <?php else: ?>結果確定待ち<?php endif; ?>
      </div>
    </div>
    <div class="dcard <?= $judge_left <= 3 ? 'warn' : '' ?>">
      <div class="dcard-label">昇格判定(<?= SHADOW_JUDGE ?>)まで</div>
      <div class="dcard-val">残り<?= $judge_left ?>日</div>
      <div class="dcard-sub"><a href="admin_v2.php">詳細比較へ →</a></div>
    </div>
  </div>

  <!-- ── 4. 4戦略の直近成績 ──────────────────────────────────── -->
  <h2 class="sub">🎯 4戦略の直近7日成績(vs 全期間)</h2>
  <div class="table-wrap">
    <table class="dash">
      <thead>
        <tr><th>戦略</th><th>7日 的中率</th><th>7日 回収率</th><th>全期間 回収率</th><th>差</th></tr>
      </thead>
      <tbody>
      <?php foreach ($STRAT_NAMES as $type => $label):
          $s7 = strat_kpi($data['strat_7d'], $type);
          $sa = strat_kpi($data['strat_all'], $type);
          $diff = ($s7['roi'] !== null && $sa['roi'] !== null) ? $s7['roi'] - $sa['roi'] : null;
      ?>
        <tr>
          <td><?= htmlspecialchars($label) ?></td>
          <td><?= $s7['hit'] !== null ? $s7['hit'] . '%' : '<span class="no-data">-</span>' ?></td>
          <td><?= $s7['roi'] !== null ? $s7['roi'] . '%' : '<span class="no-data">-</span>' ?></td>
          <td><?= $sa['roi'] !== null ? $sa['roi'] . '%' : '<span class="no-data">-</span>' ?></td>
          <td class="<?= $diff !== null ? ($diff > 0 ? 'better' : ($diff < 0 ? 'worse' : '')) : '' ?>">
            <?= $diff !== null ? sprintf('%+.1f pt', $diff) : '<span class="no-data">-</span>' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="note">※ 7日 = <?= date('m/d', strtotime('-6 days')) ?>〜本日。回収率は7日分のブレが大きいため、差が±10pt程度はノイズ範囲です。</p>

  <!-- ── ツールリンク一覧(従来のまま) ────────────────────────── -->
  <h2 class="sub" style="margin-top: 28px;">🔗 管理ツール</h2>

  <div class="tool-group">
    <h3>モデル・ダッシュボード</h3>
    <a class="tool-link" href="admin_v2.php">
      <strong>v2/v3wシャドウテスト比較</strong>
      <span>本番v2と検証中v3w(alpha=0.55)の的中率・昇格判定モニタリング</span>
    </a>
  </div>

  <div class="tool-group">
    <h3>戦略シミュレーション(読み取り専用)</h3>
    <a class="tool-link" href="simulate_balance.php?days=30">
      <strong>バランス戦略シミュレーター</strong>
      <span>オッズ上限/バンド/EVフィルタの比較</span>
    </a>
    <a class="tool-link" href="simulate_ichigeki.php?days=30">
      <strong>一撃重視戦略シミュレーター</strong>
      <span>オッズ閾値・選定プールの比較</span>
    </a>
    <a class="tool-link" href="analyze_ichigeki.php">
      <strong>一撃重視 詳細分析</strong>
      <span>一撃重視戦略の詳細分析</span>
    </a>
  </div>

  <div class="tool-group">
    <h3>v3検証ツール(読み取り専用)</h3>
    <a class="tool-link" href="shadow_eval_v3.php?from=<?= SHADOW_START ?>&to=<?php echo $today; ?>">
      <strong>v3wシャドウ評価</strong>
      <span>1着的中率・4戦略KPIシミュレーション(from/toで期間指定)</span>
    </a>
    <a class="tool-link" href="export_lr_data_v3.php?from=<?php echo $today; ?>&to=<?php echo $today; ?>&stats=1">
      <strong>v3学習用データエクスポート</strong>
      <span>月次再学習用CSV出力(from/toで期間指定)</span>
    </a>
    <a class="tool-link" href="export_odds_payouts_v3.php?from=<?php echo $today; ?>&to=<?php echo $today; ?>&mode=payouts">
      <strong>オッズ・払戻エクスポート</strong>
      <span>オフライン戦略KPI検証用(from/to/modeで指定。mode=odds|payouts|finish)</span>
    </a>
  </div>

  <div class="tool-group">
    <h3>メンテナンス</h3>
    <a class="tool-link" href="backfill_list_run.php">
      <strong>直前情報バックフィル対象一覧</strong>
      <span>exhibit_time/start_timing欠損レースの一覧</span>
    </a>
    <div class="tool-note">
      <strong>払戻バックフィル(backfill_payout.php)</strong>
      <span>POSTリクエスト専用のため直リンクなし。curl等で叩いてください(管理者セッションがあればapi_key不要)</span>
    </div>
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

// ── ジョブ実行状況(GitHub Actions API、未認証・公開リポジトリ) ──────
(function() {
  var REPO = 'faeabhgafaafae/teiou';

  function badge(conclusion, status) {
    if (status === 'in_progress' || status === 'queued') return '<span class="job-badge running">実行中</span>';
    switch (conclusion) {
      case 'success':   return '<span class="job-badge success">成功</span>';
      case 'failure':   return '<span class="job-badge failure">失敗</span>';
      case 'cancelled': return '<span class="job-badge cancelled">キャンセル</span>';
      default:          return '<span class="job-badge unknown">' + (conclusion || '不明') + '</span>';
    }
  }

  function jstTime(iso) {
    if (!iso) return '-';
    return new Date(iso).toLocaleString('ja-JP', { timeZone: 'Asia/Tokyo', month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  function render(wf, runs) {
    var tr = document.querySelector('#jobTable tr[data-wf="' + wf + '"]');
    if (!tr) return;
    var name = tr.cells[0].outerHTML;
    if (!runs || !runs.length) {
      tr.innerHTML = name + '<td colspan="3" class="no-data">実行履歴なし</td>';
      return;
    }
    var latest = runs[0];
    var lastSuccess = null;
    var failStreak = 0;
    for (var i = 0; i < runs.length; i++) {
      if (runs[i].conclusion === 'success') { if (!lastSuccess) lastSuccess = runs[i]; }
    }
    for (var j = 0; j < runs.length; j++) {
      if (runs[j].status !== 'completed') continue;         // 実行中はスキップ
      if (runs[j].conclusion === 'failure') { failStreak++; continue; }
      if (runs[j].conclusion === 'cancelled') continue;      // live.ymlのconcurrencyキャンセルは失敗扱いしない
      break;
    }
    var history = runs.slice(0, 10).map(function(r) {
      if (r.status !== 'completed') return '⏳';
      return r.conclusion === 'success' ? '🟢' : (r.conclusion === 'failure' ? '🔴' : '⚪');
    }).join('');
    var latestHtml = badge(latest.conclusion, latest.status);
    if (failStreak >= 2) {
      latestHtml += ' <span class="worse" style="font-size:11px;">連続失敗' + failStreak + '回</span>';
    }
    tr.innerHTML = name +
      '<td>' + latestHtml + '</td>' +
      '<td style="font-size:12px;">' + (lastSuccess ? jstTime(lastSuccess.run_started_at || lastSuccess.created_at) : '<span class="no-data">なし</span>') + '</td>' +
      '<td style="font-size:12px; letter-spacing:2px;" title="左が最新">' + history + '</td>';
  }

  ['boatrace.yml', 'live.yml'].forEach(function(wf) {
    fetch('https://api.github.com/repos/' + REPO + '/actions/workflows/' + wf + '/runs?per_page=10')
      .then(function(res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
      .then(function(json) { render(wf, json.workflow_runs || []); })
      .catch(function(e) {
        var tr = document.querySelector('#jobTable tr[data-wf="' + wf + '"]');
        if (tr) tr.innerHTML = tr.cells[0].outerHTML + '<td colspan="3" class="no-data">取得失敗(' + e.message + ')</td>';
      });
  });
})();
</script>
</body>
</html>
