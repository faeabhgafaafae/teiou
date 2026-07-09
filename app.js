// --- 定数・マスタデータ ---
var ALL_VENUES = [
  '桐生','戸田','江戸川','平和島','多摩川','浜名湖',
  '蒲郡','常滑','津','三国','琵琶湖','住之江',
  '尼崎','鳴門','丸亀','児島','宮島','徳山',
  '下関','若松','芦屋','福岡','唐津','大村'
];

var GRADE_CLASSES = { 'SG': 'grade-sg', 'G1': 'grade-g1', 'G2': 'grade-g2', 'G3': 'grade-g3', '一般': 'grade-ippan' };

var VENUE_GRADES = {
  '桐生': 'G3', '戸田': '一般', '江戸川': '一般', '平和島': 'G1', '多摩川': '一般',
  '浜名湖': '一般', '蒲郡': '一般', '常滑': '一般', '津': '一般', '三国': '一般',
  '琵琶湖': '一般', '住之江': 'G2', '尼崎': 'G3', '鳴門': '一般', '丸亀': '一般',
  '児島': '一般', '宮島': '一般', '徳山': '一般', '下関': 'SG', '若松': '一般',
  '芦屋': '一般', '福岡': '一般', '唐津': '一般', '大村': '一般'
};

// --- 状態管理用変数 ---
var apiDate = '';
var venueMap = {};
var favoriteVenues = []; // DBから取得したお気に入り会場名配列
var currentFilter = 'all';
var currentSearch = '';

// URLパラメータ or PAGE_DATE グローバルから表示日付を決定
(function() {
  var _n = new Date();
  var _today = _n.getFullYear() + '-' + String(_n.getMonth()+1).padStart(2,'0') + '-' + String(_n.getDate()).padStart(2,'0');
  var _raw = window.PAGE_DATE || '';
  window.PAGE_DATE = (_raw && _raw <= _today) ? _raw : _today;
})();

// --- ユーティリティ関数 ---
function formatDate(dateStr) {
  var d = new Date(dateStr + 'T00:00:00');
  var days = ['日','月','火','水','木','金','土'];
  return d.getFullYear() + '年' + (d.getMonth()+1) + '月' + d.getDate() + '日 (' + days[d.getDay()] + ')';
}

// --- 画面切り替えロジック ---
function switchPage(pageName) {
  var homeContent = document.getElementById('contentHome');
  var mypageContent = document.getElementById('contentMypage');
  var menuHome = document.getElementById('menuHome');
  var menuMypage = document.getElementById('menuMypage');

  if(menuHome) menuHome.classList.remove('active');
  if(menuMypage) menuMypage.classList.remove('active');

  if (pageName === 'mypage') {
    if(homeContent) homeContent.style.display = 'none';
    if(mypageContent) mypageContent.style.display = 'block';
    if(menuMypage) menuMypage.classList.add('active');
  } else {
    if(homeContent) homeContent.style.display = 'block';
    if(mypageContent) mypageContent.style.display = 'none';
    if(menuHome) menuHome.classList.add('active');
    
    // ホームに戻った時にお気に入り表示をリフレッシュ
    renderFavoriteVenuesTop();
    renderVenueGrid();
  }
}

// --- HTML生成・レンダリング関数 ---
function createVenueCard(venueName, venueData) {
  var isActive = !!venueData;
  var grade = VENUE_GRADES[venueName] || '一般';
  var gradeClass = GRADE_CLASSES[grade];
  var imgSrc = venueName + '.jpg';
  var displayName = venueDisplayName(venueName);

  var isFav = favoriteVenues.indexOf(venueName) !== -1;
  var starStyle = isFav ? 'color: #d97706; font-weight: 900;' : 'color: #cbd5e1; font-weight: 400;';

  var starButton = '<span class="fav-star-btn" style="font-size:18px; ' + starStyle + ' cursor:pointer; z-index:10; position:relative; transition: transform 0.1s;" ' +
                   'onclick="event.stopPropagation(); event.preventDefault(); toggleFavoriteVenue(\'' + venueName + '\'); return false;">★</span>';

  if (!isActive) {
    return '<div class="venue-card inactive">' +
        '<div style="display:flex; justify-content:space-between; align-items:center;">' +
          '<div><span class="venue-name">' + displayName + '</span><span class="grade-badge ' + gradeClass + '">' + grade + '</span></div>' +
          starButton +
        '</div>' +
        '<div class="card-main-info">' +
          '<div class="card-text-side">' +
            '<div style="color:#aaa; font-size:12px; margin-top:4px;">非開催</div>' +
          '</div>' +
          '<img src="' + imgSrc + '" alt="' + displayName + '" class="card-venue-img" style="opacity: 0.5;">' +
        '</div>' +
      '</div>';
  }

  var totalRaces = venueData.race_count;
  var href = 'races.html?venue=' + encodeURIComponent(venueName) + '&date=' + window.PAGE_DATE;

  return '<a href="' + href + '" class="venue-card">' +
      '<div style="display:flex; justify-content:space-between; align-items:center;">' +
        '<div><span class="venue-name">' + displayName + '</span><span class="grade-badge ' + gradeClass + '">' + grade + '</span></div>' +
        starButton +
      '</div>' +
      '<div class="card-main-info">' +
        '<div class="card-text-side">' +
          '<div class="status-indicator">開催中</div>' +
          '<div class="race-round">全' + totalRaces + 'R</div>' +
        '</div>' +
        '<img src="' + imgSrc + '" alt="' + displayName + '" class="card-venue-img">' +
      '</div>' +
    '</a>';
}

// ホーム画面最上部にお気に入り一覧をレンダリングする
function renderFavoriteVenuesTop() {
  var favSection = document.getElementById('favoriteVenueSection');
  var favGrid = document.getElementById('favoriteVenueGrid');
  if (!favSection || !favGrid) return;

  if (favoriteVenues.length === 0) {
    favSection.style.display = 'none';
    return;
  }

  favSection.style.display = 'block';
  favGrid.innerHTML = favoriteVenues.map(function(name) {
    return createVenueCard(name, venueMap[name]);
  }).join('');
}

function renderFeaturedBanner(venues) {
  var banner = document.getElementById('featuredBanner');
  var featuredVenues = venues.filter(function(v) { 
    var g = VENUE_GRADES[v.venue];
    return g === 'SG' || g === 'G1' || g === 'G2'; 
  }).slice(0, 3);

  if (featuredVenues.length === 0) {
    banner.innerHTML = '<div style="font-size:12px; color:#718096;">本日の注目レースはありません。</div>';
    return;
  }

  var html = '';
  featuredVenues.forEach(function(featured) {
    var grade = VENUE_GRADES[featured.venue];
    var gradeClass = GRADE_CLASSES[grade];
    var href = 'races.html?venue=' + encodeURIComponent(featured.venue) + '&date=' + window.PAGE_DATE;

    html += '<div class="featured-item">' +
        '<div class="featured-left">' +
          '<span class="venue-name">' + venueDisplayName(featured.venue) + '</span>' +
          '<span class="grade-badge ' + gradeClass + '">' + grade + '</span>' +
          '<span style="font-size:11px; color:#718096; font-weight:normal;">' + featured.race_count + 'R開催</span>' +
        '</div>' +
        '<a href="' + href + '" class="btn-predict" style="text-decoration:none;">予測を見る</a>' +
      '</div>';
  });
  
  banner.innerHTML = html;
}

function renderVenueGrid() {
  var grid = document.getElementById('venueGrid');
  var filteredVenues = ALL_VENUES.filter(function(name) {
    var grade = VENUE_GRADES[name] || '一般';
    var matchesGrade = (currentFilter === 'all' || grade === currentFilter);
    var matchesSearch = name.indexOf(currentSearch) !== -1;
    return matchesGrade && matchesSearch;
  });

  var activeCount = filteredVenues.filter(function(name) {
    return !!venueMap[name];
  }).length;

  document.getElementById('venueCount').textContent = activeCount + '場 開催中 / 該当' + filteredVenues.length + '場';

  if (filteredVenues.length === 0) {
    grid.innerHTML = '<div style="grid-column: 1/-1; text-align:center; color:#a0aec0; padding:40px;">該当するレース場が見つかりません。</div>';
  } else {
    grid.innerHTML = filteredVenues.map(function(name) { 
      return createVenueCard(name, venueMap[name]); 
    }).join('');
  }
}

// --- 非同期通信 (API・DBデータ処理) ---
async function loadVenues() {
  var grid = document.getElementById('venueGrid');
  try {
    var res = await fetch('https://2410049.moo.jp/venues.php?date=' + window.PAGE_DATE);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    var data = await res.json();

    apiDate = data.date;
    document.getElementById('headerDate').textContent = formatDate(data.date);

    venueMap = {};
    if (data.venues) {
      data.venues.forEach(function(v) { venueMap[v.venue] = v; });
    }

    var activeCount = data.venues ? data.venues.length : 0;
    var sb = document.getElementById('statsBadge');
    if (sb) sb.textContent = activeCount + '場 開催中 / 全' + ALL_VENUES.length + '場';

    if (data.venues && data.venues.length > 0) {
      renderFeaturedBanner(data.venues);
    }

    // お気に入りデータの読み込み
    await fetchFavoriteVenues();

    // レンダリング実行
    renderVenueGrid();
    renderFavoriteVenuesTop();
    setupFilterEvents();

  } catch (e) {
    grid.innerHTML = '<div class="error-msg">データの取得に失敗しました<br><small>' + e.message + '</small></div>';
  }
}

// DBからお気に入りリストを配列で引っ張ってくる関数
async function fetchFavoriteVenues() {
  try {
    var res = await fetch('get_favorites.php');
    if (res.ok) {
      favoriteVenues = await res.json();
    }
  } catch (e) {
    console.error('お気に入りデータの取得に失敗:', e);
  }
}

// ★ボタンを押したときにDBと通信して切り替える関数
async function toggleFavoriteVenue(venueName) {
  try {
    var res = await fetch('toggle_favorite.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ venue: venueName })
    });

    if (res.status === 401) {
      alert('お気に入り機能を利用するにはログインが必要です。');
      return;
    }

    var data = await res.json();
    if (data.success) {
      await fetchFavoriteVenues();
      renderVenueGrid();
      renderFavoriteVenuesTop();
    } else if (data.error === 'favorite_limit') {
      if (confirm('Freeプランはお気に入り登録が3件までです。プランをアップグレードしますか？')) {
        location.href = 'upgrade.html';
      }
    }
  } catch (e) {
    alert('通信エラーが発生しました。');
  }
}

// --- イベントリスナー設定 ---
function setupFilterEvents() {
  var tabs = document.querySelectorAll('#filterTabs .tab');
  tabs.forEach(function(tab) {
    tab.addEventListener('click', function() {
      tabs.forEach(function(t) { t.classList.remove('active'); });
      tab.classList.add('active');
      currentFilter = tab.getAttribute('data-grade');
      renderVenueGrid();
    });
  });

  var searchInput = document.getElementById('searchInput');
  if (searchInput) {
    searchInput.addEventListener('input', function(e) {
      currentSearch = e.target.value.trim();
      renderVenueGrid();
    });
  }
}

// --- 認証状態チェック & ヘッダーデータ流し込み ---
async function checkAuth() {
  var authEl = document.getElementById('headerAuth');
  try {
    var res = await fetch('me.php');
    if (!res.ok) {
      authEl.innerHTML = '<a class="auth-link" href="login.html">ログイン</a>' +
        '<a class="auth-link register" href="register.html">新規登録</a>';
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
          '<button class="dropdown-item" id="dropMypageBtn"><i class="fas fa-user-cog" style="margin-right: 8px; color: #718096;"></i>マイページ</button>' +
          '<button class="dropdown-item logout" id="logoutBtn" style="border-top: 1px solid #edf2f7; color: #dc2626;"><i class="fas fa-sign-out-alt" style="margin-right: 8px; color: #dc2626;"></i>ログアウト</button>' +
        '</div>' +
      '</div>';

    // ドロップダウンの「マイページ」をクリックしたら直接ページ移動
    document.getElementById('dropMypageBtn').addEventListener('click', function() {
      location.href = 'mypage.php';
    });

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
      location.reload();
    });

    var promoBtn = document.getElementById('sidebarPromoBtn');
    if (promoBtn && user.plan === 'premium') {
      promoBtn.textContent = '現在プレミアム会員です';
      promoBtn.removeAttribute('href');
      promoBtn.style.background = '#e2e8f0';
      promoBtn.style.color = '#a0aec0';
      promoBtn.style.border = '1px solid #cbd5e1';
      promoBtn.style.cursor = 'default';
      promoBtn.style.pointerEvents = 'none';
    }
  } catch(err) {
    authEl.innerHTML = '<a class="auth-link" href="login.html">ログイン</a>' +
      '<a class="auth-link register" href="register.html">新規登録</a>';
  }
}

// --- ナビゲーションのクリックイベント紐付け ---
function setupNavigation() {
  var menuHome = document.getElementById('menuHome');
  var headerLogo = document.getElementById('headerLogo');

  if(menuHome) menuHome.addEventListener('click', function(e) { e.preventDefault(); switchPage('home'); });
  if(headerLogo) headerLogo.addEventListener('click', function() { switchPage('home'); });
}

// --- 準備中メニュー用 ---
function showComingSoon(e) {
  if (e) e.preventDefault();
  alert('準備中です。しばらくお待ちください。');
}

// --- 初期実行 ---
loadVenues();
checkAuth();
setupNavigation();
