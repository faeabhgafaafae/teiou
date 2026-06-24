<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>マイページ - 艇王</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="style.css">
  <style>
    /* マイページ専用の追加スタイル */
    .mypage-wrapper { max-width: 800px; margin: 40px auto; padding: 0 20px; }
    .profile-card { background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .profile-header { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; border-bottom: 1px solid #edf2f7; padding-bottom: 16px; }
    .profile-avatar { width: 60px; height: 60px; background: #0055a4; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: bold; }
    .profile-title h2 { font-size: 20px; font-weight: 800; color: #1a202c; text-align: left; margin-bottom: 4px; }
    .info-group { margin-bottom: 20px; }
    .info-label { font-size: 12px; color: #718096; font-weight: bold; margin-bottom: 6px; }
    .info-value { font-size: 15px; color: #2d3748; font-weight: 600; background: #f7fafc; padding: 10px 14px; border-radius: 6px; border: 1px solid #edf2f7; }
    .btn-back { display: inline-flex; align-items: center; gap: 8px; color: #0055a4; text-decoration: none; font-size: 13px; font-weight: bold; margin-bottom: 20px; }
    .btn-back:hover { text-decoration: underline; }
    .plan-text-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; background: #edf2f7; color: #4a5568; }
    .plan-text-badge.premium { background: #feebc8; color: #dd6b20; }
    .plan-change-btn { display: inline-block; margin-top: 10px; padding: 8px 20px; border-radius: 6px; font-size: 13px; font-weight: 700; color: #0055a4; background: #fff; border: 2px solid #0055a4; text-decoration: none; transition: all 0.15s; }
    .plan-change-btn:hover { background: #0055a4; color: #fff; }
  </style>
</head>
<body>

<header>
  <div class="logo" style="cursor: pointer;" onclick="location.href='index.html'">
    <h1>艇王</h1>
    <span class="logo-sub">ボートレース予測</span>
  </div>
  <div class="header-right">
    <div class="header-auth" id="headerAuth"></div>
  </div>
</header>

<div class="mypage-wrapper">
  <a href="index.html" class="btn-back"><i class="fas fa-arrow-left"></i> レース一覧に戻る</a>

  <div class="profile-card">
    <div class="profile-header">
      <div class="profile-avatar" id="userAvatar">-</div>
      <div class="profile-title">
        <h2 id="profileName">読み込み中...</h2>
        <div id="profilePlan" class="plan-text-badge">Free</div>
        <div><a href="upgrade.html" class="plan-change-btn">プランを変更する</a></div>
      </div>
    </div>

    <div class="info-group">
      <div class="info-label">メールアドレス</div>
      <div class="info-value" id="profileEmail">---------</div>
    </div>

    <div class="info-group">
      <div class="info-label">アカウント登録日</div>
      <div class="info-value" id="profileDate">---------</div>
    </div>
  </div>
</div>

<footer>艇王 &copy; 2026</footer>

<script>
// マイページ用のデータ取得と表示処理
async function loadMyPage() {
  try {
    var res = await fetch('me.php');
    if (!res.ok) {
      // ログインしていない場合はログイン画面へ強制送還
      location.href = 'login.html';
      return;
    }
    var data = await res.json();
    var user = data.user;

    // 各種情報を画面にセット
    document.getElementById('profileName').textContent = user.name + ' さんのマイページ';
    document.getElementById('userAvatar').textContent = user.name.charAt(0);
    document.getElementById('profileEmail').textContent = user.email;
    
    // 登録日のフォーマット整形
    if (user.created_at) {
      var date = new Date(user.created_at);
      document.getElementById('profileDate').textContent = date.getFullYear() + '年' + (date.getMonth() + 1) + '月' + date.getDate() + '日';
    }

    // プランバッジの装飾切り替え
    var planEl = document.getElementById('profilePlan');
    var planLabel = { free: 'Free会員', standard: 'Standard会員', premium: 'Premium会員👑' };
    planEl.textContent = planLabel[user.plan] || 'Free会員';
    if (user.plan === 'premium') {
      planEl.classList.add('premium');
    }

  } catch (err) {
    console.error('マイページのデータ取得に失敗しました', err);
  }
}

// ヘッダーの最低限の認証状態表示
async function setupHeader() {
  var authEl = document.getElementById('headerAuth');
  try {
    var res = await fetch('me.php');
    if (res.ok) {
      var data = await res.json();
      authEl.innerHTML = '<span style="font-size: 13px; font-weight: bold; color: #4a5568;"><i class="fas fa-user" style="margin-right: 6px;"></i>' + data.user.name + '</span>';
    }
  } catch (e) {}
}

setupHeader();
loadMyPage();
</script>
</body>
</html>
