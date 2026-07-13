<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>艇王 - 戦略別予想</title>
<link rel="stylesheet" href="style.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

.header-left { display: flex; align-items: center; gap: 12px; flex: 1; margin-bottom: 16px; }
.back-btn { color: #0055a4; text-decoration: none; font-size: 20px; line-height: 1; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: background 0.15s; flex-shrink: 0; }
.back-btn:hover { background: #e8f0fd; }
.header-info h1 { font-size: 17px; font-weight: 700; color: #222; }
.header-meta { display: flex; align-items: center; gap: 8px; margin-top: 2px; }
.header-meta .date { font-size: 12px; color: #888; }
.grade-badge { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 3px; }
.grade-sg { background: #fff3cd; color: #b8860b; }
.grade-g1 { background: #fee2e2; color: #c0392b; }
.grade-g2 { background: #dbeafe; color: #2563eb; }
.grade-g3 { background: #d1fae5; color: #16a34a; }
.grade-ippan { background: #f3f4f6; color: #888; }

.container { max-width: 800px; margin: 0 auto; padding: 16px 16px 24px; }
footer { text-align: center; padding: 28px 16px; color: #bbb; font-size: 11px; }
.loading { text-align: center; padding: 60px 20px; color: #999; }
.loading-spinner { width: 32px; height: 32px; border: 3px solid #e0e3e8; border-top-color: #0055a4; border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto 12px; }
@keyframes spin { to { transform: rotate(360deg); } }
.error-msg { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; padding: 16px; color: #dc2626; font-size: 14px; }
.note-box { background: #f0f7ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; font-size: 12px; color: #1e40af; line-height: 1.5; }

/* ─── 戦略セクション ───────────────────────────────── */
.strat-section { background: #fff; border: 1px solid #e0e3e8; border-radius: 12px; margin-bottom: 14px; overflow: hidden; }

.strat-hdr { display: flex; align-items: center; padding: 13px 14px; gap: 10px; cursor: pointer; transition: background 0.1s; }
.strat-hdr:hover { background: #fafbfc; }
.strat-bar { width: 4px; min-height: 38px; border-radius: 2px; flex-shrink: 0; align-self: stretch; }
.strat-name-wrap { flex: 1; min-width: 0; }
.strat-name { font-size: 15px; font-weight: 800; }
.strat-desc { font-size: 10px; color: #aaa; margin-top: 2px; }
.strat-stats-wrap { display: flex; gap: 14px; flex-shrink: 0; }
.strat-stat { text-align: center; }
.strat-stat-lbl { font-size: 9px; color: #aaa; font-weight: 700; letter-spacing: 0.04em; display: block; }
.strat-stat-val { font-size: 14px; font-weight: 900; display: block; font-variant-numeric: tabular-nums; line-height: 1.2; }
.strat-stat-val.pos { color: #16a34a; }
.strat-stat-val.neg { color: #dc2626; }
.strat-stat-val.neu { color: #0055a4; }
.strat-stat-val.dim { color: #bbb; }
.strat-toggle { border: 1px solid #e0e3e8; border-radius: 8px; background: #fff; font-size: 11px; color: #777; padding: 5px 8px; cursor: pointer; white-space: nowrap; flex-shrink: 0; line-height: 1; }
.strat-toggle:hover { border-color: #aaa; }

.strat-body { border-top: 1px solid #f0f0f0; }
.strat-empty { padding: 20px 16px; text-align: center; color: #bbb; font-size: 13px; }

/* ─── 結果バナー ───────────────────────────────────── */
.result-banner { padding: 11px 16px 9px; border-bottom: 1px solid rgba(0,0,0,0.07); }
.banner-hit { background: #fff0f5; }
.banner-miss { background: #f5f5f5; }
.banner-label { font-size: 10px; font-weight: 700; letter-spacing: 0.06em; margin-bottom: 5px; }
.banner-hit .banner-label { color: #c2185b; }
.banner-miss .banner-label { color: #999; }
.banner-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.banner-title { font-size: 18px; font-weight: 900; }
.banner-hit .banner-title { color: #e91e8c; }
.banner-miss .banner-title { color: #aaa; }
.banner-combo-row { display: flex; align-items: center; gap: 3px; }
.banner-odds-txt { font-size: 13px; font-weight: 700; color: #e91e8c; }
.banner-payout-txt { font-size: 13px; font-weight: 700; color: #e91e8c; }
.banner-win-payout { font-size: 12px; color: #aaa; margin-left: 2px; }

/* ─── フィルターバー ───────────────────────────────── */
.filter-bar { display: flex; gap: 6px; padding: 7px 12px; background: #f9fafb; border-bottom: 1px solid #ebebeb; flex-wrap: wrap; align-items: center; }
.filter-group { display: flex; align-items: center; gap: 3px; }
.filter-pos-lbl { font-size: 11px; font-weight: 700; color: #555; margin-right: 2px; white-space: nowrap; }
.filter-btn { width: 44px; height: 44px; border-radius: 4px; border: 1px solid #ddd; background: #f0f0f0; color: #888; font-size: 12px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; padding: 0; flex-shrink: 0; }
.filter-btn:hover { border-color: #aaa; }
.filter-divider { width: 1px; height: 18px; background: #e0e0e0; margin: 0 2px; flex-shrink: 0; }

/* ─── 買い目テーブル ───────────────────────────────── */
.ct-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.ct-table { min-width: 360px; width: 100%; }
.ct-hdr { display: flex; padding: 5px 16px; background: #f7f8fa; gap: 4px; border-bottom: 1px solid #eee; }
.ct-row { display: flex; padding: 7px 16px; border-bottom: 1px solid #f5f5f5; gap: 4px; align-items: center; }
.ct-row:last-child { border-bottom: none; }
.ct-row.ct-hl { background: #fffbea; }
.ct-row.ct-hit { background: #fffde7; border-left: 3px solid #f59e0b; padding-left: 13px; }
.ct-col-combo  { flex: 1; min-width: 108px; display: flex; align-items: center; gap: 3px; }
.ct-col-odds   { width: 78px; text-align: right; flex-shrink: 0; }
.ct-col-cost   { width: 50px; text-align: right; flex-shrink: 0; }
.ct-col-payout { width: 74px; text-align: right; flex-shrink: 0; }
.ct-col-rate   { width: 52px; text-align: right; flex-shrink: 0; }
.ct-th { font-size: 10px; font-weight: 700; color: #999; }
.ct-sep { font-size: 11px; color: #bbb; }
.ct-waku { width: 22px; height: 22px; border-radius: 3px; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; flex-shrink: 0; }
.ct-odds-val { font-size: 13px; font-weight: 700; color: #0055a4; font-variant-numeric: tabular-nums; }
.ct-odds-val.ct-max { color: #d97706; }
.ct-odds-none { font-size: 13px; color: #bbb; }
.ct-icon { font-size: 11px; margin-left: 1px; }
.ct-cost-val { font-size: 12px; color: #aaa; }
.ct-payout-val { font-size: 12px; font-weight: 600; color: #333; font-variant-numeric: tabular-nums; }
.ct-rate-val { font-size: 12px; color: #6b7280; font-variant-numeric: tabular-nums; }
.ct-hit-mark { font-size: 13px; margin-right: 2px; }

/* ─── 全件表示ボタン ───────────────────────────────── */
.show-all-btn { display: block; width: 100%; padding: 11px 16px; border: none; border-top: 1px solid #ebebeb; background: #fafbfc; font-size: 13px; font-weight: 600; color: #0055a4; cursor: pointer; text-align: center; }
.show-all-btn:hover { background: #eff6ff; }

/* ─── ボトムナビ ───────────────────────────────────── */
.bottom-actions { display: flex; gap: 6px; margin-top: 4px; }
.bottom-btn { flex: 1; }

@media (max-width: 600px) {
  .strat-stats-wrap { gap: 10px; }
  .strat-stat-val { font-size: 13px; }
  .header-info h1 { font-size: 15px; }
  .ct-col-cost { display: none; }
  .filter-bar { gap: 5px; padding: 6px 10px; }
}

.premium-lock { background: #fff; border: 1px solid #e0e3e8; border-radius: 12px; text-align: center; padding: 40px 20px; }
.premium-lock-icon { font-size: 28px; margin-bottom: 10px; display: block; }
.premium-lock p { font-size: 13px; color: #666; margin-bottom: 14px; }
.premium-lock a { display: inline-block; padding: 9px 22px; border-radius: 8px; background: #d97706; color: #fff; font-size: 13px; font-weight: 700; text-decoration: none; }
.premium-lock a:hover { background: #b45309; }
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
      <h1 id="pageTitle">戦略別予想</h1>
      <div class="header-meta">
        <span class="date" id="pageDate"></span>
        <span class="grade-badge" id="pageBadge"></span>
      </div>
    </div>
  </div>

  <div class="note-box">
    各戦略の全期間実績と今レースの買い目を表示します。1点100円換算。
  </div>

  <div id="mainArea">
    <div class="loading"><div class="loading-spinner"></div>データを取得中...</div>
  </div>

  <div class="bottom-actions" id="bottomActions" style="display:none">
    <a class="nav-btn bottom-btn" id="btnRacelist">出走表</a>
    <a class="nav-btn bottom-btn" id="btnPredict">直前情報</a>
    <a class="nav-btn bottom-btn" id="btnAiPredict">予想</a>
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
var venue  = params.get('venue')   || '';
var date   = params.get('date')    || todayStr();
var raceNo = params.get('race_no') || '1';

var API_HOST  = 'https://' + '2410049.moo.jp';
var COMBO_LIMIT = 10;

var STRAT_DEFS = [
  { type: '的中特化', color: '#2563eb', desc: '上位3艇の全順列（最大6点）' },
  { type: 'バランス',   color: '#16a34a', desc: '上位2艇固定×上位4艇流し（最大12点）' },
  { type: '一撃重視', color: '#dc2626', desc: '1位固定・穴狙い（最大6点）' },
  { type: '絞り込み', color: '#7c3aed', desc: '1点勝負' }
];

var VENUE_GRADES = { '桐生':'G3','戸田':'一般','江戸川':'一般','平和島':'G1','多摩川':'一般','浜名湖':'一般','蒲郡':'一般','常滑':'一般','津':'一般','三国':'一般','琵琶湖':'一般','住之江':'G2','尼崎':'G3','鳴門':'一般','丸亀':'一般','児島':'一般','宮島':'一般','徳山':'一般','下関':'SG','若松':'一般','芦屋':'一般','福岡':'一般','唐津':'一般','大村':'一般' };
var GRADE_CLASSES = { 'SG':'grade-sg','G1':'grade-g1','G2':'grade-g2','G3':'grade-g3','一般':'grade-ippan' };

var WAKU_STYLES = {
  1: 'background:#fff;color:#222;border:2px solid #ccc',
  2: 'background:#222;color:#fff',
  3: 'background:#e53e3e;color:#fff',
  4: 'background:#2563eb;color:#fff',
  5: 'background:#eab308;color:#222',
  6: 'background:#16a34a;color:#fff'
};

function todayStr() {
  var d = new Date();
  return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
}
function pad2(n) { return n < 10 ? '0' + n : '' + n; }
function fmtDate(ds) {
  var d = new Date(ds + 'T00:00:00');
  var w = ['日','月','火','水','木','金','土'];
  return (d.getMonth() + 1) + '/' + d.getDate() + ' (' + w[d.getDay()] + ')';
}

// ─── ヘッダー初期化 ────────────────────────────────────
var vg = VENUE_GRADES[venue] || '一般';
document.getElementById('pageTitle').textContent = (venue ? venueDisplayName(venue) + ' ' + raceNo + 'R ' : '') + '戦略別予想';
document.title = '艇王 - ' + (venue ? venueDisplayName(venue) + ' ' : '') + '戦略別予想';
var badge = document.getElementById('pageBadge');
badge.textContent = vg;
badge.className = 'grade-badge ' + (GRADE_CLASSES[vg] || 'grade-ippan');
document.getElementById('pageDate').textContent = fmtDate(date);

var baseQ = 'venue=' + encodeURIComponent(venue) + '&date=' + date + '&race_no=' + raceNo;
document.getElementById('backBtn').href = 'races.php?venue=' + encodeURIComponent(venue) + '&date=' + date;
document.getElementById('btnRacelist').href  = 'racelist.php?'  + baseQ;
document.getElementById('btnPredict').href   = 'predict.php?'   + baseQ;
document.getElementById('btnAiPredict').href = 'ai-predict.php?' + baseQ;
document.getElementById('bottomActions').style.display = 'flex';

// ─── ユーティリティ ────────────────────────────────────
function makeWakuBadge(lane) {
  var el = document.createElement('span');
  el.className = 'ct-waku';
  el.style.cssText = WAKU_STYLES[lane] || 'background:#eee;color:#333';
  el.textContent = lane;
  return el;
}

function makeTh(cls, text) {
  var el = document.createElement('div');
  el.className = cls + ' ct-th';
  el.textContent = text;
  return el;
}

// ─── 結果バナー ────────────────────────────────────────
function makeBanner(stratIsHit, hitCombo, hitOdds, hitPayout) {
  var banner = document.createElement('div');
  banner.className = 'result-banner ' + (stratIsHit ? 'banner-hit' : 'banner-miss');

  var lbl = document.createElement('div'); lbl.className = 'banner-label';
  lbl.textContent = stratIsHit ? '的中！' : '今回の結果';
  banner.appendChild(lbl);

  var row = document.createElement('div'); row.className = 'banner-row';

  var title = document.createElement('div'); title.className = 'banner-title';
  title.textContent = stratIsHit ? '的中' : '不的中';
  row.appendChild(title);

  if (hitCombo) {
    var comboRow = document.createElement('div'); comboRow.className = 'banner-combo-row';
    var parts = hitCombo.split('-');
    for (var j = 0; j < parts.length; j++) {
      comboRow.appendChild(makeWakuBadge(Number(parts[j])));
      if (j < parts.length - 1) {
        var sep = document.createElement('span'); sep.className = 'ct-sep'; sep.textContent = '-';
        comboRow.appendChild(sep);
      }
    }
    row.appendChild(comboRow);
  }

  if (stratIsHit) {
    if (hitOdds !== null && hitOdds !== undefined) {
      var oddsEl = document.createElement('div'); oddsEl.className = 'banner-odds-txt';
      oddsEl.textContent = Number(hitOdds).toFixed(1) + '倍';
      row.appendChild(oddsEl);
    }
    if (hitPayout !== null && hitPayout !== undefined) {
      var payEl = document.createElement('div'); payEl.className = 'banner-payout-txt';
      payEl.textContent = '▶ ' + Number(hitPayout).toLocaleString() + '円';
      row.appendChild(payEl);
    }
  } else {
    if (hitPayout !== null && hitPayout !== undefined) {
      var missPayEl = document.createElement('div'); missPayEl.className = 'banner-win-payout';
      missPayEl.textContent = Number(hitPayout).toLocaleString() + '円';
      row.appendChild(missPayEl);
    }
  }

  banner.appendChild(row);
  return banner;
}

// ─── 買い目行 ──────────────────────────────────────────
function makeComboRow(item, maxOdds, isFinished) {
  var isMax = maxOdds !== null && item.odds !== null && item.odds === maxOdds;
  var isHit = isFinished && item.is_hit;

  var rowClass = 'ct-row';
  if (isHit)      { rowClass += ' ct-hit'; }
  else if (isMax) { rowClass += ' ct-hl'; }

  var row = document.createElement('div'); row.className = rowClass;

  var comboCell = document.createElement('div'); comboCell.className = 'ct-col-combo';
  if (isHit) {
    var mark = document.createElement('span'); mark.className = 'ct-hit-mark'; mark.textContent = '⭐';
    comboCell.appendChild(mark);
  }
  var parts = item.combo.split('-');
  for (var j = 0; j < parts.length; j++) {
    comboCell.appendChild(makeWakuBadge(Number(parts[j])));
    if (j < parts.length - 1) {
      var sep = document.createElement('span'); sep.className = 'ct-sep'; sep.textContent = '-';
      comboCell.appendChild(sep);
    }
  }

  var oddsCell = document.createElement('div'); oddsCell.className = 'ct-col-odds';
  if (item.odds !== null) {
    var oddsVal = document.createElement('span');
    oddsVal.className = 'ct-odds-val' + (isMax && !isHit ? ' ct-max' : '');
    oddsVal.textContent = Number(item.odds).toFixed(1) + '倍';
    oddsCell.appendChild(oddsVal);
    if (isMax && !isHit) {
      var icon = document.createElement('span'); icon.className = 'ct-icon'; icon.textContent = '⭐';
      oddsCell.appendChild(icon);
    }
  } else {
    var oddsNone = document.createElement('span'); oddsNone.className = 'ct-odds-none'; oddsNone.textContent = '-';
    oddsCell.appendChild(oddsNone);
  }

  var costCell = document.createElement('div'); costCell.className = 'ct-col-cost';
  var costVal = document.createElement('span'); costVal.className = 'ct-cost-val'; costVal.textContent = '100円';
  costCell.appendChild(costVal);

  var payoutCell = document.createElement('div'); payoutCell.className = 'ct-col-payout';
  if (item.odds !== null) {
    var payoutVal = document.createElement('span'); payoutVal.className = 'ct-payout-val';
    payoutVal.textContent = Math.floor(item.odds * 100).toLocaleString() + '円';
    payoutCell.appendChild(payoutVal);
  } else {
    var payoutNone = document.createElement('span'); payoutNone.className = 'ct-odds-none'; payoutNone.textContent = '-';
    payoutCell.appendChild(payoutNone);
  }

  var rateCell = document.createElement('div'); rateCell.className = 'ct-col-rate';
  if (item.odds !== null && item.odds > 0) {
    var rateVal = document.createElement('span'); rateVal.className = 'ct-rate-val';
    var pct = 100 / item.odds;
    rateVal.textContent = pct < 1 ? pct.toFixed(2) + '%' : pct.toFixed(1) + '%';
    rateCell.appendChild(rateVal);
  } else {
    var rateNone = document.createElement('span'); rateNone.className = 'ct-odds-none'; rateNone.textContent = '-';
    rateCell.appendChild(rateNone);
  }

  row.appendChild(comboCell); row.appendChild(oddsCell); row.appendChild(costCell);
  row.appendChild(payoutCell); row.appendChild(rateCell);
  return row;
}

// ─── 戦略セクション ────────────────────────────────────
function renderStrategySection(strat, def, statRow, ctx) {
  var isFinished     = ctx ? ctx.isFinished     : false;
  var hitCombination = ctx ? ctx.hitCombination : null;
  var hitOdds        = ctx ? ctx.hitOdds        : null;
  var hitPayout      = ctx ? ctx.hitPayout      : null;
  var color          = def ? def.color : '#888';

  var section = document.createElement('div'); section.className = 'strat-section';

  // ── ヘッダー
  var hdr = document.createElement('div'); hdr.className = 'strat-hdr';

  var bar = document.createElement('div'); bar.className = 'strat-bar';
  bar.style.background = color;
  hdr.appendChild(bar);

  var nameWrap = document.createElement('div'); nameWrap.className = 'strat-name-wrap';
  var nameEl = document.createElement('div'); nameEl.className = 'strat-name'; nameEl.style.color = color;
  nameEl.textContent = strat.strategy_type;
  var descEl = document.createElement('div'); descEl.className = 'strat-desc';
  descEl.textContent = def ? def.desc : '';
  nameWrap.appendChild(nameEl); nameWrap.appendChild(descEl);
  hdr.appendChild(nameWrap);

  var statsWrap = document.createElement('div'); statsWrap.className = 'strat-stats-wrap';

  var hitRate = statRow && statRow.total_races > 0 ? Number(statRow.hit_rate) : null;
  var roi     = statRow && statRow.total_races > 0 ? Number(statRow.roi)      : null;

  var hrStat = document.createElement('div'); hrStat.className = 'strat-stat';
  var hrLbl  = document.createElement('span'); hrLbl.className = 'strat-stat-lbl'; hrLbl.textContent = '的中率';
  var hrVal  = document.createElement('span');
  hrVal.className = 'strat-stat-val ' + (hitRate === null ? 'dim' : hitRate >= 20 ? 'pos' : hitRate >= 10 ? 'neu' : 'neg');
  hrVal.textContent = hitRate !== null ? hitRate.toFixed(1) + '%' : '---';
  hrStat.appendChild(hrLbl); hrStat.appendChild(hrVal);
  statsWrap.appendChild(hrStat);

  var roiStat = document.createElement('div'); roiStat.className = 'strat-stat';
  var roiLbl  = document.createElement('span'); roiLbl.className = 'strat-stat-lbl'; roiLbl.textContent = '回収率';
  var roiVal  = document.createElement('span');
  roiVal.className = 'strat-stat-val ' + (roi === null ? 'dim' : roi >= 0 ? 'pos' : 'neg');
  roiVal.textContent = roi !== null ? (roi >= 0 ? '+' : '') + roi.toFixed(1) + '%' : '---';
  roiStat.appendChild(roiLbl); roiStat.appendChild(roiVal);
  statsWrap.appendChild(roiStat);

  hdr.appendChild(statsWrap);

  var toggleBtn = document.createElement('button'); toggleBtn.className = 'strat-toggle';
  toggleBtn.textContent = '閉じる▲';
  hdr.appendChild(toggleBtn);

  section.appendChild(hdr);

  // ── ボディ
  var body = document.createElement('div'); body.className = 'strat-body';
  var expanded = true;

  hdr.onclick = function() {
    if (expanded) {
      body.style.display = 'none'; toggleBtn.textContent = '開く▼'; expanded = false;
    } else {
      body.style.display = ''; toggleBtn.textContent = '閉じる▲'; expanded = true;
    }
  };

  var combos = strat.combinations || [];
  if (!combos.length) {
    var empty = document.createElement('div'); empty.className = 'strat-empty';
    empty.textContent = '買い目が生成されていません（予想画面を開くと生成されます）';
    body.appendChild(empty);
    section.appendChild(body);
    return section;
  }

  // 的中バナー
  if (isFinished) {
    body.appendChild(makeBanner(strat.is_hit, hitCombination, hitOdds, hitPayout));
  }

  // 最大オッズ
  var maxOdds = null;
  for (var i = 0; i < combos.length; i++) {
    var o = combos[i].odds;
    if (o !== null && (maxOdds === null || o > maxOdds)) { maxOdds = o; }
  }

  // 行生成
  var rowEls    = [];
  var combosArr = [];
  for (var i = 0; i < combos.length; i++) {
    rowEls.push(makeComboRow(combos[i], maxOdds, isFinished));
    combosArr.push(combos[i].combo);
  }

  // 共有状態
  var filterSel     = [[], [], []];
  var showAllExpanded = false;

  function applyVisibility() {
    for (var ri = 0; ri < rowEls.length; ri++) {
      var passFilter = true;
      var passLimit  = showAllExpanded || ri < COMBO_LIMIT;
      var pts = combosArr[ri].split('-');
      for (var pi = 0; pi < 3; pi++) {
        if (filterSel[pi].length > 0) {
          var ln = parseInt(pts[pi], 10);
          var found = false;
          for (var si = 0; si < filterSel[pi].length; si++) {
            if (filterSel[pi][si] === ln) { found = true; break; }
          }
          if (!found) { passFilter = false; break; }
        }
      }
      rowEls[ri].style.display = (passFilter && passLimit) ? '' : 'none';
    }
  }

  // フィルターバー
  var filterBar = document.createElement('div'); filterBar.className = 'filter-bar';
  var POS_LABELS = ['1着🔴', '2着⚪', '3着🔵'];

  for (var pi = 0; pi < 3; pi++) {
    (function(posIdx) {
      if (posIdx > 0) {
        var dvd = document.createElement('div'); dvd.className = 'filter-divider';
        filterBar.appendChild(dvd);
      }
      var grp = document.createElement('div'); grp.className = 'filter-group';
      var plbl = document.createElement('span'); plbl.className = 'filter-pos-lbl';
      plbl.textContent = POS_LABELS[posIdx];
      grp.appendChild(plbl);
      for (var lane = 1; lane <= 6; lane++) {
        (function(laneNum) {
          var fbtn = document.createElement('button'); fbtn.className = 'filter-btn';
          fbtn.textContent = laneNum;
          fbtn.onclick = function() {
            var idx = -1;
            for (var si = 0; si < filterSel[posIdx].length; si++) {
              if (filterSel[posIdx][si] === laneNum) { idx = si; break; }
            }
            if (idx >= 0) {
              filterSel[posIdx].splice(idx, 1);
              fbtn.style.cssText = ''; fbtn.className = 'filter-btn';
            } else {
              filterSel[posIdx].push(laneNum);
              fbtn.style.cssText = WAKU_STYLES[laneNum] || ''; fbtn.className = 'filter-btn active';
            }
            applyVisibility();
          };
          grp.appendChild(fbtn);
        })(lane);
      }
      filterBar.appendChild(grp);
    })(pi);
  }
  body.appendChild(filterBar);

  // テーブル
  var wrap = document.createElement('div'); wrap.className = 'ct-wrap';
  var table = document.createElement('div'); table.className = 'ct-table';
  var thdr = document.createElement('div'); thdr.className = 'ct-hdr';
  thdr.appendChild(makeTh('ct-col-combo', '3連単'));
  thdr.appendChild(makeTh('ct-col-odds',  'オッズ'));
  thdr.appendChild(makeTh('ct-col-cost',  '購入'));
  thdr.appendChild(makeTh('ct-col-payout','払戻'));
  thdr.appendChild(makeTh('ct-col-rate',  '的中率'));
  table.appendChild(thdr);
  for (var i = 0; i < rowEls.length; i++) { table.appendChild(rowEls[i]); }
  wrap.appendChild(table);
  body.appendChild(wrap);

  // 全件表示ボタン
  if (combos.length > COMBO_LIMIT) {
    var showBtn = document.createElement('button'); showBtn.className = 'show-all-btn';
    showBtn.textContent = 'すべての買い目を表示する（全 ' + combos.length + ' 件）';
    (function(btn) {
      btn.onclick = function() {
        if (!showAllExpanded) {
          showAllExpanded = true;
          applyVisibility();
          btn.textContent = '折りたたむ';
        } else {
          showAllExpanded = false;
          applyVisibility();
          btn.textContent = 'すべての買い目を表示する（全 ' + combos.length + ' 件）';
        }
      };
    })(showBtn);
    body.appendChild(showBtn);
  }

  // 初期表示（上位 COMBO_LIMIT 件）
  applyVisibility();

  section.appendChild(body);
  return section;
}

// ─── 全体レンダリング ──────────────────────────────────
function renderSections(comboData, statsMap) {
  var area = document.getElementById('mainArea');
  area.textContent = '';

  var ctx = null;
  var comboMap = {};
  if (comboData) {
    ctx = {
      isFinished:     comboData.is_finished     || false,
      hitCombination: comboData.hit_combination || null,
      hitOdds:        comboData.hit_odds        || null,
      hitPayout:      comboData.hit_payout      || null,
    };
    var strats = comboData.strategies || [];
    for (var i = 0; i < strats.length; i++) { comboMap[strats[i].strategy_type] = strats[i]; }
  }

  for (var i = 0; i < STRAT_DEFS.length; i++) {
    var def      = STRAT_DEFS[i];
    var statRow  = statsMap[def.type] || null;
    var strat    = comboMap[def.type] || { strategy_type: def.type, combinations: [], combo_count: 0, total_cost: 0, is_hit: false };
    area.appendChild(renderStrategySection(strat, def, statRow, ctx));
  }
}

// ─── API取得 ───────────────────────────────────────────
// 取得失敗(通信エラー・HTTPエラー)は例外を投げ、init()側でエラー表示する。
// 「まだ買い目が生成されていない」等の正常な空データとは区別する。
async function fetchStats() {
  var res = await fetch(API_HOST + '/strategy_stats.php');
  if (!res.ok) throw new Error('strategy_stats.php HTTP ' + res.status);
  var data = await res.json();
  var map = {};
  var rows = data.stats || [];
  for (var i = 0; i < rows.length; i++) { map[rows[i].strategy_type] = rows[i]; }
  return map;
}

async function fetchCombos() {
  if (!venue || !raceNo) return null;
  var url = API_HOST + '/strategy_detail.php?venue=' + encodeURIComponent(venue) + '&date=' + date + '&race_no=' + raceNo;
  var res = await fetch(url);
  if (!res.ok) throw new Error('strategy_detail.php HTTP ' + res.status);
  return await res.json();
}

function renderPremiumLock() {
  var area = document.getElementById('mainArea');
  area.textContent = '';
  var lock = document.createElement('div');
  lock.className = 'premium-lock';
  var icon = document.createElement('span');
  icon.className = 'premium-lock-icon';
  icon.textContent = '🔒';
  var p = document.createElement('p');
  p.textContent = '戦略別の買い目候補はStandard/Premium会員限定です。';
  var a = document.createElement('a');
  a.href = 'upgrade.html';
  a.textContent = 'プランをアップグレード';
  lock.appendChild(icon);
  lock.appendChild(p);
  lock.appendChild(a);
  area.appendChild(lock);
}

// ─── 起動 ──────────────────────────────────────────────
async function init() {
  var userPlan = 'free';
  try {
    var meRes = await fetch(API_HOST + '/me.php');
    if (meRes.ok) {
      var meData = await meRes.json();
      if (meData && meData.user && meData.user.plan) { userPlan = meData.user.plan; }
    }
  } catch (e) {}

  if (userPlan === 'free') {
    renderPremiumLock();
    return;
  }

  try {
    var results = await Promise.all([fetchStats(), fetchCombos()]);
    renderSections(results[1], results[0]);
  } catch (e) {
    var area = document.getElementById('mainArea');
    area.textContent = '';
    var err = document.createElement('div');
    err.className = 'error-msg';
    err.textContent = 'データの取得に失敗しました';
    area.appendChild(err);
  }
}

init();
</script>
</body>
</html>
