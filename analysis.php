<?php
require_once __DIR__ . '/auth.php';
$user = current_user();
$plan = $user['plan'] ?? 'free';
$isStandardPlus = ($plan === 'standard' || $plan === 'premium');
$isPremium       = ($plan === 'premium');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>艇王 - データ分析</title>
<link rel="stylesheet" href="style.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, 'Hiragino Sans', 'Meiryo', sans-serif; background: #f0f2f5; color: #333; min-height: 100vh; }
.premium-lock { background: #fff; border: 1px solid #e0e3e8; border-radius: 12px; text-align: center; padding: 40px 20px; margin: 0 auto; max-width: 1000px; }
.premium-lock-icon { font-size: 28px; margin-bottom: 10px; display: block; }
.premium-lock p { font-size: 13px; color: #666; margin-bottom: 14px; }
.premium-lock a { display: inline-block; padding: 9px 22px; border-radius: 8px; background: #d97706; color: #fff; font-size: 13px; font-weight: 700; text-decoration: none; }
.premium-lock a:hover { background: #b45309; }
.back-btn { color: #0055a4; text-decoration: none; font-size: 20px; line-height: 1; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: background 0.15s; }
.back-btn:hover { background: #e8f0fd; }
.page-title-row { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.container { max-width: 1000px; margin: 0 auto; padding: 20px 16px; }

.card { background: #fff; border: 1px solid #e0e3e8; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
.card h2 { font-size: 14px; font-weight: 700; color: #222; margin-bottom: 12px; }

/* レーサー検索 */
.search-card .controls { margin-bottom: 0; }
.player-search-list { margin-top: 10px; display: flex; flex-direction: column; gap: 6px; }
.player-search-item { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border: 1px solid #e0e3e8; border-radius: 8px; cursor: pointer; text-align: left; background: #fff; }
.player-search-item:hover { border-color: #0055a4; background: #f0f5ff; }
.player-search-item .psi-name { font-weight: 700; color: #222; font-size: 13px; }
.player-search-item .psi-sub { font-size: 11px; color: #999; }

.player-detail-close { float: right; background: none; border: none; font-size: 16px; color: #999; cursor: pointer; }
.player-detail-head { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.player-detail-name { font-size: 16px; font-weight: 800; color: #222; }
.player-detail-sub { font-size: 12px; color: #888; }
.stat-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
.stat-box { flex: 1; min-width: 90px; background: #f7f8fa; border-radius: 8px; padding: 10px; text-align: center; }
.stat-box-label { font-size: 10px; color: #888; margin-bottom: 4px; }
.stat-box-value { font-size: 16px; font-weight: 800; color: #0055a4; }

/* タブ */
.tabs { display: flex; gap: 6px; overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 16px; }
.tab-btn { flex-shrink: 0; padding: 9px 16px; border-radius: 8px; background: #fff; border: 1px solid #e0e3e8; font-size: 13px; font-weight: 700; color: #555; cursor: pointer; white-space: nowrap; }
.tab-btn.active { background: #0055a4; color: #fff; border-color: #0055a4; }

.panel { display: none; }
.panel.active { display: block; }

/* ランキング: 指標タブ */
.metric-tabs { display: flex; gap: 6px; overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 14px; border-bottom: 2px solid #f0f2f5; }
.metric-tab { flex-shrink: 0; padding: 8px 14px; background: none; border: none; border-bottom: 3px solid transparent; font-size: 13px; font-weight: 700; color: #888; cursor: pointer; white-space: nowrap; }
.metric-tab.active { color: #0055a4; border-bottom-color: #0055a4; }

.rank-badge { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; font-size: 12px; font-weight: 800; color: #666; }
.rank-badge.top1 { background: #fef3c7; color: #b45309; }
.btn-outline { padding: 8px 20px; border-radius: 8px; background: #fff; border: 1px solid #0055a4; color: #0055a4; font-size: 13px; font-weight: 700; cursor: pointer; }
.btn-outline:hover { background: #f0f5ff; }

.controls { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 14px; }
.controls select, .controls input { padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; color: #333; }
.controls button { padding: 7px 16px; border-radius: 6px; background: #0055a4; color: #fff; border: none; font-size: 13px; font-weight: 700; cursor: pointer; }
.controls button:hover { background: #003d7a; }
.controls label { font-size: 12px; color: #666; font-weight: 600; }
.seg { display: flex; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden; }
.seg button { border-radius: 0; background: #fff; color: #555; border: none; padding: 7px 14px; }
.seg button.active { background: #0055a4; color: #fff; }

.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 10px; border: 1px solid #e0e3e8; }
table.data-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 560px; }
table.data-table th { background: #f7f8fa; font-size: 10px; font-weight: 700; color: #999; padding: 8px 6px; border-bottom: 2px solid #e0e3e8; white-space: nowrap; text-align: center; }
table.data-table td { padding: 8px 6px; border-bottom: 1px solid #f0f0f0; text-align: center; white-space: nowrap; }
table.data-table tr:last-child td { border-bottom: none; }
table.data-table tr.rank-1 { background: #fffbeb; }
.td-player { text-align: left; padding-left: 10px; }
.player-name { font-weight: 700; color: #222; }
.eg { font-size: 10px; font-weight: 700; padding: 1px 5px; border-radius: 3px; }
.eg-A1 { background: #fff3cd; color: #b8860b; }
.eg-A2 { background: #dbeafe; color: #2563eb; }
.eg-B1 { background: #f3f4f6; color: #666; }
.eg-B2 { background: #f3f4f6; color: #aaa; }

.note { font-size: 11px; color: #a0724b; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; padding: 8px 12px; margin-bottom: 12px; line-height: 1.6; }
.loading { text-align: center; padding: 30px; color: #999; font-size: 13px; }
.error-msg { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; padding: 14px; color: #dc2626; font-size: 13px; }

/* 会場グリッド */
.venue-card-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 16px; }
.venue-card-mini { background: #f7f8fa; border: 1px solid #e0e3e8; border-radius: 10px; padding: 12px 6px; text-align: center; font-size: 13px; font-weight: 700; color: #333; cursor: pointer; }
.venue-card-mini:hover { border-color: #0055a4; background: #f0f5ff; color: #0055a4; }
.venue-card-mini.active { background: #0055a4; border-color: #0055a4; color: #fff; }

.kimarite-row { display: flex; gap: 10px; margin-bottom: 6px; }
.kimarite-item { flex: 1; background: #f7f8fa; border-radius: 8px; padding: 10px; text-align: center; }
.kimarite-label { font-size: 11px; color: #888; margin-bottom: 4px; }
.kimarite-value { font-size: 18px; font-weight: 800; color: #0055a4; }

.race-search-list { display: flex; flex-wrap: wrap; gap: 8px; }
.race-search-item { padding: 8px 14px; border-radius: 8px; background: #f1f5f9; border: 1px solid #cbd5e1; font-size: 13px; font-weight: 700; color: #0055a4; text-decoration: none; }
.race-search-item:hover { background: #0055a4; color: #fff; }
.race-search-item.confirmed { background: #dcfce7; border-color: #16a34a; color: #16a34a; }
.race-search-item.confirmed:hover { background: #16a34a; color: #fff; }

/* 高度検索 */
.adv-divider { border: none; border-top: 1px dashed #e0e3e8; margin: 16px 0; }
.adv-header { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.adv-prem-badge { background: #d97706; color: #fff; font-size: 9px; font-weight: 700; padding: 2px 6px; border-radius: 3px; }
.adv-lock { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 10px 14px; font-size: 12px; color: #92400e; line-height: 1.6; }
.adv-lock a { color: #d97706; font-weight: 700; text-decoration: none; }
.adv-section-lbl { font-size: 11px; font-weight: 700; color: #888; margin: 8px 0 4px; letter-spacing: 0.03em; }
.adv-venue-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 4px; margin-bottom: 10px; }
.adv-venue-cb { display: flex; align-items: center; gap: 3px; font-size: 12px; color: #333; cursor: pointer; white-space: nowrap; }
.adv-venue-cb input { cursor: pointer; accent-color: #d97706; }
.adv-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-bottom: 10px; }
.adv-field { display: flex; flex-direction: column; gap: 2px; }
.adv-field select, .adv-field input[type=number], .adv-field input[type=date], .adv-field input[type=text] { padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; color: #333; }
.adv-btn { padding: 8px 22px; border-radius: 6px; background: #d97706; color: #fff; border: none; font-size: 13px; font-weight: 700; cursor: pointer; }
.adv-btn:hover { background: #b45309; }
.adv-result-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 10px; border: 1px solid #e0e3e8; margin-top: 12px; }
table.adv-table { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 500px; }
table.adv-table th { background: #f7f8fa; font-size: 10px; font-weight: 700; color: #999; padding: 7px 5px; border-bottom: 2px solid #e0e3e8; white-space: nowrap; text-align: center; }
table.adv-table td { padding: 7px 5px; border-bottom: 1px solid #f0f0f0; text-align: center; white-space: nowrap; }
table.adv-table tr:last-child td { border-bottom: none; }
table.adv-table tr.adv-rank1 { background: #fffbeb; }
.adv-link { color: #0055a4; text-decoration: none; font-weight: 700; }
.adv-link.confirmed { color: #16a34a; }
.adv-link:hover { text-decoration: underline; }
@media (max-width: 460px) { .adv-venue-grid { grid-template-columns: repeat(4, 1fr); } }

@media (max-width: 700px) {
  .venue-card-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 460px) {
  .venue-card-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
  .controls { flex-direction: column; align-items: stretch; }
  .stat-row { gap: 6px; }
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

  <div class="page-title-row">
    <a class="back-btn" href="index.php">&larr;</a>
    <h2 class="section-title">データ分析</h2>
  </div>

  <!-- レーサー検索 -->
  <div class="card search-card">
    <h2>レーサー検索</h2>
    <div class="controls">
      <input type="text" id="playerSearchInput" placeholder="選手名で検索(例: 中辻)" style="flex:1; min-width:160px;">
      <button id="playerSearchBtn">検索</button>
    </div>
    <div id="playerSearchResult"></div>
  </div>

  <!-- 選手詳細(簡易) -->
  <div class="card" id="playerDetailCard" style="display:none;"></div>

  <div class="tabs">
    <button class="tab-btn active" data-tab="players">選手ランキング</button>
    <button class="tab-btn" data-tab="venue">会場別データ</button>
    <button class="tab-btn" data-tab="search">過去レース検索</button>
    <button class="tab-btn" data-tab="payouts">払戻金傾向</button>
  </div>

  <!-- 選手ランキング -->
  <div class="panel active" id="panel-players">
    <div class="card">
      <h2>選手ランキング(全国・上位10)</h2>
      <div class="metric-tabs" id="metricTabs">
        <button class="metric-tab active" data-metric="win_score" data-order="desc">勝率(簡易)</button>
        <button class="metric-tab" data-metric="rank1_rate" data-order="desc">1着率</button>
        <button class="metric-tab" data-metric="rank2_rate" data-order="desc">2連対率</button>
        <button class="metric-tab" data-metric="rank3_rate" data-order="desc">3連対率</button>
        <button class="metric-tab" data-metric="avg_st" data-order="asc">平均ST</button>
      </div>
      <div id="rankingCompact"><div class="loading">読み込み中...</div></div>
      <div style="text-align:center; margin-top:14px;">
        <button class="btn-outline" id="showFullRankingBtn">ランキング一覧へ(条件を絞り込む)</button>
      </div>

      <div id="rankingFull" style="display:none; margin-top:18px; border-top:1px solid #eee; padding-top:16px;">
        <div class="controls">
          <div class="seg" id="scopeSeg">
            <button class="active" data-scope="national">全国</button>
            <button data-scope="local">当地</button>
          </div>
          <select id="playersVenueSelect" style="display:none;"></select>
          <label>最低出走数</label>
          <input type="number" id="minRacesInput" value="20" min="1" style="width:70px;">
          <button id="playersSearchBtn">表示</button>
        </div>
        <div id="playersResult"><div class="loading">条件を選択して「表示」を押してください</div></div>
      </div>
    </div>
  </div>

  <!-- 会場別データ -->
  <div class="panel" id="panel-venue">
    <div class="card">
      <h2>会場を選択</h2>
      <div class="venue-card-grid" id="venuePicker"></div>
      <div id="venueResult"><div class="loading">会場を選択してください</div></div>
    </div>
  </div>

  <!-- 過去レース検索 -->
  <div class="panel" id="panel-search">
    <div class="card">
      <h2>過去レース検索</h2>
      <div class="controls">
        <select id="searchVenueSelect"></select>
        <input type="date" id="searchDateInput">
        <button id="searchBtn">検索</button>
      </div>
      <div id="searchResult"><div class="loading">会場・日付を選択して検索してください</div></div>

      <hr class="adv-divider">
      <div class="adv-header">
        <span style="font-size:13px; font-weight:700; color:#222;">高度検索</span>
        <span class="adv-prem-badge">PREMIUM</span>
      </div>
<?php if (!$isPremium): ?>
      <div class="adv-lock">&#128274; 高度検索はPremiumプラン限定です。複合条件（選手名・天候・コース・期間など）で絞り込めます。<a href="upgrade.html">プランをアップグレード &rsaquo;</a></div>
<?php else: ?>
      <div id="advSearchForm">
        <div class="adv-section-lbl">選手名（部分一致）</div>
        <div style="margin-bottom:8px;">
          <input type="text" id="advPlayerName" placeholder="例: 中辻博行" style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; color:#333; width:200px; max-width:100%;">
        </div>

        <div class="adv-section-lbl">会場（複数選択可・空欄=全会場）</div>
        <div class="adv-venue-grid" id="advVenueGrid"></div>

        <div class="adv-row">
          <div class="adv-field">
            <span class="adv-section-lbl" style="margin:0 0 2px;">天候</span>
            <select id="advWeather">
              <option value="">指定なし</option>
              <option value="晴">晴</option>
              <option value="曇">曇</option>
              <option value="雨">雨</option>
              <option value="雪">雪</option>
            </select>
          </div>
          <div class="adv-field">
            <span class="adv-section-lbl" style="margin:0 0 2px;">風速 m/s 以上</span>
            <input type="number" id="advWindMin" min="0" max="30" step="0.5" placeholder="例: 3" style="width:90px;">
          </div>
          <div class="adv-field">
            <span class="adv-section-lbl" style="margin:0 0 2px;">波高 cm 以上</span>
            <input type="number" id="advWaveMin" min="0" max="200" step="1" placeholder="例: 5" style="width:90px;">
          </div>
          <div class="adv-field">
            <span class="adv-section-lbl" style="margin:0 0 2px;">進入コース</span>
            <select id="advCourse">
              <option value="">指定なし</option>
              <option value="1">1コース</option>
              <option value="2">2コース</option>
              <option value="3">3コース</option>
              <option value="4">4コース</option>
              <option value="5">5コース</option>
              <option value="6">6コース</option>
            </select>
          </div>
        </div>

        <div class="adv-section-lbl">期間</div>
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:14px; flex-wrap:wrap;">
          <input type="date" id="advDateFrom" style="padding:6px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; color:#333;">
          <span style="color:#666; font-size:13px;">〜</span>
          <input type="date" id="advDateTo" style="padding:6px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; color:#333;">
        </div>

        <button class="adv-btn" id="advSearchBtn">高度検索を実行</button>
      </div>
      <div id="advSearchResult"></div>
<?php endif; ?>
    </div>
  </div>

  <!-- 払戻金傾向 -->
  <div class="panel" id="panel-payouts">
    <div class="card">
      <h2>賭式別 配当傾向</h2>
      <div id="payoutsByType"><div class="loading">読み込み中...</div></div>
    </div>
    <div class="card">
      <h2>3連単 人気別決着分布（荒れ具合の目安）</h2>
      <div id="payoutsPopularity"></div>
    </div>
  </div>

  </div>
  </main>

</div>

<script>
var IS_STANDARD_PLUS = <?php echo $isStandardPlus ? 'true' : 'false'; ?>;
var IS_PREMIUM       = <?php echo $isPremium      ? 'true' : 'false'; ?>;

// --- 共通ヘッダー(index.php/mypage.phpと同じロジック) ---
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
          '<button class="dropdown-item" onclick="location.href=\'mypage.php\'"><i class="fas fa-user-cog" style="margin-right: 8px; color: #718096;"></i>マイページ</button>' +
          '<button class="dropdown-item logout" id="logoutBtn" style="border-top: 1px solid #edf2f7; color: #dc2626;"><i class="fas fa-sign-out-alt" style="margin-right: 8px; color: #dc2626;"></i>ログアウト</button>' +
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

// Free ユーザー: 会場別・過去検索・払戻傾向パネルをロック表示に差し替え
if (!IS_STANDARD_PLUS) {
  (function() {
    var lockHtml = '<div class="premium-lock" style="padding:24px 16px;"><span class="premium-lock-icon">&#128274;</span><p>Standard / Premiumプランでご利用いただけます。</p><a href="upgrade.html">プランをアップグレード</a></div>';
    ['#panel-venue .card', '#panel-search .card'].forEach(function(sel) {
      var el = document.querySelector(sel);
      if (el) el.innerHTML = lockHtml;
    });
    document.querySelectorAll('#panel-payouts .card').forEach(function(el) {
      el.innerHTML = lockHtml;
    });
  })();
}

var ALL_VENUES = [
  '桐生','戸田','江戸川','平和島','多摩川','浜名湖',
  '蒲郡','常滑','津','三国','琵琶湖','住之江',
  '尼崎','鳴門','高松','丸亀','児島','宮島','徳山',
  '下関','若松','芦屋','福岡','唐津','大村'
];
var API_HOST = 'https://' + '2410049.moo.jp';

function formatName(n) { return n ? n.replace(/[\s　]+/g, ' ').trim() : ''; }
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
function gradeBadge(grade) {
  var span = document.createElement('span');
  span.className = 'eg eg-' + (grade || 'B1').replace(/\s/g, '');
  span.textContent = grade || '-';
  return span;
}

// --- タブ切り替え ---
var tabButtons = document.querySelectorAll('.tab-btn');
for (var i = 0; i < tabButtons.length; i++) {
  tabButtons[i].addEventListener('click', function() {
    var tab = this.getAttribute('data-tab');
    for (var j = 0; j < tabButtons.length; j++) tabButtons[j].classList.remove('active');
    this.classList.add('active');
    var panels = document.querySelectorAll('.panel');
    for (var k = 0; k < panels.length; k++) panels[k].classList.remove('active');
    document.getElementById('panel-' + tab).classList.add('active');
  });
}

// ============================================================
// レーサー検索・簡易詳細
// ============================================================
async function searchPlayers() {
  var resultEl = document.getElementById('playerSearchResult');
  var keyword = document.getElementById('playerSearchInput').value.trim();
  if (!keyword) { resultEl.textContent = ''; return; }
  resultEl.textContent = '';
  resultEl.appendChild(makeLoading('検索中...'));

  try {
    var res = await fetch(API_HOST + '/search_players.php?keyword=' + encodeURIComponent(keyword));
    var data = await res.json();
    resultEl.textContent = '';
    if (!data.players || data.players.length === 0) {
      resultEl.appendChild(makeError('該当する選手が見つかりませんでした'));
      return;
    }
    var list = document.createElement('div');
    list.className = 'player-search-list';
    data.players.forEach(function(p) {
      var item = document.createElement('button');
      item.className = 'player-search-item';
      item.type = 'button';
      var nameEl = document.createElement('div');
      nameEl.className = 'psi-name';
      nameEl.textContent = formatName(p.name) + '(' + (p.grade || '-') + ')';
      var subEl = document.createElement('div');
      subEl.className = 'psi-sub';
      subEl.textContent = (p.branch || '-') + ' / 登番' + p.id;
      item.appendChild(nameEl);
      item.appendChild(subEl);
      item.addEventListener('click', function() { showPlayerDetail(p.id); });
      list.appendChild(item);
    });
    resultEl.appendChild(list);
  } catch (e) {
    resultEl.textContent = '';
    resultEl.appendChild(makeError('検索に失敗しました'));
  }
}
document.getElementById('playerSearchBtn').addEventListener('click', searchPlayers);
document.getElementById('playerSearchInput').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') searchPlayers();
});

async function showPlayerDetail(playerId) {
  var card = document.getElementById('playerDetailCard');
  card.style.display = 'block';
  card.textContent = '';
  card.appendChild(makeLoading('読み込み中...'));
  card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

  try {
    var res = await fetch(API_HOST + '/get_player_detail.php?player_id=' + playerId);
    var data = await res.json();
    if (data.error) throw new Error(data.error);

    card.textContent = '';
    var closeBtn = document.createElement('button');
    closeBtn.className = 'player-detail-close';
    closeBtn.textContent = '×';
    closeBtn.addEventListener('click', function() { card.style.display = 'none'; });
    card.appendChild(closeBtn);

    var head = document.createElement('div');
    head.className = 'player-detail-head';
    var nameEl = document.createElement('div');
    nameEl.className = 'player-detail-name';
    nameEl.textContent = formatName(data.player.name);
    head.appendChild(nameEl);
    head.appendChild(gradeBadge(data.player.grade));
    card.appendChild(head);

    var sub = document.createElement('div');
    sub.className = 'player-detail-sub';
    sub.style.marginBottom = '12px';
    sub.textContent = '支部: ' + (data.player.branch || '-') + ' / 登番: ' + data.player.id;
    card.appendChild(sub);

    if (data.stats) {
      var row = document.createElement('div');
      row.className = 'stat-row';
      var items = [
        ['出走数', data.stats.race_count],
        ['1着率', data.stats.rank1_rate.toFixed(1) + '%'],
        ['2連対率', data.stats.rank2_rate.toFixed(1) + '%'],
        ['3連対率', data.stats.rank3_rate.toFixed(1) + '%'],
        ['平均ST', data.stats.avg_st != null ? data.stats.avg_st.toFixed(2) : '-'],
      ];
      items.forEach(function(it) {
        var box = document.createElement('div');
        box.className = 'stat-box';
        var lbl = document.createElement('div');
        lbl.className = 'stat-box-label';
        lbl.textContent = it[0];
        var val = document.createElement('div');
        val.className = 'stat-box-value';
        val.textContent = it[1];
        box.appendChild(lbl); box.appendChild(val);
        row.appendChild(box);
      });
      card.appendChild(row);
    } else {
      card.appendChild(makeError('成績データがありません'));
    }

    if (data.recent && data.recent.length > 0) {
      var recentTitle = document.createElement('div');
      recentTitle.style.fontSize = '12px';
      recentTitle.style.fontWeight = '700';
      recentTitle.style.color = '#555';
      recentTitle.style.margin = '4px 0 8px';
      recentTitle.textContent = '直近成績';
      card.appendChild(recentTitle);

      var wrap = document.createElement('div');
      wrap.className = 'table-wrap';
      var table = document.createElement('table');
      table.className = 'data-table';
      var thead = document.createElement('thead');
      var hrow = document.createElement('tr');
      ['日付', '会場', 'R', '枠', '着'].forEach(function(h) {
        var th = document.createElement('th');
        th.textContent = h;
        hrow.appendChild(th);
      });
      thead.appendChild(hrow);
      table.appendChild(thead);
      var tbody = document.createElement('tbody');
      data.recent.forEach(function(r) {
        var tr = document.createElement('tr');
        [r.date, venueDisplayName(r.venue), r.race_no + 'R', r.lane, r.actual_rank != null ? r.actual_rank : '-'].forEach(function(v) {
          var td = document.createElement('td');
          td.textContent = v;
          tr.appendChild(td);
        });
        tbody.appendChild(tr);
      });
      table.appendChild(tbody);
      wrap.appendChild(table);
      card.appendChild(wrap);
    }
  } catch (e) {
    card.textContent = '';
    card.appendChild(makeError('選手情報の取得に失敗しました'));
  }
}

// ============================================================
// 1. 選手ランキング(コンパクト表示 + 全件表示)
// ============================================================
var currentScope = 'national';
var currentMetric = 'win_score';
var currentMetricOrder = 'desc';

var METRIC_LABELS = {
  win_score: '勝率(簡易)',
  rank1_rate: '1着率',
  rank2_rate: '2連対率',
  rank3_rate: '3連対率',
  avg_st: '平均ST',
};

function formatMetricValue(metric, p) {
  if (metric === 'win_score') return p.win_score.toFixed(2);
  if (metric === 'avg_st') return p.avg_st != null ? p.avg_st.toFixed(2) : '-';
  return p[metric].toFixed(1) + '%';
}

function renderCompactTable(data, metric) {
  var wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  var table = document.createElement('table');
  table.className = 'data-table';

  var thead = document.createElement('thead');
  var hrow = document.createElement('tr');
  ['順位', 'レーサー名', '級', '支部', METRIC_LABELS[metric]].forEach(function(h) {
    var th = document.createElement('th');
    th.textContent = h;
    hrow.appendChild(th);
  });
  thead.appendChild(hrow);
  table.appendChild(thead);

  var tbody = document.createElement('tbody');
  data.players.forEach(function(p, idx) {
    var tr = document.createElement('tr');
    if (idx === 0) tr.className = 'rank-1';

    var tdRank = document.createElement('td');
    var badge = document.createElement('span');
    badge.className = 'rank-badge' + (idx === 0 ? ' top1' : '');
    badge.textContent = idx === 0 ? '★1' : String(idx + 1);
    tdRank.appendChild(badge);
    tr.appendChild(tdRank);

    var tdName = document.createElement('td');
    tdName.className = 'td-player';
    var nameSpan = document.createElement('span');
    nameSpan.className = 'player-name';
    nameSpan.textContent = formatName(p.name) || ('登番' + p.player_id);
    tdName.appendChild(nameSpan);
    tr.appendChild(tdName);

    var tdGrade = document.createElement('td');
    tdGrade.appendChild(gradeBadge(p.grade));
    tr.appendChild(tdGrade);

    var tdBranch = document.createElement('td');
    tdBranch.textContent = p.branch || '-';
    tr.appendChild(tdBranch);

    var tdVal = document.createElement('td');
    tdVal.textContent = formatMetricValue(metric, p);
    tr.appendChild(tdVal);

    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  wrap.appendChild(table);
  return wrap;
}

async function loadCompactRanking() {
  var el = document.getElementById('rankingCompact');
  el.textContent = '';
  el.appendChild(makeLoading('読み込み中...'));

  var qs = 'scope=national&min_races=20&sort=' + currentMetric + '&order=' + currentMetricOrder + '&limit=10';
  try {
    var res = await fetch(API_HOST + '/get_analysis_players.php?' + qs);
    var data = await res.json();
    if (data.error) throw new Error(data.error);
    el.textContent = '';
    if (!data.players || data.players.length === 0) {
      el.appendChild(makeError('データがありません'));
      return;
    }
    el.appendChild(renderCompactTable(data, currentMetric));
  } catch (e) {
    el.textContent = '';
    el.appendChild(makeError('データの取得に失敗しました'));
  }
}

var metricTabs = document.querySelectorAll('.metric-tab');
metricTabs.forEach(function(btn) {
  btn.addEventListener('click', function() {
    metricTabs.forEach(function(b) { b.classList.remove('active'); });
    this.classList.add('active');
    currentMetric = this.getAttribute('data-metric');
    currentMetricOrder = this.getAttribute('data-order');
    loadCompactRanking();
  });
});

document.getElementById('showFullRankingBtn').addEventListener('click', function() {
  var full = document.getElementById('rankingFull');
  if (!IS_STANDARD_PLUS) {
    full.style.display = 'block';
    full.innerHTML = '<div class="premium-lock" style="padding:20px;"><span class="premium-lock-icon">&#128274;</span><p>ランキング全件表示はStandard / Premiumプランでご利用いただけます。</p><a href="upgrade.html">プランをアップグレード</a></div>';
    full.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    this.textContent = '一覧を閉じる';
    return;
  }
  var isHidden = full.style.display === 'none';
  full.style.display = isHidden ? 'block' : 'none';
  this.textContent = isHidden ? '一覧を閉じる' : 'ランキング一覧へ(条件を絞り込む)';
  if (isHidden) full.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
});

var playersVenueSelect = document.getElementById('playersVenueSelect');
ALL_VENUES.forEach(function(v) {
  var opt = document.createElement('option');
  opt.value = v; opt.textContent = venueDisplayName(v);
  playersVenueSelect.appendChild(opt);
});

var scopeSeg = document.getElementById('scopeSeg');
scopeSeg.querySelectorAll('button').forEach(function(btn) {
  btn.addEventListener('click', function() {
    scopeSeg.querySelectorAll('button').forEach(function(b) { b.classList.remove('active'); });
    this.classList.add('active');
    currentScope = this.getAttribute('data-scope');
    playersVenueSelect.style.display = currentScope === 'local' ? 'inline-block' : 'none';
  });
});

function renderPlayersTable(data) {
  var wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  var table = document.createElement('table');
  table.className = 'data-table';

  var thead = document.createElement('thead');
  var hrow = document.createElement('tr');
  var headers = ['順位', 'レーサー名', '級', '支部', '出走数', '勝率(簡易)', '1着率', '2連対率', '3連対率', '平均ST'];
  headers.forEach(function(h) {
    var th = document.createElement('th');
    th.textContent = h;
    hrow.appendChild(th);
  });
  thead.appendChild(hrow);
  table.appendChild(thead);

  var tbody = document.createElement('tbody');
  data.players.forEach(function(p, idx) {
    var tr = document.createElement('tr');
    if (idx === 0) tr.className = 'rank-1';

    var tdRank = document.createElement('td');
    var badge = document.createElement('span');
    badge.className = 'rank-badge' + (idx === 0 ? ' top1' : '');
    badge.textContent = idx === 0 ? '★1' : String(idx + 1);
    tdRank.appendChild(badge);
    tr.appendChild(tdRank);

    var tdName = document.createElement('td');
    tdName.className = 'td-player';
    var nameSpan = document.createElement('span');
    nameSpan.className = 'player-name';
    nameSpan.textContent = formatName(p.name) || ('登番' + p.player_id);
    tdName.appendChild(nameSpan);
    tr.appendChild(tdName);

    var tdGrade = document.createElement('td');
    tdGrade.appendChild(gradeBadge(p.grade));
    tr.appendChild(tdGrade);

    var tdBranch = document.createElement('td');
    tdBranch.textContent = p.branch || '-';
    tr.appendChild(tdBranch);

    var tdCount = document.createElement('td');
    tdCount.textContent = p.race_count;
    tr.appendChild(tdCount);

    var tdWin = document.createElement('td');
    tdWin.textContent = p.win_score.toFixed(2);
    tr.appendChild(tdWin);

    var tdR1 = document.createElement('td');
    tdR1.textContent = p.rank1_rate.toFixed(1) + '%';
    tr.appendChild(tdR1);

    var tdR2 = document.createElement('td');
    tdR2.textContent = p.rank2_rate.toFixed(1) + '%';
    tr.appendChild(tdR2);

    var tdR3 = document.createElement('td');
    tdR3.textContent = p.rank3_rate.toFixed(1) + '%';
    tr.appendChild(tdR3);

    var tdSt = document.createElement('td');
    tdSt.textContent = p.avg_st != null ? p.avg_st.toFixed(2) : '-';
    tr.appendChild(tdSt);

    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  wrap.appendChild(table);
  return wrap;
}

async function loadPlayers() {
  var resultEl = document.getElementById('playersResult');
  resultEl.textContent = '';
  resultEl.appendChild(makeLoading('読み込み中...'));

  var minRaces = document.getElementById('minRacesInput').value || 20;
  var qs = 'scope=' + currentScope + '&min_races=' + encodeURIComponent(minRaces) + '&sort=' + currentMetric + '&order=' + currentMetricOrder + '&limit=50';
  if (currentScope === 'local') {
    qs += '&venue=' + encodeURIComponent(playersVenueSelect.value);
  }

  try {
    var res = await fetch(API_HOST + '/get_analysis_players.php?' + qs);
    var data = await res.json();
    if (data.error) throw new Error(data.error);
    resultEl.textContent = '';
    if (!data.players || data.players.length === 0) {
      resultEl.appendChild(makeError('条件に合致する選手がいません(最低出走数を下げてお試しください)'));
      return;
    }
    resultEl.appendChild(renderPlayersTable(data));
    var footnote = document.createElement('div');
    footnote.className = 'note';
    footnote.style.marginTop = '10px';
    footnote.textContent = '勝率(簡易)は (1着数×2 + 2着数×1) ÷ 出走数 で算出した当サイト独自の簡易指標です。公式発表の勝率とは計算方法が異なる場合があります。';
    resultEl.appendChild(footnote);
  } catch (e) {
    resultEl.textContent = '';
    resultEl.appendChild(makeError('データの取得に失敗しました'));
  }
}
document.getElementById('playersSearchBtn').addEventListener('click', loadPlayers);

// ============================================================
// 2. 会場別データ(グリッドカード)
// ============================================================
var venuePicker = document.getElementById('venuePicker');
if (venuePicker) {
  ALL_VENUES.forEach(function(v) {
    var btn = document.createElement('button');
    btn.className = 'venue-card-mini';
    btn.type = 'button';
    btn.textContent = venueDisplayName(v);
    btn.addEventListener('click', function() {
      venuePicker.querySelectorAll('.venue-card-mini').forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      loadVenueAnalysis(v);
    });
    venuePicker.appendChild(btn);
  });
}

function renderRateTable(title, rows, keyLabel, keyField) {
  var box = document.createElement('div');
  var h = document.createElement('div');
  h.style.fontSize = '12px';
  h.style.fontWeight = '700';
  h.style.color = '#555';
  h.style.margin = '4px 0 8px';
  h.textContent = title;
  box.appendChild(h);

  var wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  var table = document.createElement('table');
  table.className = 'data-table';
  var thead = document.createElement('thead');
  var hrow = document.createElement('tr');
  [keyLabel, '件数', '1着率', '2連対率', '3連対率'].forEach(function(h2) {
    var th = document.createElement('th');
    th.textContent = h2;
    hrow.appendChild(th);
  });
  thead.appendChild(hrow);
  table.appendChild(thead);
  var tbody = document.createElement('tbody');
  rows.forEach(function(r) {
    var tr = document.createElement('tr');
    var tds = [r[keyField], r.race_count, r.rank1_rate.toFixed(1) + '%', r.rank2_rate.toFixed(1) + '%', r.rank3_rate.toFixed(1) + '%'];
    tds.forEach(function(v) {
      var td = document.createElement('td');
      td.textContent = v;
      tr.appendChild(td);
    });
    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  wrap.appendChild(table);
  box.appendChild(wrap);
  return box;
}

async function loadVenueAnalysis(venue) {
  var resultEl = document.getElementById('venueResult');
  resultEl.textContent = '';
  resultEl.appendChild(makeLoading('読み込み中...'));

  try {
    var res = await fetch(API_HOST + '/get_analysis_venue.php?venue=' + encodeURIComponent(venue));
    var data = await res.json();
    if (data.error) throw new Error(data.error);

    resultEl.textContent = '';

    if (data.lane_stats && data.lane_stats.length > 0) {
      resultEl.appendChild(renderRateTable('枠別 入着率(全期間)', data.lane_stats, '枠', 'lane'));
    }

    var note = document.createElement('div');
    note.className = 'note';
    note.style.marginTop = '16px';
    note.textContent = '進入コース別データ・決まり手推定は、進入コースの記録を開始した ' + data.course_data_since + ' 以降のデータのみで集計しています。サンプル数が少ないため参考値としてご覧ください。';
    resultEl.appendChild(note);

    if (data.course_stats && data.course_stats.length > 0) {
      resultEl.appendChild(renderRateTable('進入コース別 入着率(' + data.course_data_since + '〜)', data.course_stats, 'コース', 'course'));
    } else {
      resultEl.appendChild(makeError('この会場は対象期間中のレースデータがありません。'));
    }

    if (data.kimarite_estimate) {
      var kh = document.createElement('div');
      kh.style.fontSize = '12px';
      kh.style.fontWeight = '700';
      kh.style.color = '#555';
      kh.style.margin = '16px 0 8px';
      kh.textContent = '決まり手 簡易推定(1着艇の進入コースのみで判定した近似値。実際の決まり手データではありません)';
      resultEl.appendChild(kh);

      var krow = document.createElement('div');
      krow.className = 'kimarite-row';
      var items = [
        ['逃げ(1コース)', data.kimarite_estimate.nige_rate],
        ['差し(2コース)', data.kimarite_estimate.sashi_rate],
        ['まくり系(3〜6コース)', data.kimarite_estimate.makuri_rate],
      ];
      items.forEach(function(it) {
        var box = document.createElement('div');
        box.className = 'kimarite-item';
        var lbl = document.createElement('div');
        lbl.className = 'kimarite-label';
        lbl.textContent = it[0];
        var val = document.createElement('div');
        val.className = 'kimarite-value';
        val.textContent = it[1].toFixed(1) + '%';
        box.appendChild(lbl); box.appendChild(val);
        krow.appendChild(box);
      });
      resultEl.appendChild(krow);
    }
  } catch (e) {
    resultEl.textContent = '';
    resultEl.appendChild(makeError('データの取得に失敗しました'));
  }
}

// ============================================================
// 3. 過去レース検索
// ============================================================
var searchVenueSelect = document.getElementById('searchVenueSelect');
if (searchVenueSelect) {
  ALL_VENUES.forEach(function(v) {
    var opt = document.createElement('option');
    opt.value = v; opt.textContent = venueDisplayName(v);
    searchVenueSelect.appendChild(opt);
  });
}

function todayStr() {
  var d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}
var searchDateEl = document.getElementById('searchDateInput');
if (searchDateEl) searchDateEl.value = todayStr();

async function searchRaces() {
  var resultEl = document.getElementById('searchResult');
  resultEl.textContent = '';
  resultEl.appendChild(makeLoading('検索中...'));

  var venue = searchVenueSelect.value;
  var date = document.getElementById('searchDateInput').value;
  if (!date) { resultEl.textContent = ''; resultEl.appendChild(makeError('日付を選択してください')); return; }

  try {
    var res = await fetch(API_HOST + '/races.php?date=' + encodeURIComponent(date) + '&venue=' + encodeURIComponent(venue));
    var data = await res.json();
    resultEl.textContent = '';
    if (!data.races || data.races.length === 0) {
      resultEl.appendChild(makeError('この会場・日付のレースは見つかりませんでした'));
      return;
    }
    var list = document.createElement('div');
    list.className = 'race-search-list';
    data.races.forEach(function(r) {
      var a = document.createElement('a');
      var q = 'venue=' + encodeURIComponent(venue) + '&date=' + date + '&race_no=' + r.race_no;
      if (r.has_result) {
        a.className = 'race-search-item confirmed';
        a.href = 'result.php?' + q;
        a.textContent = r.race_no + 'R (結果確定)';
      } else {
        a.className = 'race-search-item';
        a.href = 'racelist.php?' + q;
        a.textContent = r.race_no + 'R (出走表)';
      }
      list.appendChild(a);
    });
    resultEl.appendChild(list);
  } catch (e) {
    resultEl.textContent = '';
    resultEl.appendChild(makeError('検索に失敗しました'));
  }
}
var searchBtnEl = document.getElementById('searchBtn');
if (searchBtnEl) searchBtnEl.addEventListener('click', searchRaces);

// ============================================================
// 3b. 高度検索 (Premium限定)
// ============================================================
if (IS_PREMIUM) {
  var advVenueGrid = document.getElementById('advVenueGrid');
  if (advVenueGrid) {
    ALL_VENUES.forEach(function(v) {
      var lbl = document.createElement('label');
      lbl.className = 'adv-venue-cb';
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.value = v;
      cb.name = 'venues[]';
      var txt = document.createTextNode(venueDisplayName(v));
      lbl.appendChild(cb);
      lbl.appendChild(txt);
      advVenueGrid.appendChild(lbl);
    });
  }

  async function searchRacesAdvanced() {
    var resultEl = document.getElementById('advSearchResult');
    resultEl.textContent = '';
    resultEl.appendChild(makeLoading('検索中...'));

    var playerName = document.getElementById('advPlayerName').value.trim();
    var weather    = document.getElementById('advWeather').value;
    var windMin    = document.getElementById('advWindMin').value.trim();
    var waveMin    = document.getElementById('advWaveMin').value.trim();
    var course     = document.getElementById('advCourse').value;
    var dateFrom   = document.getElementById('advDateFrom').value;
    var dateTo     = document.getElementById('advDateTo').value;

    var checkedCbs = document.querySelectorAll('#advVenueGrid input:checked');
    var venues = [];
    for (var i = 0; i < checkedCbs.length; i++) venues.push(checkedCbs[i].value);

    var params = [];
    if (playerName) params.push('player_name=' + encodeURIComponent(playerName));
    for (var j = 0; j < venues.length; j++) params.push('venues%5B%5D=' + encodeURIComponent(venues[j]));
    if (weather)  params.push('weather='   + encodeURIComponent(weather));
    if (windMin)  params.push('wind_min='  + encodeURIComponent(windMin));
    if (waveMin)  params.push('wave_min='  + encodeURIComponent(waveMin));
    if (course)   params.push('course='    + encodeURIComponent(course));
    if (dateFrom) params.push('date_from=' + encodeURIComponent(dateFrom));
    if (dateTo)   params.push('date_to='   + encodeURIComponent(dateTo));

    try {
      var res  = await fetch(API_HOST + '/search_races_advanced.php?' + params.join('&'));
      var data = await res.json();
      resultEl.textContent = '';

      if (data.error) {
        resultEl.appendChild(makeError(data.message || data.error));
        return;
      }
      if (!data.races || data.races.length === 0) {
        resultEl.appendChild(makeError('条件に一致するレースが見つかりませんでした'));
        return;
      }

      var countEl = document.createElement('div');
      countEl.style.cssText = 'font-size:12px; color:#888; margin-bottom:8px;';
      countEl.textContent = data.count + '件' + (data.count >= 200 ? '（上限200件。条件を絞り込んでください）' : '');
      resultEl.appendChild(countEl);

      var hasPlayer = (data.races[0].player_name !== undefined);

      var wrap  = document.createElement('div');
      wrap.className = 'adv-result-wrap';
      var table = document.createElement('table');
      table.className = 'adv-table';

      var thead = document.createElement('thead');
      var hrow  = document.createElement('tr');
      var cols  = hasPlayer
        ? ['日付', '会場', 'R', '選手', '枠', 'C', '結果', '天候', '風', '波']
        : ['日付', '会場', 'R', '天候', '風速', '波高'];
      cols.forEach(function(c) {
        var th = document.createElement('th');
        th.textContent = c;
        hrow.appendChild(th);
      });
      thead.appendChild(hrow);
      table.appendChild(thead);

      var tbody = document.createElement('tbody');
      data.races.forEach(function(r) {
        var tr = document.createElement('tr');
        if (hasPlayer && r.actual_rank === 1) tr.className = 'adv-rank1';

        var q    = 'venue=' + encodeURIComponent(r.venue) + '&date=' + r.date + '&race_no=' + r.race_no;
        var href = r.has_result ? ('result.php?' + q) : ('racelist.php?' + q);

        function makeTd(text) {
          var td = document.createElement('td');
          td.textContent = text != null ? String(text) : '-';
          tr.appendChild(td);
          return td;
        }
        function makeLinkTd(text) {
          var td = document.createElement('td');
          var a  = document.createElement('a');
          a.href = href;
          a.className = 'adv-link' + (r.has_result ? ' confirmed' : '');
          a.textContent = text;
          td.appendChild(a);
          tr.appendChild(td);
          return td;
        }

        makeTd(r.date);
        makeTd(venueDisplayName(r.venue));
        makeLinkTd(r.race_no + 'R');

        if (hasPlayer) {
          makeTd(formatName(r.player_name));
          makeTd(r.lane);
          makeTd(r.exhibit_course);
          makeTd(r.actual_rank != null ? r.actual_rank + '着' : '-');
        }

        makeTd(r.weather);
        makeTd(r.wind_speed  != null ? r.wind_speed  + 'm'  : '-');
        makeTd(r.wave_height != null ? r.wave_height + 'cm' : '-');

        tbody.appendChild(tr);
      });
      table.appendChild(tbody);
      wrap.appendChild(table);
      resultEl.appendChild(wrap);

    } catch (e) {
      resultEl.textContent = '';
      resultEl.appendChild(makeError('検索に失敗しました'));
    }
  }

  var advSearchBtnEl = document.getElementById('advSearchBtn');
  if (advSearchBtnEl) advSearchBtnEl.addEventListener('click', searchRacesAdvanced);
}

// ============================================================
// 4. 払戻金傾向
// ============================================================
function renderPayoutsByType(rows) {
  var wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  var table = document.createElement('table');
  table.className = 'data-table';
  var thead = document.createElement('thead');
  var hrow = document.createElement('tr');
  ['式別', '件数', '平均配当', '最高配当', '最低配当'].forEach(function(h) {
    var th = document.createElement('th');
    th.textContent = h;
    hrow.appendChild(th);
  });
  thead.appendChild(hrow);
  table.appendChild(thead);
  var tbody = document.createElement('tbody');
  rows.forEach(function(r) {
    var tr = document.createElement('tr');
    var tds = [r.bet_type, r.count, r.avg_amount.toLocaleString() + '円', r.max_amount.toLocaleString() + '円', r.min_amount.toLocaleString() + '円'];
    tds.forEach(function(v) {
      var td = document.createElement('td');
      td.textContent = v;
      tr.appendChild(td);
    });
    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  wrap.appendChild(table);
  return wrap;
}

function renderPopularityDist(dist) {
  var wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  var table = document.createElement('table');
  table.className = 'data-table';
  var thead = document.createElement('thead');
  var hrow = document.createElement('tr');
  ['人気区分', '件数', '割合'].forEach(function(h) {
    var th = document.createElement('th');
    th.textContent = h;
    hrow.appendChild(th);
  });
  thead.appendChild(hrow);
  table.appendChild(thead);
  var tbody = document.createElement('tbody');
  dist.forEach(function(d) {
    var tr = document.createElement('tr');
    var tds = [d.bucket, d.count, d.rate.toFixed(1) + '%'];
    tds.forEach(function(v) {
      var td = document.createElement('td');
      td.textContent = v;
      tr.appendChild(td);
    });
    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  wrap.appendChild(table);
  return wrap;
}

async function loadPayouts() {
  var byTypeEl = document.getElementById('payoutsByType');
  var popEl = document.getElementById('payoutsPopularity');
  try {
    var res = await fetch(API_HOST + '/get_analysis_payouts.php');
    var data = await res.json();
    if (data.error) throw new Error(data.error);

    byTypeEl.textContent = '';
    var note = document.createElement('div');
    note.className = 'note';
    note.textContent = '集計期間: ' + formatDateJP(data.date_range.min_date) + ' 〜 ' + formatDateJP(data.date_range.max_date) + '(払戻金データの記録開始から)';
    byTypeEl.appendChild(note);
    byTypeEl.appendChild(renderPayoutsByType(data.by_bet_type));

    popEl.textContent = '';
    if (data.popularity_dist && data.popularity_dist.length > 0) {
      var popNote = document.createElement('div');
      popNote.className = 'note';
      popNote.textContent = '対象: 3連単 ' + data.sanrentan_total.toLocaleString() + '件。1番人気決着の割合が低いほど「荒れ」傾向です。';
      popEl.appendChild(popNote);
      popEl.appendChild(renderPopularityDist(data.popularity_dist));
    } else {
      popEl.appendChild(makeError('データがありません'));
    }
  } catch (e) {
    byTypeEl.textContent = '';
    byTypeEl.appendChild(makeError('データの取得に失敗しました'));
  }
}

// --- 初期実行 ---
loadCompactRanking(); // Free 含む全ユーザー
if (IS_STANDARD_PLUS) {
  loadPayouts();
}
</script>
</body>
</html>
