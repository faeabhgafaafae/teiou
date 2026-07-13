<aside class="sidebar">
  <nav class="side-nav">
    <a href="index.php" class="nav-item" id="menuHome"><svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg> ホーム</a>
    <a href="mypage.php" class="nav-item" id="menuMypage"><svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> マイページ</a>
    <a href="predictions.php" class="nav-item"><svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg> 予測レース</a>
    <a href="performance.php" class="nav-item"><svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg> 成績・回収率 <span class="nav-standard-badge">STANDARD+</span></a>
    <a href="analysis.php" class="nav-item"><svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg> データ分析 <span class="nav-standard-badge">STANDARD+</span></a>
  </nav>

  <div class="premium-box">
    <h3>プレミアム会員になると予測の精度がさらにアップ！</h3>
    <ul>
      <li><i class="fas fa-check"></i> 全レースのAI予想紐解き</li>
      <li><i class="fas fa-check"></i> AI分析</li>
      <li><i class="fas fa-check"></i> 回収率ランキング</li>
      <li><i class="fas fa-check"></i> 広告非表示</li>
    </ul>
    <a href="upgrade.html" id="sidebarPromoBtn" class="btn-primary" style="display: block; text-align: center; text-decoration: none; line-height: 1.4;">詳しく見る</a>
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
