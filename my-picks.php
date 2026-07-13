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
body { font-family: -apple-system, 'Hiragino Sans', 'Meiryo', sans-serif; background: #f0f2f5; color: #333; min-height: 100vh; }
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
.btn-record { padding: 9px 22px; border-radius: 8px; background: #d97706; color: #fff; border: none; font-size: 14px; font-weight: 700; cursor: pointer; }
.btn-record:hover { background: #b45309; }
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
  <a href="upgrade.html">プレミアムにアップグレード</a>
</div>
<?php else: ?>

  <!-- サマリー -->
  <div class="card">
    <h2>集計サマリー</h2>
    <div id="summaryArea"><div class="loading">読み込み中...</div></div>
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

// ===== レース一覧取得 =====
var selectedVenue   = PREFILL.venue  || '';
var selectedDate    = PREFILL.date   || '';
var selectedRaceNo  = PREFILL.race_no || 0;

document.getElementById('findRaceBtn').addEventListener('click', findRaces);

async function findRaces() {
  var venue = formVenueEl.value;
  var date  = document.getElementById('formDate').value;
  if (!date) { alert('日付を選択してください'); return; }

  var chipArea = document.getElementById('raceChipArea');
  var chips    = document.getElementById('raceChips');
  chips.textContent = '';
  chipArea.style.display = 'none';
  document.getElementById('pickFormArea').style.display = 'none';
  document.getElementById('selectedRaceDisplay').style.display = 'none';
  selectedRaceNo = 0;

  try {
    var res = await fetch(API_HOST + '/races.php?date=' + encodeURIComponent(date) + '&venue=' + encodeURIComponent(venue));
    var data = await res.json();
    if (!data.races || data.races.length === 0) {
      alert('この会場・日付のレースは見つかりませんでした');
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
    alert('レース一覧の取得に失敗しました');
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
  if (!selectedRaceNo) { alert('レースを選択してください'); return; }
  var betType = document.getElementById('betType').value;
  var combo   = document.getElementById('combo').value.trim();
  var cost    = parseInt(document.getElementById('cost').value, 10);
  var msgEl   = document.getElementById('saveMsg');

  if (!combo) { alert('組番を入力してください'); return; }
  if (!cost || cost <= 0) { alert('購入額を入力してください'); return; }

  this.disabled = true;
  msgEl.textContent = '';
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

function renderPicksList() {
  var area        = document.getElementById('picksListArea');
  var filterBet   = document.getElementById('filterBetType').value;
  var filterHit   = document.getElementById('filterHit').value;

  var filtered = allPicks.filter(function(p) {
    if (filterBet  && p.bet_type !== filterBet) return false;
    if (filterHit === '1'    && p.is_hit !== 1)    return false;
    if (filterHit === '0'    && p.is_hit !== 0)    return false;
    if (filterHit === 'null' && p.is_hit !== null)  return false;
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
  ['日付', '会場', 'R', '賭式', '組番', '購入額', '結果', '払戻額'].forEach(function(h) {
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
    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  wrap.appendChild(table);

  area.textContent = '';
  area.appendChild(wrap);
}

document.getElementById('filterBetType').addEventListener('change', renderPicksList);
document.getElementById('filterHit').addEventListener('change', renderPicksList);

// 初期ロード
loadPicks();

<?php endif; ?>
</script>
</body>
</html>
