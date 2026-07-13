<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>艇王 - 出走表</title>
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
.race-bar-right { display: flex; gap: 6px; }
.race-tabs-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; white-space: nowrap; margin-bottom: 4px; }
.race-tabs { display: inline-flex; gap: 4px; }
.race-tab { display: inline-block; padding: 7px 10px; border-radius: 6px; background: #e9ecef; font-size: 12px; font-weight: 600; color: #888; text-decoration: none; transition: all 0.15s; text-align: center; min-width: 38px; }
.race-tab:hover { background: #dde0e4; color: #555; }
.race-tab.active { background: #222; color: #fff; }
.predict-btn { display: inline-block; padding: 7px 14px; border-radius: 8px; background: #0055a4; color: #fff; font-size: 12px; font-weight: 600; text-decoration: none; transition: background 0.15s; }
.predict-btn:hover { background: #003d7a; }

.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 12px; border: 1px solid #e0e3e8; background: #fff; }
.entry-table { width: 100%; border-collapse: collapse; min-width: 700px; font-size: 13px; }
.entry-table th { background: #f7f8fa; font-size: 10px; font-weight: 700; color: #999; padding: 8px 6px; border-bottom: 2px solid #e0e3e8; white-space: nowrap; text-align: center; }
.entry-table td { padding: 8px 6px; border-bottom: 1px solid #f0f0f0; text-align: center; vertical-align: middle; white-space: nowrap; }
.entry-table tr:last-child td { border-bottom: none; }

.waku { width: 28px; height: 28px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; }
.waku-1 { background: #fff; color: #222; border: 2px solid #ccc; }
.waku-2 { background: #222; color: #fff; }
.waku-3 { background: #e53e3e; color: #fff; }
.waku-4 { background: #2563eb; color: #fff; }
.waku-5 { background: #eab308; color: #222; }
.waku-6 { background: #16a34a; color: #fff; }

.td-player { text-align: left; padding-left: 10px; }
.player-name { font-size: 14px; font-weight: 700; color: #222; }
.player-sub { font-size: 10px; color: #999; margin-top: 1px; }
.eg { font-size: 10px; font-weight: 700; padding: 1px 5px; border-radius: 3px; margin-left: 4px; }
.eg-A1 { background: #fff3cd; color: #b8860b; }
.eg-A2 { background: #dbeafe; color: #2563eb; }
.eg-B1 { background: #f3f4f6; color: #666; }
.eg-B2 { background: #f3f4f6; color: #aaa; }
.td-fl { font-size: 12px; color: #888; }
.fl-f { color: #dc2626; font-weight: 700; }
.td-rate { font-size: 13px; font-weight: 600; color: #222; font-variant-numeric: tabular-nums; }
.td-rate.hl-win { background: #eef4ff; }
.td-rate.hl-motor { background: #fff7ed; }
.td-st { font-size: 13px; font-weight: 600; color: #222; font-variant-numeric: tabular-nums; }

@media (max-width: 600px) {
  .race-bar { flex-direction: column; align-items: flex-start; }
  .race-tab { padding: 6px 8px; min-width: 34px; font-size: 11px; }
  .header-info h1 { font-size: 16px; }
  .entry-table { font-size: 12px; }
  .player-name { font-size: 13px; }
  .waku { width: 24px; height: 24px; font-size: 12px; }
}
</style>
<script src="venue-display.js"></script>
</head>
<body>

  <?php include 'header.php'; ?>

<div class="dashboard-container">

  <?php include 'sidebar.php'; ?>

  <main class="main-content">
  <div class="container">

  <div class="header-left">
    <a class="back-btn" id="backBtn" href="index.php">&larr;</a>
    <div class="header-info">
      <h1 id="pageTitle">出走表</h1>
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
    <a class="predict-btn" id="btnPredict">予想</a>
  </div>
  <div class="race-tabs-wrap" id="raceTabsWrap" style="display:none">
    <div class="race-tabs" id="raceTabs"></div>
  </div>

  <div id="entryList">
    <div class="loading"><div class="loading-spinner"></div>出走表を取得中...</div>
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

document.getElementById('pageTitle').textContent = venueDisplayName(venue) + ' ' + raceNo + 'R 出走表';
document.title = '艇王 - ' + venueDisplayName(venue) + ' ' + raceNo + 'R 出走表';
var badge = document.getElementById('pageBadge');
badge.textContent = vg;
badge.className = 'grade-badge ' + (GRADE_CLASSES[vg] || 'grade-ippan');

function fmtDate(ds) { var d = new Date(ds + 'T00:00:00'); var w = ['日','月','火','水','木','金','土']; return (d.getMonth()+1) + '/' + d.getDate() + ' (' + w[d.getDay()] + ')'; }
document.getElementById('pageDate').textContent = fmtDate(date);

var baseQ = 'venue=' + encodeURIComponent(venue) + '&date=' + date;
document.getElementById('backBtn').href = 'races.html?' + baseQ;
document.getElementById('btnPredict').href = 'ai-predict.php?' + baseQ + '&race_no=' + raceNo;

var tabsEl = document.getElementById('raceTabs');
for (var t = 1; t <= 12; t++) {
  var tab = document.createElement('a');
  tab.className = 'race-tab' + (t === raceNo ? ' active' : '');
  tab.href = 'racelist.php?' + baseQ + '&race_no=' + t;
  tab.textContent = t + 'R';
  tabsEl.appendChild(tab);
}

function fmt(v, dec) {
  if (v == null || v === '' || v === 0) return '-';
  return Number(v).toFixed(dec != null ? dec : 2);
}
function formatName(n) { return n.replace(/[\s　]+/g, ' ').trim(); }

function findTopN(entries, key, n) {
  var vals = [];
  for (var i = 0; i < entries.length; i++) {
    var v = entries[i][key] != null ? Number(entries[i][key]) : -1;
    vals.push({ idx: i, v: v });
  }
  vals.sort(function(a, b) { return b.v - a.v; });
  var result = {};
  for (var j = 0; j < Math.min(n, vals.length); j++) {
    if (vals[j].v > 0) result[vals[j].idx] = true;
  }
  return result;
}

function renderTable(entries) {
  var winRate = function(e) { return e.pp_win_rate != null ? Number(e.pp_win_rate) : (e.win_rate_national != null ? Number(e.win_rate_national) : -1); };
  var motorRate = function(e) { return e.motor_2rate != null ? Number(e.motor_2rate) : -1; };

  var topWin = findTopN(entries, '_winSort', 2);
  var topMotor = findTopN(entries, '_motorSort', 2);

  var wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  var table = document.createElement('table');
  table.className = 'entry-table';

  var thead = document.createElement('thead');
  var hrow = document.createElement('tr');
  var headers = ['枠', '選手', 'F/L', '全国勝率', '全国2連率', '当地勝率', '当地2連率', 'モーター', 'ボート', 'ST'];
  for (var h = 0; h < headers.length; h++) {
    var th = document.createElement('th');
    th.textContent = headers[h];
    hrow.appendChild(th);
  }
  thead.appendChild(hrow);
  table.appendChild(thead);

  var tbody = document.createElement('tbody');
  for (var i = 0; i < entries.length; i++) {
    var e = entries[i];
    var tr = document.createElement('tr');

    var tdWaku = document.createElement('td');
    var wakuEl = document.createElement('div');
    wakuEl.className = 'waku waku-' + e.waku;
    wakuEl.textContent = e.waku;
    tdWaku.appendChild(wakuEl);
    tr.appendChild(tdWaku);

    var tdPlayer = document.createElement('td');
    tdPlayer.className = 'td-player';
    var pName = document.createElement('div');
    pName.className = 'player-name';
    pName.textContent = e.name ? formatName(e.name) : e.player_id;
    var grSpan = document.createElement('span');
    grSpan.className = 'eg eg-' + (e.grade || 'B1').replace(/\s/g, '');
    grSpan.textContent = e.grade || '-';
    pName.appendChild(grSpan);
    var pSub = document.createElement('div');
    pSub.className = 'player-sub';
    pSub.textContent = e.player_id;
    tdPlayer.appendChild(pName);
    tdPlayer.appendChild(pSub);
    tr.appendChild(tdPlayer);

    var tdFL = document.createElement('td');
    tdFL.className = 'td-fl';
    var fVal = e.f_count != null ? e.f_count : 0;
    var lVal = e.l_count != null ? e.l_count : 0;
    var fSpan = document.createElement('span');
    if (fVal > 0) fSpan.className = 'fl-f';
    fSpan.textContent = 'F' + fVal;
    tdFL.appendChild(fSpan);
    tdFL.appendChild(document.createTextNode(' L' + lVal));
    tr.appendChild(tdFL);

    var wrNat = e.pp_win_rate != null ? fmt(e.pp_win_rate) : fmt(e.win_rate_national);
    var tdWinNat = document.createElement('td');
    tdWinNat.className = 'td-rate' + (topWin[i] ? ' hl-win' : '');
    tdWinNat.textContent = wrNat;
    tr.appendChild(tdWinNat);

    var frNat = e.pp_fukusho_rate != null ? fmt(e.pp_fukusho_rate) : fmt(e.fukusho_national);
    var tdFukNat = document.createElement('td');
    tdFukNat.className = 'td-rate';
    tdFukNat.textContent = frNat;
    tr.appendChild(tdFukNat);

    var tdWinLoc = document.createElement('td');
    tdWinLoc.className = 'td-rate';
    tdWinLoc.textContent = fmt(e.win_rate_local);
    tr.appendChild(tdWinLoc);

    var tdFukLoc = document.createElement('td');
    tdFukLoc.className = 'td-rate';
    tdFukLoc.textContent = fmt(e.fukusho_local);
    tr.appendChild(tdFukLoc);

    var tdMotor = document.createElement('td');
    tdMotor.className = 'td-rate' + (topMotor[i] ? ' hl-motor' : '');
    tdMotor.textContent = (e.motor_no || '-') + ' ' + fmt(e.motor_2rate, 1) + '%';
    tr.appendChild(tdMotor);

    var tdBoat = document.createElement('td');
    tdBoat.className = 'td-rate';
    tdBoat.textContent = (e.boat_no || '-') + ' ' + fmt(e.boat_2rate, 1) + '%';
    tr.appendChild(tdBoat);

    var tdST = document.createElement('td');
    tdST.className = 'td-st';
    tdST.textContent = fmt(e.avg_st);
    tr.appendChild(tdST);

    tbody.appendChild(tr);
  }
  table.appendChild(tbody);
  wrap.appendChild(table);
  return wrap;
}

var API_HOST = 'https://' + '2410049.moo.jp';

async function loadEntry() {
  var list = document.getElementById('entryList');
  try {
    var url = API_HOST + '/get_racelist.php?date=' + encodeURIComponent(date) + '&venue=' + encodeURIComponent(venue) + '&race_no=' + raceNo;
    var res = await fetch(url);
    var data = null;
    var useFallback = false;

    if (res.ok) {
      data = await res.json();
      if (data.error || !data.entries || data.entries.length === 0) {
        useFallback = true;
      }
    } else {
      useFallback = true;
    }

    if (useFallback) {
      var url2 = API_HOST + '/predict.php?date=' + encodeURIComponent(date) + '&venue=' + encodeURIComponent(venue) + '&race_no=' + raceNo;
      var res2 = await fetch(url2);
      if (!res2.ok) throw new Error('HTTP ' + res2.status);
      var pData = await res2.json();
      if (pData.error || !pData.predictions || pData.predictions.length === 0) {
        list.textContent = '';
        var errDiv = document.createElement('div');
        errDiv.className = 'error-msg';
        errDiv.textContent = '出走表データが見つかりません';
        list.appendChild(errDiv);
        return;
      }
      data = {
        date: pData.date,
        venue: pData.venue,
        race_no: pData.race_no,
        scheduled_time: null,
        entries: pData.predictions.map(function(p) {
          return {
            waku: p.lane, player_id: p.player_id, name: p.name, grade: p.grade,
            f_count: p.is_flying ? 1 : 0, l_count: null, avg_st: null,
            win_rate_national: p.win_rate_national, fukusho_national: null,
            win_rate_local: p.win_rate_local, fukusho_local: null,
            motor_no: null, motor_2rate: p.motor_2rate, boat_no: null, boat_2rate: null,
            pp_win_rate: null, pp_fukusho_rate: null
          };
        })
      };
    }

    var bar = document.getElementById('raceBar');
    document.getElementById('raceNoLg').textContent = raceNo + 'R';
    var detail = document.getElementById('raceBarDetail');
    detail.textContent = '';
    var vs = document.createElement('strong');
    vs.textContent = venueDisplayName(venue);
    detail.appendChild(vs);
    var timeText = data.scheduled_time ? '  締切 ' + data.scheduled_time : '';
    detail.appendChild(document.createTextNode(' ' + fmtDate(date) + timeText));
    bar.style.display = 'flex';
    document.getElementById('raceTabsWrap').style.display = 'block';
    var activeTab = tabsEl.children[raceNo - 1];
    if (activeTab) activeTab.scrollIntoView({ inline: 'center', block: 'nearest' });

    var sorted = data.entries.slice().sort(function(a, b) {
      return (a.waku || 0) - (b.waku || 0);
    });

    var winKey = sorted[0] && sorted[0].pp_win_rate != null ? 'pp_win_rate' : 'win_rate_national';
    for (var k = 0; k < sorted.length; k++) {
      sorted[k]._winSort = sorted[k][winKey] != null ? Number(sorted[k][winKey]) : -1;
      sorted[k]._motorSort = sorted[k].motor_2rate != null ? Number(sorted[k].motor_2rate) : -1;
    }

    list.textContent = '';
    list.appendChild(renderTable(sorted));
  } catch(e) {
    list.textContent = '';
    var errDiv = document.createElement('div');
    errDiv.className = 'error-msg';
    errDiv.textContent = 'データの取得に失敗しました';
    list.appendChild(errDiv);
  }
}

loadEntry();
</script>
</body>
</html>
