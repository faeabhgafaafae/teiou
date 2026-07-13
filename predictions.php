<?php
$today = date('Y-m-d');
$weekDays = array('日', '月', '火', '水', '木', '金', '土');
$displayDate = date('n月j日', strtotime($today)) . ' (' . $weekDays[date('w', strtotime($today))] . ')';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>艇王 - 予測レース</title>
<link rel="stylesheet" href="style.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
.header-left { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.back-btn { color: #0055a4; text-decoration: none; font-size: 20px; line-height: 1; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: background 0.15s; }
.back-btn:hover { background: #e8f0fd; }
.header-info h1 { font-size: 18px; font-weight: 700; color: #222; }
.header-meta { font-size: 12px; color: #888; margin-top: 2px; }
.container { max-width: 1100px; margin: 0 auto; padding: 20px 16px; }
.loading { text-align: center; padding: 60px 20px; color: #999; }
.loading-spinner { width: 32px; height: 32px; border: 3px solid #e0e3e8; border-top-color: #0055a4; border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto 12px; }
@keyframes spin { to { transform: rotate(360deg); } }
.error-msg { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; padding: 16px; color: #dc2626; font-size: 14px; }
footer { text-align: center; padding: 28px 16px; color: #bbb; font-size: 11px; }

.grade-badge { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 3px; }
.grade-sg { background: #fff3cd; color: #b8860b; }
.grade-g1 { background: #fee2e2; color: #c0392b; }
.grade-g2 { background: #dbeafe; color: #2563eb; }
.grade-g3 { background: #d1fae5; color: #16a34a; }
.grade-ippan { background: #f3f4f6; color: #888; }

.waku { width: 26px; height: 26px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; flex-shrink: 0; }
.waku-1 { background: #fff; color: #222; border: 2px solid #ccc; }
.waku-2 { background: #222; color: #fff; }
.waku-3 { background: #e53e3e; color: #fff; }
.waku-4 { background: #2563eb; color: #fff; }
.waku-5 { background: #eab308; color: #222; }
.waku-6 { background: #16a34a; color: #fff; }

/* --- フィルターカード --- */
.filter-card { background: #fff; border: 1px solid #e0e3e8; border-radius: 12px; padding: 14px 16px; margin-bottom: 16px; }
.filter-row { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; }
.filter-row + .filter-row { margin-top: 10px; }
.filter-label { font-size: 11px; font-weight: 700; color: #888; margin-right: 4px; white-space: nowrap; }
.chip { padding: 6px 12px; border-radius: 20px; border: 1px solid #d0d5dd; background: #fff; font-size: 12px; font-weight: 600; color: #555; cursor: pointer; white-space: nowrap; transition: all 0.15s; }
.chip:hover { border-color: #0055a4; color: #0055a4; }
.chip.active { background: #0055a4; border-color: #0055a4; color: #fff; }
.chip.reset { color: #999; border-style: dashed; }

.race-summary { font-size: 12px; color: #718096; margin-bottom: 12px; }

/* --- レースカードグリッド --- */
.race-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 14px; }
.prc-card { border-left: 4px solid #e0e3e8; text-decoration: none; color: inherit; display: block; transition: transform 0.15s, box-shadow 0.15s; margin-bottom: 0; }
.prc-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.prc-card.prc-urgent { border-left-color: #ef4444; background: #fffaf9; }
.prc-card.prc-finished { border-left-color: #94a3b8; }

.prc-header { display: flex; align-items: center; gap: 6px; margin-bottom: 10px; flex-wrap: wrap; }
.prc-venue { background: #0055a4; color: #fff; font-weight: bold; font-size: 11px; padding: 2px 7px; border-radius: 4px; }
.prc-no { font-size: 14px; font-weight: 800; color: #222; }
.prc-deadline { margin-left: auto; font-size: 11px; font-weight: bold; padding: 3px 8px; border-radius: 4px; }
.prc-deadline.time-red { color: #fff; background: #ef4444; }
.prc-deadline.time-yellow { color: #451a03; background: #eab308; }
.prc-deadline.time-green { color: #fff; background: #22c55e; }
.prc-deadline.closed { color: #fff; background: #718096; }
.prc-deadline.finished { color: #fff; background: #64748b; }
.prc-urgent-tag { font-size: 10px; font-weight: 800; color: #dc2626; background: #fee2e2; padding: 2px 6px; border-radius: 10px; }

.prc-pick-row { display: flex; align-items: center; gap: 8px; background: #f7f8fa; border-radius: 8px; padding: 8px 10px; margin-bottom: 8px; }
.prc-pick-name { font-size: 13px; font-weight: 700; color: #222; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.prc-pick-score { font-size: 13px; font-weight: 700; color: #0055a4; flex-shrink: 0; }
.prc-pick-placeholder { font-size: 12px; color: #aaa; }

.prc-confidence { display: inline-block; font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 10px; }
.conf-high { background: #dcfce7; color: #16a34a; }
.conf-mid  { background: #dbeafe; color: #0055a4; }
.conf-low  { background: #fef3c7; color: #b45309; }

.prc-footer { margin-top: 10px; font-size: 12px; font-weight: 700; color: #0055a4; text-align: right; }
.prc-footer.finished-footer { color: #64748b; }

.ai-locked-banner { background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 14px 16px; margin-bottom: 16px; font-size: 13px; color: #92400e; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ai-locked-banner a { margin-left: auto; display: inline-block; padding: 7px 16px; border-radius: 8px; background: #d97706; color: #fff; font-size: 12px; font-weight: 700; text-decoration: none; white-space: nowrap; }
.ai-locked-banner a:hover { background: #b45309; }

@media (max-width: 600px) {
  .race-grid { grid-template-columns: 1fr; }
  .filter-label { width: 100%; margin-bottom: 2px; }
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
    <a class="back-btn" href="index.php">&larr;</a>
    <div class="header-info">
      <h1>予測レース</h1>
      <div class="header-meta"><?php echo htmlspecialchars($displayDate, ENT_QUOTES, 'UTF-8'); ?> 開催 &middot; 締切時刻の近い順</div>
    </div>
  </div>

  <div class="filter-card">
    <div class="filter-row" id="venueFilterRow">
      <span class="filter-label">会場</span>
      <span class="chip active reset">すべて</span>
    </div>
    <div class="filter-row" id="gradeFilterRow">
      <span class="filter-label">グレード</span>
      <span class="chip active reset">すべて</span>
      <span class="chip" data-grade="SG">SG</span>
      <span class="chip" data-grade="G1">G1</span>
      <span class="chip" data-grade="G2">G2</span>
      <span class="chip" data-grade="G3">G3</span>
      <span class="chip" data-grade="一般">一般</span>
    </div>
  </div>

  <div class="ai-locked-banner" id="aiLockedBanner" style="display:none">
    <span>🔒 AI予想の1位候補・自信度はStandard/Premium会員限定です。</span>
    <a href="upgrade.html">プランをアップグレード</a>
  </div>

  <div class="race-summary" id="raceSummary"></div>

  <div id="raceListArea">
    <div class="loading"><div class="loading-spinner"></div>本日のレースを取得中...</div>
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

var TODAY = '<?php echo $today; ?>';

var ALL_VENUES = [
  '桐生','戸田','江戸川','平和島','多摩川','浜名湖',
  '蒲郡','常滑','津','三国','琵琶湖','住之江',
  '尼崎','鳴門','丸亀','児島','宮島','徳山',
  '下関','若松','芦屋','福岡','唐津','大村'
];
var VENUE_GRADES = { '桐生':'G3','戸田':'一般','江戸川':'一般','平和島':'G1','多摩川':'一般','浜名湖':'一般','蒲郡':'一般','常滑':'一般','津':'一般','三国':'一般','琵琶湖':'一般','住之江':'G2','尼崎':'G3','鳴門':'一般','丸亀':'一般','児島':'一般','宮島':'一般','徳山':'一般','下関':'SG','若松':'一般','芦屋':'一般','福岡':'一般','唐津':'一般','大村':'一般' };
var GRADE_CLASSES = { 'SG':'grade-sg','G1':'grade-g1','G2':'grade-g2','G3':'grade-g3','一般':'grade-ippan' };

var API_HOST = 'https://' + '2410049.moo.jp';

var selectedVenues = [];
var selectedGrades = [];
var allRaces = [];
var raceCardRefs = {};
var userPlan = 'free';

function raceKey(venue, raceNo) { return venue + '-' + raceNo; }

function formatName(n) { return n ? n.replace(/[\s　]+/g, ' ').trim() : ''; }

function getDiffMs(scheduledTime) {
  if (!scheduledTime) return null;
  var now = new Date();
  var p = scheduledTime.split(':').map(Number);
  var target = new Date();
  target.setHours(p[0], p[1], 0, 0);
  return target - now;
}

function getDeadlineInfo(race) {
  if (race.has_result) {
    return { text: '確定', cls: 'finished', urgent: false, mins: null };
  }
  var diffMs = getDiffMs(race.scheduled_time);
  if (diffMs === null) return { text: '-', cls: 'closed', urgent: false, mins: null };
  var mins = Math.floor(diffMs / 60000);
  if (diffMs <= 0) return { text: '締切済', cls: 'closed', urgent: false, mins: mins };
  if (mins <= 10) return { text: 'あと' + mins + '分', cls: 'time-red', urgent: true, mins: mins };
  if (mins <= 30) return { text: 'あと' + mins + '分', cls: 'time-yellow', urgent: true, mins: mins };
  return { text: 'あと' + mins + '分', cls: 'time-green', urgent: false, mins: mins };
}

/* --- 会場横断でのレース取得: index.php(home-races.js)と同じく races.php を会場ごとに並列取得する --- */
async function loadAllRaces() {
  var results = await Promise.all(ALL_VENUES.map(async function(v) {
    try {
      var res = await fetch(API_HOST + '/races.php?date=' + TODAY + '&venue=' + encodeURIComponent(v));
      if (!res.ok) return [];
      var data = await res.json();
      if (!data.races) return [];
      return data.races.map(function(r) {
        r.venue = v;
        r.grade = VENUE_GRADES[v] || '一般';
        return r;
      });
    } catch (e) {
      return [];
    }
  }));

  var merged = [];
  results.forEach(function(list) { merged = merged.concat(list); });
  merged.sort(function(a, b) {
    var ta = a.scheduled_time || '99:99';
    var tb = b.scheduled_time || '99:99';
    if (ta !== tb) return ta < tb ? -1 : 1;
    return a.venue < b.venue ? -1 : (a.venue > b.venue ? 1 : 0);
  });
  return merged;
}

/* --- フィルターUI --- */
function buildVenueChips(activeVenueNames) {
  var row = document.getElementById('venueFilterRow');
  activeVenueNames.forEach(function(v) {
    var chip = document.createElement('span');
    chip.className = 'chip';
    chip.textContent = venueDisplayName(v);
    chip.dataset.venue = v;
    chip.onclick = function() { toggleVenueFilter(v, chip); };
    row.appendChild(chip);
  });
}

function toggleVenueFilter(venueName, chipEl) {
  var idx = selectedVenues.indexOf(venueName);
  if (idx >= 0) {
    selectedVenues.splice(idx, 1);
    chipEl.className = 'chip';
  } else {
    selectedVenues.push(venueName);
    chipEl.className = 'chip active';
  }
  syncResetChip('venueFilterRow', selectedVenues);
  applyFilters();
}

function toggleGradeFilter(grade, chipEl) {
  var idx = selectedGrades.indexOf(grade);
  if (idx >= 0) {
    selectedGrades.splice(idx, 1);
    chipEl.className = 'chip';
  } else {
    selectedGrades.push(grade);
    chipEl.className = 'chip active';
  }
  syncResetChip('gradeFilterRow', selectedGrades);
  applyFilters();
}

function syncResetChip(rowId, selectedArr) {
  var row = document.getElementById(rowId);
  var resetChip = row.querySelector('.chip.reset');
  if (selectedArr.length === 0) {
    resetChip.className = 'chip active reset';
  } else {
    resetChip.className = 'chip reset';
  }
}

document.getElementById('venueFilterRow').querySelector('.chip.reset').onclick = function() {
  selectedVenues = [];
  document.querySelectorAll('#venueFilterRow .chip[data-venue]').forEach(function(c) { c.className = 'chip'; });
  syncResetChip('venueFilterRow', selectedVenues);
  applyFilters();
};

document.getElementById('gradeFilterRow').querySelector('.chip.reset').onclick = function() {
  selectedGrades = [];
  document.querySelectorAll('#gradeFilterRow .chip[data-grade]').forEach(function(c) { c.className = 'chip'; });
  syncResetChip('gradeFilterRow', selectedGrades);
  applyFilters();
};

document.querySelectorAll('#gradeFilterRow .chip[data-grade]').forEach(function(c) {
  c.onclick = function() { toggleGradeFilter(c.dataset.grade, c); };
});

function applyFilters() {
  var visibleCount = 0;
  allRaces.forEach(function(race) {
    var card = raceCardRefs[raceKey(race.venue, race.race_no)];
    if (!card) return;
    var passVenue = selectedVenues.length === 0 || selectedVenues.indexOf(race.venue) !== -1;
    var passGrade = selectedGrades.length === 0 || selectedGrades.indexOf(race.grade) !== -1;
    var show = passVenue && passGrade;
    card.style.display = show ? '' : 'none';
    if (show) visibleCount++;
  });
  var summary = document.getElementById('raceSummary');
  summary.textContent = '該当 ' + visibleCount + ' レース / 本日全 ' + allRaces.length + ' レース';
}

/* --- カード生成 --- */
function renderPickPlaceholder(pickRow) {
  pickRow.textContent = '';
  var ph = document.createElement('span');
  ph.className = 'prc-pick-placeholder';
  ph.textContent = '予想準備中…';
  pickRow.appendChild(ph);
}

function renderPickLocked(pickRow) {
  pickRow.textContent = '';
  var ph = document.createElement('span');
  ph.className = 'prc-pick-placeholder';
  ph.textContent = '🔒 Standard/Premium会員限定';
  pickRow.appendChild(ph);
}

function renderPick(pickRow, confEl, predictions) {
  pickRow.textContent = '';
  if (!predictions || predictions.length === 0) {
    var ph = document.createElement('span');
    ph.className = 'prc-pick-placeholder';
    ph.textContent = '予想データなし';
    pickRow.appendChild(ph);
    return;
  }
  var rank1 = predictions[0];
  var rank2 = predictions.length > 1 ? predictions[1] : null;

  var wakuEl = document.createElement('span');
  wakuEl.className = 'waku waku-' + rank1.lane;
  wakuEl.textContent = rank1.lane;
  pickRow.appendChild(wakuEl);

  var nameEl = document.createElement('span');
  nameEl.className = 'prc-pick-name';
  nameEl.textContent = formatName(rank1.name);
  pickRow.appendChild(nameEl);

  var scoreEl = document.createElement('span');
  scoreEl.className = 'prc-pick-score';
  scoreEl.textContent = Number(rank1.score_total).toFixed(1) + '点';
  pickRow.appendChild(scoreEl);

  if (rank2 != null) {
    var gap = Number(rank1.score_total) - Number(rank2.score_total);
    var tier = 'mid';
    var label = '中';
    if (gap >= 15) { tier = 'high'; label = '高'; }
    else if (gap < 7) { tier = 'low'; label = '拮抗'; }
    confEl.textContent = '';
    var badge = document.createElement('span');
    badge.className = 'prc-confidence conf-' + tier;
    badge.textContent = '自信度 ' + label + ' (2着候補と+' + gap.toFixed(1) + 'pt差)';
    confEl.appendChild(badge);
  }
}

function renderRaceCard(race) {
  var deadline = getDeadlineInfo(race);
  var isFinished = !!race.has_result;

  var card = document.createElement('a');
  card.className = 'card prc-card' + (deadline.urgent ? ' prc-urgent' : '') + (isFinished ? ' prc-finished' : '');
  var baseQ = 'venue=' + encodeURIComponent(race.venue) + '&date=' + TODAY + '&race_no=' + race.race_no;
  card.href = isFinished ? ('result.php?' + baseQ) : ('ai-predict.php?' + baseQ);

  var header = document.createElement('div');
  header.className = 'prc-header';

  var venueEl = document.createElement('span');
  venueEl.className = 'prc-venue';
  venueEl.textContent = venueDisplayName(race.venue);
  header.appendChild(venueEl);

  var gradeEl = document.createElement('span');
  gradeEl.className = 'grade-badge ' + (GRADE_CLASSES[race.grade] || 'grade-ippan');
  gradeEl.textContent = race.grade;
  header.appendChild(gradeEl);

  var noEl = document.createElement('span');
  noEl.className = 'prc-no';
  noEl.textContent = race.race_no + 'R';
  header.appendChild(noEl);

  if (deadline.urgent) {
    var tag = document.createElement('span');
    tag.className = 'prc-urgent-tag';
    tag.textContent = 'まもなく締切';
    header.appendChild(tag);
  }

  var deadlineEl = document.createElement('span');
  deadlineEl.className = 'prc-deadline ' + deadline.cls;
  deadlineEl.textContent = deadline.text;
  header.appendChild(deadlineEl);

  card.appendChild(header);

  var pickRow = document.createElement('div');
  pickRow.className = 'prc-pick-row';
  card.appendChild(pickRow);

  var confEl = document.createElement('div');
  card.appendChild(confEl);

  var footer = document.createElement('div');
  footer.className = 'prc-footer' + (isFinished ? ' finished-footer' : '');
  footer.textContent = isFinished ? '結果を見る →' : '予想を見る →';
  card.appendChild(footer);

  if (isFinished) {
    var doneMsg = document.createElement('span');
    doneMsg.className = 'prc-pick-placeholder';
    doneMsg.textContent = 'レース確定済み';
    pickRow.appendChild(doneMsg);
  } else {
    renderPickPlaceholder(pickRow);
  }

  raceCardRefs[raceKey(race.venue, race.race_no)] = card;
  return { card: card, pickRow: pickRow, confEl: confEl };
}

/* --- 予想データの取得（既存の predict.php / get_prediction.php をそのまま利用） ---
   まず get_prediction.php で既存の予想（過去に誰かが該当レースを閲覧して生成済みの場合）を
   安価に読みに行き、無ければ predict.php を呼んでその場でスコアを計算・保存する。
   全レース同時ではなく runPool() で同時実行数を絞り、共有ホスティングへの負荷を抑える。 */
async function fetchPredictions(race) {
  var baseQ = 'date=' + TODAY + '&venue=' + encodeURIComponent(race.venue) + '&race_no=' + race.race_no;
  try {
    var cachedRes = await fetch(API_HOST + '/get_prediction.php?' + baseQ);
    if (cachedRes.ok) {
      var cachedData = await cachedRes.json();
      if (cachedData.predictions && cachedData.predictions.length > 0) {
        return cachedData.predictions;
      }
    }
  } catch (e) {}

  try {
    var freshRes = await fetch(API_HOST + '/predict.php?' + baseQ);
    if (!freshRes.ok) return null;
    var freshData = await freshRes.json();
    return freshData.predictions || null;
  } catch (e) {
    return null;
  }
}

function runPool(items, worker, concurrency, onDone) {
  var idx = 0, activeCount = 0, doneCount = 0, total = items.length;
  if (total === 0) { onDone(); return; }
  function next() {
    if (doneCount >= total) { onDone(); return; }
    while (activeCount < concurrency && idx < total) {
      (function(item) {
        activeCount++;
        worker(item).catch(function() {}).then(function() {
          activeCount--;
          doneCount++;
          next();
        });
      })(items[idx++]);
    }
  }
  next();
}

/* --- 初期化 --- */
async function init() {
  try {
    var meRes = await fetch(API_HOST + '/me.php');
    if (meRes.ok) {
      var meData = await meRes.json();
      if (meData && meData.user && meData.user.plan) { userPlan = meData.user.plan; }
    }
  } catch (e) {}

  if (userPlan === 'free') {
    document.getElementById('aiLockedBanner').style.display = 'flex';
  }

  var listArea = document.getElementById('raceListArea');
  try {
    allRaces = await loadAllRaces();
  } catch (e) {
    listArea.textContent = '';
    var err = document.createElement('div');
    err.className = 'error-msg';
    err.textContent = 'レース一覧の取得に失敗しました';
    listArea.appendChild(err);
    return;
  }

  if (allRaces.length === 0) {
    listArea.textContent = '';
    var empty = document.createElement('div');
    empty.className = 'error-msg';
    empty.style.cssText = 'background:#fff; border-color:#e0e3e8; color:#999; text-align:center;';
    empty.textContent = '本日開催中のレースはありません。';
    listArea.appendChild(empty);
    document.getElementById('raceSummary').textContent = '';
    return;
  }

  var activeVenueNames = [];
  allRaces.forEach(function(r) {
    if (activeVenueNames.indexOf(r.venue) === -1) activeVenueNames.push(r.venue);
  });
  buildVenueChips(activeVenueNames);

  listArea.textContent = '';
  var grid = document.createElement('div');
  grid.className = 'race-grid';
  listArea.appendChild(grid);

  var pending = [];
  allRaces.forEach(function(race) {
    var rendered = renderRaceCard(race);
    grid.appendChild(rendered.card);
    if (!race.has_result) {
      pending.push({ race: race, pickRow: rendered.pickRow, confEl: rendered.confEl });
    }
  });

  applyFilters();

  if (userPlan === 'free') {
    // Free会員はAPIが403を返すだけなので、無駄なリクエストをせずその場でロック表示にする
    pending.forEach(function(item) { renderPickLocked(item.pickRow); });
    return;
  }

  runPool(pending, async function(item) {
    var predictions = await fetchPredictions(item.race);
    renderPick(item.pickRow, item.confEl, predictions);
  }, 3, function() {});
}

init();
</script>
</body>
</html>
