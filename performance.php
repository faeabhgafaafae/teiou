<?php
require_once __DIR__ . '/auth.php';
$user = current_user();
$plan = $user['plan'] ?? 'free';
$isPremium = ($plan === 'standard' || $plan === 'premium');
$isPremiumOnly = ($plan === 'premium');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>艇王 - 成績・回収率</title>
<link rel="stylesheet" href="style.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
.back-btn { color: #0055a4; text-decoration: none; font-size: 20px; line-height: 1; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: background 0.15s; }
.back-btn:hover { background: #e8f0fd; }
.page-title-row { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.container { max-width: 1000px; margin: 0 auto; padding: 20px 16px; }

.note { font-size: 11px; color: #a0724b; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; padding: 8px 12px; margin-bottom: 12px; line-height: 1.6; }
.loading { text-align: center; padding: 30px; color: #999; font-size: 13px; }
.error-msg { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; padding: 14px; color: #dc2626; font-size: 13px; }

/* 戦略カード(サマリー) */
.strategy-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.strategy-card { margin-bottom: 0; }
.strategy-card-name { font-size: 13px; font-weight: 800; color: #0055a4; margin-bottom: 8px; }
.strategy-card-row { display: flex; justify-content: space-between; font-size: 12px; color: #555; padding: 3px 0; }
.strategy-card-row strong { color: #222; font-variant-numeric: tabular-nums; }
.strategy-card-row.profit-plus strong { color: #16a34a; }
.strategy-card-row.profit-minus strong { color: #dc2626; }

.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 10px; border: 1px solid #e0e3e8; }
table.data-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 480px; }
table.data-table th { background: #f7f8fa; font-size: 10px; font-weight: 700; color: #999; padding: 8px 6px; border-bottom: 2px solid #e0e3e8; white-space: nowrap; text-align: center; }
table.data-table td { padding: 8px 6px; border-bottom: 1px solid #f0f0f0; text-align: center; white-space: nowrap; }
table.data-table tr:last-child td { border-bottom: none; }
.roi-plus { color: #16a34a; font-weight: 700; }
.roi-minus { color: #dc2626; font-weight: 700; }

/* プレミアム限定バナー */
.premium-lock { text-align: center; padding: 30px 16px; }
.premium-lock-icon { font-size: 28px; margin-bottom: 10px; display: block; }
.premium-lock p { font-size: 13px; color: #666; margin-bottom: 14px; }
.premium-lock a { display: inline-block; padding: 9px 22px; border-radius: 8px; background: #d97706; color: #fff; font-size: 13px; font-weight: 700; text-decoration: none; }
.premium-lock a:hover { background: #b45309; }

/* 会場横断比較 */
.filter-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 14px; }
.filter-row select { padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; color: #333; }
.filter-row label { font-size: 12px; color: #666; font-weight: 600; }
table.venue-cmp-table th { cursor: pointer; user-select: none; }
table.venue-cmp-table th:hover { color: #0055a4; }
table.venue-cmp-table th.sorted { color: #0055a4; }
.sort-arrow { font-size: 9px; margin-left: 2px; }
svg.bar-chart { width: 100%; height: auto; }

/* 個別レース詳細(スコア内訳) */
.race-detail-row { border: 1px solid #e0e3e8; border-radius: 8px; margin-bottom: 8px; overflow: hidden; }
.race-detail-hdr { display: flex; align-items: center; gap: 8px; padding: 9px 12px; cursor: pointer; background: #fff; flex-wrap: wrap; }
.race-detail-hdr:hover { background: #fafbfc; }
.race-detail-venue { font-size: 12px; font-weight: 700; color: #222; }
.race-detail-strat { font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 10px; background: #e2e8f0; color: #4a5568; }
.race-detail-hit { font-size: 11px; font-weight: 700; }
.race-detail-hit.hit { color: #16a34a; }
.race-detail-hit.miss { color: #999; }
.race-detail-toggle { margin-left: auto; font-size: 11px; color: #777; border: 1px solid #e0e3e8; border-radius: 6px; padding: 4px 8px; background: #fff; cursor: pointer; }
.race-detail-body { display: none; border-top: 1px solid #f0f0f0; padding: 10px 14px; }
.race-detail-body.open { display: block; }

.bk-section { margin-top: 0; }
.bk-lock { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 8px 12px; text-align: center; font-size: 11px; color: #92400e; }
.bk-lock a { color: #d97706; font-weight: 700; text-decoration: none; }
.bk-group-title { font-size: 10px; font-weight: 700; color: #888; letter-spacing: 0.04em; margin: 7px 0 3px; border-left: 2px solid #cbd5e1; padding-left: 5px; }
.bk-row { display: flex; align-items: center; gap: 6px; padding: 2px 0; font-size: 11px; color: #333; }
.bk-label { width: 116px; flex-shrink: 0; color: #666; }
.bk-value { font-weight: 700; color: #222; font-variant-numeric: tabular-nums; }
.bk-sub { color: #999; font-size: 10px; }
.bk-chip { background: #eef2ff; color: #3b4fd8; border-radius: 4px; padding: 1px 7px; font-size: 11px; font-weight: 700; font-variant-numeric: tabular-nums; }

/* 折れ線グラフ */
.chart-legend { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; font-size: 11px; }
.chart-legend-item { display: flex; align-items: center; gap: 4px; }
.chart-legend-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
svg.trend-chart { width: 100%; height: auto; }

@media (max-width: 600px) {
  .strategy-grid { grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); }
}
</style>
<script src="venue-display.js"></script>
</head>
<body>

  <?php include 'header.php'; ?>

<div class="dashboard-container">

  <script>var ACTIVE_NAV = 'performance';</script>
  <?php include 'sidebar.php'; ?>

  <main class="main-content">
  <div class="container">

  <div class="page-title-row">
    <a class="back-btn" href="index.php">&larr;</a>
    <h2 class="section-title">成績・回収率</h2>
  </div>

  <!-- 1. 全体サマリー(無料) -->
  <div class="card">
    <h2>全体サマリー</h2>
    <div id="summaryResult"><div class="loading">読み込み中...</div></div>
  </div>

  <!-- 2. 日別推移グラフ(premium) -->
  <div class="card">
    <h2>日別推移(的中率・回収率)</h2>
    <?php if ($isPremium): ?>
      <div id="dailyResult"><div class="loading">読み込み中...</div></div>
    <?php else: ?>
      <div class="premium-lock">
        <span class="premium-lock-icon">&#128274;</span>
        <p>日別推移グラフは Standard / Premium プラン限定機能です。</p>
        <a href="upgrade.html">プランをアップグレード</a>
      </div>
    <?php endif; ?>
  </div>

  <!-- 3. 会場別内訳(premium) -->
  <div class="card">
    <h2>会場別 内訳</h2>
    <?php if ($isPremium): ?>
      <div id="venueResult"><div class="loading">読み込み中...</div></div>
    <?php else: ?>
      <div class="premium-lock">
        <span class="premium-lock-icon">&#128274;</span>
        <p>会場別の内訳は Standard / Premium プラン限定機能です。</p>
        <a href="upgrade.html">プランをアップグレード</a>
      </div>
    <?php endif; ?>
  </div>

  <!-- 4. 戦略比較表(premium) -->
  <div class="card">
    <h2>戦略比較表</h2>
    <?php if ($isPremium): ?>
      <div id="compareResult"><div class="loading">読み込み中...</div></div>
    <?php else: ?>
      <div class="premium-lock">
        <span class="premium-lock-icon">&#128274;</span>
        <p>戦略比較表は Standard / Premium プラン限定機能です。</p>
        <a href="upgrade.html">プランをアップグレード</a>
      </div>
    <?php endif; ?>
  </div>

  <!-- 5. 会場横断比較(premium限定) -->
  <div class="card" id="venueCmpCard">
    <h2>会場横断比較</h2>
    <?php if ($isPremiumOnly): ?>
      <div class="note">全会場・全期間の戦略別成績を横断比較できます。</div>
      <div id="venueCmpChartResult"><div class="loading">読み込み中...</div></div>
      <div class="filter-row" style="margin-top:16px;">
        <label>会場:</label>
        <select id="venueCmpFilterVenue">
          <option value="">全会場</option>
        </select>
        <label>戦略:</label>
        <select id="venueCmpFilterStrategy">
          <option value="">すべて</option>
          <option value="的中特化">的中特化</option>
          <option value="バランス">バランス</option>
          <option value="一撃重視">一撃重視</option>
          <option value="絞り込み">絞り込み</option>
        </select>
      </div>
      <div id="venueCmpTableResult"><div class="loading">読み込み中...</div></div>
    <?php else: ?>
      <div class="premium-lock">
        <span class="premium-lock-icon">&#128274;</span>
        <p>会場横断の的中率比較は Premium プラン限定機能です。</p>
        <a href="upgrade.html">プランをアップグレード</a>
      </div>
    <?php endif; ?>
  </div>

  <!-- 6. 個別レース詳細(スコア内訳、premium限定) -->
  <div class="card">
    <h2>個別レース詳細(スコア内訳)</h2>
    <?php if ($isPremiumOnly): ?>
      <div id="raceDetailResult"><div class="loading">読み込み中...</div></div>
    <?php else: ?>
      <div class="premium-lock">
        <span class="premium-lock-icon">&#128274;</span>
        <p>個別レースの詳細スコア内訳は Premium プラン限定機能です。</p>
        <a href="upgrade.html">プランをアップグレード</a>
      </div>
    <?php endif; ?>
  </div>

  </div>
  </main>

</div>

<script>
// --- 共通ヘッダー(index.php/mypage.php/analysis.phpと同じロジック) ---
(function() {
  function formatHeaderDate(dateStr) {
    var d = new Date(dateStr + 'T00:00:00');
    var days = ['日','月','火','水','木','金','土'];
    return d.getFullYear() + '年' + (d.getMonth()+1) + '月' + d.getDate() + '日 (' + days[d.getDay()] + ')';
  }
  async function loadHeaderDate() {
    try {
      var res = await fetch('https://2410049.moo.jp/venues.php');
      if (res.ok) {
        var data = await res.json();
        var el = document.getElementById('headerDate');
        if (el) el.textContent = formatHeaderDate(data.date);
      }
    } catch (e) { console.error(e); }
  }
  async function checkAuth() {
    var authEl = document.getElementById('headerAuth');
    if (!authEl) return;
    try {
      var res = await fetch('me.php');
      if (!res.ok) {
        authEl.innerHTML = '<a class="auth-link" href="login.html">ログイン</a><a class="auth-link register" href="register.html">新規登録</a>';
        return;
      }
      var data = await res.json();
      var user = data.user;
      var planLabel = { free: 'Free', standard: 'Standard', premium: 'Premium' };
      var planClass = user.plan !== 'free' ? user.plan : '';

      authEl.innerHTML = '<div class="user-menu">' +
          '<button class="user-btn" id="userBtn">' +
            '<span>' + user.name + '</span>' +
            '<span class="plan-badge ' + planClass + '">' + (planLabel[user.plan] || 'Free') + '</span>' +
          '</button>' +
          '<div class="dropdown" id="userDropdown">' +
            '<button class="dropdown-item" onclick="location.href=\'mypage.php\'"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:8px; vertical-align:-2px; color:#718096;"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>マイページ</button>' +
            '<button class="dropdown-item logout" id="logoutBtn" style="border-top: 1px solid #edf2f7; color: #dc2626;"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:8px; vertical-align:-2px;"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>ログアウト</button>' +
          '</div>' +
        '</div>';

      document.getElementById('userBtn').addEventListener('click', function(e) {
        e.stopPropagation();
        document.getElementById('userDropdown').classList.toggle('open');
      });
      document.addEventListener('click', function() {
        var dropdown = document.getElementById('userDropdown');
        if (dropdown) dropdown.classList.remove('open');
      });
      document.getElementById('logoutBtn').addEventListener('click', async function() {
        await fetch('logout.php');
        location.href = 'index.php';
      });
    } catch (err) {
      authEl.innerHTML = '<a class="auth-link" href="login.html">ログイン</a><a class="auth-link register" href="register.html">新規登録</a>';
    }
  }
  var headerLogoEl = document.getElementById('headerLogo');
  if (headerLogoEl) headerLogoEl.addEventListener('click', function() { location.href = 'index.php'; });
  loadHeaderDate();
  checkAuth();
})();

var API_HOST = 'https://' + '2410049.moo.jp';
var IS_PREMIUM = <?php echo $isPremium ? 'true' : 'false'; ?>;
var IS_PREMIUM_ONLY = <?php echo $isPremiumOnly ? 'true' : 'false'; ?>;
var STRATEGY_COLORS = { '的中特化': '#0055a4', 'バランス': '#16a34a', '一撃重視': '#dc2626', '絞り込み': '#d97706' };

function formatDateJP(iso) {
  if (!iso) return '';
  var parts = iso.split('-');
  if (parts.length !== 3) return iso;
  return parts[0] + '年' + Number(parts[1]) + '月' + Number(parts[2]) + '日';
}

function makeLoading(text) {
  var d = document.createElement('div');
  d.className = 'loading';
  d.textContent = text;
  return d;
}
function makeError(text) {
  var d = document.createElement('div');
  d.className = 'error-msg';
  d.textContent = text;
  return d;
}
function roiSpan(roi) {
  var span = document.createElement('span');
  span.className = roi >= 0 ? 'roi-plus' : 'roi-minus';
  span.textContent = (roi >= 0 ? '+' : '') + roi.toFixed(1) + '%';
  return span;
}

// ============================================================
// 1. 全体サマリー
// ============================================================
async function loadSummary() {
  var el = document.getElementById('summaryResult');
  el.textContent = '';
  el.appendChild(makeLoading('読み込み中...'));

  try {
    var res = await fetch(API_HOST + '/get_performance_summary.php');
    var data = await res.json();
    if (data.error) throw new Error(data.error);

    el.textContent = '';

    var note = document.createElement('div');
    note.className = 'note';
    note.textContent = '集計期間: ' + formatDateJP(data.date_range.min_date) + ' 〜 ' + formatDateJP(data.date_range.max_date) +
      '(対象 ' + data.date_range.race_count.toLocaleString() + 'レース)。運用開始から間もないため、サンプル数はまだ少ない点にご留意ください。';
    el.appendChild(note);

    if (!data.stats || data.stats.length === 0) {
      el.appendChild(makeError('集計対象データがありません'));
      return;
    }

    var grid = document.createElement('div');
    grid.className = 'strategy-grid';
    data.stats.forEach(function(s) {
      var card = document.createElement('div');
      card.className = 'card strategy-card';

      var name = document.createElement('div');
      name.className = 'strategy-card-name';
      name.textContent = s.strategy_type;
      card.appendChild(name);

      var rows = [
        ['対象レース数', s.total_races + '件'],
        ['的中率', s.hit_rate.toFixed(1) + '%'],
        ['投資額', s.total_cost.toLocaleString() + '円'],
        ['払戻額', s.total_payout.toLocaleString() + '円'],
      ];
      rows.forEach(function(r) {
        var rowEl = document.createElement('div');
        rowEl.className = 'strategy-card-row';
        var lbl = document.createElement('span');
        lbl.textContent = r[0];
        var val = document.createElement('strong');
        val.textContent = r[1];
        rowEl.appendChild(lbl); rowEl.appendChild(val);
        card.appendChild(rowEl);
      });

      var roiRow = document.createElement('div');
      roiRow.className = 'strategy-card-row ' + (s.profit >= 0 ? 'profit-plus' : 'profit-minus');
      var roiLbl = document.createElement('span');
      roiLbl.textContent = '回収率';
      var roiVal = document.createElement('strong');
      roiVal.textContent = (s.roi + 100).toFixed(1) + '%';
      roiRow.appendChild(roiLbl); roiRow.appendChild(roiVal);
      card.appendChild(roiRow);

      grid.appendChild(card);
    });
    el.appendChild(grid);
  } catch (e) {
    el.textContent = '';
    el.appendChild(makeError('データの取得に失敗しました'));
  }
}
loadSummary();

// ============================================================
// 2. 日別推移グラフ(SVG、premiumのみ)
// ============================================================
function buildTrendChart(daily, metricKey, metricSuffix) {
  var strategies = {};
  daily.forEach(function(d) {
    if (!strategies[d.strategy_type]) strategies[d.strategy_type] = [];
    strategies[d.strategy_type].push(d);
  });

  var dates = [];
  daily.forEach(function(d) { if (dates.indexOf(d.date) === -1) dates.push(d.date); });
  dates.sort();

  var W = 640, H = 220, padL = 40, padR = 10, padT = 10, padB = 24;
  var plotW = W - padL - padR, plotH = H - padT - padB;

  var values = daily.map(function(d) { return d[metricKey]; });
  var minV = Math.min(0, Math.min.apply(null, values));
  var maxV = Math.max(10, Math.max.apply(null, values));
  var range = maxV - minV || 1;

  function xPos(i) { return padL + (dates.length <= 1 ? 0 : (i / (dates.length - 1)) * plotW); }
  function yPos(v) { return padT + plotH - ((v - minV) / range) * plotH; }

  var svgParts = [];
  svgParts.push('<svg class="trend-chart" viewBox="0 0 ' + W + ' ' + H + '" xmlns="http://www.w3.org/2000/svg">');
  // 0ライン
  var zeroY = yPos(0);
  svgParts.push('<line x1="' + padL + '" y1="' + zeroY + '" x2="' + (W - padR) + '" y2="' + zeroY + '" stroke="#e0e3e8" stroke-width="1" />');

  Object.keys(strategies).forEach(function(type) {
    var color = STRATEGY_COLORS[type] || '#888';
    var points = [];
    dates.forEach(function(date, i) {
      var row = strategies[type].filter(function(d) { return d.date === date; })[0];
      if (row) points.push(xPos(i) + ',' + yPos(row[metricKey]));
    });
    if (points.length > 0) {
      svgParts.push('<polyline points="' + points.join(' ') + '" fill="none" stroke="' + color + '" stroke-width="2" />');
    }
  });

  // x軸ラベル(先頭・末尾のみ)
  if (dates.length > 0) {
    svgParts.push('<text x="' + padL + '" y="' + (H - 6) + '" font-size="10" fill="#999">' + dates[0] + '</text>');
    svgParts.push('<text x="' + (W - padR) + '" y="' + (H - 6) + '" font-size="10" fill="#999" text-anchor="end">' + dates[dates.length - 1] + '</text>');
  }

  svgParts.push('</svg>');

  var wrap = document.createElement('div');
  wrap.innerHTML = svgParts.join('');
  return wrap.firstChild;
}

function buildLegend(types) {
  var legend = document.createElement('div');
  legend.className = 'chart-legend';
  types.forEach(function(t) {
    var item = document.createElement('div');
    item.className = 'chart-legend-item';
    var dot = document.createElement('span');
    dot.className = 'chart-legend-dot';
    dot.style.background = STRATEGY_COLORS[t] || '#888';
    var label = document.createElement('span');
    label.textContent = t;
    item.appendChild(dot); item.appendChild(label);
    legend.appendChild(item);
  });
  return legend;
}

async function loadDaily() {
  if (!IS_PREMIUM) return;
  var el = document.getElementById('dailyResult');
  el.textContent = '';
  el.appendChild(makeLoading('読み込み中...'));

  try {
    var res = await fetch(API_HOST + '/get_performance_daily.php');
    var data = await res.json();
    if (data.error) throw new Error(data.message || data.error);

    el.textContent = '';
    if (!data.daily || data.daily.length === 0) {
      el.appendChild(makeError('データがありません'));
      return;
    }

    var types = [];
    data.daily.forEach(function(d) { if (types.indexOf(d.strategy_type) === -1) types.push(d.strategy_type); });

    var note = document.createElement('div');
    note.className = 'note';
    note.textContent = '運用開始から日が浅いため、日別の変動が大きく出る場合があります。';
    el.appendChild(note);

    var titleR1 = document.createElement('div');
    titleR1.style.fontSize = '12px'; titleR1.style.fontWeight = '700'; titleR1.style.color = '#555'; titleR1.style.margin = '8px 0 4px';
    titleR1.textContent = '的中率(%)の推移';
    el.appendChild(titleR1);
    el.appendChild(buildLegend(types));
    el.appendChild(buildTrendChart(data.daily, 'hit_rate'));

    var titleR2 = document.createElement('div');
    titleR2.style.fontSize = '12px'; titleR2.style.fontWeight = '700'; titleR2.style.color = '#555'; titleR2.style.margin = '18px 0 4px';
    titleR2.textContent = '回収率(損益率, %)の推移';
    el.appendChild(titleR2);
    el.appendChild(buildLegend(types));
    el.appendChild(buildTrendChart(data.daily, 'roi'));
  } catch (e) {
    el.textContent = '';
    el.appendChild(makeError('データの取得に失敗しました'));
  }
}
loadDaily();

// ============================================================
// 3. 会場別内訳(premium)
// ============================================================
async function loadVenue() {
  if (!IS_PREMIUM) return;
  var el = document.getElementById('venueResult');
  el.textContent = '';
  el.appendChild(makeLoading('読み込み中...'));

  try {
    var res = await fetch(API_HOST + '/get_performance_venue.php');
    var data = await res.json();
    if (data.error) throw new Error(data.message || data.error);

    el.textContent = '';
    if (!data.by_venue || data.by_venue.length === 0) {
      el.appendChild(makeError('データがありません'));
      return;
    }

    var note = document.createElement('div');
    note.className = 'note';
    note.textContent = '賭式別(3連単/2連単等)の内訳は、現在すべての戦略が3連単のみを対象としているため区別できるデータがなく、非表示にしています。';
    el.appendChild(note);

    var wrap = document.createElement('div');
    wrap.className = 'table-wrap';
    var table = document.createElement('table');
    table.className = 'data-table';
    var thead = document.createElement('thead');
    var hrow = document.createElement('tr');
    ['会場', '戦略', '対象数', '的中率', '回収率'].forEach(function(h) {
      var th = document.createElement('th');
      th.textContent = h;
      hrow.appendChild(th);
    });
    thead.appendChild(hrow);
    table.appendChild(thead);
    var tbody = document.createElement('tbody');
    data.by_venue.forEach(function(r) {
      var tr = document.createElement('tr');
      var tdVenue = document.createElement('td'); tdVenue.textContent = venueDisplayName(r.venue); tr.appendChild(tdVenue);
      var tdType = document.createElement('td'); tdType.textContent = r.strategy_type; tr.appendChild(tdType);
      var tdCount = document.createElement('td'); tdCount.textContent = r.total_races; tr.appendChild(tdCount);
      var tdHit = document.createElement('td'); tdHit.textContent = r.hit_rate.toFixed(1) + '%'; tr.appendChild(tdHit);
      var tdRoi = document.createElement('td'); tdRoi.appendChild(roiSpan(r.roi)); tr.appendChild(tdRoi);
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    wrap.appendChild(table);
    el.appendChild(wrap);
  } catch (e) {
    el.textContent = '';
    el.appendChild(makeError('データの取得に失敗しました'));
  }
}
loadVenue();

// ============================================================
// 4. 戦略比較表(premium、summaryと同じデータを再利用)
// ============================================================
async function loadCompare() {
  if (!IS_PREMIUM) return;
  var el = document.getElementById('compareResult');
  el.textContent = '';
  el.appendChild(makeLoading('読み込み中...'));

  try {
    var res = await fetch(API_HOST + '/get_performance_summary.php');
    var data = await res.json();
    if (data.error) throw new Error(data.error);

    el.textContent = '';
    if (!data.stats || data.stats.length === 0) {
      el.appendChild(makeError('データがありません'));
      return;
    }

    var wrap = document.createElement('div');
    wrap.className = 'table-wrap';
    var table = document.createElement('table');
    table.className = 'data-table';
    var thead = document.createElement('thead');
    var hrow = document.createElement('tr');
    var headers = ['指標'].concat(data.stats.map(function(s) { return s.strategy_type; }));
    headers.forEach(function(h) {
      var th = document.createElement('th');
      th.textContent = h;
      hrow.appendChild(th);
    });
    thead.appendChild(hrow);
    table.appendChild(thead);

    var tbody = document.createElement('tbody');
    var metricRows = [
      ['対象レース数', function(s) { return s.total_races + '件'; }],
      ['的中率', function(s) { return s.hit_rate.toFixed(1) + '%'; }],
      ['投資額', function(s) { return s.total_cost.toLocaleString() + '円'; }],
      ['払戻額', function(s) { return s.total_payout.toLocaleString() + '円'; }],
      ['回収率(損益率)', function(s) {
        var span = document.createElement('span');
        span.className = s.roi >= 0 ? 'roi-plus' : 'roi-minus';
        span.textContent = (s.roi >= 0 ? '+' : '') + s.roi.toFixed(1) + '%';
        return span;
      }],
    ];
    metricRows.forEach(function(mr) {
      var tr = document.createElement('tr');
      var tdLabel = document.createElement('td');
      tdLabel.style.fontWeight = '700';
      tdLabel.style.textAlign = 'left';
      tdLabel.style.paddingLeft = '10px';
      tdLabel.textContent = mr[0];
      tr.appendChild(tdLabel);
      data.stats.forEach(function(s) {
        var td = document.createElement('td');
        var v = mr[1](s);
        if (typeof v === 'string') td.textContent = v; else td.appendChild(v);
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    wrap.appendChild(table);
    el.appendChild(wrap);
  } catch (e) {
    el.textContent = '';
    el.appendChild(makeError('データの取得に失敗しました'));
  }
}
loadCompare();

// ============================================================
// 5. 会場横断比較(premium限定、旧premium_dashboard.phpから統合)
// get_dashboard_comparison.php のデータ取得ロジックはそのまま流用。
// ============================================================
var ALL_VENUES = [
  '桐生','戸田','江戸川','平和島','多摩川','浜名湖',
  '蒲郡','常滑','津','三国','琵琶湖','住之江',
  '尼崎','鳴門','高松','丸亀','児島','宮島','徳山',
  '下関','若松','芦屋','福岡','唐津','大村'
];

var venueCmpAllRows = [];
var venueCmpSortKey = 'hit_rate';
var venueCmpSortDir = 'desc';

function buildVenueCmpBarChart(rows) {
  var sorted = rows.slice().sort(function(a, b) { return b.hit_rate - a.hit_rate; });
  var BAR_H = 18, GAP = 6, padL = 60, padR = 100, W = 640;
  var H = GAP + sorted.length * (BAR_H + GAP);
  var plotW = W - padL - padR;

  var svgParts = [];
  svgParts.push('<svg class="bar-chart" viewBox="0 0 ' + W + ' ' + H + '" xmlns="http://www.w3.org/2000/svg">');
  sorted.forEach(function(row, i) {
    var y = GAP + i * (BAR_H + GAP);
    var rate = Math.max(0, Math.min(100, row.hit_rate)) / 100;
    var barW = Math.max(2, rate * plotW);
    var color = row.hit_rate >= 30 ? '#16a34a' : row.hit_rate >= 15 ? '#d97706' : '#dc2626';
    svgParts.push('<rect x="' + padL + '" y="' + y + '" width="' + plotW + '" height="' + BAR_H + '" rx="4" fill="#f0f4f8" />');
    svgParts.push('<rect x="' + padL + '" y="' + y + '" width="' + barW + '" height="' + BAR_H + '" rx="4" fill="' + color + '" />');
    svgParts.push('<text x="' + (padL - 6) + '" y="' + (y + BAR_H / 2 + 4) + '" font-size="11" fill="#555" text-anchor="end">' + venueDisplayName(row.venue) + '</text>');
    svgParts.push('<text x="' + (padL + barW + 6) + '" y="' + (y + BAR_H / 2 + 4) + '" font-size="10" fill="#555">' + row.hit_rate.toFixed(1) + '% (' + row.total_races + '件)</text>');
  });
  svgParts.push('</svg>');
  var wrap = document.createElement('div');
  wrap.innerHTML = svgParts.join('');
  return wrap.firstChild;
}

function renderVenueCmpChart() {
  var el = document.getElementById('venueCmpChartResult');
  el.textContent = '';

  var strategyFilter = document.getElementById('venueCmpFilterStrategy').value;
  var byVenue = {};
  venueCmpAllRows.forEach(function(r) {
    if (strategyFilter && r.strategy_type !== strategyFilter) return;
    if (!byVenue[r.venue]) byVenue[r.venue] = { venue: r.venue, total_races: 0, hits: 0 };
    byVenue[r.venue].total_races += r.total_races;
    byVenue[r.venue].hits += r.hits;
  });
  var venueRows = Object.keys(byVenue).map(function(v) {
    var b = byVenue[v];
    b.hit_rate = b.total_races > 0 ? (b.hits / b.total_races * 100) : 0;
    return b;
  }).filter(function(b) { return b.total_races > 0; });

  if (venueRows.length === 0) {
    el.appendChild(makeError('データがありません'));
    return;
  }

  var note = document.createElement('div');
  note.className = 'note';
  note.textContent = strategyFilter
    ? ('戦略「' + strategyFilter + '」の会場別的中率です。')
    : '全戦略を合算した会場別的中率です。下の比較表で戦略ごとに絞り込めます。';
  el.appendChild(note);
  el.appendChild(buildVenueCmpBarChart(venueRows));
}

function renderVenueCmpTable() {
  var el = document.getElementById('venueCmpTableResult');
  el.textContent = '';

  var venueFilter    = document.getElementById('venueCmpFilterVenue').value;
  var strategyFilter = document.getElementById('venueCmpFilterStrategy').value;

  var filtered = venueCmpAllRows.filter(function(r) {
    if (venueFilter    && r.venue         !== venueFilter)    return false;
    if (strategyFilter && r.strategy_type !== strategyFilter) return false;
    return true;
  });

  if (filtered.length === 0) {
    el.appendChild(makeError('該当するデータがありません'));
    return;
  }

  var sorted = filtered.slice().sort(function(a, b) {
    var av = a[venueCmpSortKey], bv = b[venueCmpSortKey];
    if (typeof av === 'string') {
      return venueCmpSortDir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
    }
    return venueCmpSortDir === 'asc' ? (av - bv) : (bv - av);
  });

  var wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  var table = document.createElement('table');
  table.className = 'data-table venue-cmp-table';

  var columns = [
    ['venue',       '会場'],
    ['strategy_type', '戦略'],
    ['total_races', '対象数'],
    ['hit_rate',    '的中率'],
    ['total_cost',  '投資額'],
    ['total_payout','払戻額'],
    ['roi',         '回収率']
  ];

  var thead = document.createElement('thead');
  var hrow = document.createElement('tr');
  columns.forEach(function(col) {
    var th = document.createElement('th');
    th.textContent = col[1];
    if (col[0] === venueCmpSortKey) {
      th.className = 'sorted';
      var arrow = document.createElement('span');
      arrow.className = 'sort-arrow';
      arrow.textContent = venueCmpSortDir === 'asc' ? '▲' : '▼';
      th.appendChild(arrow);
    }
    th.addEventListener('click', function() {
      if (venueCmpSortKey === col[0]) {
        venueCmpSortDir = venueCmpSortDir === 'asc' ? 'desc' : 'asc';
      } else {
        venueCmpSortKey = col[0];
        venueCmpSortDir = 'desc';
      }
      renderVenueCmpTable();
    });
    hrow.appendChild(th);
  });
  thead.appendChild(hrow);
  table.appendChild(thead);

  var tbody = document.createElement('tbody');
  sorted.forEach(function(row) {
    var tr = document.createElement('tr');

    var tdVenue = document.createElement('td'); tdVenue.textContent = venueDisplayName(row.venue); tr.appendChild(tdVenue);
    var tdType = document.createElement('td'); tdType.textContent = row.strategy_type; tr.appendChild(tdType);
    var tdCount = document.createElement('td'); tdCount.textContent = row.total_races; tr.appendChild(tdCount);
    var tdHit = document.createElement('td'); tdHit.textContent = row.hit_rate.toFixed(1) + '%'; tr.appendChild(tdHit);
    var tdCost = document.createElement('td'); tdCost.textContent = row.total_cost.toLocaleString() + '円'; tr.appendChild(tdCost);
    var tdPayout = document.createElement('td'); tdPayout.textContent = row.total_payout.toLocaleString() + '円'; tr.appendChild(tdPayout);
    var tdRoi = document.createElement('td'); tdRoi.appendChild(roiSpan(row.roi)); tr.appendChild(tdRoi);

    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  wrap.appendChild(table);
  el.appendChild(wrap);
}

async function loadVenueComparison() {
  if (!IS_PREMIUM_ONLY) return;

  var venueSelEl = document.getElementById('venueCmpFilterVenue');
  ALL_VENUES.forEach(function(v) {
    var opt = document.createElement('option');
    opt.value = v;
    opt.textContent = venueDisplayName(v);
    venueSelEl.appendChild(opt);
  });
  venueSelEl.addEventListener('change', renderVenueCmpTable);
  document.getElementById('venueCmpFilterStrategy').addEventListener('change', function() {
    renderVenueCmpChart();
    renderVenueCmpTable();
  });

  try {
    var res = await fetch(API_HOST + '/get_dashboard_comparison.php');
    var data = await res.json();
    if (data.error) throw new Error(data.message || data.error);
    venueCmpAllRows = data.by_venue || [];
    if (venueCmpAllRows.length === 0) {
      document.getElementById('venueCmpChartResult').appendChild(makeError('データがありません'));
      document.getElementById('venueCmpTableResult').appendChild(makeError('データがありません'));
      return;
    }
    renderVenueCmpChart();
    renderVenueCmpTable();
  } catch (e) {
    document.getElementById('venueCmpChartResult').textContent = '';
    document.getElementById('venueCmpChartResult').appendChild(makeError('データの取得に失敗しました'));
    document.getElementById('venueCmpTableResult').textContent = '';
    document.getElementById('venueCmpTableResult').appendChild(makeError('データの取得に失敗しました'));
  }
}
loadVenueComparison();

// ============================================================
// 6. 個別レース詳細(スコア内訳、premium限定)
// predict.php/ai-predict.php の詳細スコア内訳(get_prediction.phpの
// breakdown)と同じデータ・表示ロジックを流用する。
// ============================================================
function makeBkRow(label, value, sub) {
  var row = document.createElement('div');
  row.className = 'bk-row';
  var lbl = document.createElement('span');
  lbl.className = 'bk-label';
  lbl.textContent = label;
  var val = document.createElement('span');
  val.className = 'bk-value';
  val.textContent = value;
  row.appendChild(lbl);
  row.appendChild(val);
  if (sub) {
    var subEl = document.createElement('span');
    subEl.className = 'bk-sub';
    subEl.textContent = sub;
    row.appendChild(subEl);
  }
  return row;
}
function makeBkChipRow(label, val, max) {
  var row = document.createElement('div');
  row.className = 'bk-row';
  var lbl = document.createElement('span');
  lbl.className = 'bk-label';
  lbl.textContent = label;
  var chip = document.createElement('span');
  chip.className = 'bk-chip';
  chip.textContent = Number(val).toFixed(1) + ' / ' + max + 'pt';
  row.appendChild(lbl);
  row.appendChild(chip);
  return row;
}
function makeBkGroupTitle(text) {
  var el = document.createElement('div');
  el.className = 'bk-group-title';
  el.textContent = text;
  return el;
}

function renderRaceBreakdown(container, pred) {
  var bk = pred.breakdown;
  container.textContent = '';
  if (!bk) {
    var noData = document.createElement('div');
    noData.style.cssText = 'font-size:11px;color:#999;padding:4px 0;';
    noData.textContent = '内訳データを取得できませんでした。';
    container.appendChild(noData);
    return;
  }

  var head = document.createElement('div');
  head.style.cssText = 'font-size:12px;font-weight:700;color:#222;margin-bottom:4px;';
  head.textContent = pred.name + '(' + pred.lane + '号艇・予測' + pred.predicted_rank + '位・スコア' + Number(pred.score_total).toFixed(1) + ')';
  container.appendChild(head);

  container.appendChild(makeBkGroupTitle('選手能力 (max 40pt)'));
  container.appendChild(makeBkRow('全国勝率', bk.win_rate_national != null ? Number(bk.win_rate_national).toFixed(2) + '%' : '-'));
  container.appendChild(makeBkRow('当地勝率', bk.win_rate_local != null ? Number(bk.win_rate_local).toFixed(2) + '%' : '-',
    bk.local_total > 0 ? '(直近2年 ' + bk.local_total + '走)' : '(データなし→全国値を使用)'));
  container.appendChild(makeBkChipRow('素点', bk.score_ability_raw || 0, 40));

  container.appendChild(makeBkGroupTitle('コース補正 (max 35pt)'));
  if (bk.course_total > 0) {
    container.appendChild(makeBkRow(pred.lane + '号艇 (直近2年)', bk.course_total + '走'));
    container.appendChild(makeBkRow('1着率', bk.course_win_rate != null ? Number(bk.course_win_rate).toFixed(1) + '%' : '-'));
  } else {
    container.appendChild(makeBkRow('データ', 'なし', '(レーン平均値を適用)'));
  }
  container.appendChild(makeBkChipRow('素点', bk.score_course_raw || 0, 35));

  container.appendChild(makeBkGroupTitle('当日情報 (max 35pt)'));
  container.appendChild(makeBkRow('展示タイム', pred.exhibit_time != null ? Number(pred.exhibit_time).toFixed(2) + '秒' : 'なし', '→ ' + (bk.score_exhibit_raw || 0).toFixed(1) + 'pt / 15pt'));
  if (bk.is_flying) {
    container.appendChild(makeBkRow('スタートタイミング', 'F (フライング)', '→ -10pt 適用'));
  } else {
    container.appendChild(makeBkRow('スタートタイミング', pred.start_timing != null ? Number(pred.start_timing).toFixed(2) + '秒' : 'なし', '→ ' + (bk.score_st_raw || 0).toFixed(1) + 'pt / 10pt'));
  }
  container.appendChild(makeBkRow('モーター2連率', pred.motor_2rate != null ? Number(pred.motor_2rate).toFixed(1) + '%' : 'なし', '→ ' + (bk.score_motor_raw || 0).toFixed(1) + 'pt / 10pt'));
  container.appendChild(makeBkChipRow('小計', bk.score_today_raw || 0, 35));

  container.appendChild(makeBkGroupTitle('気象 (max 5pt)'));
  container.appendChild(makeBkRow('風速', bk.wind_speed != null ? Number(bk.wind_speed).toFixed(1) + 'm/s' : '-', bk.wind_dir || ''));
  container.appendChild(makeBkRow('波高', bk.wave_height != null ? Number(bk.wave_height).toFixed(0) + 'cm' : '-'));
  container.appendChild(makeBkChipRow('素点', bk.score_weather_raw || 0, 5));
}

async function toggleRaceDetail(row, body, race) {
  var isOpen = body.classList.contains('open');
  if (isOpen) {
    body.classList.remove('open');
    return;
  }
  body.classList.add('open');
  if (body.dataset.loaded === '1') return;
  body.textContent = '';
  body.appendChild(makeLoading('読み込み中...'));

  try {
    var qs = 'date=' + encodeURIComponent(race.date) + '&venue=' + encodeURIComponent(race.venue) + '&race_no=' + race.race_no;
    var res = await fetch(API_HOST + '/get_prediction.php?' + qs);
    var data = await res.json();
    if (data.error) throw new Error(data.error);

    body.textContent = '';
    if (!data.predictions || data.predictions.length === 0) {
      body.appendChild(makeError('予測データがありません'));
      return;
    }
    data.predictions.forEach(function(p, idx) {
      var box = document.createElement('div');
      box.className = 'bk-section';
      if (idx > 0) {
        box.style.borderTop = '1px dashed #e0e3e8';
        box.style.paddingTop = '8px';
        box.style.marginTop = '8px';
      }
      renderRaceBreakdown(box, p);
      body.appendChild(box);
    });
    body.dataset.loaded = '1';
  } catch (e) {
    body.textContent = '';
    body.appendChild(makeError('内訳の取得に失敗しました'));
  }
}

function renderRaceDetailRow(race) {
  var wrap = document.createElement('div');
  wrap.className = 'race-detail-row';

  var hdr = document.createElement('div');
  hdr.className = 'race-detail-hdr';

  var venueEl = document.createElement('span');
  venueEl.className = 'race-detail-venue';
  venueEl.textContent = venueDisplayName(race.venue) + ' ' + formatDateJP(race.date) + ' ' + race.race_no + 'R';
  hdr.appendChild(venueEl);

  var stratEl = document.createElement('span');
  stratEl.className = 'race-detail-strat';
  stratEl.textContent = race.strategy_type;
  hdr.appendChild(stratEl);

  var hitEl = document.createElement('span');
  hitEl.className = 'race-detail-hit ' + (race.is_hit ? 'hit' : 'miss');
  hitEl.textContent = race.is_hit ? ('的中 +' + race.payout.toLocaleString() + '円') : '不的中';
  hdr.appendChild(hitEl);

  if (race.combination) {
    var comboEl = document.createElement('span');
    comboEl.style.cssText = 'font-size:11px;color:#888;';
    comboEl.textContent = '結果 ' + race.combination;
    hdr.appendChild(comboEl);
  }

  var toggleBtn = document.createElement('button');
  toggleBtn.className = 'race-detail-toggle';
  toggleBtn.type = 'button';
  toggleBtn.textContent = 'スコア内訳を見る';
  hdr.appendChild(toggleBtn);

  var body = document.createElement('div');
  body.className = 'race-detail-body';

  hdr.addEventListener('click', function() {
    toggleRaceDetail(hdr, body, race);
  });

  wrap.appendChild(hdr);
  wrap.appendChild(body);
  return wrap;
}

async function loadRaceDetails() {
  if (!IS_PREMIUM_ONLY) return;
  var el = document.getElementById('raceDetailResult');
  el.textContent = '';
  el.appendChild(makeLoading('読み込み中...'));

  try {
    var res = await fetch(API_HOST + '/get_performance_races.php');
    var data = await res.json();
    if (data.error) throw new Error(data.message || data.error);

    el.textContent = '';
    if (!data.races || data.races.length === 0) {
      el.appendChild(makeError('データがありません'));
      return;
    }
    var note = document.createElement('div');
    note.className = 'note';
    note.textContent = '直近30件の戦略買い目を表示しています。「スコア内訳を見る」でAIがその予測に至った根拠(選手能力・コース補正・当日情報・気象)を確認できます。';
    el.appendChild(note);

    data.races.forEach(function(race) {
      el.appendChild(renderRaceDetailRow(race));
    });
  } catch (e) {
    el.textContent = '';
    el.appendChild(makeError('データの取得に失敗しました'));
  }
}
loadRaceDetails();
</script>
</body>
</html>
