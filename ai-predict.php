<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>艇王 - 予想</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, 'Hiragino Sans', 'Meiryo', sans-serif; background: #f0f2f5; color: #333; min-height: 100vh; padding-top: 130px; /* ヘッダーとナビの高さ分余白を追加 */ }

/* --- ヘッダー全体を上部に固定 --- */
header { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 12px 24px; display: flex; align-items: center; justify-content: space-between; gap: 16px; position: fixed; top: 0; left: 0; width: 100%; z-index: 1000; height: 71px; /* ヘッダーの高さを固定 */ }

/* 左：戻るボタン・ロゴ ＆ レース場情報エリア */
.header-left { display: flex; align-items: center; gap: 20px; }
.back-btn { color: #0055a4; text-decoration: none; font-size: 20px; line-height: 1; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: background 0.15s; flex-shrink: 0; }
.back-btn:hover { background: #e8f0fd; }
.logo img { height: 44px; width: auto; display: block; cursor: pointer; }

.header-venue-info { display: flex; flex-direction: column; }
.header-venue-row { display: flex; align-items: center; gap: 8px; margin-bottom: 2px; }
.header-page-title { font-size: 18px; font-weight: 800; color: #1a202c; }
.gh-badge { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px; text-transform: uppercase; }
.header-main-date { font-size: 14px; color: #4a5568; font-weight: 700; font-variant-numeric: tabular-nums; }

/* 右：ログイン・新規登録ボタン */
.header-right { display: flex; align-items: center; gap: 16px; }
.header-auth { display: flex; align-items: center; gap: 8px; }
.auth-link { font-size: 12px; color: #0055a4; text-decoration: none; font-weight: 600; padding: 6px 14px; border-radius: 6px; border: 1px solid #e2e8f0; background: #fff; transition: background 0.2s; }
.auth-link:hover { background: #f8fafc; }
.auth-link.register { background: #0055a4; color: #fff; border: none; }
.auth-link.register:hover { background: #004488; }

/* グレード badge カラーマスタ */
.grade-sg { background: #fff3cd; color: #b8860b; }
.grade-g1 { background: #fee2e2; color: #c0392b; }
.grade-g2 { background: #dbeafe; color: #2563eb; }
.grade-g3 { background: #d1fae5; color: #16a34a; }
.grade-ippan { background: #f3f4f6; color: #888; }

/* --- R数クイックナビ --- */
.race-nav-sticky {
  position: fixed;
  top: 71px; /* ヘッダーの下に配置 */
  left: 0;
  width: 100%;
  background: #ffffff;
  z-index: 1000;
  border-bottom: 2px solid #0055a4;
  padding: 8px 12px;
  overflow-x: auto;
  white-space: nowrap;
  display: flex;
  gap: 6px;
  box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
}
.race-nav-sticky::-webkit-scrollbar { display: none; }

.race-nav-btn {
  background: #f1f5f9;
  border: 1px solid #cbd5e1;
  color: #0055a4;
  font-weight: 800;
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 13px;
  cursor: pointer;
  min-width: 52px;
  text-align: center;
  transition: all 0.15s ease;
  text-decoration: none;
  display: inline-block;
}
.race-nav-btn:hover { background: #0055a4; color: #ffffff; border-color: #0055a4; }
.race-nav-btn.active { background: #0055a4; color: #ffffff; border-color: #0055a4; }

.container { max-width: 800px; margin: 0 auto; padding: 20px 16px; }
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
.nav-btn { padding: 7px 14px; border: 1px solid #d0d5dd; border-radius: 8px; background: #fff; font-size: 12px; font-weight: 600; color: #555; text-decoration: none; transition: all 0.15s; }
.nav-btn:hover { border-color: #0055a4; color: #0055a4; }

.stats-tabs { display: flex; gap: 4px; margin-bottom: 8px; padding: 4px; background: #e9ecef; border-radius: 10px; }
.stats-tab { flex: 1; padding: 8px 4px; border: none; border-radius: 8px; background: transparent; font-size: 12px; font-weight: 600; color: #888; cursor: pointer; text-align: center; transition: all 0.15s; }
.stats-tab:hover { color: #555; background: #dde0e4; }
.stats-tab.active { background: #1a56db; color: #fff; }

.pred-table-header { display: flex; align-items: center; padding: 8px 12px; background: #f7f8fa; border: 1px solid #e0e3e8; border-radius: 12px 12px 0 0; gap: 8px; font-size: 10px; font-weight: 700; color: #999; }
.th-waku { width: 28px; flex-shrink: 0; text-align: center; }
.th-name { width: 90px; flex-shrink: 0; }
.th-score { width: 50px; flex-shrink: 0; text-align: center; }
.th-breakdown { width: 100px; flex-grow: 1; min-width: 80px; }
.th-rates { display: flex; gap: 4px; flex-shrink: 0; }
.th-rates > div { width: 52px; text-align: center; }
.th-top3 { color: #0055a4; }

.pred-card { background: #fff; border-left: 1px solid #e0e3e8; border-right: 1px solid #e0e3e8; border-bottom: 1px solid #e0e3e8; }
.pred-card:last-child { border-radius: 0 0 12px 12px; }
.pred-card.rank-1 { border-left: 3px solid #d97706; }
.pred-card.rank-2 { border-left: 3px solid #9ca3af; }
.pred-card.rank-3 { border-left: 3px solid #92400e; }

.pred-row { display: flex; align-items: center; padding: 10px 12px; gap: 8px; cursor: pointer; transition: background 0.1s; }
.pred-row:hover { background: #fafbfc; }

.waku { width: 28px; height: 28px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; flex-shrink: 0; }
.waku-1 { background: #fff; color: #222; border: 2px solid #ccc; }
.waku-2 { background: #222; color: #fff; }
.waku-3 { background: #e53e3e; color: #fff; }
.waku-4 { background: #2563eb; color: #fff; }
.waku-5 { background: #eab308; color: #222; }
.waku-6 { background: #16a34a; color: #fff; }

.pred-name-area { width: 90px; flex-shrink: 0; }
.pred-player-name { font-size: 13px; font-weight: 600; color: #222; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pred-player-sub { display: flex; align-items: center; gap: 4px; margin-top: 2px; }
.pred-player-grade { font-size: 10px; font-weight: 700; padding: 1px 5px; border-radius: 3px; }
.pg-A1 { background: #fff3cd; color: #b8860b; }
.pg-A2 { background: #dbeafe; color: #2563eb; }
.pg-B1 { background: #f3f4f6; color: #666; }
.pg-B2 { background: #f3f4f6; color: #aaa; }

.pred-score-total { width: 50px; flex-shrink: 0; text-align: center; font-size: 15px; font-weight: 700; color: #0055a4; }

.score-stack-wrap { width: 100px; flex-grow: 1; min-width: 80px; display: flex; align-items: center; gap: 6px; }
.score-stack { flex: 1; display: flex; height: 14px; border-radius: 4px; overflow: hidden; background: #e9ecef; }
.seg { height: 100%; }
.seg-ability { background: #2563eb; }
.seg-course { background: #16a34a; }
.seg-daily { background: #e67e22; }
.seg-weather { background: #00bcd4; }
.accordion-chevron { font-size: 9px; color: #bbb; flex-shrink: 0; transition: color 0.15s; }
.pred-row:hover .accordion-chevron { color: #666; }

.pred-rates { display: flex; gap: 4px; flex-shrink: 0; }
.pred-rate-box { width: 52px; text-align: center; padding: 4px 2px; border-radius: 6px; background: #fafbfc; }
.pred-rate-box.top3 { background: #e8f0fd; }
.pred-rate-pct { font-size: 14px; font-weight: 700; color: #222; line-height: 1.2; }
.pred-rate-box.top3 .pred-rate-pct { color: #0055a4; }
.pred-rate-count { font-size: 9px; color: #999; margin-top: 1px; }

.pred-detail { padding: 0 16px 14px 16px; background: #f9fafb; border-top: 1px dashed #e0e3e8; }
.score-detail-row { display: flex; align-items: center; gap: 10px; padding: 7px 0; }
.score-detail-row + .score-detail-row { border-top: 1px solid #f0f0f0; }
.score-detail-label { width: 72px; flex-shrink: 0; font-size: 12px; font-weight: 600; color: #555; }
.score-detail-bar-wrap { flex: 1; min-width: 0; }
.score-detail-bar-track { height: 12px; background: #e9ecef; border-radius: 4px; overflow: hidden; }
.score-detail-bar-fill { height: 100%; border-radius: 4px; transition: width 0.3s ease; }
.fill-ability { background: #2563eb; }
.fill-course { background: #16a34a; }
.fill-daily { background: #e67e22; }
.fill-weather { background: #00bcd4; }
.score-detail-value { width: 90px; flex-shrink: 0; font-size: 12px; color: #666; text-align: right; font-variant-numeric: tabular-nums; }

.score-legend { display: flex; gap: 12px; padding: 10px 16px; flex-wrap: wrap; }
.legend-item { display: flex; align-items: center; gap: 4px; font-size: 10px; color: #888; }
.legend-dot { width: 10px; height: 10px; border-radius: 2px; }

.bottom-actions { display: flex; gap: 6px; margin-top: 16px; }
.bottom-btn { flex: 1; padding: 10px; border: 1px solid #e0e3e8; border-radius: 10px; background: #fff; font-size: 13px; font-weight: 600; color: #555; text-align: center; text-decoration: none; transition: all 0.15s; }
.bottom-btn:hover { border-color: #0055a4; color: #0055a4; }

.pikaichi-bar { margin-bottom: 10px; }
.pikaichi-toggle { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d0d5dd; border-radius: 20px; background: #fff; font-size: 13px; font-weight: 700; color: #555; cursor: pointer; transition: all 0.15s; }
.pikaichi-toggle:hover { border-color: #f59e0b; color: #d97706; }
.pikaichi-toggle.active { background: #fffbeb; border-color: #f59e0b; color: #d97706; }

/* ─── 戦略セクション（strategy.phpから移植） ───────────── */
.note-box { background: #f0f7ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; font-size: 12px; color: #1e40af; line-height: 1.5; }
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

.filter-bar { display: flex; gap: 6px; padding: 7px 12px; background: #f9fafb; border-bottom: 1px solid #ebebeb; flex-wrap: wrap; align-items: center; }
.filter-group { display: flex; align-items: center; gap: 3px; }
.filter-pos-lbl { font-size: 11px; font-weight: 700; color: #555; margin-right: 2px; white-space: nowrap; }
.filter-btn { width: 44px; height: 44px; border-radius: 4px; border: 1px solid #ddd; background: #f0f0f0; color: #888; font-size: 12px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; padding: 0; flex-shrink: 0; }
.filter-btn:hover { border-color: #aaa; }
.filter-divider { width: 1px; height: 18px; background: #e0e0e0; margin: 0 2px; flex-shrink: 0; }

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
.ct-pika-mark { font-size: 13px; margin-right: 2px; }

.show-all-btn { display: block; width: 100%; padding: 11px 16px; border: none; border-top: 1px solid #ebebeb; background: #fafbfc; font-size: 13px; font-weight: 600; color: #0055a4; cursor: pointer; text-align: center; }
.show-all-btn:hover { background: #eff6ff; }

/* ブレークポイントはmax-widthの降順(820→600→480)で記述し、
   幅が狭くなるほど後段のルールが優先されるようにする */
@media (max-width: 820px) {
  header { padding: 12px 16px; min-height: 110px; height: auto; flex-direction: column; }
  .header-left { gap: 12px; }
  .logo img { height: 36px; }
  .header-page-title { font-size: 15px; }
  .header-main-date { font-size: 12px; }
  body { padding-top: 170px; }
}

@media (max-width: 600px) {
  header { flex-wrap: wrap; justify-content: space-between; padding: 10px 12px; gap: 8px; min-height: 110px; height: auto; }
  .header-left { width: auto; order: 1; }
  .header-right { width: auto; order: 2; gap: 8px; }
  .header-venue-info { flex-direction: row; align-items: center; width: 100%; order: 3; padding-top: 4px; border-top: 1px dashed #e2e8f0; justify-content: center; gap: 8px; }
  .race-nav-btn { min-width: 44px; padding: 6px 10px; font-size: 12px; }
  .race-bar { flex-direction: column; align-items: flex-start; }
  .race-bar-right { width: 100%; }
  .nav-btn { flex: 1; text-align: center; }
  .stats-tab { font-size: 11px; padding: 7px 2px; }
  .pred-table-header { display: none; }
  .pred-row { flex-wrap: wrap; padding: 10px 10px; gap: 6px; }
  .pred-name-area { width: auto; flex: 1; min-width: 0; }
  .pred-score-total { width: auto; font-size: 14px; }
  .score-stack-wrap { width: 100%; order: 10; }
  .score-stack { height: 12px; }
  .pred-rates { width: 100%; order: 11; }
  .pred-rate-box { flex: 1; width: auto; }
  .pred-rate-pct { font-size: 13px; }
  .waku { width: 24px; height: 24px; font-size: 12px; }
  .pred-detail { padding: 0 10px 10px 10px; }
  .score-detail-label { width: 60px; font-size: 11px; }
  .score-detail-value { width: 80px; font-size: 11px; }
}

@media (max-width: 480px) {
  .strat-stats-wrap { gap: 10px; }
  .strat-stat-val { font-size: 13px; }
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

<header>
  <div class="header-left">
    <a class="back-btn" id="backBtn" href="races.html">&larr;</a>
    <div class="logo" id="headerLogo" onclick="location.href='index.php'">
      <img src="艇王.jpg" alt="艇王 BOATRACE ROYAL">
    </div>
    <div class="header-venue-info">
      <div class="header-venue-row">
        <h1 id="pageTitle" class="header-page-title"></h1>
        <span id="pageBadge" style="display: none;"></span>
      </div>
      <div class="header-main-date" id="pageDate">--/-- (-)</div>
    </div>
  </div>

  <div class="header-right">
    <div class="header-auth" id="headerAuth">
      <a class="auth-link" href="login.html">ログイン</a>
      <a class="auth-link register" href="register.html">新規登録</a>
    </div>
  </div>
</header>

<div id="raceTopNav" class="race-nav-sticky" style="display: none;"></div>

<div class="container">
  <div class="race-bar" id="raceBar" style="display:none">
    <div class="race-bar-left">
      <div class="race-no-lg" id="raceNoLg"></div>
      <div class="race-bar-detail" id="raceBarDetail"></div>
    </div>
    <div class="race-bar-right">
      <a class="nav-btn" id="btnPrev">&#9664; 前R</a>
      <a class="nav-btn" id="btnNext">次R &#9654;</a>
    </div>
  </div>

  <div class="premium-lock" id="aiPaywall" style="display:none">
    <span class="premium-lock-icon">&#128274;</span>
    <p>AI予想(スコア・順位・買い目)はStandard/Premium会員限定です。</p>
    <a href="upgrade.html">プランをアップグレード</a>
  </div>

  <div id="predictSection">
    <div class="stats-tabs" id="statsTabs" style="display:none">
      <button class="stats-tab active" data-tab="recent10">直近10走</button>
      <button class="stats-tab" data-tab="recent6m">直近6ヶ月</button>
      <button class="stats-tab" data-tab="local">当地</button>
      <button class="stats-tab" data-tab="current_period">今期</button>
    </div>

    <div class="pred-table-header">
      <div class="th-waku">枠</div>
      <div class="th-name">レーサー</div>
      <div class="th-score">スコア</div>
      <div class="th-breakdown">スコア内訳</div>
      <div class="th-rates">
        <div class="th-top3">3連対率</div>
        <div>1着率</div>
        <div>2着率</div>
        <div>3着率</div>
      </div>
    </div>

    <div id="predList">
      <div class="loading"><div class="loading-spinner"></div>予想データを取得中...</div>
    </div>

    <div id="aiExplainSection" style="display:none; background:#fff; border:1px solid #e0e3e8; border-radius:12px; margin-top:16px; overflow:hidden;">
      <h3 style="font-size:14px; font-weight:700; color:#222; padding:14px 16px; border-bottom:1px solid #e0e3e8; margin:0;">&#129302; AI&#35299;&#35500;</h3>
      <div id="aiExplainBody" style="padding:14px 16px; font-size:13px; line-height:1.7; color:#333;">&#35299;&#35500;&#12434;&#29983;&#25104;&#20013;...</div>
    </div>

  </div>

  <div id="strategySection">
    <div class="note-box">各戦略の全期間実績と今レースの買い目を表示します。1点100円換算。</div>
    <div class="pikaichi-bar" id="pikaichiBar" style="display:none">
      <button class="pikaichi-toggle" id="pikaichiToggle">&#11088; ピカイチのみを表示</button>
    </div>
    <div id="strategyArea">
      <div class="loading"><div class="loading-spinner"></div>データを取得中...</div>
    </div>
  </div>

  <div class="bottom-actions" id="bottomActions" style="display:none">
    <a class="bottom-btn" id="btnRacelist">出走表</a>
    <a class="bottom-btn" id="btnPredict">直前情報</a>
  </div>
</div>

<footer>艇王 &copy; 2026</footer>

<script>
var params = new URLSearchParams(location.search);
var venue = params.get('venue') || '';
var date = params.get('date') || todayStr();
var raceNo = parseInt(params.get('race_no') || '1', 10);

function todayStr() { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0'); }

var VENUE_GRADES = { '桐生':'G3','戸田':'一般','江戸川':'一般','平和島':'G1','多摩川':'一般','浜名湖':'一般','蒲郡':'一般','常滑':'一般','津':'一般','三国':'一般','琵琶湖':'一般','住之江':'G2','尼崎':'G3','鳴門':'一般','丸亀':'一般','児島':'一般','宮島':'一般','徳山':'一般','下関':'SG','若松':'一般','芦屋':'一般','福岡':'一般','唐津':'一般','大村':'一般' };
var GRADE_CLASSES = { 'SG':'grade-sg','G1':'grade-g1','G2':'grade-g2','G3':'grade-g3','一般':'grade-ippan' };
var WAKU_STYLES = { 1:'background:#fff;color:#222;border:2px solid #ccc', 2:'background:#222;color:#fff', 3:'background:#e53e3e;color:#fff', 4:'background:#2563eb;color:#fff', 5:'background:#eab308;color:#222', 6:'background:#16a34a;color:#fff' };
var vg = VENUE_GRADES[venue] || '一般';

var backBtnEl = document.getElementById('backBtn');
if (backBtnEl) {
  backBtnEl.href = 'races.html?venue=' + encodeURIComponent(venue) + '&date=' + date;
}

function fmtDate(ds) { var d = new Date(ds + 'T00:00:00'); var w = ['日','月','火','水','木','金','土']; return (d.getMonth()+1) + '/' + d.getDate() + ' (' + w[d.getDay()] + ')'; }
function formatName(n) { return n.replace(/[\s　]+/g, ' ').trim(); }

document.getElementById('pageTitle').textContent = venueDisplayName(venue) + ' ' + raceNo + 'R 予想';
document.title = '艇王 - ' + venueDisplayName(venue) + ' ' + raceNo + 'R 予想';
var badge = document.getElementById('pageBadge');
badge.textContent = vg;
badge.className = 'gh-badge ' + (GRADE_CLASSES[vg] || 'grade-ippan');
badge.style.display = 'inline-block';
document.getElementById('pageDate').textContent = fmtDate(date);

var baseQ = 'venue=' + encodeURIComponent(venue) + '&date=' + date;
document.getElementById('btnRacelist').href = 'racelist.php?' + baseQ + '&race_no=' + raceNo;
document.getElementById('btnPredict').href = 'predict.html?' + baseQ + '&race_no=' + raceNo;

var prevNo = raceNo > 1 ? raceNo - 1 : 1;
var nextNo = raceNo < 12 ? raceNo + 1 : 12;
document.getElementById('btnPrev').href = 'ai-predict.php?' + baseQ + '&race_no=' + prevNo;
document.getElementById('btnNext').href = 'ai-predict.php?' + baseQ + '&race_no=' + nextNo;
if (raceNo <= 1) document.getElementById('btnPrev').style.visibility = 'hidden';
if (raceNo >= 12) document.getElementById('btnNext').style.visibility = 'hidden';

var raceNav = document.getElementById('raceTopNav');
raceNav.style.display = 'flex';
for (var rn = 1; rn <= 12; rn++) {
  var navBtn = document.createElement('a');
  navBtn.className = 'race-nav-btn' + (rn === raceNo ? ' active' : '');
  navBtn.href = 'ai-predict.php?' + baseQ + '&race_no=' + rn;
  navBtn.textContent = rn + 'R';
  raceNav.appendChild(navBtn);
}

// ヘッダーは画面幅によって行数が変わり高さが可変のため、
// 実際の高さを測ってbody余白とレースナビの位置に反映する(固定px値だと375px幅等で崩れるため)
function syncHeaderOffsets() {
  var headerEl = document.querySelector('header');
  if (!headerEl) return;
  var headerH = headerEl.offsetHeight;
  raceNav.style.top = headerH + 'px';
  document.body.style.paddingTop = (headerH + raceNav.offsetHeight) + 'px';
}
syncHeaderOffsets();
window.addEventListener('resize', syncHeaderOffsets);

var rateRefs = {};
var renderedPlayers = [];
var personalExplainCache = null;
var personalExplainLoading = false;
var personalExplainRefs = {};
var currentRaceId = null;
var userPlan = 'free';

var topScoreLane = null;
var pikaichiOnly = false;
var stratApplyFns = [];

function isPikaichiCombo(combo) {
  if (topScoreLane == null) return false;
  return Number(combo.split('-')[0]) === topScoreLane;
}

function buildResults(predictions) {
  var results = [];
  for (var i = 0; i < predictions.length; i++) {
    var p = predictions[i];
    results.push({
      player_id: p.player_id,
      lane: Number(p.lane),
      name: p.name,
      grade: p.grade,
      score: p.score_total,
      score_ability: p.score_ability,
      score_course: p.score_course,
      score_today: p.score_today,
      score_weather: p.score_weather
    });
  }
  results.sort(function(a, b) { return Number(b.score) - Number(a.score); });
  return results;
}

function renderPredCard(r, rank) {
  var card = document.createElement('div');
  card.className = 'pred-card' + (rank <= 3 ? ' rank-' + rank : '');

  var row = document.createElement('div');
  row.className = 'pred-row';

  var waku = document.createElement('div');
  waku.className = 'waku waku-' + r.lane;
  waku.textContent = r.lane;

  var nameArea = document.createElement('div');
  nameArea.className = 'pred-name-area';
  var nm = document.createElement('div');
  nm.className = 'pred-player-name';
  nm.textContent = formatName(r.name);
  var sub = document.createElement('div');
  sub.className = 'pred-player-sub';
  var gr = document.createElement('span');
  gr.className = 'pred-player-grade pg-' + (r.grade || '').replace(/\s/g, '');
  gr.textContent = r.grade || '';
  sub.appendChild(gr);
  nameArea.appendChild(nm);
  nameArea.appendChild(sub);

  var scoreEl = document.createElement('div');
  scoreEl.className = 'pred-score-total';
  scoreEl.textContent = r.score != null ? Number(r.score).toFixed(1) : '-';

  var sAbility = Number(r.score_ability) || 0;
  var sCourse = Number(r.score_course) || 0;
  var sDaily = Number(r.score_today) || 0;
  var sWeather = Number(r.score_weather) || 0;

  var stackWrap = document.createElement('div');
  stackWrap.className = 'score-stack-wrap';
  var stack = document.createElement('div');
  stack.className = 'score-stack';
  var segs = [
    { cls: 'seg-ability', val: sAbility },
    { cls: 'seg-course', val: sCourse },
    { cls: 'seg-daily', val: sDaily },
    { cls: 'seg-weather', val: sWeather }
  ];
  for (var s = 0; s < segs.length; s++) {
    if (segs[s].val > 0) {
      var seg = document.createElement('div');
      seg.className = 'seg ' + segs[s].cls;
      seg.style.width = segs[s].val + '%';
      stack.appendChild(seg);
    }
  }
  stackWrap.appendChild(stack);
  var chevron = document.createElement('div');
  chevron.className = 'accordion-chevron';
  chevron.innerHTML = '&#9660;';
  stackWrap.appendChild(chevron);

  var rates = document.createElement('div');
  rates.className = 'pred-rates';
  var rateKeys = ['top3', 'r1', 'r2', 'r3'];
  var refs = {};
  for (var b = 0; b < rateKeys.length; b++) {
    var box = document.createElement('div');
    box.className = 'pred-rate-box' + (b === 0 ? ' top3' : '');
    var pctEl = document.createElement('div');
    pctEl.className = 'pred-rate-pct';
    pctEl.textContent = '-';
    var cntEl = document.createElement('div');
    cntEl.className = 'pred-rate-count';
    cntEl.textContent = '';
    box.appendChild(pctEl);
    box.appendChild(cntEl);
    rates.appendChild(box);
    refs[rateKeys[b] + 'Pct'] = pctEl;
    refs[rateKeys[b] + 'Count'] = cntEl;
  }
  rateRefs[r.player_id] = refs;

  row.appendChild(waku);
  row.appendChild(nameArea);
  row.appendChild(scoreEl);
  row.appendChild(stackWrap);
  row.appendChild(rates);

  var detail = document.createElement('div');
  detail.className = 'pred-detail';
  detail.style.display = 'none';

  var components = [
    { label: '選手能力', val: sAbility, max: 40, cls: 'fill-ability' },
    { label: 'コース補正', val: sCourse, max: 35, cls: 'fill-course' },
    { label: '当日情報', val: sDaily, max: 35, cls: 'fill-daily' },
    { label: '気象', val: sWeather, max: 5, cls: 'fill-weather' }
  ];
  for (var c = 0; c < components.length; c++) {
    var comp = components[c];
    var dRow = document.createElement('div');
    dRow.className = 'score-detail-row';

    var lbl = document.createElement('div');
    lbl.className = 'score-detail-label';
    lbl.textContent = comp.label;

    var barWrap = document.createElement('div');
    barWrap.className = 'score-detail-bar-wrap';
    var track = document.createElement('div');
    track.className = 'score-detail-bar-track';
    var fill = document.createElement('div');
    fill.className = 'score-detail-bar-fill ' + comp.cls;
    var pct = comp.max > 0 ? (comp.val / comp.max * 100) : 0;
    if (pct > 100) pct = 100;
    fill.style.width = pct + '%';
    track.appendChild(fill);
    barWrap.appendChild(track);

    var valEl = document.createElement('div');
    valEl.className = 'score-detail-value';
    valEl.textContent = comp.val.toFixed(1) + ' / ' + comp.max + '点';

    dRow.appendChild(lbl);
    dRow.appendChild(barWrap);
    dRow.appendChild(valEl);
    detail.appendChild(dRow);
  }

  var personalBox = document.createElement('div');
  personalBox.style.cssText = 'background:#f5f6f8;border-radius:8px;padding:8px;margin-top:8px;font-size:12px;line-height:1.6;color:#555;display:none';
  detail.appendChild(personalBox);
  personalExplainRefs[r.player_id] = personalBox;

  row.onclick = function() {
    if (detail.style.display === 'none') {
      detail.style.display = 'block';
      chevron.innerHTML = '&#9650;';
      loadPersonalExplain(r.player_id);
    } else {
      detail.style.display = 'none';
      chevron.innerHTML = '&#9660;';
    }
  };

  card.appendChild(row);
  card.appendChild(detail);
  return card;
}

function updateRateDisplay(playerId, data) {
  var refs = rateRefs[playerId];
  if (!refs) return;
  if (!data || data.error || !data.total) {
    refs.top3Pct.textContent = '-';
    refs.top3Count.textContent = '';
    refs.r1Pct.textContent = '-';
    refs.r1Count.textContent = '';
    refs.r2Pct.textContent = '-';
    refs.r2Count.textContent = '';
    refs.r3Pct.textContent = '-';
    refs.r3Count.textContent = '';
    return;
  }
  var t = data.total;
  var r123 = data.rank1 + data.rank2 + data.rank3;
  refs.top3Pct.textContent = Number(data.rate123).toFixed(1) + '%';
  refs.top3Count.textContent = '(' + r123 + '/' + t + '走)';
  refs.r1Pct.textContent = Number(data.rate1).toFixed(1) + '%';
  refs.r1Count.textContent = '(' + data.rank1 + '/' + t + '走)';
  refs.r2Pct.textContent = Number(data.rate2).toFixed(1) + '%';
  refs.r2Count.textContent = '(' + data.rank2 + '/' + t + '走)';
  refs.r3Pct.textContent = Number(data.rate3).toFixed(1) + '%';
  refs.r3Count.textContent = '(' + data.rank3 + '/' + t + '走)';
}

function loadStats(tab) {
  for (var i = 0; i < renderedPlayers.length; i++) {
    (function(rp) {
      var url = API_HOST + '/get_stats.php?player_id=' + rp.player_id + '&lane=' + rp.lane + '&venue=' + encodeURIComponent(venue) + '&tab=' + tab;
      fetch(url).then(function(res) {
        return res.json();
      }).then(function(data) {
        updateRateDisplay(rp.player_id, data);
      }).catch(function() {
        updateRateDisplay(rp.player_id, null);
      });
    })(renderedPlayers[i]);
  }
}

var API_HOST = 'https://' + '2410049.moo.jp';

async function loadData() {
  var list = document.getElementById('predList');
  try {
    var url = API_HOST + '/get_prediction.php?date=' + encodeURIComponent(date) + '&venue=' + encodeURIComponent(venue) + '&race_no=' + raceNo;
    var res = await fetch(url);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    var data = await res.json();

    if (data.error || !data.predictions || data.predictions.length === 0) {
      list.textContent = '';
      var err = document.createElement('div');
      err.className = 'error-msg';
      err.textContent = '予想データが見つかりません';
      list.appendChild(err);
      return;
    }

    var bar = document.getElementById('raceBar');
    document.getElementById('raceNoLg').textContent = raceNo + 'R';
    var detail = document.getElementById('raceBarDetail');
    detail.textContent = '';
    var vs = document.createElement('strong');
    vs.textContent = venueDisplayName(venue);
    detail.appendChild(vs);
    detail.appendChild(document.createTextNode(' ' + fmtDate(date) + ' / 予想'));
    bar.style.display = 'flex';
    document.getElementById('statsTabs').style.display = 'flex';
    document.getElementById('bottomActions').style.display = 'flex';
    document.getElementById('pikaichiBar').style.display = 'block';

    var results = buildResults(data.predictions);
    topScoreLane = results.length ? results[0].lane : null;
    list.textContent = '';

    var legend = document.createElement('div');
    legend.className = 'score-legend';
    var legendItems = [
      { color: '#2563eb', label: '選手能力' },
      { color: '#16a34a', label: 'コース補正' },
      { color: '#e67e22', label: '当日情報' },
      { color: '#00bcd4', label: '気象' }
    ];
    for (var li = 0; li < legendItems.length; li++) {
      var item = document.createElement('div');
      item.className = 'legend-item';
      var dot = document.createElement('div');
      dot.className = 'legend-dot';
      dot.style.background = legendItems[li].color;
      var txt = document.createTextNode(legendItems[li].label);
      item.appendChild(dot);
      item.appendChild(txt);
      legend.appendChild(item);
    }
    list.appendChild(legend);

    renderedPlayers = [];
    rateRefs = {};
    for (var i = 0; i < results.length; i++) {
      list.appendChild(renderPredCard(results[i], i + 1));
      renderedPlayers.push({ player_id: results[i].player_id, lane: results[i].lane });
    }

    loadStats('recent10');

    if (data.race_id) {
      currentRaceId = data.race_id;
      loadExplain(data.race_id);
    }
  } catch(e) {
    list.textContent = '';
    var err2 = document.createElement('div');
    err2.className = 'error-msg';
    err2.textContent = 'データの取得に失敗しました';
    list.appendChild(err2);
  }
}

function applyPersonalExplain(data) {
  if (!data || !data.personals) return;
  personalExplainCache = {};
  for (var i = 0; i < data.personals.length; i++) {
    var p = data.personals[i];
    personalExplainCache[p.player_id] = p.explanation || '';
  }
  var keys = Object.keys(personalExplainRefs);
  for (var k = 0; k < keys.length; k++) {
    var pid = keys[k];
    var box = personalExplainRefs[pid];
    var text = personalExplainCache[pid];
    if (text) {
      box.textContent = text;
      box.style.display = '';
    } else {
      box.style.display = 'none';
    }
  }
}

function loadPersonalExplain(playerId) {
  var box = personalExplainRefs[playerId];
  if (!box) return;
  if (userPlan === 'free') {
    box.style.cssText = 'background:#f5f6f8;border-radius:8px;padding:8px;margin-top:8px;font-size:12px;line-height:1.6;color:#999;display:block;text-align:center';
    box.textContent = '🔒 個別解説はStandardプラン以上で利用できます';
    return;
  }
  if (personalExplainCache) {
    var text = personalExplainCache[playerId];
    if (text) {
      box.textContent = text;
      box.style.display = '';
    }
    return;
  }
  if (personalExplainLoading) {
    box.textContent = '💬 解説を生成中...';
    box.style.display = '';
    return;
  }
  personalExplainLoading = true;
  box.textContent = '💬 解説を生成中...';
  box.style.display = '';
  var raceId = currentRaceId;
  var url = API_HOST + '/gemini_explain.php?race_id=' + raceId + '&type=personal';
  fetch(url).then(function(res) {
    return res.json();
  }).then(function(data) {
    personalExplainLoading = false;
    applyPersonalExplain(data);
  }).catch(function() {
    personalExplainLoading = false;
    box.textContent = '解説を取得できませんでした';
  });
}

async function loadExplain(raceId) {
  var section = document.getElementById('aiExplainSection');
  var body = document.getElementById('aiExplainBody');
  section.style.display = '';
  body.textContent = '解説を生成中...';
  try {
    var url = API_HOST + '/gemini_explain.php?race_id=' + raceId;
    var res = await fetch(url);
    if (!res.ok) {
      body.textContent = '解説を取得できませんでした';
      return;
    }
    var data = await res.json();
    if (data.error) {
      body.textContent = '解説を取得できませんでした';
      return;
    }
    body.textContent = data.explanation;
  } catch(e) {
    body.textContent = '解説を取得できませんでした';
  }
}

var tabBtns = document.querySelectorAll('.stats-tab');
for (var t = 0; t < tabBtns.length; t++) {
  (function(btn) {
    btn.onclick = function() {
      for (var i = 0; i < tabBtns.length; i++) {
        tabBtns[i].className = 'stats-tab';
      }
      btn.className = 'stats-tab active';
      loadStats(btn.getAttribute('data-tab'));
    };
  })(tabBtns[t]);
}

/* ─── ピカイチのみ表示トグル ────────────────────────── */
document.getElementById('pikaichiToggle').onclick = function() {
  pikaichiOnly = !pikaichiOnly;
  this.className = 'pikaichi-toggle' + (pikaichiOnly ? ' active' : '');
  for (var s = 0; s < stratApplyFns.length; s++) { stratApplyFns[s](); }
};

/* ─── 戦略タブ（strategy.phpから移植） ─────────────── */
var COMBO_LIMIT = 10;
var STRAT_DEFS = [
  { type: '的中特化', color: '#2563eb', desc: '上位3艇の全順列（最大6点）' },
  { type: 'バランス',   color: '#16a34a', desc: '上位2艇固定×上位4艇流し（最大12点）' },
  { type: '一撃重視', color: '#dc2626', desc: '1位固定・穴狙い（最大6点）' },
  { type: '絞り込み', color: '#7c3aed', desc: '1点勝負' }
];

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

function makeComboRow(item, maxOdds, isFinished) {
  var isMax = maxOdds !== null && item.odds !== null && item.odds === maxOdds;
  var isHit = isFinished && item.is_hit;
  var isPika = isPikaichiCombo(item.combo);

  var rowClass = 'ct-row';
  if (isHit)      { rowClass += ' ct-hit'; }
  else if (isMax) { rowClass += ' ct-hl'; }

  var row = document.createElement('div'); row.className = rowClass;

  var comboCell = document.createElement('div'); comboCell.className = 'ct-col-combo';
  if (isPika) {
    var pikaMark = document.createElement('span'); pikaMark.className = 'ct-pika-mark'; pikaMark.textContent = '⭐';
    comboCell.appendChild(pikaMark);
  }
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

function renderStrategySection(strat, def, statRow, ctx) {
  var isFinished     = ctx ? ctx.isFinished     : false;
  var hitCombination = ctx ? ctx.hitCombination : null;
  var hitOdds        = ctx ? ctx.hitOdds        : null;
  var hitPayout      = ctx ? ctx.hitPayout      : null;
  var color          = def ? def.color : '#888';

  var section = document.createElement('div'); section.className = 'strat-section';

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

  if (isFinished) {
    body.appendChild(makeBanner(strat.is_hit, hitCombination, hitOdds, hitPayout));
  }

  var maxOdds = null;
  for (var i = 0; i < combos.length; i++) {
    var o = combos[i].odds;
    if (o !== null && (maxOdds === null || o > maxOdds)) { maxOdds = o; }
  }

  var rowEls    = [];
  var combosArr = [];
  for (var i = 0; i < combos.length; i++) {
    rowEls.push(makeComboRow(combos[i], maxOdds, isFinished));
    combosArr.push(combos[i].combo);
  }

  var filterSel     = [[], [], []];
  var showAllExpanded = false;

  function applyVisibility() {
    for (var ri = 0; ri < rowEls.length; ri++) {
      var passFilter = true;
      var passLimit  = showAllExpanded || ri < COMBO_LIMIT;
      var passPika   = !pikaichiOnly || isPikaichiCombo(combosArr[ri]);
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
      rowEls[ri].style.display = (passFilter && passPika && passLimit) ? '' : 'none';
    }
  }
  stratApplyFns.push(applyVisibility);

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

  applyVisibility();

  section.appendChild(body);
  return section;
}

function renderStrategySections(comboData, statsMap) {
  var area = document.getElementById('strategyArea');
  area.textContent = '';
  stratApplyFns = [];

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

async function fetchStrategyStats() {
  try {
    var res = await fetch(API_HOST + '/strategy_stats.php');
    if (!res.ok) return {};
    var data = await res.json();
    var map = {};
    var rows = data.stats || [];
    for (var i = 0; i < rows.length; i++) { map[rows[i].strategy_type] = rows[i]; }
    return map;
  } catch(e) { return {}; }
}

async function fetchStrategyCombos() {
  if (!venue || !raceNo) return null;
  try {
    var url = API_HOST + '/strategy_detail.php?venue=' + encodeURIComponent(venue) + '&date=' + date + '&race_no=' + raceNo;
    var res = await fetch(url);
    if (!res.ok) return null;
    return await res.json();
  } catch(e) { return null; }
}

async function loadStrategyTab() {
  var results = await Promise.all([fetchStrategyStats(), fetchStrategyCombos()]);
  renderStrategySections(results[1], results[0]);
}

async function init() {
  try {
    var meRes = await fetch(API_HOST + '/me.php');
    if (meRes.ok) {
      var meData = await meRes.json();
      if (meData && meData.user && meData.user.plan) { userPlan = meData.user.plan; }
    }
  } catch (e) {}

  if (userPlan === 'free') {
    document.getElementById('aiPaywall').style.display = 'block';
    document.getElementById('predictSection').style.display = 'none';
    document.getElementById('strategySection').style.display = 'none';
    return;
  }

  loadData();
  loadStrategyTab();
}
init();
</script>
</body>
</html>
