<?php
require_once __DIR__ . '/auth.php';
$user = current_user();
$plan = $user['plan'] ?? 'free';
$isPremium = ($plan === 'premium');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>艇王 - 会場横断 的中率比較</title>
<link rel="stylesheet" href="style.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
.back-btn { color: #0055a4; text-decoration: none; font-size: 20px; line-height: 1; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: background 0.15s; }
.back-btn:hover { background: #e8f0fd; }
.page-title-row { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.container { max-width: 1000px; margin: 0 auto; padding: 20px 16px; }

.premium-lock { background: #fff; border: 1px solid #e0e3e8; border-radius: 12px; text-align: center; padding: 40px 20px; margin: 20px auto; max-width: 500px; }
.premium-lock-icon { font-size: 28px; margin-bottom: 10px; display: block; }
.premium-lock p { font-size: 13px; color: #666; margin-bottom: 14px; line-height: 1.6; }
.premium-lock a { display: inline-block; padding: 9px 22px; border-radius: 8px; background: #d97706; color: #fff; font-size: 13px; font-weight: 700; text-decoration: none; }
.premium-lock a:hover { background: #b45309; }

.note { font-size: 11px; color: #a0724b; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; padding: 8px 12px; margin-bottom: 12px; line-height: 1.6; }
.loading { text-align: center; padding: 30px; color: #999; font-size: 13px; }
.error-msg { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; padding: 14px; color: #dc2626; font-size: 13px; }

.filter-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 14px; }
.filter-row select { padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; color: #333; }
.filter-row label { font-size: 12px; color: #666; font-weight: 600; }

.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 10px; border: 1px solid #e0e3e8; }
table.data-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 560px; }
table.data-table th { background: #f7f8fa; font-size: 10px; font-weight: 700; color: #999; padding: 8px 6px; border-bottom: 2px solid #e0e3e8; white-space: nowrap; text-align: center; cursor: pointer; user-select: none; }
table.data-table th:hover { color: #0055a4; }
table.data-table th.sorted { color: #0055a4; }
table.data-table td { padding: 8px 6px; border-bottom: 1px solid #f0f0f0; text-align: center; white-space: nowrap; }
table.data-table tr:last-child td { border-bottom: none; }
.roi-plus { color: #16a34a; font-weight: 700; }
.roi-minus { color: #dc2626; font-weight: 700; }

.sort-arrow { font-size: 9px; margin-left: 2px; }

/* 会場別 的中率 棒グラフ */
svg.bar-chart { width: 100%; height: auto; }

@media (max-width: 600px) {
  .filter-row { flex-direction: column; align-items: stretch; }
}
</style>
<script src="venue-display.js"></script>
</head>
<body>

  <?php include 'header.php'; ?>

<div class="dashboard-container">

  <script>var ACTIVE_NAV = 'premium_dashboard';</script>
  <?php include 'sidebar.php'; ?>

  <main class="main-content">
  <div class="container">

  <div class="page-title-row">
    <a class="back-btn" href="index.php">&larr;</a>
    <h2 class="section-title">会場横断 的中率比較</h2>
  </div>

<?php if (!$isPremium): ?>
<div class="premium-lock">
  <span class="premium-lock-icon">&#128274;</span>
  <p>会場横断の的中率比較ダッシュボードはPremium会員限定機能です。<br>全会場・全期間の戦略別成績を横断比較できます。</p>
  <a href="upgrade.html">プランをアップグレード</a>
</div>
<?php else: ?>

  <div class="card">
    <h2>会場別 的中率</h2>
    <div id="chartResult"><div class="loading">読み込み中...</div></div>
  </div>

  <div class="card">
    <h2>会場×戦略 比較表</h2>
    <div class="filter-row">
      <label>会場:</label>
      <select id="filterVenue">
        <option value="">全会場</option>
      </select>
      <label>戦略:</label>
      <select id="filterStrategy">
        <option value="">すべて</option>
        <option value="的中特化">的中特化</option>
        <option value="バランス">バランス</option>
        <option value="一撃重視">一撃重視</option>
        <option value="絞り込み">絞り込み</option>
      </select>
    </div>
    <div id="tableResult"><div class="loading">読み込み中...</div></div>
  </div>

<?php endif; ?>

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

<?php if ($isPremium): ?>

var API_HOST = 'https://' + '2410049.moo.jp';
var ALL_VENUES = [
  '桐生','戸田','江戸川','平和島','多摩川','浜名湖',
  '蒲郡','常滑','津','三国','琵琶湖','住之江',
  '尼崎','鳴門','高松','丸亀','児島','宮島','徳山',
  '下関','若松','芦屋','福岡','唐津','大村'
];

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

var filterVenueEl = document.getElementById('filterVenue');
ALL_VENUES.forEach(function(v) {
  var opt = document.createElement('option');
  opt.value = v;
  opt.textContent = venueDisplayName(v);
  filterVenueEl.appendChild(opt);
});

var allRows = [];
var sortKey = 'hit_rate';
var sortDir = 'desc';

function buildBarChart(rows) {
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

function renderChart() {
  var el = document.getElementById('chartResult');
  el.textContent = '';

  var strategyFilter = document.getElementById('filterStrategy').value;
  var byVenue = {};
  allRows.forEach(function(r) {
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
  el.appendChild(buildBarChart(venueRows));
}

function renderTable() {
  var el = document.getElementById('tableResult');
  el.textContent = '';

  var venueFilter    = document.getElementById('filterVenue').value;
  var strategyFilter = document.getElementById('filterStrategy').value;

  var filtered = allRows.filter(function(r) {
    if (venueFilter    && r.venue         !== venueFilter)    return false;
    if (strategyFilter && r.strategy_type !== strategyFilter) return false;
    return true;
  });

  if (filtered.length === 0) {
    el.appendChild(makeError('該当するデータがありません'));
    return;
  }

  var sorted = filtered.slice().sort(function(a, b) {
    var av = a[sortKey], bv = b[sortKey];
    if (typeof av === 'string') {
      return sortDir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
    }
    return sortDir === 'asc' ? (av - bv) : (bv - av);
  });

  var wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  var table = document.createElement('table');
  table.className = 'data-table';

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
    if (col[0] === sortKey) {
      th.className = 'sorted';
      var arrow = document.createElement('span');
      arrow.className = 'sort-arrow';
      arrow.textContent = sortDir === 'asc' ? '▲' : '▼';
      th.appendChild(arrow);
    }
    th.addEventListener('click', function() {
      if (sortKey === col[0]) {
        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
      } else {
        sortKey = col[0];
        sortDir = 'desc';
      }
      renderTable();
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

async function loadDashboard() {
  try {
    var res = await fetch(API_HOST + '/get_dashboard_comparison.php');
    var data = await res.json();
    if (data.error) throw new Error(data.message || data.error);
    allRows = data.by_venue || [];
    if (allRows.length === 0) {
      document.getElementById('chartResult').innerHTML = '';
      document.getElementById('chartResult').appendChild(makeError('データがありません'));
      document.getElementById('tableResult').innerHTML = '';
      document.getElementById('tableResult').appendChild(makeError('データがありません'));
      return;
    }
    renderChart();
    renderTable();
  } catch (e) {
    document.getElementById('chartResult').textContent = '';
    document.getElementById('chartResult').appendChild(makeError('データの取得に失敗しました'));
    document.getElementById('tableResult').textContent = '';
    document.getElementById('tableResult').appendChild(makeError('データの取得に失敗しました'));
  }
}

document.getElementById('filterVenue').addEventListener('change', renderTable);
document.getElementById('filterStrategy').addEventListener('change', function() {
  renderChart();
  renderTable();
});

loadDashboard();

<?php endif; ?>
</script>
</body>
</html>
