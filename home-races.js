(function() {
  // 会場一覧はapp.jsのALL_VENUESを共有する(index.phpでapp.js→home-races.jsの順に読み込まれるため参照可能)
  var VENUES = window.ALL_VENUES;
  var _now = new Date();
  var today = _now.getFullYear() + '-' + String(_now.getMonth()+1).padStart(2,'0') + '-' + String(_now.getDate()).padStart(2,'0');
  var currentDate = (window.PAGE_DATE && window.PAGE_DATE <= today) ? window.PAGE_DATE : today;
  var isToday = (currentDate === today);
  var API_HOST = '';  // 同一オリジン。ルート相対パスで叩く

  function getDiffMs(scheduledTime) {
    if (!scheduledTime) return 999999999;
    var now = new Date();
    var p = scheduledTime.split(':').map(Number);
    var target = new Date();
    target.setHours(p[0], p[1], 0, 0);
    return target - now;
  }

  function getCountdownInfo(diffMs) {
    if (diffMs <= 0) return { text: '締切済', cls: 'closed' };
    var mins = Math.floor(diffMs / 60000);
    if (mins <= 10) return { text: 'あと ' + mins + ' 分', cls: 'time-red' };
    if (mins <= 30) return { text: 'あと ' + mins + ' 分', cls: 'time-yellow' };
    return { text: 'あと ' + mins + ' 分', cls: 'time-green' };
  }

  function renderRaceCard(race) {
    var diffMs = getDiffMs(race.scheduled_time);
    var countdown = getCountdownInfo(diffMs);

    var card = document.createElement('div');
    card.className = 'urgent-card-box';

    card.innerHTML =
      '<div class="urgent-header">' +
        '<span class="urgent-venue">' + venueDisplayName(race.venue) + '</span>' +
        '<span class="urgent-no">' + race.race_no + 'R</span>' +
        '<span class="urgent-time ' + countdown.cls + '">' + countdown.text + '</span>' +
      '</div>' +
      '<div class="urgent-players" id="urg-p-' + race.venue + '-' + race.race_no + '">' +
        '<div style="font-size:10px; color:#bbb;">選手読込中...</div>' +
      '</div>' +
      '<div class="urgent-btn-group">' +
        '<a class="urgent-btn" href="racelist.php?venue=' + encodeURIComponent(race.venue) + '&date=' + currentDate + '&race_no=' + race.race_no + '">出走表</a>' +
        '<a class="urgent-btn main-btn" href="ai-predict.php?venue=' + encodeURIComponent(race.venue) + '&date=' + currentDate + '&race_no=' + race.race_no + '">予想</a>' +
      '</div>';

    return card;
  }

  async function loadPlayers(venue, raceNo) {
    try {
      var res = await fetch(API_HOST + '/api_predict.php?date=' + currentDate + '&venue=' + encodeURIComponent(venue) + '&race_no=' + raceNo);
      if (!res.ok) return;
      var data = await res.json();
      if (!data.predictions) return;

      var sorted = data.predictions.sort(function(a, b) {
        return a.lane - b.lane;
      });

      var pBox = document.getElementById('urg-p-' + venue + '-' + raceNo);
      if (pBox) {
        pBox.innerHTML = '';
        sorted.forEach(function(p) {
          var name = p.name.replace(/[\s ]+/g, '').substring(0,3);
          pBox.innerHTML += '<span class="urgent-player-dot"><span class="w-dot wd-' + p.lane + '"></span>' + name + '</span>';
        });
      }
    } catch(e){}
  }

  async function init() {
    var container = document.getElementById('urgentRaceList');
    if (!container) return;
    if (!isToday) return;

    var allRaces = [];

    await Promise.all(VENUES.map(async function(v) {
      try {
        var res = await fetch(API_HOST + '/api_races.php?date=' + currentDate + '&venue=' + encodeURIComponent(v));
        if (res.ok) {
          var data = await res.json();
          if (data.races) {
            data.races.forEach(function(r) {
              r.venue = v;
              allRaces.push(r);
            });
          }
        }
      } catch(e){}
    }));

    var active = allRaces.filter(function(r) {
      return getDiffMs(r.scheduled_time) > 0;
    }).sort(function(a, b) {
      return getDiffMs(a.scheduled_time) - getDiffMs(b.scheduled_time);
    });

    var top3 = active.slice(0, 3);

    if (top3.length === 0) {
      container.innerHTML = '<div style="text-align:center; color:#999; font-size:12px; padding:20px 0;">投票可能なレースはありません</div>';
      return;
    }

    container.innerHTML = '';
    top3.forEach(function(race) {
      container.appendChild(renderRaceCard(race));
      loadPlayers(race.venue, race.race_no);
    });
  }

  // ─── 的中速報 ─────────────────────────────────────────
  var LANE_BG   = ['', '#f0f0f0', '#222',    '#e53e3e', '#2563eb', '#eab308', '#16a34a'];
  var LANE_FG   = ['', '#555',    '#fff',    '#fff',    '#fff',    '#333',    '#fff'   ];
  // 戦略の色は予想画面(ai-predict.php / strategy.php の STRAT_DEFS)に揃える
  var STRAT_COLOR = {
    '的中特化': { fg: '#2563eb', bg: '#dbeafe' },
    'バランス':  { fg: '#16a34a', bg: '#d1fae5' },
    '一撃重視': { fg: '#dc2626', bg: '#fee2e2' },
    '絞り込み': { fg: '#7c3aed', bg: '#ede9fe' }
  };
  // 戦略アイコン(インラインSVG、24x24、currentColor で描画)
  //   的中特化=的 / バランス=天秤 / 一撃重視=稲妻 / 絞り込み=漏斗
  var STRAT_ICON_SVG = {
    '的中特化': '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/>' +
                '<circle cx="12" cy="12" r="5" fill="none" stroke="currentColor" stroke-width="2"/>' +
                '<circle cx="12" cy="12" r="1.8" fill="currentColor"/>',
    'バランス':  '<path d="M12 4v16M7 20h10M4 7h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
                '<path d="M6 7l-3 6h6zM18 7l-3 6h6z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>' +
                '<path d="M3 13a3 2 0 0 0 6 0zM15 13a3 2 0 0 0 6 0z" fill="currentColor"/>',
    '一撃重視': '<path d="M13 2L4 14h7l-1 8 10-13h-7z" fill="currentColor" stroke="currentColor" stroke-width="1" stroke-linejoin="round"/>',
    '絞り込み': '<path d="M3 4h18l-7 8.5V19l-4 2v-8.5z" fill="currentColor" stroke="currentColor" stroke-width="1" stroke-linejoin="round"/>'
  };

  function makeStrategyIcon(type) {
    var c = STRAT_COLOR[type] || { fg: '#4a5568', bg: '#e2e8f0' };
    var chip = document.createElement('span');
    chip.setAttribute('title', type);
    chip.setAttribute('aria-label', type);
    chip.setAttribute('role', 'img');
    chip.style.cssText = 'display:inline-flex; align-items:center; justify-content:center; flex:0 0 auto;' +
      ' width:22px; height:22px; border-radius:50%; background:' + c.bg + '; color:' + c.fg + ';';
    var inner = STRAT_ICON_SVG[type];
    if (inner) {
      chip.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + inner + '</svg>';
    } else {
      chip.style.fontSize = '10px';
      chip.style.fontWeight = 'bold';
      chip.textContent = type.charAt(0);
    }
    return chip;
  }

  // 1枚 = 1レース(同じレースで複数戦略が的中した場合は get_hits.php がまとめて strategies に並べる)
  function renderHitCard(hit) {
    var card = document.createElement('div');
    card.style.cssText = 'position:relative; background:#fff; border:1px solid #e2e8f0; border-radius:8px;' +
      ' padding:10px 12px; margin-bottom:10px; box-sizing:border-box; min-width:0;';

    // 右上の「的中」バッジ
    var badge = document.createElement('span');
    badge.style.cssText = 'position:absolute; top:8px; right:8px; background:#e53e3e; color:#fff;' +
      ' font-size:10px; font-weight:bold; line-height:1; padding:4px 7px; border-radius:10px;';
    badge.textContent = '的中';
    card.appendChild(badge);

    // 1行目: 会場名+レース番号 / 的中した戦略のアイコン
    var header = document.createElement('div');
    header.style.cssText = 'display:flex; flex-wrap:wrap; align-items:center; gap:4px 8px;' +
      ' margin-bottom:8px; padding-right:44px; min-width:0;';
    var venueEl = document.createElement('span');
    venueEl.style.cssText = 'font-size:13px; font-weight:bold; color:#2d3748; white-space:nowrap;';
    venueEl.textContent = venueDisplayName(hit.venue) + ' ' + hit.race_no + 'R';
    header.appendChild(venueEl);
    var icons = document.createElement('span');
    icons.style.cssText = 'display:inline-flex; flex-wrap:wrap; align-items:center; gap:4px;';
    var strategies = hit.strategies || (hit.strategy_type ? [hit.strategy_type] : []);
    strategies.forEach(function(t) { icons.appendChild(makeStrategyIcon(t)); });
    icons.setAttribute('aria-label', '的中した戦略: ' + strategies.join('、'));
    header.appendChild(icons);
    card.appendChild(header);

    // 2行目: 組み合わせ(艇番の色付きボックスを線でつなぐ。1白・2黒・3赤・4青・5黄・6緑)
    if (hit.combination) {
      var comboRow = document.createElement('div');
      comboRow.style.cssText = 'display:flex; align-items:center; margin-bottom:8px;';
      comboRow.setAttribute('aria-label', '3連単 ' + hit.combination);
      var parts = hit.combination.split('-');
      for (var i = 0; i < parts.length; i++) {
        if (i > 0) {
          var line = document.createElement('span');
          line.style.cssText = 'display:inline-block; width:12px; height:2px; background:#cbd5e0; flex:0 0 auto;';
          comboRow.appendChild(line);
        }
        var ln = parseInt(parts[i], 10);
        var box = document.createElement('span');
        box.style.cssText = 'display:inline-flex; align-items:center; justify-content:center; flex:0 0 auto;' +
          ' width:24px; height:24px; border-radius:4px; font-size:13px; font-weight:bold;' +
          ' background:' + (LANE_BG[ln] || '#ccc') + '; color:' + (LANE_FG[ln] || '#333') + ';' +
          ' border:1px solid rgba(0,0,0,0.15);';
        box.textContent = parts[i];
        comboRow.appendChild(box);
      }
      card.appendChild(comboRow);
    }

    // 3行目: オッズ(3連単の確定払戻÷100)と人気
    var oddsEl = document.createElement('div');
    oddsEl.style.cssText = 'font-size:15px; font-weight:bold; color:#e91e8c;';
    var oddsText = (hit.odds !== null && hit.odds !== undefined)
      ? Number(hit.odds).toFixed(1) + '倍'
      : 'オッズ不明';
    if (hit.popularity) oddsText += '(' + hit.popularity + '番人気)';
    oddsEl.textContent = oddsText;
    card.appendChild(oddsEl);

    return card;
  }

  var _hitsAll   = [];   // 当日分の全的中（ロード済み）
  var _hitsShown = 0;    // 現在表示中の件数
  var HITS_PER_PAGE = 20;

  function renderMoreButton(container, total) {
    var existing = document.getElementById('hitsMoreBtn');
    if (existing) existing.remove();
    if (_hitsShown >= _hitsAll.length) return;

    var remaining = _hitsAll.length - _hitsShown;
    var btn = document.createElement('button');
    btn.id = 'hitsMoreBtn';
    btn.style.cssText = 'display:block; width:100%; margin-top:8px; padding:8px; background:#f7fafc;' +
      ' border:1px solid #e2e8f0; border-radius:8px; font-size:12px; color:#4a5568; cursor:pointer;';
    btn.textContent = 'もっと見る（あと ' + remaining + '件）';
    btn.onclick = function() {
      var next = _hitsAll.slice(_hitsShown, _hitsShown + HITS_PER_PAGE);
      next.forEach(function(hit) { container.insertBefore(renderHitCard(hit), btn); });
      _hitsShown += next.length;
      renderMoreButton(container, total);
    };
    container.appendChild(btn);
  }

  async function loadHits() {
    var container = document.getElementById('hitsList');
    if (!container) return;
    try {
      var url = API_HOST + '/get_hits.php?date=' + encodeURIComponent(currentDate) + '&limit=500';
      var res = await fetch(url);
      if (!res.ok) throw new Error('fetch error');
      var data = await res.json();
      _hitsAll   = data.hits || [];
      _hitsShown = 0;

      if (_hitsAll.length === 0) {
        var _emptyMsg = isToday ? '本日の的中はまだありません' : 'この日の的中はまだありません';
        container.innerHTML = '<div style="text-align:center; color:#999; font-size:12px; padding:20px 0;">' + _emptyMsg + '</div>';
        return;
      }

      container.innerHTML = '';
      var first = _hitsAll.slice(0, HITS_PER_PAGE);
      first.forEach(function(hit) { container.appendChild(renderHitCard(hit)); });
      _hitsShown = first.length;
      renderMoreButton(container, data.total);
    } catch(e) {
      container.innerHTML = '<div style="text-align:center; color:#999; font-size:12px; padding:20px 0;">データを取得できませんでした</div>';
    }
  }

  // 過去日付選択時、会場グリッド内のリンクの date= を currentDate で上書きする
  function patchDateLinks(container) {
    if (!container) return;
    var links = container.querySelectorAll('a[href]');
    Array.prototype.forEach.call(links, function(a) {
      var href = a.getAttribute('href');
      if (!href || href.charAt(0) === '#') return;
      if (href.indexOf('date=') !== -1) {
        // app.js が既に付けた date= を currentDate で置換
        a.setAttribute('href', href.replace(/([?&])date=[^&#]*/g, '$1date=' + currentDate));
      } else {
        var sep = href.indexOf('?') !== -1 ? '&' : '?';
        a.setAttribute('href', href + sep + 'date=' + currentDate);
      }
    });
  }

  function watchVenueLinks() {
    var ids = ['venueGrid', 'favoriteVenueGrid', 'featuredBanner'];
    ids.forEach(function(id) {
      var el = document.getElementById(id);
      if (!el) return;
      patchDateLinks(el);
      var obs = new MutationObserver(function() { patchDateLinks(el); });
      obs.observe(el, { childList: true, subtree: true });
    });
  }

  window.addEventListener('DOMContentLoaded', function() {
    loadHits();
    if (!isToday) {
      watchVenueLinks();
      return;
    }
    init();
    setInterval(init, 60000);
  });
})();
