// common-header.js
(function() {
  // 1. 共通のスタイル（CSS）を注入
  const css = `
    /* --- Gemini風 モダン共通ヘッダー（左メニュー版） --- */
    header.global-header {
      background: rgba(255, 255, 255, 0.85);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border-bottom: 1px solid rgba(0, 0, 0, 0.08);
      padding: 14px 20px;
      display: flex;
      align-items: center;
      justify-content: flex-start; /* 左詰めに変更 */
      gap: 8px;
      position: sticky;
      top: 0;
      z-index: 1010;
    }
    .gh-left { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0; }
    
    .gh-back-btn {
      color: #1a73e8;
      text-decoration: none;
      font-size: 20px;
      width: 36px;
      height: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      transition: background 0.2s;
      flex-shrink: 0;
    }
    .gh-back-btn:hover { background: rgba(26, 115, 232, 0.08); }
    
    .gh-info { min-width: 0; }
    .gh-info h1 { font-size: 18px; font-weight: 600; color: #1f1f1f; letter-spacing: -0.3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .gh-meta { display: flex; align-items: center; gap: 8px; margin-top: 2px; }
    .gh-meta .date { font-size: 12px; color: #5f6368; }
    
    /* グレードバッジ */
    .gh-badge { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 6px; }
    .gb-sg { background: #fef3c7; color: #d97706; }
    .gb-g1 { background: #fee2e2; color: #dc2626; }
    .gb-g2 { background: #e0f2fe; color: #0284c7; }
    .gb-g3 { background: #dcfce7; color: #16a34a; }
    .gb-ippan { background: #f1f3f4; color: #5f6368; }

    /* ハンバーガーボタン（左側に配置） */
    .gh-menu-trigger {
      background: none;
      border: none;
      width: 40px;
      height: 40px;
      cursor: pointer;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      gap: 4px;
      border-radius: 50%;
      transition: background 0.2s;
      flex-shrink: 0;
    }
    .gh-menu-trigger:hover { background: rgba(0, 0, 0, 0.04); }
    .gh-menu-trigger span {
      display: block;
      width: 18px;
      height: 2px;
      background: #5f6368;
      border-radius: 1px;
      transition: transform 0.2s, opacity 0.2s;
    }
    /* 左側での展開アニメーション */
    .gh-menu-trigger.open span:nth-child(1) { transform: translateY(6px) rotate(45deg); }
    .gh-menu-trigger.open span:nth-child(2) { opacity: 0; }
    .gh-menu-trigger.open span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }

    /* 🌟 ドロワーメニュー（左側から引き出すように修正） */
    .gh-drawer {
      position: fixed;
      top: 0;
      left: -280px; /* 左側に隠す */
      width: 280px;
      height: 100vh;
      background: #ffffff;
      z-index: 1050;
      box-shadow: 8px 0 24px rgba(0,0,0,0.06);
      padding: 24px 12px;
      transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .gh-drawer.open { left: 0; } /* 左からスッと出る */
    
    .gh-drawer-logo {
      font-size: 20px;
      font-weight: 700;
      color: #1f1f1f;
      padding: 12px 16px;
      margin-bottom: 12px;
      border-bottom: 1px solid #f1f3f4;
    }
    .gh-drawer a {
      display: flex;
      align-items: center;
      padding: 12px 16px;
      color: #3c4043;
      text-decoration: none;
      font-size: 14px;
      font-weight: 500;
      border-radius: 8px;
      transition: background 0.2s, color 0.2s;
    }
    .gh-drawer a:hover { background: #f8f9fa; color: #1a73e8; }
    .gh-drawer a.active { background: #e8f0fe; color: #1a73e8; font-weight: 600; }

    /* オーバーレイ */
    .gh-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(0, 0, 0, 0.3);
      backdrop-filter: blur(4px);
      z-index: 1040;
      opacity: 0;
      visibility: hidden;
      transition: opacity 0.2s, visibility 0.2s;
    }
    .gh-overlay.open { opacity: 1; visibility: visible; }
  `;

  const styleEl = document.createElement('style');
  styleEl.textContent = css;
  document.head.appendChild(styleEl);

  // 2. DOMの構築
  window.addEventListener('DOMContentLoaded', () => {
    const scriptTag = document.querySelector('script[src*="common-header.js"]');
    const pageType = scriptTag ? scriptTag.getAttribute('data-page-type') : '';
    const hasBack = scriptTag ? scriptTag.getAttribute('data-back') !== 'false' : true;

    const headerContainer = document.getElementById('globalHeaderContainer') || document.body;

    const header = document.createElement('header');
    header.className = 'global-header';

    // 🌟 1番最初（左端）にハンバーガーボタンを配置
    const trigger = document.createElement('button');
    trigger.className = 'gh-menu-trigger';
    trigger.innerHTML = '<span></span><span></span><span></span>';
    header.appendChild(trigger);

    // タイトルと戻るボタンをまとめるDiv
    const leftDiv = document.createElement('div');
    leftDiv.className = 'gh-left';
    
    if (hasBack) {
      leftDiv.innerHTML = `<a class="gh-back-btn" href="index.php">&larr;</a>`;
    }

    leftDiv.innerHTML += `
      <div class="gh-info">
        <h1 id="pageTitle">読み込み中...</h1>
        <div class="gh-meta">
          <span class="date" id="pageDate"></span>
          <span class="gh-badge" id="pageBadge" style="display:none;"></span>
        </div>
      </div>
    `;
    header.appendChild(leftDiv);

    // ドロワーとオーバーレイの作成
    const drawer = document.createElement('nav');
    drawer.className = 'gh-drawer';
    drawer.innerHTML = `
      <div class="gh-drawer-logo">艇王 Menu</div>
      <a href="index.php" class="${pageType === 'home' ? 'active' : ''}">🏠 ホーム（場選択）</a>
      <a href="mypage.html" class="${pageType === 'mypage' ? 'active' : ''}">👤 マイページ</a>
      <a href="history.html" class="${pageType === 'history' ? 'active' : ''}">📊 投票履歴・収支分析</a>
      <a href="settings.html" class="${pageType === 'settings' ? 'active' : ''}">⚙️ 設定</a>
    `;

    const overlay = document.createElement('div');
    overlay.className = 'gh-overlay';

    // 画面への追加
    if (headerContainer === document.body) {
      document.body.insertBefore(header, document.body.firstChild);
    } else {
      headerContainer.appendChild(header);
    }
    document.body.appendChild(drawer);
    document.body.appendChild(overlay);

    // 開閉イベント
    const toggleMenu = () => {
      trigger.classList.toggle('open');
      drawer.classList.toggle('toggle'); // 一応古い対策
      drawer.classList.toggle('open');
      overlay.classList.toggle('open');
    };

    trigger.addEventListener('click', toggleMenu);
    overlay.addEventListener('click', toggleMenu);
  });
})();
