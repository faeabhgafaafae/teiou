<?php
/**
 * admin.php  管理画面トップ(ステータスダッシュボード+ツールリンク一覧)
 *
 * 2026-09-14: リンク一覧のみ→ダッシュボード化。
 *   1. 本日のデータ取得状況(races/entries/odds_updated_atの軽量集計のみ)
 *   2. ジョブ実行状況(GitHub Actions API・公開リポジトリのため未認証。
 *      ページ表示をブロックしないようクライアント側fetchで遅延取得)
 *   3. モデル運用状況(2026-10-08: v3wシャドウ進捗から置き換え)
 *      参照モデル(model_switch.php)・切り替え履歴・次回再集計日と、v3w 本番化以降の
 *      v3w / v2(並行保存)/ 1号艇決め打ちの1着的中率。集計は model_status_lib.php
 *      (model_switch_status.php と共用)
 *   4. 4戦略の直近7日成績 vs 全期間(7日分は model_ref 別)
 *   5. データ品質の注意(data_quality.php。performance.php の注記と同じ定数・判定)
 * WAF 制約: CSS はページ内 <style> のみ、JS はテンプレートリテラル(バッククォート)を使わない。
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/model_switch.php';
require_once __DIR__ . '/model_status_lib.php';
require_once __DIR__ . '/data_quality.php';
$user = current_user();
if (!$user || !$user['is_admin']) {
    http_response_code(403);
    echo '<p>管理者権限が必要です</p>';
    exit;
}

$today = date('Y-m-d');
$from7 = date('Y-m-d', strtotime('-6 days'));

// データ品質欄の対象期間の起点(成績データの開始日)
const ADMIN_DATA_FROM = '2026-06-29';

$db_error = null;
$data = [
    'races' => 0, 'venues' => 0,
    'exhibit_total' => 0, 'exhibit_filled' => 0,
    'odds_races' => 0, 'before_last' => null, 'odds_last' => null,
    'top1_daily' => [], 'strat_7d' => [], 'strat_all' => [],
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

    // ── 3. モデル運用状況: 保存済み予測の1着的中(model_status_lib.php) ────────
    $data['top1_daily'] = ms_top1_daily($pdo, V3W_PRODUCTION_FROM, $today);

    // ── 4. 4戦略の直近7日(model_ref 別。model_status_lib.php)vs 全期間 ────────
    $data['strat_7d'] = ms_sum_strategy_results(ms_strategy_results($pdo, $from7, $today));
    $stmt = $pdo->query("
        SELECT s.strategy_type,
               COUNT(sr.id) AS races, COALESCE(SUM(sr.is_hit),0) AS hits,
               COALESCE(SUM(sr.cost),0) AS cost, COALESCE(SUM(sr.payout),0) AS payout
        FROM strategy_results sr
        JOIN strategies s ON s.id = sr.strategy_id
        GROUP BY s.strategy_type");
    foreach ($stmt->fetchAll() as $row) {
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
// 割合の表示(小数1桁固定。null は '-')
function pct($p) { return $p !== null ? sprintf('%.1f%%', $p) : '-'; }
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
// 7日分の model_ref 別集計を戦略単位にまとめる(的中率・回収率は全 model_ref 合算)
function strat_7d_kpi(array $by_ref): array {
    $n = $h = $c = $p = 0;
    foreach ($by_ref as $k) { $n += $k['n']; $h += $k['hits']; $c += $k['cost']; $p += $k['payout']; }
    $kpi = ms_result_kpi($n, $h, $c, $p);
    return ['races' => $n, 'hit' => $kpi['hit_rate'], 'roi' => $kpi['roi']];
}
function model_badge(string $model): string {
    $cls = $model === 'v3w' ? 'v3w' : ($model === 'v2' ? 'v2' : 'other');
    return '<span class="model-badge ' . $cls . '">' . htmlspecialchars($model) . '</span>';
}
function signed_class($a, $b) {
    if ($a === null || $b === null) return '';
    return $a > $b ? 'better' : ($a < $b ? 'worse' : '');
}

$exhibit_pct = rate_pct($data['exhibit_filled'], $data['exhibit_total']);
$odds_pct    = rate_pct($data['odds_races'], $data['races']);

// モデル運用状況(累計)
$top1_sum    = ms_sum_top1($data['top1_daily']);
$v3w_pct     = rate_pct($top1_sum['v3w_hits'],        $top1_sum['races']);
$v2_pct      = rate_pct($top1_sum['v2_hits'],         $top1_sum['races']);
$lane1_pct   = rate_pct($top1_sum['lane1_wins'],      $top1_sum['races']);
$l1rank_pct  = rate_pct($top1_sum['v3w_lane1_rank1'], $top1_sum['races']);
$review_left = (int)ceil((strtotime(NEXT_MODEL_REVIEW['date']) - strtotime($today)) / 86400);

// 日別表(新しい日が上): 結果確定データの無い日は、データ品質の問題(欠損等)に該当するときだけ行を出す
$top1_rows = [];
for ($d = $today; $d >= V3W_PRODUCTION_FROM; $d = date('Y-m-d', strtotime($d . ' -1 day'))) {
    $issue = data_quality_issue_on($d);
    if (isset($data['top1_daily'][$d])) {
        $top1_rows[] = ['date' => $d, 't' => $data['top1_daily'][$d], 'issue' => $issue];
    } elseif ($issue) {
        $top1_rows[] = ['date' => $d, 't' => null, 'issue' => $issue];
    }
}

// データ品質の注意(成績データの開始日〜本日に該当するもの)
$dq_issues = data_quality_issues(ADMIN_DATA_FROM, $today);
$DQ_LABELS = ['leak' => 'リーク込み', 'gap' => '欠損'];

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
.table-wrap.scroll-y { max-height: 360px; overflow-y: auto; }
table.dash { width: 100%; min-width: 480px; border-collapse: collapse; background: #fff; }
table.dash th { background: #f7f8fa; font-size: 11px; font-weight: 700; color: #888; padding: 7px 10px; text-align: center; border-bottom: 2px solid #e0e3e8; white-space: nowrap; }
table.dash td { padding: 7px 10px; text-align: center; font-size: 13px; border-bottom: 1px solid #f0f0f0; }
table.dash tr:last-child td { border-bottom: none; }
table.dash tr.total-row td { font-weight: 700; background: #f7f8fa; }
table.dash tr.issue-row td { color: #94a3b8; background: #f8fafc; font-size: 12px; }

.job-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }
.job-badge.success { background: #dcfce7; color: #16a34a; }
.job-badge.failure { background: #fee2e2; color: #dc2626; }
.job-badge.cancelled { background: #f1f5f9; color: #64748b; }
.job-badge.running { background: #e0f2fe; color: #0369a1; }
.job-badge.unknown { background: #f1f5f9; color: #94a3b8; }

.model-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }
.model-badge.v3w { background: #e0ecff; color: #0055a4; }
.model-badge.v2  { background: #fef3c7; color: #b45309; }
.model-badge.other { background: #f1f5f9; color: #64748b; }
.ref-list { font-size: 12px; line-height: 1.8; }
.history { font-size: 12px; color: #444; line-height: 1.8; margin: 8px 0 12px; }
.history b { color: #0055a4; margin-right: 6px; }

.dq-list { list-style: none; }
.dq-item { border: 1px solid #f59e0b; background: #fffbeb; border-radius: 8px; padding: 10px 14px; margin-bottom: 8px; font-size: 12px; line-height: 1.6; color: #444; }
.dq-item.gap { border-color: #cbd5e1; background: #f8fafc; }
.dq-tag { display: inline-block; font-size: 11px; font-weight: 700; padding: 1px 8px; border-radius: 10px; margin-right: 6px; background: #fde68a; color: #92400e; }
.dq-item.gap .dq-tag { background: #e2e8f0; color: #475569; }
.dq-period { font-weight: 700; margin-right: 6px; }

.tool-group { margin-bottom: 22px; }
.tool-group h3 { font-size: 11px; font-weight: 700; color: #888; letter-spacing: 0.04em; margin-bottom: 8px; border-left: 2px solid #cbd5e1; padding-left: 6px; }
.tool-link { display: block; padding: 12px 14px; border: 1px solid #e0e3e8; border-radius: 8px; margin-bottom: 8px; text-decoration: none; color: #222; background: #fff; transition: background 0.15s; }
.tool-link:hover { background: #f7f8fa; }
.tool-link strong { display: block; font-size: 14px; margin-bottom: 2px; color: #0055a4; }
.tool-link span { font-size: 12px; color: #777; }
.tool-group.legacy .tool-link { background: #fafbfc; }
.tool-group.legacy .tool-link strong { color: #64748b; }
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
      <div class="dcard-val"><?= pct($exhibit_pct) ?></div>
      <div class="dcard-sub"><?= $data['exhibit_filled'] ?>/<?= $data['exhibit_total'] ?>艇 ・ 最終 <?= hhmm($data['before_last']) ?></div>
    </div>
    <div class="dcard <?= rate_class($odds_pct) ?>">
      <div class="dcard-label">オッズ取得率</div>
      <div class="dcard-val"><?= pct($odds_pct) ?></div>
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

  <!-- ── 3. モデル運用状況 ──────────────────────────────────── -->
  <h2 class="sub">🧠 モデル運用状況</h2>
  <div class="table-wrap">
    <table class="dash">
      <thead>
        <tr>
          <th>参照モデル</th>
          <?php foreach (STRATEGY_MODEL_MAP as $type => $m): ?><th><?= htmlspecialchars($type) ?></th><?php endforeach; ?>
          <th>予測表示</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>現在(model_switch.php)</td>
          <?php foreach (STRATEGY_MODEL_MAP as $type => $m): ?><td><?= model_badge($m) ?></td><?php endforeach; ?>
          <td><?= model_badge(PREDICTION_DISPLAY_MODEL) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
  <div class="history">
    <?php foreach (MODEL_SWITCH_HISTORY as $h): ?>
      <div><b><?= htmlspecialchars($h['date']) ?></b><?= htmlspecialchars($h['text']) ?></div>
    <?php endforeach; ?>
    <div>v3w の予測が無いレースは <?= model_badge(STRATEGY_MODEL_FALLBACK) ?> で買い目を作り、strategies.model_ref に実際の参照先が残ります。v2 の予測は常に並行保存しています。</div>
  </div>

  <div class="dash-grid">
    <div class="dcard v3">
      <div class="dcard-label">v3w 1着的中率(<?= htmlspecialchars(V3W_PRODUCTION_FROM) ?>〜累計)</div>
      <div class="dcard-val"><?= pct($v3w_pct) ?></div>
      <div class="dcard-sub"><?= $top1_sum['v3w_hits'] ?>/<?= $top1_sum['races'] ?>レース(結果確定分)</div>
    </div>
    <div class="dcard">
      <div class="dcard-label">1号艇決め打ち(同レース)</div>
      <div class="dcard-val"><?= pct($lane1_pct) ?></div>
      <div class="dcard-sub">
        <?php if ($v3w_pct !== null && $lane1_pct !== null): ?>
          v3w との差 <span class="<?= signed_class($v3w_pct, $lane1_pct) ?>"><?= sprintf('%+.1f', $v3w_pct - $lane1_pct) ?>pt</span>
        <?php else: ?>結果確定待ち<?php endif; ?>
      </div>
    </div>
    <div class="dcard">
      <div class="dcard-label">v2(並行保存・同レース)</div>
      <div class="dcard-val"><?= pct($v2_pct) ?></div>
      <div class="dcard-sub">
        <?php if ($v3w_pct !== null && $v2_pct !== null): ?>
          v3w との差 <span class="<?= signed_class($v3w_pct, $v2_pct) ?>"><?= sprintf('%+.1f', $v3w_pct - $v2_pct) ?>pt</span>
        <?php else: ?>結果確定待ち<?php endif; ?>
      </div>
    </div>
    <div class="dcard <?= ($review_left >= 0 && $review_left <= 3) ? 'warn' : '' ?>">
      <div class="dcard-label">次回再集計(<?= htmlspecialchars(NEXT_MODEL_REVIEW['date']) ?>)</div>
      <div class="dcard-val"><?= $review_left > 0 ? 'あと' . $review_left . '日' : ($review_left === 0 ? '本日' : '実施日経過') ?></div>
      <div class="dcard-sub"><?= htmlspecialchars(NEXT_MODEL_REVIEW['text']) ?></div>
    </div>
  </div>

  <div class="table-wrap scroll-y" style="margin-top: 12px;">
    <table class="dash">
      <thead>
        <tr><th>日付</th><th>レース</th><th>v3w</th><th>v2(並行保存)</th><th>1号艇決め打ち</th><th>v3w − 1号艇</th><th>v3w 1号艇1位率</th></tr>
      </thead>
      <tbody>
      <?php if ($top1_sum['races'] > 0): ?>
        <tr class="total-row">
          <td>累計</td>
          <td><?= $top1_sum['races'] ?></td>
          <td><?= pct($v3w_pct) ?></td>
          <td><?= pct($v2_pct) ?></td>
          <td><?= pct($lane1_pct) ?></td>
          <td class="<?= signed_class($v3w_pct, $lane1_pct) ?>"><?= sprintf('%+.1f', $v3w_pct - $lane1_pct) ?></td>
          <td><?= pct($l1rank_pct) ?></td>
        </tr>
      <?php endif; ?>
      <?php foreach ($top1_rows as $row): ?>
        <?php if ($row['t'] === null): ?>
        <tr class="issue-row">
          <td><?= htmlspecialchars($row['date']) ?></td>
          <td colspan="6"><?= htmlspecialchars($DQ_LABELS[$row['issue']['type']] ?? '注意') ?>(下の「データ品質の注意」参照)</td>
        </tr>
        <?php else:
            $t  = $row['t'];
            $v3 = rate_pct($t['v3w_hits'], $t['races']);
            $l1 = rate_pct($t['lane1_wins'], $t['races']);
        ?>
        <tr>
          <td><?= htmlspecialchars($row['date']) ?></td>
          <td><?= $t['races'] ?></td>
          <td><?= pct($v3) ?></td>
          <td><?= pct(rate_pct($t['v2_hits'], $t['races'])) ?></td>
          <td><?= pct($l1) ?></td>
          <td class="<?= signed_class($v3, $l1) ?>"><?= sprintf('%+.1f', $v3 - $l1) ?></td>
          <td><?= pct(rate_pct($t['v3w_lane1_rank1'], $t['races'])) ?></td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$top1_rows): ?>
        <tr><td colspan="7" class="no-data">結果確定済みのレースがありません</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <p class="note">※ 結果取込み前に保存された予測(v3w=predictions_v2、v2=predictions)と着順の突き合わせ。v2・v3w とも予測があり結果確定済みのレースのみ(当日分は結果取込み後に反映)。
    数日〜数週間の差は誤差の範囲で、v3w と1号艇決め打ちの差の判定には約1ヶ月(4,000R)が必要です(次回再集計で判定)。</p>

  <!-- ── 4. 4戦略の直近成績 ──────────────────────────────────── -->
  <h2 class="sub">🎯 4戦略の直近7日成績(vs 全期間)</h2>
  <div class="table-wrap">
    <table class="dash">
      <thead>
        <tr><th>戦略</th><th>現在の参照</th><th>7日の model_ref</th><th>7日 的中率</th><th>7日 回収率</th><th>全期間 回収率</th><th>差</th></tr>
      </thead>
      <tbody>
      <?php foreach ($STRAT_NAMES as $type => $label):
          $refs7 = $data['strat_7d'][$type] ?? [];
          $s7 = strat_7d_kpi($refs7);
          $sa = strat_kpi($data['strat_all'], $type);
          $diff = ($s7['roi'] !== null && $sa['roi'] !== null) ? $s7['roi'] - $sa['roi'] : null;
      ?>
        <tr>
          <td><?= htmlspecialchars($label) ?></td>
          <td><?= model_badge(STRATEGY_MODEL_MAP[$type] ?? '-') ?></td>
          <td class="ref-list">
            <?php if (!$refs7): ?><span class="no-data">-</span><?php endif; ?>
            <?php foreach ($refs7 as $ref => $k): ?><?= model_badge($ref) ?> <?= $k['n'] ?>R<br><?php endforeach; ?>
          </td>
          <td><?= $s7['hit'] !== null ? pct($s7['hit']) : '<span class="no-data">-</span>' ?></td>
          <td><?= $s7['roi'] !== null ? pct($s7['roi']) : '<span class="no-data">-</span>' ?></td>
          <td><?= $sa['roi'] !== null ? pct($sa['roi']) : '<span class="no-data">-</span>' ?></td>
          <td class="<?= $diff !== null ? ($diff > 0 ? 'better' : ($diff < 0 ? 'worse' : '')) : '' ?>">
            <?= $diff !== null ? sprintf('%+.1f pt', $diff) : '<span class="no-data">-</span>' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="note">※ 7日 = <?= date('m/d', strtotime($from7)) ?>〜本日(清算済み分)。「7日の model_ref」は実際に買い目を作ったモデル別のレース数で、切り替え直後は2つが混在します。
    回収率は7日分のブレが大きいため、差が±10pt程度はノイズ範囲です。全期間はリーク込み期間を含みます(下記)。</p>

  <!-- ── 5. データ品質の注意(data_quality.php。performance.php の注記と同じ定数・判定) ── -->
  <h2 class="sub">⚠️ データ品質の注意</h2>
  <?php if (!$dq_issues): ?>
  <p class="note">該当なし</p>
  <?php else: ?>
  <ul class="dq-list">
    <?php foreach ($dq_issues as $iss): ?>
    <li class="dq-item <?= htmlspecialchars($iss['type']) ?>">
      <span class="dq-tag"><?= htmlspecialchars($DQ_LABELS[$iss['type']] ?? $iss['type']) ?></span>
      <span class="dq-period"><?= htmlspecialchars($iss['from'] === $iss['to'] ? $iss['from'] : $iss['from'] . '〜' . $iss['to']) ?></span>
      <?= htmlspecialchars($iss['text']) ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <p class="note">※ 定義は data_quality.php(performance.php の利用者向け注記と同じ定数・判定)。新しい問題はそこに1行追加すると両画面に反映されます。</p>

  <!-- ── ツールリンク一覧 ────────────────────────────────────── -->
  <h2 class="sub" style="margin-top: 28px;">🔗 管理ツール</h2>

  <div class="tool-group">
    <h3>モデル運用・監視(読み取り専用・JSON)</h3>
    <a class="tool-link" href="model_switch_status.php?from=<?= htmlspecialchars(V3W_PRODUCTION_FROM) ?>&to=<?= htmlspecialchars($today) ?>">
      <strong>モデル切り替え監視(model_switch_status.php)</strong>
      <span>日別の予測保存状況(v2/v3w)、買い目・清算の model_ref 別件数と成績、保存済み予測の1着的中(from/toで期間指定)</span>
    </a>
    <a class="tool-link" href="audit_leak_rewrites.php?from=<?= htmlspecialchars(ADMIN_DATA_FROM) ?>&to=<?= htmlspecialchars($today) ?>">
      <strong>リーク書き換え監査(audit_leak_rewrites.php)</strong>
      <span>結果取込み後に予測が書き換えられたレースの日別件数(2026-09-29 以降は0件のはず。from/toで期間指定)</span>
    </a>
  </div>

  <div class="tool-group">
    <h3>戦略シミュレーション(読み取り専用)</h3>
    <a class="tool-link" href="simulate_balance.php?days=30">
      <strong>バランス戦略シミュレーター</strong>
      <span>オッズ上限/バンド/EVフィルタの比較</span>
    </a>
    <a class="tool-link" href="simulate_ichigeki.php?days=14">
      <strong>一撃重視戦略シミュレーター</strong>
      <span>オッズ閾値・選定プールの比較(days=30 以上はデータ量で HTTP 500 になるため既定14日。21日までは動作確認済み)</span>
    </a>
  </div>

  <div class="tool-group">
    <h3>データエクスポート(読み取り専用)</h3>
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

  <div class="tool-group legacy">
    <h3>旧(役目を終えたツール・参照用)</h3>
    <a class="tool-link" href="admin_v2.php">
      <strong>v2/v3wシャドウテスト比較(〜2026-09-29)</strong>
      <span>v3w 昇格判定用。v2 側の値は 2026-08-19〜09-28 がリーク込みのため比較には使えません</span>
    </a>
    <a class="tool-link" href="shadow_eval_v3.php?from=2026-09-13&to=2026-09-29">
      <strong>v3wシャドウ評価(shadow_eval_v3.php)</strong>
      <span>シャドウ期間の1着的中率・旧ロジックの戦略シム。長期間は120秒制限で失敗するため、現在の監視は上の model_switch_status.php を使用</span>
    </a>
    <a class="tool-link" href="analyze_ichigeki.php">
      <strong>一撃重視 詳細分析(一時分析)</strong>
      <span>2026-08〜09 の一撃重視調査用(既定期間 08-28〜09-07)</span>
    </a>
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
