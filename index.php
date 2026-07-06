<?php
$today = date('Y-m-d');
$rawDate = isset($_GET['date']) ? trim($_GET['date']) : $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) || $rawDate > $today) {
    $pageDate = $today;
} else {
    $pageDate = $rawDate;
}
$isPast = ($pageDate < $today);
$prevDate = date('Y-m-d', strtotime($pageDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($pageDate . ' +1 day'));
$nextDisabled = ($nextDate > $today);
$weekDays = array('日', '月', '火', '水', '木', '金', '土');
$displayDay  = date('n月j日', strtotime($pageDate));
$displayDow  = $weekDays[date('w', strtotime($pageDate))];
?>
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

    /* 日付ナビゲーション */
    .date-nav {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
      padding: 14px 20px;
      background: var(--card-bg, #ffffff);
      border: 1px solid var(--border-color, #e2e8f0);
      border-radius: 12px;
      box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    }
    .date-nav-arrow {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 38px;
      height: 38px;
      background: var(--bg-nest, #f8fafc);
      border: 1px solid var(--border-color, #e2e8f0);
      border-radius: 50%;
      color: #0055a4;
      text-decoration: none;
      font-size: 14px;
      flex-shrink: 0;
    }
    .date-nav-arrow.disabled {
      color: #cbd5e0;
      cursor: not-allowed;
      pointer-events: none;
    }
    .date-nav-center {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .date-nav-today-badge {
      background: #0055a4;
      color: #fff;
      font-size: 10px;
      font-weight: bold;
      padding: 2px 8px;
      border-radius: 10px;
      letter-spacing: 0.5px;
    }
    .date-nav-date {
      font-size: 20px;
      font-weight: bold;
      color: var(--text-main, #1a202c);
    }
    .date-nav-dow {
      font-size: 14px;
      font-weight: 500;
      color: var(--text-sub, #718096);
    }
    .badge-past {
      display: inline-block;
      background: #718096;
      color: #fff;
      font-size: 11px;
      font-weight: bold;
      padding: 2px 8px;
      border-radius: 4px;
      margin-left: 8px;
      vertical-align: middle;
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
        <a href="#" class="nav-item" onclick="showComingSoon(event)"><i class="fas fa-bullseye icon"></i> 予測レース</a>
        <a href="mypage.php#favoritesSection" class="nav-item"><i class="fas fa-star icon"></i> お気に入り</a>
        <a href="performance.php" class="nav-item"><i class="fas fa-chart-line icon"></i> 成績・回収率</a>
        <a href="analysis.php" class="nav-item"><i class="fas fa-database icon"></i> データ分析</a>
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

        <div class="date-nav">
          <a class="date-nav-arrow" href="?date=<?php echo $prevDate; ?>"><i class="fas fa-chevron-left"></i></a>
          <div class="date-nav-center">
            <i class="fas fa-calendar-alt" style="color:#0055a4; font-size:15px;"></i>
            <?php if ($pageDate === $today): ?><span class="date-nav-today-badge">今日</span><?php endif; ?>
            <span class="date-nav-date"><?php echo htmlspecialchars($displayDay, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="date-nav-dow">（<?php echo $displayDow; ?>）</span>
          </div>
          <?php if ($nextDisabled): ?>
            <span class="date-nav-arrow disabled"><i class="fas fa-chevron-right"></i></span>
          <?php else: ?>
            <a class="date-nav-arrow" href="?date=<?php echo $nextDate; ?>"><i class="fas fa-chevron-right"></i></a>
          <?php endif; ?>
        </div>

        <div class="section-header">
          <h2 class="section-title">開催中のレース場 <span class="venue-count" id="venueCount">0場 開催中</span><?php if ($isPast): ?><span class="badge-past">開催済み</span><?php endif; ?></h2>
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

          <div class="bottom-card"<?php if ($isPast): ?> style="display:none;"<?php endif; ?>>
            <h3><i class="fas fa-hourglass-half" style="color: #eab308;"></i>まもなく締切</h3>
            <div id="urgentRaceList">
              <div style="text-align:center; color:#999; font-size:12px; padding:20px 0;">レースを集計中...</div>
            </div>
          </div>

          <div class="bottom-card">
            <h3><i class="fas fa-bullseye" style="color: #e53e3e; margin-right: 6px;"></i>的中速報</h3>
            <div id="hitsList" style="max-height:300px; overflow-y:auto;">
              <div style="text-align:center; color:#999; font-size:12px; padding:20px 0;">読み込み中...</div>
            </div>
          </div>
        </div>

      </div>

      <div id="contentMypage" style="display: none; padding: 20px; background: #fff; border-radius: 12px; border: 1px solid #e2e8f0;">
        <p>マイページへ遷移します...</p>
      </div>

    </main>
  </div>

  <script>var PAGE_DATE = '<?php echo htmlspecialchars($pageDate, ENT_QUOTES, 'UTF-8'); ?>';</script>
  <script src="app.js"></script>
  <script src="home-races.js" defer></script>
</body>
</html>
