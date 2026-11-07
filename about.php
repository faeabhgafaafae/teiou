<?php
/**
 * about.php — 艇王について(サイトの目的・使い方・予想の仕組み・4戦略・成績の見方)(2026-11-07〜)
 * 数値は本番の DB から表示時に集計する(公開ページと同じく 2026-08-19〜09-28 のリーク込み期間は除外。data_quality.php)。
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/data_quality.php';
require_once __DIR__ . '/model_switch.php';
require_once __DIR__ . '/model_status_lib.php';
require_once __DIR__ . '/generate_strategies.php';   // 戦略の定数(TOKKA_POINTS 等)

const ABOUT_FROM = '2026-09-29';   // performance.php の「現行」と同じ集計開始日
$today = date('Y-m-d');
$stats = [];
$range = null;
$top1  = null;
try {
    $pdo = get_db();
    $where = ' WHERE r.date >= ?' . public_exclusion_sql('r.date');
    $st = $pdo->prepare("
        SELECT s.strategy_type, COUNT(sr.id) AS races, COALESCE(SUM(sr.is_hit), 0) AS hits,
               COALESCE(SUM(sr.cost), 0) AS cost, COALESCE(SUM(sr.payout), 0) AS payout
        FROM strategy_results sr
        JOIN strategies s ON s.id = sr.strategy_id
        JOIN races r ON r.id = sr.race_id" . $where . "
        GROUP BY s.strategy_type");
    $st->execute([ABOUT_FROM]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $stats[$r['strategy_type']] = $r;
    $st = $pdo->prepare("SELECT MIN(r.date) AS min_d, MAX(r.date) AS max_d, COUNT(DISTINCT sr.race_id) AS n
        FROM strategy_results sr JOIN races r ON r.id = sr.race_id" . $where);
    $st->execute([ABOUT_FROM]);
    $range = $st->fetch(PDO::FETCH_ASSOC);
    $top1 = ms_sum_top1(ms_top1_daily($pdo, V3W_PRODUCTION_FROM, $today));
} catch (Exception $e) {
    $stats = [];
}
function pct1($n, $d) { return $d > 0 ? sprintf('%.1f%%', $n / $d * 100) : '-'; }
function jd($d) { return $d ? date('Y年n月j日', strtotime($d)) : '-'; }
$STRATS = [
    '的中特化' => '予想上位' . TOKKA_POOL_SIZE . '艇の3連単から、確率の高い順に' . TOKKA_POINTS . '点。当てることを優先します。',
    'バランス' => '予想1・2位を1着に固定し、上位4艇から2・3着(最大12点)。オッズ' . (int)BALANCE_MAX_ODDS . '倍超の大穴は除きます。',
    '一撃重視' => '予想1位を1着に固定し、2〜4位から2・3着(最大6点)。オッズ' . (int)ICHIGEKI_MIN_ODDS . '倍未満の組は除き、配当を狙います。',
    '絞り込み' => '予想上位3艇の3連単6通りから、確率の高い順に3点。点数を絞ります。',
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>艇王 - 艇王について</title>
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<style>
.container { max-width: 860px; margin: 0 auto; padding: 20px 16px; }
.page-title-row { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.back-btn { color: #0055a4; text-decoration: none; font-size: 20px; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; }
.about-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 14px; }
.about-card h3 { font-size: 15px; color: #0055a4; margin: 0 0 10px; }
.about-card p, .about-card li { font-size: 13px; line-height: 1.8; color: #333; }
.about-card ol, .about-card ul { padding-left: 1.3em; margin: 0; }
.about-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.about-table th, .about-table td { border-bottom: 1px solid #eef0f3; padding: 7px 6px; text-align: left; vertical-align: top; }
.about-table th { color: #666; font-weight: 700; white-space: nowrap; }
.about-table td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.table-wrap { overflow-x: auto; }
.about-note { font-size: 12px; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 8px 12px; margin-top: 10px; line-height: 1.7; }
.strat-name { font-weight: 700; white-space: nowrap; }
</style>
<script src="venue-display.js"></script>
<script src="strategy-colors.js?v=<?php echo (int)@filemtime(__DIR__ . '/strategy-colors.js'); ?>"></script>
</head>
<body>
<?php include 'header.php'; ?>
<div class="dashboard-container">
  <script>var ACTIVE_NAV = 'about';</script>
  <?php include 'sidebar.php'; ?>
  <main class="main-content">
  <div class="container">

  <div class="page-title-row">
    <a class="back-btn" href="index.php">&larr;</a>
    <h2 class="section-title">艇王について</h2>
  </div>

  <div class="about-card">
    <h3>このサイトの目的</h3>
    <p>艇王は、ボートレースの出走表・直前情報・オッズ・レース結果を毎日自動で集め、統計モデルで各艇の1着確率を計算し、
      3連単の予想と買い目を出すサイトです。予想がどれだけ当たり、どれだけ回収できたかを、成績ページですべて公開しています。</p>
  </div>

  <div class="about-card">
    <h3>使い方</h3>
    <ol>
      <li>トップページで日付とレース場を選び、レースを開きます。</li>
      <li>「予想」で予測順位を確認します(どなたでも見られます)。Standard 以上の会員は、1着確率・買い目・予想の根拠・AI解説も見られます。</li>
      <li>レース後は「結果」と「今日の的中」、「成績・回収率」で、予想の答え合わせができます。</li>
    </ol>
  </div>

  <div class="about-card">
    <h3>予想の仕組み</h3>
    <ul>
      <li><b>予想は統計モデルの計算です。</b>ロジスティック回帰というモデルが、選手の全国・当地の成績、その枠番での成績、
        直近10走、級別、今期の平均スタート、展示タイム、展示のスタート、モーター2連率などから各艇の1着確率を計算し、確率の高い順を予測順位にしています。</li>
      <li>計算に使うのはレース日より前のデータと、レース前に公開される直前情報だけです。結果を知った後に予想を書き換えることはしていません。</li>
      <li>各艇の「予想の根拠」は、モデルの計算でその艇の評価をレース平均より上げた要因・下げた要因です。</li>
      <li><b>生成AIを使っているのは、予想をもとにした解説文(AI解説)だけです。</b>予想そのもの(順位・確率・買い目)にはAIを使っていません。</li>
    </ul>
  </div>

  <div class="about-card">
    <h3>4つの買い方(戦略)</h3>
    <div class="table-wrap">
    <table class="about-table">
      <?php foreach ($STRATS as $name => $desc): ?>
      <tr><th><span class="strat-name"><?= htmlspecialchars($name) ?></span></th>
          <td><?= htmlspecialchars($desc) ?></td></tr>
      <?php endforeach; ?>
    </table>
    </div>
    <p style="margin-top:8px;">賭け金は、各買い目の確率に比例して配分しています(1点平均<?= number_format(STAKE_UNIT) ?>円、最低<?= STAKE_MIN ?>円)。</p>
  </div>

  <div class="about-card">
    <h3>成績の見方</h3>
    <p><b>回収率</b> = 払戻金 ÷ 投資額。100%なら収支はトントン、100%未満なら損をしていることを表します。</p>
    <?php if ($stats && $range): ?>
    <p style="margin-top:8px;">本番の成績(<?= htmlspecialchars(jd($range['min_d'])) ?>〜<?= htmlspecialchars(jd($range['max_d'])) ?>、<?= number_format((int)$range['n']) ?>レース):</p>
    <div class="table-wrap">
    <table class="about-table">
      <tr><th>戦略</th><th>的中率</th><th>回収率</th></tr>
      <?php foreach (array_keys($STRATS) as $name): $r = $stats[$name] ?? null; if (!$r) continue; ?>
      <tr><td class="strat-name"><?= htmlspecialchars($name) ?></td>
          <td class="num"><?= pct1((int)$r['hits'], (int)$r['races']) ?></td>
          <td class="num"><?= pct1((int)$r['payout'], (int)$r['cost']) ?></td></tr>
      <?php endforeach; ?>
    </table>
    </div>
    <?php if ($top1 && $top1['races'] > 0): ?>
    <p style="margin-top:8px;">予測1位が1着になった割合: <b><?= pct1($top1['v3w_hits'], $top1['races']) ?></b>
      (<?= jd(V3W_PRODUCTION_FROM) ?>〜、<?= number_format($top1['races']) ?>レース)。
      参考までに、毎回1号艇を1着と予想した場合は <?= pct1($top1['lane1_wins'], $top1['races']) ?> です。</p>
    <?php endif; ?>
    <div class="about-note">
      いずれの戦略も、回収率は100%を下回っています。つまり、この予想どおりに買い続けると、平均すると損をします。
      予想は参考情報としてご利用ください。成績の詳細は<a href="performance.php">成績・回収率</a>で確認できます。
    </div>
    <?php else: ?>
    <p>成績は<a href="performance.php">成績・回収率</a>で確認できます。</p>
    <?php endif; ?>
  </div>

  </div>
  </main>
</div>
<script src="common-header.js"></script>
</body>
</html>
