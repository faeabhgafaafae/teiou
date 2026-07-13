<?php
require_once __DIR__ . '/auth.php';
$user = current_user();

if (!$user) {
    header('Location: login.php');
    exit;
}

$plan      = $user['plan'] ?? 'free';
$isPremium = ($plan === 'premium');

// URL パラメータ経由でのフォーム事前入力 (ai-predict.php / strategy.php 等からのリンク)
$pre_venue    = htmlspecialchars($_GET['venue']    ?? '', ENT_QUOTES);
$pre_date     = htmlspecialchars($_GET['date']     ?? '', ENT_QUOTES);
$pre_race_no  = (int)($_GET['race_no']  ?? 0);
$pre_bet_type = htmlspecialchars($_GET['bet_type'] ?? '', ENT_QUOTES);
$pre_combo    = htmlspecialchars($_GET['combo']    ?? '', ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>艇王 - マイ的中トラッカー</title>
<link rel="stylesheet" href="style.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
.back-btn { color: #0055a4; text-decoration: none; font-size: 20px; line-height: 1; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: background 0.15s; }
.back-btn:hover { background: #e8f0fd; }
.page-title-row { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.container { max-width: 900px; margin: 0 auto; padding: 20px 16px; }
.premium-lock { background: #fff; border: 1px solid #e0e3e8; border-radius: 12px; text-align: center; padding: 40px 20px; margin: 20px auto; max-width: 500px; }
.premium-lock-icon { font-size: 28px; margin-bottom: 10px; display: block; }
.premium-lock p { font-size: 13px; color: #666; margin-bottom: 14px; }
.premium-lock a { display: inline-block; padding: 9px 22px; border-radius: 8px; background: #d97706; color: #fff; font-size: 13px; font-weight: 700; text-decoration: none; }
.note { font-size: 11px; color: #a0724b; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; padding: 8px 12px; margin-bottom: 12px; line-height: 1.6; }
.loading { text-align: center; padding: 24px; color: #999; font-size: 13px; }
.error-msg { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; padding: 12px; color: #dc2626; font-size: 13px; }
.success-msg { background: #f0fdf4; border: 1px solid #86efac; border-radius: 10px; padding: 12px; color: #16a34a; font-size: 13px; }

/* サマリー */
.summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; }
.summary-box { background: #f7f8fa; border-radius: 10px; padding: 12px; text-align: center; }
.summary-label { font-size: 10px; color: #888; margin-bottom: 4px; }
.summary-value { font-size: 20px; font-weight: 800; color: #0055a4; }
.summary-value.positive { color: #16a34a; }
.summary-value.negative { color: #dc2626; }
.summary-value.neutral { color: #d97706; }

/* フォーム */
.controls { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
.controls select, .controls input { padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; color: #333; }
.controls label { font-size: 12px; color: #666; font-weight: 600; }
/* 共通style.cssの.btn-primaryはwidth:100%が既定のため、.controls内のインライン
   ボタン(#findRaceBtn)はここで幅を自動に戻す */
#findRaceBtn { width: auto; }
.btn-record { padding: 9px 22px; border-radius: 8px; background: #0055a4; color: #fff; border: none; font-size: 14px; font-weight: 700; cursor: pointer; }
.btn-record:hover { background: #003d80; }
.divider { border: none; border-top: 1px solid #e0e3e8; margin: 14px 0; }
.race-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.race-chip { padding: 6px 12px; border-radius: 6px; background: #f1f5f9; border: 1px solid #cbd5e1; font-size: 13px; font-weight: 700; color: #0055a4; cursor: pointer; }
.race-chip:hover, .race-chip.selected { background: #0055a4; color: #fff; border-color: #0055a4; }
.race-chip.confirmed { background: #dcfce7; border-color: #16a34a; color: #16a34a; }
.race-chip.confirmed:hover, .race-chip.confirmed.selected { background: #16a34a; color: #fff; }
.selected-race-badge { display: inline-block; padding: 5px 12px; border-radius: 6px; background: #0055a4; color: #fff; font-size: 12px; font-weight: 700; margin-bottom: 12px; }

/* 一覧テーブル */
.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 10px; border: 1px solid #e0e3e8; }
table.picks-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 560px; }
table.picks-table th { background: #f7f8fa; font-size: 10px; font-weight: 700; color: #999; padding: 8px 6px; border-bottom: 2px solid #e0e3e8; white-space: nowrap; text-align: center; }
table.picks-table td { padding: 8px 6px; border-bottom: 1px solid #f0f0f0; text-align: center; white-space: nowrap; }
table.picks-table tr:last-child td { border-bottom: none; }
.hit-icon { font-size: 15px; }
.payout-plus { color: #16a34a; font-weight: 700; }
.payout-minus { color: #dc2626; }
.payout-pending { color: #999; }

/* フィルタ */
.filter-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
.filter-row select { padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; color: #333; }
.filter-row label { font-size: 11px; color: #888; }

/* グラフ */
.chart-section-title { font-size: 12px; font-weight: 700; color: #555; margin: 10px 0 4px; }
svg.trend-chart { width: 100%; height: auto; }

/* 削除ボタン */
.btn-delete { padding: 3px 8px; border-radius: 5px; background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; font-size: 11px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.btn-delete:hover { background: #dc2626; color: #fff; }

@media (max-width: 600px) {
  .controls { flex-direction: column; align-items: stretch; }
  .summary-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
<script src="venue-display.js"></script>
</head>
<body>

  <?php include 'header.php'; ?>

<div class="dashboard-container">

  <script>var ACTIVE_NAV = 'mypage';</script>
  <?php include 'sidebar.php'; ?>

  <main class="main-content">
  <div class="container">

  <div class="page-title-row">
    <a class="back-btn" href="index.php">&larr;</a>
    <h2 class="section-title">マイ的中トラッカー</h2>
  </div>

<?php if (!$isPremium): ?>
<div class="premium-lock">
  <span class="premium-lock-icon">&#128274;</span>
  <p>マイ的中トラッカーはPremium会員限定機能です。<br>自分の買い目を記録して的中率・回収率を管理できます。</p>
  <a href="upgrade.html">プランをアップグレード</a>
</div>
<?php else: ?>

  <!-- サマリー -->
  <div class="card">
    <h2>集計サマリー</h2>
    <div id="summaryArea"><div class="loading">読み込み中...</div></div>
  </div>

  <!-- グラフ -->
  <div class="card">
    <h2>推移グラフ</h2>
    <div id="chartsArea"><div class="loading">読み込み中...</div></div>
  </div>

  <!-- 買い目記録フォーム -->
  <div class="card">
    <h2>買い目を記録する</h2>

    <div class="note">会場・日付・レース番号を選択してから、賭式・組番・購入額を入力して「記録する」を押してください。</div>

    <!-- レース選択 -->
    <div class="controls">
      <label>会場</label>
      <select id="formVenue"></select>
      <label>日付</label>
      <input type="date" id="formDate">
      <button class="btn-primary" id="findRaceBtn">レース一覧</button>
    </div>

    <div id="raceChipArea" style="display:none; margin-bottom:12px;">
      <div style="font-size:12px; color:#666; margin-bottom:6px; font-weight:600;">レースを選択</div>
      <div id="raceChips" class="race-chips"></div>
    </div>
    <div id="findMsg" style="margin-top:6px;"></div>

    <div id="selectedRaceDisplay" style="display:none; margin-bottom:12px;"></div>

    <div id="pickFormArea" style="display:none;">
      <hr class="divider">
      <div class="controls">
        <label>賭式</label>
        <select id="betType">
          <option value="3連単">3連単</option>
          <option value="3連複">3連複</option>
          <option value="2連単">2連単</option>
          <option value="2連複">2連複</option>
          <option value="拡連複">拡連複</option>
          <option value="単勝">単勝</option>
          <option value="複勝">複勝</option>
        </select>
        <label>組番</label>
        <input type="text" id="combo" placeholder="1-3-2" style="width:90px;">
        <label>購入額(円)</label>
        <input type="number" id="cost" placeholder="100" min="100" step="100" style="width:90px;">
      </div>
      <div style="margin-top:4px;">
        <button class="btn-record" id="savePickBtn">&#9654; 買い目を記録する</button>
      </div>
      <div id="saveMsg" style="margin-top:10px;"></div>
    </div>
  </div>

  <!-- 一覧 -->
  <div class="card">
    <h2>記録済み買い目</h2>
    <div class="filter-row">
      <label>賭式:</label>
      <select id="filterBetType">
        <option value="">すべて</option>
        <option value="3連単">3連単</option>
        <option value="3連複">3連複</option>
        <option value="2連単">2連単</option>
        <option value="2連複">2連複</option>
        <option value="拡連複">拡連複</option>
        <option value="単勝">単勝</option>
        <option value="複勝">複勝</option>
      </select>
      <label>結果:</label>
      <select id="filterHit">
        <option value="">すべて</option>
        <option value="1">的中のみ</option>
        <option value="0">外れのみ</option>
        <option value="null">未確定</option>
      </select>
      <label>期間:</label>
      <select id="filterDateRange">
        <option value="">すべて</option>
        <option value="last_7">直近7日</option>
        <option value="last_30">直近30日</option>
        <option value="this_month">今月</option>
        <option value="this_year">今年</option>
      </select>
      <label>会場:</label>
      <select id="filterVenue">
        <option value="">すべて</option>
      </select>
    </div>
    <div id="picksListArea"><div class="loading">読み込み中...</div></div>
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

var API_HOST = 'https://' + '2410049.moo.jp';
var ALL_VENUES = [
  '桐生','戸田','江戸川','平和島','多摩川','浜名湖',
  '蒲郡','常滑','津','三国','琵琶湖','住之江',
  '尼崎','鳴門','高松','丸亀','児島','宮島','徳山',
  '下関','若松','芦屋','福岡','唐津','大村'
];

// URL 事前入力パラメータ
var PREFILL = {
  venue:    '<?php echo $pre_venue; ?>',
  date:     '<?php echo $pre_date; ?>',
  race_no:  <?php echo $pre_race_no; ?>,
  bet_type: '<?php echo $pre_bet_type; ?>',
  combo:    '<?php echo $pre_combo; ?>'
};

<?php if ($isPremium): ?>

// ===== 共通ユーティリティ =====
function showInlineMsg(elId, type, text) {
  var el = document.getElementById(elId);
  el.className = type === 'error' ? 'error-msg' : 'success-msg';
  el.textContent = text;
}
function clearInlineMsg(elId) {
  var el = document.getElementById(elId);
  el.className = '';
  el.textContent = '';
}

function todayStr() {
  var d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}
function formatDateJP(iso) {
  if (!iso) return '';
  var p = iso.split('-');
  return p[0] + '年' + Number(p[1]) + '月' + Number(p[2]) + '日';
}

// ===== 会場セレクタ初期化 =====
var formVenueEl = document.getElementById('formVenue');
ALL_VENUES.forEach(function(v) {
  var opt = document.createElement('option');
  opt.value = v;
  opt.textContent = venueDisplayName(v);
  formVenueEl.appendChild(opt);
});

document.getElementById('formDate').value = todayStr();

// 事前入力
if (PREFILL.venue) formVenueEl.value = PREFILL.venue;
if (PREFILL.date)  document.getElementById('formDate').value = PREFILL.date;

// ===== 賭式別プレースホルダー(優先度5) =====
var COMBO_PLACEHOLDER = {
  '3連単': '例) 1-3-2', '3連複': '例) 1-2-3',
  '2連単': '例) 1-3',   '2連複': '例) 1-2',
  '拡連複': '例) 1-2',  '単勝': '例) 1', '複勝': '例) 1'
};
document.getElementById('betType').addEventListener('change', function() {
  document.getElementById('combo').placeholder = COMBO_PLACEHOLDER[this.value] || '例) 1-3-2';
});

// ===== レース一覧取得 =====
var selectedVenue   = PREFILL.venue  || '';
var selectedDate    = PREFILL.date   || '';
var selectedRaceNo  = PREFILL.race_no || 0;

document.getElementById('findRaceBtn').addEventListener('click', findRaces);

async function findRaces() {
  var venue = formVenueEl.value;
  var date  = document.getElementById('formDate').value;
  clearInlineMsg('findMsg');
  if (!date) { showInlineMsg('findMsg', 'error', '日付を選択してください'); return; }

  var chipArea = document.getElementById('raceChipArea');
  var chips    = document.getElementById('raceChips');
  chips.textContent = '';
  chipArea.style.display = 'none';
  document.getElementById('pickFormArea').style.display = 'none';
  document.getElementById('selectedRaceDisplay').style.display = 'none';
  selectedRaceNo = 0;

  try {
    var res = await fetch(API_HOST + '/api_races.php?date=' + encodeURIComponent(date) + '&venue=' + encodeURIComponent(venue));
    var data = await res.json();
    if (!data.races || data.races.length === 0) {
      showInlineMsg('findMsg', 'error', 'この会場・日付のレースは見つかりませんでした');
      return;
    }
    selectedVenue = venue;
    selectedDate  = date;
    data.races.forEach(function(r) {
      var chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'race-chip' + (r.has_result ? ' confirmed' : '');
      chip.textContent = r.race_no + 'R' + (r.has_result ? '(結果確定)' : '');
      chip.dataset.raceNo = r.race_no;
      chip.addEventListener('click', function() {
        chips.querySelectorAll('.race-chip').forEach(function(c) { c.classList.remove('selected'); });
        this.classList.add('selected');
        selectRace(Number(this.dataset.raceNo), r.has_result);
      });
      chips.appendChild(chip);
    });
    chipArea.style.display = 'block';
  } catch (e) {
    showInlineMsg('findMsg', 'error', 'レース一覧の取得に失敗しました');
  }
}

function selectRace(raceNo, hasResult) {
  selectedRaceNo = raceNo;
  var disp = document.getElementById('selectedRaceDisplay');
  var venueName = venueDisplayName(selectedVenue);
  disp.innerHTML = '<span class="selected-race-badge">' + venueName + ' ' + formatDateJP(selectedDate) + ' ' + raceNo + 'R' + (hasResult ? ' (結果確定)' : '') + '</span>';
  disp.style.display = 'block';
  document.getElementById('pickFormArea').style.display = 'block';
  document.getElementById('saveMsg').textContent = '';
}

// 事前入力があればレース一覧を自動取得
if (PREFILL.venue && PREFILL.date) {
  findRaces().then(function() {
    if (PREFILL.race_no) {
      var chip = document.querySelector('.race-chip[data-race-no="' + PREFILL.race_no + '"]');
      if (chip) chip.click();
    }
    if (PREFILL.bet_type) document.getElementById('betType').value = PREFILL.bet_type;
    if (PREFILL.combo)    document.getElementById('combo').value    = PREFILL.combo;
  });
}

// ===== 買い目保存 =====
document.getElementById('savePickBtn').addEventListener('click', async function() {
  var msgEl = document.getElementById('saveMsg');
  msgEl.className = '';
  msgEl.textContent = '';
  if (!selectedRaceNo) { showInlineMsg('saveMsg', 'error', 'レースを選択してください'); return; }
  var betType = document.getElementById('betType').value;
  var combo   = document.getElementById('combo').value.trim();
  var cost    = parseInt(document.getElementById('cost').value, 10);

  if (!combo) { showInlineMsg('saveMsg', 'error', '組番を入力してください'); return; }
  if (!cost || cost <= 0) { showInlineMsg('saveMsg', 'error', '購入額を入力してください'); return; }

  this.disabled = true;
  try {
    var res = await fetch(API_HOST + '/save_user_pick.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        venue: selectedVenue, date: selectedDate, race_no: selectedRaceNo,
        bet_type: betType, combo: combo, cost: cost
      })
    });
    var data = await res.json();
    if (!res.ok) throw new Error(data.message || data.error || '保存に失敗しました');

    msgEl.className = 'success-msg';
    msgEl.textContent = '✓ 記録しました！';
    document.getElementById('combo').value = '';
    document.getElementById('cost').value  = '';
    loadPicks();
  } catch (e) {
    msgEl.className = 'error-msg';
    msgEl.textContent = e.message;
  } finally {
    this.disabled = false;
  }
});

// ===== 一覧・サマリー取得 =====
var allPicks = [];

async function loadPicks() {
  try {
    var res = await fetch(API_HOST + '/get_user_picks.php');
    if (!res.ok) throw new Error('取得に失敗しました');
    var data = await res.json();
    allPicks = data.picks || [];
    renderSummary(data.summary);
    renderCharts(allPicks);
    updateVenueFilter();
    renderPicksList();
  } catch (e) {
    document.getElementById('picksListArea').innerHTML = '<div class="error-msg">' + e.message + '</div>';
  }
}

function renderSummary(s) {
  var el = document.getElementById('summaryArea');
  if (!s || s.total === 0) {
    el.innerHTML = '<div style="color:#999;font-size:13px;">まだ記録がありません。</div>';
    return;
  }
  var hitRate = s.hit_rate !== null ? s.hit_rate + '%' : '-';
  var roi     = s.roi     !== null ? (s.roi >= 0 ? '+' : '') + s.roi + '%' : '-';
  var roiClass = s.roi === null ? '' : s.roi >= 0 ? 'positive' : 'negative';
  el.innerHTML =
    '<div class="summary-grid">' +
    '<div class="summary-box"><div class="summary-label">総記録数</div><div class="summary-value">' + s.total + '<span style="font-size:12px;color:#888;"> 件</span></div></div>' +
    '<div class="summary-box"><div class="summary-label">的中率</div><div class="summary-value ' + (s.hit_rate !== null && s.hit_rate >= 30 ? 'positive' : '') + '">' + hitRate + '</div></div>' +
    '<div class="summary-box"><div class="summary-label">回収率(損益率)</div><div class="summary-value ' + roiClass + '">' + roi + '</div></div>' +
    '<div class="summary-box"><div class="summary-label">総購入額(確定分)</div><div class="summary-value neutral">' + (s.total_cost || 0).toLocaleString() + '<span style="font-size:11px;color:#888;"> 円</span></div></div>' +
    '<div class="summary-box"><div class="summary-label">総払戻額</div><div class="summary-value ' + (s.total_payout > 0 ? 'positive' : '') + '">' + (s.total_payout || 0).toLocaleString() + '<span style="font-size:11px;color:#888;"> 円</span></div></div>' +
    '</div>' +
    '<div style="margin-top:8px;font-size:11px;color:#aaa;">未確定 ' + (s.total - s.decided) + '件を除いた ' + s.decided + '件で集計</div>';
}

function isInDateRange(dateStr, range) {
  if (!range) return true;
  var d = new Date(dateStr + 'T00:00:00');
  var now = new Date();
  now.setHours(0, 0, 0, 0);
  if (range === 'this_month') return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth();
  if (range === 'last_30') { var t30 = new Date(now); t30.setDate(t30.getDate() - 29); return d >= t30; }
  if (range === 'last_7')  { var t7  = new Date(now); t7.setDate(t7.getDate() - 6);   return d >= t7; }
  if (range === 'this_year') return d.getFullYear() === now.getFullYear();
  return true;
}

function updateVenueFilter() {
  var sel = document.getElementById('filterVenue');
  var current = sel.value;
  var venues = [];
  allPicks.forEach(function(p) { if (venues.indexOf(p.venue) === -1) venues.push(p.venue); });
  venues.sort();
  sel.textContent = '';
  var allOpt = document.createElement('option'); allOpt.value = ''; allOpt.textContent = 'すべて'; sel.appendChild(allOpt);
  venues.forEach(function(v) {
    var opt = document.createElement('option'); opt.value = v; opt.textContent = venueDisplayName(v); sel.appendChild(opt);
  });
  if (current && venues.indexOf(current) !== -1) sel.value = current;
}

function buildPnlChart(picks) {
  var decided = picks.filter(function(p) { return p.is_hit !== null; });
  if (decided.length === 0) return null;
  var sorted = decided.slice().sort(function(a, b) {
    return a.date.localeCompare(b.date) || a.created_at.localeCompare(b.created_at);
  });
  var dateMap = {};
  var running = 0;
  sorted.forEach(function(p) {
    running += (p.payout - p.cost);
    dateMap[p.date] = running;
  });
  var dates = Object.keys(dateMap).sort();
  var values = dates.map(function(d) { return dateMap[d]; });

  var W = 640, H = 200, padL = 60, padR = 10, padT = 12, padB = 24;
  var plotW = W - padL - padR, plotH = H - padT - padB;
  var minV = Math.min(0, Math.min.apply(null, values));
  var maxV = Math.max(0, Math.max.apply(null, values));
  var range = maxV - minV || 1;

  function xPos(i) { return padL + (dates.length <= 1 ? plotW / 2 : (i / (dates.length - 1)) * plotW); }
  function yPos(v) { return padT + plotH - ((v - minV) / range) * plotH; }

  var svgParts = [];
  svgParts.push('<svg class="trend-chart" viewBox="0 0 ' + W + ' ' + H + '" xmlns="http://www.w3.org/2000/svg">');
  var zeroY = yPos(0);
  svgParts.push('<line x1="' + padL + '" y1="' + zeroY + '" x2="' + (W - padR) + '" y2="' + zeroY + '" stroke="#e0e3e8" stroke-width="1" />');
  if (maxV !== 0) svgParts.push('<text x="' + (padL - 4) + '" y="' + (padT + 5) + '" font-size="9" fill="#aaa" text-anchor="end">' + Math.round(maxV).toLocaleString() + '円</text>');
  if (minV !== 0) svgParts.push('<text x="' + (padL - 4) + '" y="' + (padT + plotH) + '" font-size="9" fill="#aaa" text-anchor="end">' + Math.round(minV).toLocaleString() + '円</text>');
  svgParts.push('<text x="' + (padL - 4) + '" y="' + (zeroY + 4) + '" font-size="9" fill="#ccc" text-anchor="end">0</text>');
  var pts = dates.map(function(d, i) { return xPos(i) + ',' + yPos(dateMap[d]); });
  if (pts.length > 1) svgParts.push('<polyline points="' + pts.join(' ') + '" fill="none" stroke="#0055a4" stroke-width="2" stroke-linejoin="round" />');
  dates.forEach(function(d, i) {
    var v = dateMap[d];
    svgParts.push('<circle cx="' + xPos(i) + '" cy="' + yPos(v) + '" r="3" fill="' + (v >= 0 ? '#0055a4' : '#dc2626') + '" />');
  });
  svgParts.push('<text x="' + padL + '" y="' + (H - 6) + '" font-size="9" fill="#999">' + dates[0] + '</text>');
  if (dates.length > 1) svgParts.push('<text x="' + (W - padR) + '" y="' + (H - 6) + '" font-size="9" fill="#999" text-anchor="end">' + dates[dates.length - 1] + '</text>');
  svgParts.push('</svg>');
  var wrap = document.createElement('div');
  wrap.innerHTML = svgParts.join('');
  return wrap.firstChild;
}

function buildBetTypeChart(picks) {
  var decided = picks.filter(function(p) { return p.is_hit !== null; });
  if (decided.length === 0) return null;
  var byType = {};
  decided.forEach(function(p) {
    if (!byType[p.bet_type]) byType[p.bet_type] = { total: 0, hit: 0 };
    byType[p.bet_type].total++;
    if (p.is_hit === 1) byType[p.bet_type].hit++;
  });
  var types = Object.keys(byType).sort(function(a, b) {
    return (byType[b].hit / byType[b].total) - (byType[a].hit / byType[a].total);
  });
  if (types.length === 0) return null;

  var BAR_H = 22, GAP = 8, padL = 54, padR = 110, W = 640;
  var H = GAP + types.length * (BAR_H + GAP);
  var plotW = W - padL - padR;

  var svgParts = [];
  svgParts.push('<svg class="trend-chart" viewBox="0 0 ' + W + ' ' + H + '" xmlns="http://www.w3.org/2000/svg">');
  types.forEach(function(type, i) {
    var y = GAP + i * (BAR_H + GAP);
    var d = byType[type];
    var rate = d.total > 0 ? d.hit / d.total : 0;
    var barW = Math.max(2, rate * plotW);
    var color = rate >= 0.3 ? '#16a34a' : rate >= 0.15 ? '#d97706' : '#dc2626';
    svgParts.push('<rect x="' + padL + '" y="' + y + '" width="' + plotW + '" height="' + BAR_H + '" rx="4" fill="#f0f4f8" />');
    svgParts.push('<rect x="' + padL + '" y="' + y + '" width="' + barW + '" height="' + BAR_H + '" rx="4" fill="' + color + '" />');
    svgParts.push('<text x="' + (padL - 6) + '" y="' + (y + BAR_H / 2 + 4) + '" font-size="11" fill="#555" text-anchor="end">' + type + '</text>');
    svgParts.push('<text x="' + (padL + barW + 6) + '" y="' + (y + BAR_H / 2 + 4) + '" font-size="10" fill="#555">' + Math.round(rate * 100) + '% (' + d.hit + '/' + d.total + '件)</text>');
  });
  svgParts.push('</svg>');
  var wrap = document.createElement('div');
  wrap.innerHTML = svgParts.join('');
  return wrap.firstChild;
}

function renderCharts(picks) {
  var el = document.getElementById('chartsArea');
  var decided = picks.filter(function(p) { return p.is_hit !== null; });
  if (decided.length === 0) {
    el.innerHTML = '<div style="color:#999;font-size:13px;">確定済みの記録がないため、グラフを表示できません。</div>';
    return;
  }
  el.textContent = '';

  var t1 = document.createElement('div');
  t1.className = 'chart-section-title';
  t1.textContent = '累計損益の推移 (確定済みのみ、単位: 円)';
  el.appendChild(t1);
  var pnlChart = buildPnlChart(picks);
  if (pnlChart) el.appendChild(pnlChart);

  var t2 = document.createElement('div');
  t2.className = 'chart-section-title';
  t2.style.marginTop = '20px';
  t2.textContent = '賭式別 的中率 (確定済みのみ)';
  el.appendChild(t2);
  var betChart = buildBetTypeChart(picks);
  if (betChart) el.appendChild(betChart);
}

function renderPicksList() {
  var area          = document.getElementById('picksListArea');
  var filterBet     = document.getElementById('filterBetType').value;
  var filterHit     = document.getElementById('filterHit').value;
  var filterDate    = document.getElementById('filterDateRange').value;
  var filterVenueV  = document.getElementById('filterVenue').value;

  var filtered = allPicks.filter(function(p) {
    if (filterBet       && p.bet_type !== filterBet)          return false;
    if (filterHit === '1'    && p.is_hit !== 1)               return false;
    if (filterHit === '0'    && p.is_hit !== 0)               return false;
    if (filterHit === 'null' && p.is_hit !== null)            return false;
    if (filterDate      && !isInDateRange(p.date, filterDate)) return false;
    if (filterVenueV    && p.venue !== filterVenueV)          return false;
    return true;
  });

  if (filtered.length === 0) {
    area.innerHTML = '<div style="color:#999;font-size:13px;padding:12px;">表示する記録がありません。</div>';
    return;
  }

  var wrap  = document.createElement('div');
  wrap.className = 'table-wrap';
  var table = document.createElement('table');
  table.className = 'picks-table';

  var thead = document.createElement('thead');
  var hrow  = document.createElement('tr');
  ['日付', '会場', 'R', '賭式', '組番', '購入額', '結果', '払戻額', ''].forEach(function(h) {
    var th = document.createElement('th');
    th.textContent = h;
    hrow.appendChild(th);
  });
  thead.appendChild(hrow);
  table.appendChild(thead);

  var tbody = document.createElement('tbody');
  filtered.forEach(function(p) {
    var tr = document.createElement('tr');

    var hitIcon, payoutText, payoutClass;
    if (p.is_hit === 1) {
      hitIcon    = '<span class="hit-icon">&#9989;</span>';
      payoutText = p.payout.toLocaleString() + '円';
      payoutClass = 'payout-plus';
    } else if (p.is_hit === 0) {
      hitIcon    = '<span class="hit-icon">&#10060;</span>';
      payoutText = '0円';
      payoutClass = 'payout-minus';
    } else {
      hitIcon    = '<span class="hit-icon" title="レース結果未入力">&#9201;</span>';
      payoutText = '-';
      payoutClass = 'payout-pending';
    }

    var cells = [
      formatDateJP(p.date),
      venueDisplayName(p.venue),
      p.race_no + 'R',
      p.bet_type,
      p.combo,
      p.cost.toLocaleString() + '円',
      hitIcon,
      payoutText
    ];
    cells.forEach(function(v, i) {
      var td = document.createElement('td');
      if (i === 6) { td.innerHTML = v; }
      else if (i === 7) { td.className = payoutClass; td.textContent = v; }
      else { td.textContent = v; }
      tr.appendChild(td);
    });

    var tdDel = document.createElement('td');
    var btnDel = document.createElement('button');
    btnDel.className = 'btn-delete';
    btnDel.textContent = '削除';
    btnDel.dataset.pickId = p.id;
    btnDel.addEventListener('click', function() { deletePick(Number(this.dataset.pickId)); });
    tdDel.appendChild(btnDel);
    tr.appendChild(tdDel);

    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  wrap.appendChild(table);

  area.textContent = '';
  area.appendChild(wrap);
}

async function deletePick(id) {
  if (!confirm('この買い目を削除しますか？\nこの操作は元に戻せません。')) return;
  try {
    var res = await fetch(API_HOST + '/delete_user_pick.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: id })
    });
    var data = await res.json();
    if (!res.ok) throw new Error(data.message || '削除に失敗しました');
    loadPicks();
  } catch (e) {
    var listArea = document.getElementById('picksListArea');
    var errDiv = document.createElement('div');
    errDiv.className = 'error-msg';
    errDiv.style.marginBottom = '8px';
    errDiv.textContent = '削除エラー: ' + e.message;
    listArea.insertBefore(errDiv, listArea.firstChild);
  }
}

document.getElementById('filterBetType').addEventListener('change', renderPicksList);
document.getElementById('filterHit').addEventListener('change', renderPicksList);
document.getElementById('filterDateRange').addEventListener('change', renderPicksList);
document.getElementById('filterVenue').addEventListener('change', renderPicksList);

// 初期ロード
loadPicks();

<?php endif; ?>
</script>
</body>
</html>
