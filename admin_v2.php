<?php
/**
 * admin_v2.php  開発者専用 シャドウテスト比較ダッシュボード
 * ユーザー向けには公開しない。auth.php のログイン認証を通過した場合のみ表示。
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

// 管理者チェック（メールアドレスで判定。auth.phpのcurrent_user()を使用）
$user = current_user();
if (!$user) {
    http_response_code(403);
    echo '<p>ログインが必要です</p>';
    exit;
}

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// predictions_v2 が存在するか確認
$has_v2 = false;
try {
    $pdo->query("SELECT 1 FROM predictions_v2 LIMIT 1");
    $has_v2 = true;
} catch (PDOException $e) {}

// ── 集計クエリ ────────────────────────────────────────────────

// v1: predicted_rank=1 の選手が actual_rank=1 だったか (日別)
$v1_sql = "
    SELECT r.date,
           COUNT(DISTINCT p.race_id)                                     AS races,
           SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END)          AS hits,
           SUM(CASE WHEN lres.actual_rank = 1 THEN 1 ELSE 0 END)         AS lane1_hits
    FROM predictions p
    JOIN races r      ON r.id = p.race_id
    JOIN results res  ON res.race_id = p.race_id AND res.player_id = p.player_id
    JOIN results lres ON lres.race_id = p.race_id AND lres.lane = 1 AND lres.actual_rank IS NOT NULL
    WHERE p.predicted_rank = 1
      AND r.date >= '2026-06-01'
    GROUP BY r.date
    ORDER BY r.date DESC
";

// v2: 同様
$v2_sql = "
    SELECT r.date,
           COUNT(DISTINCT p2.race_id)                                    AS races,
           SUM(CASE WHEN res.actual_rank = 1 THEN 1 ELSE 0 END)          AS hits
    FROM predictions_v2 p2
    JOIN races r      ON r.id = p2.race_id
    JOIN results res  ON res.race_id = p2.race_id AND res.player_id = p2.player_id
    WHERE p2.predicted_rank = 1
      AND r.date >= '2026-06-01'
    GROUP BY r.date
    ORDER BY r.date DESC
";

$v1_rows = $pdo->query($v1_sql)->fetchAll();
$v1_map  = [];
foreach ($v1_rows as $row) {
    $v1_map[$row['date']] = $row;
}

$v2_map = [];
if ($has_v2) {
    $v2_rows = $pdo->query($v2_sql)->fetchAll();
    foreach ($v2_rows as $row) {
        $v2_map[$row['date']] = $row;
    }
}

// 全期間サマリー
$v1_total_races = array_sum(array_column($v1_rows, 'races'));
$v1_total_hits  = array_sum(array_column($v1_rows, 'hits'));
$v1_lane1_hits  = array_sum(array_column($v1_rows, 'lane1_hits'));
$v2_total_races = 0;
$v2_total_hits  = 0;
if ($has_v2) {
    $v2_total_races = array_sum(array_column($v2_rows, 'races'));
    $v2_total_hits  = array_sum(array_column($v2_rows, 'hits'));
}

$all_dates = array_unique(array_merge(array_keys($v1_map), array_keys($v2_map)));
rsort($all_dates);

function pct($hit, $total) {
    if ($total <= 0) return '-';
    return sprintf('%.1f%%', $hit / $total * 100);
}
function diff_class($v2, $v1) {
    if ($v2 === null || $v1 === null) return '';
    return $v2 > $v1 ? 'better' : ($v2 < $v1 ? 'worse' : '');
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>艇王 v2シャドウテスト</title>
<link rel="stylesheet" href="style.css">
<style>
body { font-family: sans-serif; font-size: 13px; }
.adm-wrap { max-width: 900px; margin: 24px auto; padding: 0 16px; }
h1 { font-size: 18px; font-weight: 700; margin-bottom: 8px; }
.summary-cards { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
.scard { background: #f7f8fa; border: 1px solid #e0e3e8; border-radius: 8px; padding: 12px 16px; min-width: 160px; }
.scard-label { font-size: 11px; color: #888; margin-bottom: 4px; }
.scard-val { font-size: 22px; font-weight: 700; color: #0055a4; }
.scard-sub { font-size: 11px; color: #888; }
.scard.v2  { border-color: #0055a4; background: #f0f5ff; }
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
</style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>
<div class="adm-wrap">
  <h1>🔬 v2 シャドウテスト ダッシュボード</h1>
  <p class="note">
    v1 = 現行手動スコアモデル (predict_rank=1 が実際に1着か) ／
    v2 = ロジスティック回帰 (2026-07-28 学習, train期間 6/29〜7/19) ／
    ベースライン = 1号艇決め打ち
  </p>

  <!-- サマリーカード -->
  <div class="summary-cards">
    <div class="scard">
      <div class="scard-label">v1 全期間 1着的中率</div>
      <div class="scard-val"><?= pct($v1_total_hits, $v1_total_races) ?></div>
      <div class="scard-sub"><?= $v1_total_hits ?>/<?= $v1_total_races ?> レース</div>
    </div>
    <?php if ($has_v2): ?>
    <div class="scard v2">
      <div class="scard-label">v2 全期間 1着的中率</div>
      <div class="scard-val"><?= pct($v2_total_hits, $v2_total_races) ?></div>
      <div class="scard-sub"><?= $v2_total_hits ?>/<?= $v2_total_races ?> レース</div>
    </div>
    <?php else: ?>
    <div class="scard v2">
      <div class="scard-label">v2</div>
      <div class="scard-val no-data">データなし</div>
      <div class="scard-sub">api_v2_batch.php 実行後に表示</div>
    </div>
    <?php endif; ?>
    <div class="scard baseline">
      <div class="scard-label">1号艇ベースライン</div>
      <div class="scard-val"><?= pct($v1_lane1_hits, $v1_total_races) ?></div>
      <div class="scard-sub"><?= $v1_lane1_hits ?>/<?= $v1_total_races ?> レース</div>
    </div>
  </div>

  <!-- v2 本番昇格基準 -->
  <div class="criteria-box">
    <strong>📋 v2 本番昇格基準</strong>
    <ul>
      <li><strong>蓄積期間:</strong> predictions_v2 が毎晩安定して <strong>1ヶ月間</strong> 記録され続けていること</li>
      <li><strong>判断指標:</strong> 1着的中率と ROI(回収率) の両方を見る</li>
      <li><strong>昇格条件(両方満たした場合に昇格を検討):</strong>
        <ul>
          <li>① 1号艇ベースライン(53.4%前後)を安定して上回っていること <strong>【最優先】</strong></li>
          <li>② v1 比で <strong>+5 pt 以上</strong> の明確な差がついていること</li>
        </ul>
      </li>
    </ul>
  </div>

  <!-- [開発ログ 2026-08-13]
       v1(ルールベース)スコアリングの的中率が競合他社比で大きく劣ることが判明
       (艇王17.6% vs 競合49.1%、約31pt差)。手動重み調整では改善が頭打ちであることも
       既に確認済み(score_today新提案が-1.9pt悪化)。v2(LR)への移行が効果的な
       解決策と見られ、8/28の昇格判定を予定通り実施する。 -->

  <!-- 日別比較テーブル -->
  <table>
    <thead>
      <tr>
        <th>日付</th>
        <th>v1 レース数</th>
        <th>v1 1着的中率</th>
        <th>v2 レース数</th>
        <th>v2 1着的中率</th>
        <th>差 (v2-v1)</th>
        <th>ベースライン</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($all_dates as $d): ?>
      <?php
        $v1 = $v1_map[$d] ?? null;
        $v2 = $v2_map[$d] ?? null;
        $v1_r = $v1 ? (int)$v1['races'] : 0;
        $v1_h = $v1 ? (int)$v1['hits']  : 0;
        $v2_r = $v2 ? (int)$v2['races'] : 0;
        $v2_h = $v2 ? (int)$v2['hits']  : 0;
        $bl_h = $v1 ? (int)$v1['lane1_hits'] : 0;
        $v1_rate = $v1_r > 0 ? $v1_h / $v1_r : null;
        $v2_rate = $v2_r > 0 ? $v2_h / $v2_r : null;
        $diff    = ($v1_rate !== null && $v2_rate !== null) ? $v2_rate - $v1_rate : null;
        $dc      = diff_class($v2_rate, $v1_rate);
      ?>
      <tr>
        <td><?= htmlspecialchars($d) ?></td>
        <td><?= $v1_r ?: '-' ?></td>
        <td><?= pct($v1_h, $v1_r) ?></td>
        <td><?= $v2_r ? $v2_r : '<span class="no-data">-</span>' ?></td>
        <td class="<?= $dc ?>"><?= pct($v2_h, $v2_r) ?></td>
        <td class="<?= $dc ?>">
          <?= $diff !== null ? sprintf('%+.1f pt', $diff * 100) : '<span class="no-data">-</span>' ?>
        </td>
        <td><?= pct($bl_h, $v1_r) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <p class="note">
    ※ v2の予測は毎晩の fetch_results ジョブ完了後に api_v2_batch.php 経由で自動記録されます。<br>
    ※ 結果が登録されていないレース（当日中など）は v2 数値が「-」になります。<br>
    ※ v1レース数とv2レース数が異なる場合、v2バッチが一部スキップしたレースがあります。
  </p>
</div>
</body>
</html>
