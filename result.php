<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>艇王 - レース結果</title>
<link rel="stylesheet" href="style.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, 'Hiragino Sans', 'Meiryo', sans-serif; background: #f0f2f5; color: #333; min-height: 100vh; }
.header-left { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.back-btn { color: #0055a4; text-decoration: none; font-size: 20px; line-height: 1; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: background 0.15s; }
.back-btn:hover { background: #e8f0fd; }
.header-info h1 { font-size: 18px; font-weight: 700; color: #222; }
.header-meta { display: flex; align-items: center; gap: 8px; margin-top: 2px; }
.header-meta .date { font-size: 12px; color: #888; }
.grade-badge { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 3px; }
.grade-sg { background: #fff3cd; color: #b8860b; }
.grade-g1 { background: #fee2e2; color: #c0392b; }
.grade-g2 { background: #dbeafe; color: #2563eb; }
.grade-g3 { background: #d1fae5; color: #16a34a; }
.grade-ippan { background: #f3f4f6; color: #888; }
.container { max-width: 960px; margin: 0 auto; padding: 20px 16px; }
.loading { text-align: center; padding: 60px 20px; color: #999; }
.loading-spinner { width: 32px; height: 32px; border: 3px solid #e0e3e8; border-top-color: #0055a4; border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto 12px; }
@keyframes spin { to { transform: rotate(360deg); } }
.error-msg { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; padding: 16px; color: #dc2626; font-size: 14px; }
footer { text-align: center; padding: 28px 16px; color: #bbb; font-size: 11px; }

.race-bar { background: #fff; border: 1px solid #e0e3e8; border-radius: 12px; padding: 14px 20px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
.race-bar-left { display: flex; align-items: center; gap: 14px; }
.race-no-lg { font-size: 26px; font-weight: 800; color: #0055a4; }
.race-bar-detail { font-size: 13px; color: #555; }
.race-bar-detail strong { color: #222; }
.race-tabs-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; white-space: nowrap; margin-bottom: 4px; }
.race-tabs { display: inline-flex; gap: 4px; }
.race-tab { display: inline-block; padding: 7px 10px; border-radius: 6px; background: #e9ecef; font-size: 12px; font-weight: 600; color: #888; text-decoration: none; transition: all 0.15s; text-align: center; min-width: 38px; }
.race-tab:hover { background: #dde0e4; color: #555; }
.race-tab.active { background: #222; color: #fff; }

.section-title { font-size: 14px; font-weight: 700; color: #222; margin: 20px 0 10px; }
.section-title:first-child { margin-top: 0; }

.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 12px; border: 1px solid #e0e3e8; background: #fff; margin-bottom: 16px; }
.result-table, .payout-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.result-table { min-width: 560px; }
.payout-table { min-width: 480px; }
.result-table th, .payout-table th { background: #f7f8fa; font-size: 10px; font-weight: 700; color: #999; padding: 8px 6px; border-bottom: 2px solid #e0e3e8; white-space: nowrap; text-align: center; }
.result-table td, .payout-table td { padding: 8px 6px; border-bottom: 1px solid #f0f0f0; text-align: center; vertical-align: middle; white-space: nowrap; }
.result-table tr:last-child td, .payout-table tr:last-child td { border-bottom: none; }

.waku { width: 28px; height: 28px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; }
.waku-1 { background: #fff; color: #222; border: 2px solid #ccc; }
.waku-2 { background: #222; color: #fff; }
.waku-3 { background: #e53e3e; color: #fff; }
.waku-4 { background: #2563eb; color: #fff; }
.waku-5 { background: #eab308; color: #222; }
.waku-6 { background: #16a34a; color: #fff; }

.td-player { text-align: left; padding-left: 10px; }
.player-name { font-size: 14px; font-weight: 700; color: #222; }
.rank-num { font-size: 15px; font-weight: 800; color: #0055a4; }

.bet-type { font-size: 12px; font-weight: 700; color: #444; text-align: left; padding-left: 10px; white-space: nowrap; }
.combo { font-size: 14px; font-weight: 700; color: #222; font-variant-numeric: tabular-nums; }
.amount { font-size: 14px; font-weight: 700; color: #0055a4; font-variant-numeric: tabular-nums; }
.popularity { font-size: 12px; color: #888; }

@media (max-width: 600px) {
  .header-info h1 { font-size: 16px; }
  .result-table, .payout-table { font-size: 12px; }
  .player-name { font-size: 13px; }
  .waku { width: 24px; height: 24px; font-size: 12px; }
}
</style>
<script src="venue-display.js"></script>
</head>
<body>

  <?php include 'header.php'; ?>

<div class="dashboard-container">

  <script>var ACTIVE_NAV = 'predictions';</script>
  <?php include 'sidebar.php'; ?>

  <main class="main-content">
  <div class="container">

  <div class="header-left">
    <a class="back-btn" id="backBtn" href="index.php">&larr;</a>
    <div class="header-info">
      <h1 id="pageTitle">レース結果</h1>
      <div class="header-meta">
        <span class="date" id="pageDate"></span>
        <span class="grade-badge" id="pageBadge"></span>
      </div>
    </div>
  </div>

  <div class="race-bar" id="raceBar" style="display:none">
    <div class="race-bar-left">
      <div class="race-no-lg" id="raceNoLg"></div>
      <div class="race-bar-detail" id="raceBarDetail"></div>
    </div>
  </div>
  <div class="race-tabs-wrap" id="raceTabsWrap" style="display:none">
    <div class="race-tabs" id="raceTabs"></div>
  </div>

  <div id="resultBody">
    <div class="loading"><div class="loading-spinner"></div>結果を取得中...</div>
  </div>

  </div>
  </main>

</div>

<footer>艇王 &copy; 2026</footer>

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

var params = new URLSearchParams(location.search);
var venue = params.get('venue') || '';
var date = params.get('date') || todayStr();
var raceNo = parseInt(params.get('race_no') || '1', 10);

function todayStr() { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0'); }

var VENUE_GRADES = { '桐生':'G3','戸田':'一般','江戸川':'一般','平和島':'G1','多摩川':'一般','浜名湖':'一般','蒲郡':'一般','常滑':'一般','津':'一般','三国':'一般','琵琶湖':'一般','住之江':'G2','尼崎':'G3','鳴門':'一般','丸亀':'一般','児島':'一般','宮島':'一般','徳山':'一般','下関':'SG','若松':'一般','芦屋':'一般','福岡':'一般','唐津':'一般','大村':'一般' };
var GRADE_CLASSES = { 'SG':'grade-sg','G1':'grade-g1','G2':'grade-g2','G3':'grade-g3','一般':'grade-ippan' };
var vg = VENUE_GRADES[venue] || '一般';

document.getElementById('pageTitle').textContent = venueDisplayName(venue) + ' ' + raceNo + 'R 結果';
document.title = '艇王 - ' + venueDisplayName(venue) + ' ' + raceNo + 'R 結果';
var badge = document.getElementById('pageBadge');
badge.textContent = vg;
badge.className = 'grade-badge ' + (GRADE_CLASSES[vg] || 'grade-ippan');

function fmtDate(ds) { var d = new Date(ds + 'T00:00:00'); var w = ['日','月','火','水','木','金','土']; return (d.getMonth()+1) + '/' + d.getDate() + ' (' + w[d.getDay()] + ')'; }
document.getElementById('pageDate').textContent = fmtDate(date);

var baseQ = 'venue=' + encodeURIComponent(venue) + '&date=' + date;
document.getElementById('backBtn').href = 'races.html?' + baseQ;

var tabsEl = document.getElementById('raceTabs');
for (var t = 1; t <= 12; t++) {
  var tab = document.createElement('a');
  tab.className = 'race-tab' + (t === raceNo ? ' active' : '');
  tab.href = 'result.php?' + baseQ + '&race_no=' + t;
  tab.textContent = t + 'R';
  tabsEl.appendChild(tab);
}

function formatName(n) { return n ? n.replace(/[\s　]+/g, ' ').trim() : ''; }

var BET_TYPE_ORDER = ['3連単', '3連複', '2連単', '2連複', '拡連複', '単勝', '複勝'];
var NO_POPULARITY_TYPES = { '単勝': true, '複勝': true };

function renderPayoutTable(payouts) {
  var order = {};
  for (var i = 0; i < BET_TYPE_ORDER.length; i++) order[BET_TYPE_ORDER[i]] = i;
  var sorted = payouts.slice().sort(function(a, b) {
    var oa = order[a.bet_type] != null ? order[a.bet_type] : 99;
    var ob = order[b.bet_type] != null ? order[b.bet_type] : 99;
    return oa - ob;
  });

  var wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  var table = document.createElement('table');
  table.className = 'payout-table';

  var thead = document.createElement('thead');
  var hrow = document.createElement('tr');
  var headers = ['式別', '組番', '払戻金', '人気'];
  for (var h = 0; h < headers.length; h++) {
    var th = document.createElement('th');
    th.textContent = headers[h];
    hrow.appendChild(th);
  }
  thead.appendChild(hrow);
  table.appendChild(thead);

  var tbody = document.createElement('tbody');
  for (var i2 = 0; i2 < sorted.length; i2++) {
    var p = sorted[i2];
    var tr = document.createElement('tr');

    var tdType = document.createElement('td');
    tdType.className = 'bet-type';
    tdType.textContent = p.bet_type;
    tr.appendChild(tdType);

    var tdCombo = document.createElement('td');
    var comboSpan = document.createElement('span');
    comboSpan.className = 'combo';
    comboSpan.textContent = p.combo;
    tdCombo.appendChild(comboSpan);
    tr.appendChild(tdCombo);

    var tdAmount = document.createElement('td');
    var amountSpan = document.createElement('span');
    amountSpan.className = 'amount';
    amountSpan.textContent = Number(p.amount).toLocaleString() + '円';
    tdAmount.appendChild(amountSpan);
    tr.appendChild(tdAmount);

    var tdPop = document.createElement('td');
    if (!NO_POPULARITY_TYPES[p.bet_type] && p.popularity != null) {
      var popSpan = document.createElement('span');
      popSpan.className = 'popularity';
      popSpan.textContent = p.popularity + '番人気';
      tdPop.appendChild(popSpan);
    }
    tr.appendChild(tdPop);

    tbody.appendChild(tr);
  }
  table.appendChild(tbody);
  wrap.appendChild(table);
  return wrap;
}

function renderResultTable(results) {
  var wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  var table = document.createElement('table');
  table.className = 'result-table';

  var thead = document.createElement('thead');
  var hrow = document.createElement('tr');
  var headers = ['着', '枠', '選手', 'タイム', '進入', 'ST'];
  for (var h = 0; h < headers.length; h++) {
    var th = document.createElement('th');
    th.textContent = headers[h];
    hrow.appendChild(th);
  }
  thead.appendChild(hrow);
  table.appendChild(thead);

  var tbody = document.createElement('tbody');
  for (var i = 0; i < results.length; i++) {
    var r = results[i];
    var tr = document.createElement('tr');

    var tdRank = document.createElement('td');
    var rankSpan = document.createElement('span');
    rankSpan.className = 'rank-num';
    rankSpan.textContent = r.actual_rank != null ? r.actual_rank : '-';
    tdRank.appendChild(rankSpan);
    tr.appendChild(tdRank);

    var tdWaku = document.createElement('td');
    var wakuEl = document.createElement('div');
    wakuEl.className = 'waku waku-' + r.lane;
    wakuEl.textContent = r.lane;
    tdWaku.appendChild(wakuEl);
    tr.appendChild(tdWaku);

    var tdPlayer = document.createElement('td');
    tdPlayer.className = 'td-player';
    var pName = document.createElement('div');
    pName.className = 'player-name';
    pName.textContent = r.name ? formatName(r.name) : '-';
    tdPlayer.appendChild(pName);
    tr.appendChild(tdPlayer);

    var tdTime = document.createElement('td');
    tdTime.textContent = r.time ? r.time : '';
    tr.appendChild(tdTime);

    var tdCourse = document.createElement('td');
    tdCourse.textContent = r.course != null ? r.course : '';
    tr.appendChild(tdCourse);

    var tdSt = document.createElement('td');
    tdSt.textContent = r.start_timing != null ? Number(r.start_timing).toFixed(2) : '';
    tr.appendChild(tdSt);

    tbody.appendChild(tr);
  }
  table.appendChild(tbody);
  wrap.appendChild(table);
  return wrap;
}

var API_HOST = 'https://' + '2410049.moo.jp';

async function loadResult() {
  var body = document.getElementById('resultBody');
  try {
    var url = API_HOST + '/get_race_result.php?date=' + encodeURIComponent(date) + '&venue=' + encodeURIComponent(venue) + '&race_no=' + raceNo;
    var res = await fetch(url);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    var data = await res.json();

    if (data.error || !data.has_result) {
      body.textContent = '';
      var errDiv = document.createElement('div');
      errDiv.className = 'error-msg';
      errDiv.textContent = data.error || 'このレースはまだ結果が確定していません';
      body.appendChild(errDiv);
      return;
    }

    var bar = document.getElementById('raceBar');
    document.getElementById('raceNoLg').textContent = raceNo + 'R';
    var detail = document.getElementById('raceBarDetail');
    detail.textContent = '';
    var vs = document.createElement('strong');
    vs.textContent = venueDisplayName(venue);
    detail.appendChild(vs);
    detail.appendChild(document.createTextNode(' ' + fmtDate(date)));
    bar.style.display = 'flex';
    document.getElementById('raceTabsWrap').style.display = 'block';
    var activeTab = tabsEl.children[raceNo - 1];
    if (activeTab) activeTab.scrollIntoView({ inline: 'center', block: 'nearest' });

    body.textContent = '';

    var payoutTitle = document.createElement('div');
    payoutTitle.className = 'section-title';
    payoutTitle.textContent = '払戻金';
    body.appendChild(payoutTitle);
    body.appendChild(renderPayoutTable(data.payouts || []));

    var resultTitle = document.createElement('div');
    resultTitle.className = 'section-title';
    resultTitle.textContent = 'レース結果';
    body.appendChild(resultTitle);
    body.appendChild(renderResultTable(data.results || []));
  } catch(e) {
    body.textContent = '';
    var errDiv2 = document.createElement('div');
    errDiv2.className = 'error-msg';
    errDiv2.textContent = 'データの取得に失敗しました';
    body.appendChild(errDiv2);
  }
}

loadResult();
</script>
</body>
</html>
