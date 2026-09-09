<?php
require_once __DIR__ . '/auth.php';
$user = current_user();
if (!$user || !$user['is_admin']) {
    http_response_code(403);
    echo '<p>管理者権限が必要です</p>';
    exit;
}
$today = date('Y-m-d');
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
.container { max-width: 800px; margin: 0 auto; padding: 20px 16px; }

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

  <div class="tool-group">
    <h3>モデル・ダッシュボード</h3>
    <a class="tool-link" href="admin_v2.php">
      <strong>v2/v3シャドウテスト比較</strong>
      <span>本番v2と検証中v3の的中率・昇格判定モニタリング</span>
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
    <a class="tool-link" href="shadow_eval_v3.php?from=<?php echo $today; ?>&to=<?php echo $today; ?>">
      <strong>v3シャドウ評価</strong>
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

</body>
</html>
