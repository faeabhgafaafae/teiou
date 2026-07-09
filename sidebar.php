<aside class="sidebar">
  <nav class="side-nav">
    <a href="index.php" class="nav-item" id="menuHome"><i class="fas fa-home icon"></i> ホーム</a>
    <a href="mypage.php" class="nav-item" id="menuMypage"><i class="fas fa-user-cog icon"></i> マイページ</a>
    <a href="predictions.php" class="nav-item"><i class="fas fa-bullseye icon"></i> 予測レース</a>
    <a href="mypage.php#favoritesSection" class="nav-item"><i class="fas fa-star icon"></i> お気に入り</a>
    <a href="performance.php" class="nav-item"><i class="fas fa-chart-line icon"></i> 成績・回収率 <span class="nav-standard-badge">STANDARD+</span></a>
    <a href="analysis.php" class="nav-item"><i class="fas fa-database icon"></i> データ分析 <span class="nav-standard-badge">STANDARD+</span></a>
    <a href="my-picks.php" class="nav-item"><i class="fas fa-trophy icon"></i> マイ的中トラッカー <span class="nav-premium-badge">PREMIUM</span></a>
  </nav>

  <div class="premium-box">
    <h3>プレミアム会員になると予測の精度がさらにアップ！</h3>
    <ul>
      <li><i class="fas fa-check"></i> 全レースのAI予想紐解き</li>
      <li><i class="fas fa-check"></i> AI分析</li>
      <li><i class="fas fa-check"></i> 回収率ランキング</li>
      <li><i class="fas fa-check"></i> 広告非表示</li>
    </ul>
    <a href="plan.php" id="sidebarPromoBtn" class="btn-primary" style="display: block; text-align: center; text-decoration: none; line-height: 1.4;">詳しく見る</a>
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

<script>
function showComingSoon(e) {
  if (e) e.preventDefault();
  alert('準備中です。しばらくお待ちください。');
}

(function() {
  var currentPath = window.location.pathname.split('/').pop();
  if (currentPath === '' || currentPath === 'index.php') {
    currentPath = 'index.php';
  }

  var navItems = document.querySelectorAll('.side-nav .nav-item');
  navItems.forEach(function(item) {
    var href = item.getAttribute('href');
    if (href && href === currentPath) {
      item.classList.add('active');
    } else {
      item.classList.remove('active');
    }
  });
})();

(async function() {
  try {
    var res = await fetch('me.php');
    if (res.ok) {
      var data = await res.json();
      var user = data.user;
      var promoBtn = document.getElementById('sidebarPromoBtn');

      if (promoBtn && user && user.plan === 'premium') {
        promoBtn.textContent = '現在プレミアム会員です';
        promoBtn.removeAttribute('href');
        promoBtn.style.background = '#e2e8f0';
        promoBtn.style.color = '#a0aec0';
        promoBtn.style.border = '1px solid #cbd5e1';
        promoBtn.style.cursor = 'default';
        promoBtn.style.pointerEvents = 'none';
      }
    }
  } catch(e) { console.error(e); }
})();
</script>
