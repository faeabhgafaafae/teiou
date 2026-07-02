<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>艇王 - ボートレース予測</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    /* ==========================================
       追加: まもなく締切レースカード用のCSS
       ========================================== */
    .urgent-card-box {
      background: var(--card-bg, #f8fafc);
      border: 1px solid var(--border-color, #e2e8f0);
      border-radius: 8px;
      margin-bottom: 12px;
      padding: 10px;
    }
    .urgent-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 8px;
    }
    .urgent-venue {
      background: #0055a4;
      color: #fff;
      font-weight: bold;
      font-size: 11px;
      padding: 2px 6px;
      border-radius: 4px;
    }
    .urgent-no {
      font-size: 13px;
      font-weight: bold;
      color: var(--text-main, #4a5568);
    }
    .urgent-time {
      font-size: 11px;
      font-weight: bold;
      padding: 2px 6px;
      border-radius: 4px;
    }
    .urgent-time.time-red { color: #fff; background: #ef4444; }
    .urgent-time.time-yellow { color: #451a03; background: #eab308; }
    .urgent-time.time-green { color: #fff; background: #22c55e; }
    .urgent-time.closed { color: #fff; background: #718096; }
    
    .urgent-players {
      display: flex;
      flex-wrap: wrap;
      gap: 4px;
      background: var(--bg-nest, #fff);
      padding: 6px;
      border-radius: 4px;
      border: 1px solid var(--border-color, #edf2f7);
      margin-bottom: 8px;
    }
    .urgent-player-dot {
      font-size: 11px;
      display: flex;
      align-items: center;
      gap: 3px;
      background: var(--card-bg, #f1f5f9);
      padding: 1px 4px;
      border-radius: 3px;
      color: var(--text-main, #4a5568);
    }
    .w-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
    .wd-1 { background: #ccc; border: 1px solid #999; }
    .wd-2 { background: #222; }
    .wd-3 { background: #e53e3e; }
    .wd-4 { background: #2563eb; }
    .wd-5 { background: #eab308; }
    .wd-6 { background: #16a34a; }

    .urgent-btn-group { display: flex; gap: 4px; }
    .urgent-btn { flex: 1; text-align: center; font-size: 11px; padding: 5px 0; border: 1px solid var(--border-color, #e2e8f0); border-radius: 4px; color: var(--text-main, #4a5568); text-decoration: none; background: var(--bg-nest, #fff); }
    .urgent-btn.main-btn { background: #0055a4; color: #fff; border-color: #0055a4; }

    /* 3カラム等幅に並べるためのメディアクエリ設定 */
    @media (min-width: 992px) {
      .bottom-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
      .bottom-card { margin-bottom: 0 !important; }
    }
  </style>
</head>
<body>

  <?php include 'header.php'; ?>

  <div class="dashboard-container">
    
    <aside class="sidebar">
      <nav class="side-nav">
        <a href="#" class="nav-item active" id="menuHome"><i class="fas fa-home icon"></i> ホーム</a>
        <a href="mypage.php" class="nav-item" id="menuMypage"><i class="fas fa-user-cog icon"></i> マイページ</a>
        <a href="#" class="nav-item"><i class="fas fa-bullseye icon"></i> 予測レース</a>
        <a href="#" class="nav-item"><i class="fas fa-star icon"></i> お気に入り</a>
        <a href="#" class="nav-item"><i class="fas fa-chart-line icon"></i> 成績・回収率</a>
        <a href="#" class="nav-item"><i class="fas fa-database icon"></i> データ分析</a>
        <a href="#" class="nav-item"><i class="fas fa-newspaper icon"></i> ニュース・コラム</a>
      </nav>

      <div class="premium-box">
        <h3>プレミアム会員になると予測の精度がさらにアップ！</h3>
        <ul>
          <li><i class="fas fa-check"></i> 全レースのAI予想紐解き</li>
          <li><i class="fas fa-check"></i> AI analysis</li>
          <li><i class="fas fa-check"></i> 回収率ランキング</li>
          <li><i class="fas fa-check"></i> 広告非表示</li>
        </ul>
        <button class="btn-primary">詳しく見る</button>
      </div>

      <div class="stats-box">
        <div class="stats-title">本日のレース数</div>
        <div class="stats-badge" id="statsBadge">--場 開催中 / 全24場</div>
        <button class="btn-refresh" onclick="location.reload();">更新する</button>
      </div>

      <div class="sidebar-footer">
        <a href="#">ヘルプ</a><a href="#">お問い合わせ</a><br>
        <a href="#">利用規約</a><a href="#">プライバシー</a><br>
        © 2026 艇王
      </div>
    </aside>

    <main class="main-content">
      
      <div id="contentHome">
        
        <section id="favoriteVenueSection" style="display: none; margin-bottom: 24px;">
          <div class="section-header">
            <h2 class="section-title"><i class="fas fa-star" style="color: #d97706; margin-right: 6px;"></i>お気に入りレース場</h2>
          </div>
          <div class="venue-grid" id="favoriteVenueGrid"></div>
        </section>

        <div class="section-header">
          <h2 class="section-title">開催中のレース場 <span class="venue-count" id="venueCount">0場 開催中</span></h2>
        </div>

        <div class="filter-bar">
          <div class="filter-tabs" id="filterTabs">
            <button class="tab active" data-grade="all">すべて</button>
            <button class="tab" data-grade="G1">G1</button>
            <button class="tab" data-grade="G2">G2</button>
            <button class="tab" data-grade="G3">G3</button>
            <button class="tab" data-grade="一般">一般</button>
            <button class="tab" data-grade="SG">SG</button>
          </div>
          <div class="search-box">
            <input type="text" id="searchInput" placeholder="会場名検索">
          </div>
        </div>

        <div class="venue-grid" id="venueGrid">
          <div class="loading">
            <div class="loading-spinner"></div>
            データを読み込み中...
          </div>
        </div>

        <div class="bottom-row">
          <div class="bottom-card">
            <h3><i class="fas fa-fire" style="color: #e53e3e;"></i>注目のレース</h3>
            <div id="featuredBanner">
              <div style="font-size:12px; color:#718096;">データを読み込み中...</div>
            </div>
          </div>

          <div class="bottom-card">
            <h3><i class="fas fa-hourglass-half" style="color: #eab308;"></i>まもなく締切</h3>
            <div id="urgentRaceList">
              <div style="text-align:center; color:#999; font-size:12px; padding:20px 0;">レースを集計中...</div>
            </div>
          </div>

          <div class="bottom-card">
            <h3><i class="fas fa-info-circle" style="color: #0055a4;"></i>お知らせ</h3>
            <ul class="info-list">
              <li><span class="date">2026/06/24</span><a href="#">AI予測モデルのアルゴリズムをアップデートしました</a></li>
              <li><span class="date">2026/06/20</span><a href="#">SG開催期間中のサーバーメンテナンスについて</a></li>
              <li><span class="date">2026/06/15</span><a href="#">プレミアムプランの新規登録キャンペーン実施中！</a></li>
            </ul>
          </div>
        </div>

      </div>

      <div id="contentMypage" style="display: none; padding: 20px; background: #fff; border-radius: 12px; border: 1px solid #e2e8f0;">
        <p>マイページへ遷移します...</p>
      </div>

    </main>
  </div>

  <script src="app.js"></script>
  <script src="home-races.js" defer></script>
</body>
</html>
