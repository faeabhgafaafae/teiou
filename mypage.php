<?php
/* 必要に応じてログインチェックなどをここに記述 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>マイページ - 艇王</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <script src="venue-display.js"></script>
  <script src="plan-features.js"></script>
  <style>
    /* マイページ固有のスタイル */
    .mypage-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); margin-bottom: 20px; }
    .profile-section { display: flex; align-items: center; gap: 20px; margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid #edf2f7; }
    .profile-avatar { width: 64px; height: 64px; background: #0055a4; color: #fff; font-size: 24px; font-weight: bold; display: flex; align-items: center; justify-content: center; border-radius: 50%; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 13px; font-weight: 600; color: #4a5568; margin-bottom: 6px; }
    .form-group input[type="password"] { width: 100%; max-width: 400px; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; }
    .alert-msg { padding: 10px 12px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; display: none; }
    /* プランカード */
    .plan-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: 8px; }
    .plan-card { border: 2px solid #e2e8f0; border-radius: 10px; padding: 20px 16px; text-align: center; position: relative; transition: border-color 0.2s; }
    .plan-card.current { border-color: #0055a4; background: #f0f7ff; }
    .plan-card.premium-card { border-color: #d97706; }
    .plan-card.premium-card.current { background: #fffbeb; }
    .plan-card-name { font-size: 18px; font-weight: 800; color: #1a202c; margin-bottom: 4px; }
    .plan-card-price { font-size: 22px; font-weight: 900; color: #0055a4; margin-bottom: 12px; }
    .plan-card-price span { font-size: 13px; font-weight: 500; color: #718096; }
    .plan-card-price.price-premium { color: #d97706; }
    .plan-card-features { list-style: none; padding: 0; margin: 0 0 16px; text-align: left; font-size: 13px; color: #4a5568; }
    .plan-card-features li { padding: 3px 0; }
    .plan-card-features li::before { content: '✓ '; color: #22c55e; font-weight: bold; }
    .plan-card-features li.disabled { color: #cbd5e1; }
    .plan-card-features li.disabled::before { content: '✗ '; color: #cbd5e1; }
    .plan-current-badge { display: inline-block; background: #0055a4; color: #fff; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 20px; margin-bottom: 8px; }
    .plan-current-badge.badge-premium { background: #d97706; }
    .btn-plan-change { width: 100%; padding: 9px 0; border-radius: 6px; border: none; font-size: 13px; font-weight: 700; cursor: pointer; background: #0055a4; color: #fff; transition: background 0.2s; }
    .btn-plan-change:hover { background: #003f7d; }
    .btn-plan-change.btn-premium { background: #d97706; }
    .btn-plan-change.btn-premium:hover { background: #b45309; }
    .btn-plan-change:disabled { background: #e2e8f0; color: #a0aec0; cursor: default; }
    .plan-alert-msg { padding: 10px 12px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; display: none; }
  </style>
</head>
<body>

  <?php include 'header.php'; ?>

  <div class="dashboard-container">
    
    <?php include 'sidebar.php'; ?>

    <main class="main-content">
      
      <div class="section-header">
        <h2 class="section-title">マイページ</h2>
      </div>

      <div class="mypage-card">
        <div class="profile-section">
          <div class="profile-avatar" id="userAvatar">-</div>
          <div>
            <h3 style="font-size: 18px; font-weight: 800; color: #2d3748; margin-bottom: 4px;" id="profileName">ユーザー名</h3>
            <p style="font-size: 13px; color: #718096;" id="profileEmail">email@example.com</p>
          </div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
          <div>
            <span style="font-size: 12px; color: #a0aec0; display: block; margin-bottom: 2px;">現在のプラン</span>
            <span style="font-size: 15px; font-weight: bold; color: #2b6cb0;" id="profilePlan">- 会員</span>
          </div>
          <div>
            <span style="font-size: 12px; color: #a0aec0; display: block; margin-bottom: 2px;">登録日</span>
            <span style="font-size: 15px; font-weight: bold; color: #2d3748;" id="profileDate">----年--月--日</span>
          </div>
        </div>
      </div>

      <div class="mypage-card">
        <h3 style="font-size: 15px; font-weight: 800; color: #1a202c; margin-bottom: 16px;"><i class="fas fa-lock" style="margin-right: 8px; color: #718096;"></i>パスワードの変更</h3>
        <div class="alert-msg" id="passwordMessage"></div>
        <div class="form-group">
          <label for="newPasswordInput">新しいパスワード (8文字以上)</label>
          <input type="password" id="newPasswordInput" placeholder="••••••••">
        </div>
        <div class="form-group">
          <label for="confirmPasswordInput">新しいパスワード (確認用)</label>
          <input type="password" id="confirmPasswordInput" placeholder="••••••••">
        </div>
        <button class="btn-primary" id="btnUpdatePassword" style="max-width: 200px; margin-top: 8px; padding: 10px;">パスワードを更新</button>
      </div>

      <div class="mypage-card">
        <h3 style="font-size: 15px; font-weight: 800; color: #1a202c; margin-bottom: 4px;"><i class="fas fa-crown" style="margin-right: 8px; color: #d97706;"></i>プランの変更</h3>
        <p style="font-size: 13px; color: #718096; margin-bottom: 16px;">現在のプランを変更できます。</p>
        <div class="plan-alert-msg" id="planMessage"></div>
        <div class="plan-cards">

          <div class="plan-card" id="planCardFree">
            <div class="plan-card-name">Free</div>
            <div class="plan-card-price">¥0 <span>/ 月</span></div>
            <ul class="plan-card-features" id="featuresFree"></ul>
            <button class="btn-plan-change" id="btnSelectFree" disabled>現在のプラン</button>
          </div>

          <div class="plan-card" id="planCardStandard">
            <div class="plan-card-name">Standard</div>
            <div class="plan-card-price">¥980 <span>/ 月</span></div>
            <ul class="plan-card-features" id="featuresStandard"></ul>
            <button class="btn-plan-change" id="btnSelectStandard">このプランに変更</button>
          </div>

          <div class="plan-card premium-card" id="planCardPremium">
            <div class="plan-card-name">Premium</div>
            <div class="plan-card-price price-premium">¥1,980 <span>/ 月</span></div>
            <ul class="plan-card-features" id="featuresPremium"></ul>
            <button class="btn-plan-change btn-premium" id="btnSelectPremium">このプランに変更</button>
          </div>

        </div>
      </div>

      <div class="section-header" id="favoritesSection" style="margin-top: 32px;">
        <h2 class="section-title"><i class="fas fa-star" style="color: #d97706; margin-right: 6px;"></i>お気に入りのレース場</h2>
      </div>
      <div id="mypageFavoriteList" class="venue-grid"></div>

    </main>
  </div>

  <script>
    var apiDate = '';
    var favoriteVenues = [];
    var venueMap = {};
    var currentUserPlan = 'free';

    function formatDate(dateStr) {
      var d = new Date(dateStr + 'T00:00:00');
      var days = ['日','月','火','水','木','金','土'];
      return d.getFullYear() + '年' + (d.getMonth()+1) + '月' + d.getDate() + '日 (' + days[d.getDay()] + ')';
    }

    async function loadHeaderStats() {
      try {
        var res = await fetch('https://2410049.moo.jp/venues.php');
        if (res.ok) {
          var data = await res.json();
          apiDate = data.date;
          document.getElementById('headerDate').textContent = formatDate(data.date);
          var activeCount = data.venues ? data.venues.length : 0;
          var sb = document.getElementById('statsBadge');
          if (sb) sb.textContent = activeCount + '場 開催中 / 全24場';
        }
      } catch (e) { console.error(e); }
    }

    function renderMypageFavorites() {
      var container = document.getElementById('mypageFavoriteList');
      if (!container) return;
      if (favoriteVenues.length === 0) {
        container.style.display = 'block'; 
        container.innerHTML = '<span style="color:#a0aec0; font-size:13px;">登録されているお気に入りはありません。</span>';
        return;
      }
      container.style.display = 'grid';
      container.innerHTML = favoriteVenues.map(function(name) {
        var imgSrc = name + '.jpg';
        var displayName = venueDisplayName(name);
        return '<div style="background: #fff; border: 1px solid #edf2f7; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.05); text-align: center; position: relative;">' +
                 '<img src="' + imgSrc + '" alt="' + displayName + '" style="width: 100%; height: 110px; object-fit: cover; display: block;">' +
                 '<div style="padding: 8px 6px; font-size: 13px; font-weight: bold; color: #2d3748; background: #fff; border-top: 1px solid #edf2f7;">' +
                   '★ ' + displayName +
                 '</div>' +
               '</div>';
      }).join('');
    }

    async function fetchFavoriteVenues() {
      try {
        var res = await fetch('get_favorites.php');
        if (res.ok) {
          favoriteVenues = await res.json();
          renderMypageFavorites();
        }
      } catch (e) { console.error(e); }
    }

    async function checkAuth() {
      var authEl = document.getElementById('headerAuth');
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

        currentUserPlan = user.plan;

        if (document.getElementById('profileName')) document.getElementById('profileName').textContent = user.name;
        if (document.getElementById('userAvatar')) document.getElementById('userAvatar').textContent = user.name.charAt(0);
        if (document.getElementById('profileEmail')) document.getElementById('profileEmail').textContent = user.email;
        if (document.getElementById('profilePlan')) document.getElementById('profilePlan').textContent = planLabel[user.plan] + ' 会員';
        if (document.getElementById('profileDate') && user.created_at) {
          var d = new Date(user.created_at);
          document.getElementById('profileDate').textContent = d.getFullYear() + '年' + (d.getMonth()+1) + '月' + d.getDate() + '日';
        }

        updatePlanCards(user.plan);

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
          if(dropdown) dropdown.classList.remove('open');
        });
        document.getElementById('logoutBtn').addEventListener('click', async function() {
          await fetch('logout.php');
          location.href = 'index.php';
        });
      } catch(err) {
        authEl.innerHTML = '<a class="auth-link" href="login.html">ログイン</a><a class="auth-link register" href="register.html">新規登録</a>';
      }
    }

    function updatePlanCards(plan) {
      var cards = { free: 'planCardFree', standard: 'planCardStandard', premium: 'planCardPremium' };
      var btns  = { free: 'btnSelectFree', standard: 'btnSelectStandard', premium: 'btnSelectPremium' };

      Object.keys(cards).forEach(function(p) {
        var card = document.getElementById(cards[p]);
        var btn  = document.getElementById(btns[p]);
        if (!card || !btn) return;

        var existingBadge = card.querySelector('.plan-current-badge');
        if (existingBadge) existingBadge.remove();

        if (p === plan) {
          card.classList.add('current');
          var badge = document.createElement('div');
          badge.className = 'plan-current-badge' + (p === 'premium' ? ' badge-premium' : '');
          badge.textContent = '現在のプラン';
          card.insertBefore(badge, card.firstChild);
          btn.disabled = true;
          btn.textContent = '現在のプラン';
        } else {
          card.classList.remove('current');
          btn.disabled = false;
          btn.textContent = 'このプランに変更';
        }
      });
    }

    function setupPlanChange() {
      ['standard', 'premium'].forEach(function(plan) {
        var btnId = plan === 'standard' ? 'btnSelectStandard' : 'btnSelectPremium';
        var btn = document.getElementById(btnId);
        if (!btn) return;
        btn.addEventListener('click', async function() {
          var msgEl = document.getElementById('planMessage');
          btn.disabled = true;
          btn.textContent = '変更中...';
          try {
            var res = await fetch('update_plan.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ plan: plan })
            });
            var data = await res.json();
            msgEl.style.display = 'block';
            if (res.ok && data.success) {
              currentUserPlan = plan;
              var planLabel = { free: 'Free', standard: 'Standard', premium: 'Premium' };
              msgEl.style.background = '#c6f6d5'; msgEl.style.color = '#22543d';
              msgEl.textContent = '✓ プランを ' + planLabel[plan] + ' に変更しました！';
              if (document.getElementById('profilePlan')) {
                document.getElementById('profilePlan').textContent = planLabel[plan] + ' 会員';
              }
              updatePlanCards(plan);
            } else {
              msgEl.style.background = '#fed7d7'; msgEl.style.color = '#c53030';
              msgEl.textContent = data.error || 'プランの変更に失敗しました。';
              updatePlanCards(currentUserPlan);
            }
          } catch (err) {
            var msgEl2 = document.getElementById('planMessage');
            msgEl2.style.display = 'block';
            msgEl2.style.background = '#fed7d7'; msgEl2.style.color = '#c53030';
            msgEl2.textContent = '通信エラーが発生しました。';
            updatePlanCards(currentUserPlan);
          }
        });
      });
    }

    function setupPasswordUpdate() {
      var btn = document.getElementById('btnUpdatePassword');
      if (!btn) return;
      btn.addEventListener('click', async function() {
        var passwordInput = document.getElementById('newPasswordInput');
        var confirmInput = document.getElementById('confirmPasswordInput');
        var messageEl = document.getElementById('passwordMessage');
        var password = passwordInput.value.trim();
        var confirmPassword = confirmInput.value.trim();

        messageEl.style.display = 'block';
        if (!password || password.length < 8) {
          messageEl.style.background = '#fed7d7'; messageEl.style.color = '#c53030';
          messageEl.textContent = 'パスワードは8文字以上で入力してください。'; return;
        }
        if (password !== confirmPassword) {
          messageEl.style.background = '#fed7d7'; messageEl.style.color = '#c53030';
          messageEl.textContent = '入力されたパスワードが一致しません。'; return;
        }

        try {
          var res = await fetch('update_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: password })
          });
          var data = await res.json();
          if (res.ok) {
            messageEl.style.background = '#c6f6d5'; messageEl.style.color = '#22543d';
            messageEl.textContent = '✓ パスワードを正常に変更しました！';
            passwordInput.value = ''; confirmInput.value = '';
          } else {
            messageEl.style.background = '#fed7d7'; messageEl.style.color = '#c53030';
            messageEl.textContent = data.error || '変更に失敗しました。';
          }
        } catch (err) {
          messageEl.style.background = '#fed7d7'; messageEl.style.color = '#c53030';
          messageEl.textContent = '通信エラーが発生しました。';
        }
      });
    }

    // プラン特典リストはplan-features.js(mypage.php・upgrade.html共通)から描画する
    function renderPlanFeatures(ulId, tierKey) {
      var ul = document.getElementById(ulId);
      if (!ul) return;
      ul.textContent = '';
      PLAN_FEATURES.forEach(function(f) {
        var val = f.tiers[tierKey];
        var li = document.createElement('li');
        if (val === false) {
          li.className = 'disabled';
          li.textContent = f.label;
        } else if (typeof val === 'string') {
          li.textContent = f.label + ' (' + val + ')';
        } else {
          li.textContent = f.label;
        }
        ul.appendChild(li);
      });
    }
    renderPlanFeatures('featuresFree', 'free');
    renderPlanFeatures('featuresStandard', 'standard');
    renderPlanFeatures('featuresPremium', 'premium');

    document.getElementById('headerLogo').addEventListener('click', function() { location.href = 'index.php'; });
    loadHeaderStats();
    checkAuth();
    fetchFavoriteVenues();
    setupPasswordUpdate();
    setupPlanChange();
  </script>
</body>
</html>
