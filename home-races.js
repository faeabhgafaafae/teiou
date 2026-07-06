(function() {
  // 会場一覧はapp.jsのALL_VENUESを共有する(index.phpでapp.js→home-races.jsの順に読み込まれるため参照可能)
  var VENUES = window.ALL_VENUES;
  var _now = new Date();
  var today = _now.getFullYear() + '-' + String(_now.getMonth()+1).padStart(2,'0') + '-' + String(_now.getDate()).padStart(2,'0');
  var currentDate = (window.PAGE_DATE && window.PAGE_DATE <= today) ? window.PAGE_DATE : today;
  var isToday = (currentDate === today);
  var API_HOST = 'https://2410049.moo.jp';

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
        '<a class="urgent-btn main-btn" href="ai-predict.php?venue=' + encodeURIComponent(race.venue) + '&date=' + currentDate + '&race_no=' + race.race_no + '">AI予想</a>' +
      '</div>';

    return card;
  }

  async function loadPlayers(venue, raceNo) {
    try {
      var res = await fetch(API_HOST + '/predict.php?date=' + currentDate + '&venue=' + encodeURIComponent(venue) + '&race_no=' + raceNo);
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
        var res = await fetch(API_HOST + '/races.php?date=' + currentDate + '&venue=' + encodeURIComponent(v));
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
  var STRAT_STYLE = {
    '的中特化': 'background:#dbeafe; color:#1d4ed8;',
    'バランス':  'background:#d1fae5; color:#065f46;',
    '一撃重視': 'background:#fee2e2; color:#991b1b;',
    '絞り込み': 'background:#ede9fe; color:#5b21b6;'
  };

  function renderHitCard(hit) {
    var card = document.createElement('div');
    card.style.cssText = 'background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; margin-bottom:10px;';

    // 会場・R番号 + 戦略バッジ
    var header = document.createElement('div');
    header.style.cssText = 'display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;';

    var venueEl = document.createElement('span');
    venueEl.style.cssText = 'font-size:13px; font-weight:bold; color:#2d3748;';
    venueEl.textContent = venueDisplayName(hit.venue) + ' ' + hit.race_no + 'R';

    var stratEl = document.createElement('span');
    var stratStyle = STRAT_STYLE[hit.strategy_type] || 'background:#e2e8f0; color:#4a5568;';
    stratEl.style.cssText = 'font-size:10px; font-weight:bold; padding:2px 7px; border-radius:10px; ' + stratStyle;
    stratEl.textContent = hit.strategy_type;

    header.appendChild(venueEl);
    header.appendChild(stratEl);
    card.appendChild(header);

    // 的中買い目（艇番カラーバッジ）
    if (hit.combination) {
      var comboRow = document.createElement('div');
      comboRow.style.cssText = 'display:flex; align-items:center; gap:3px; margin-bottom:8px;';

      var parts = hit.combination.split('-');
      for (var i = 0; i < parts.length; i++) {
        if (i > 0) {
          var sep = document.createElement('span');
          sep.style.cssText = 'color:#bbb; font-size:11px; font-weight:bold;';
          sep.textContent = '-';
          comboRow.appendChild(sep);
        }
        var ln = parseInt(parts[i], 10);
        var badge = document.createElement('span');
        badge.style.cssText = 'display:inline-flex; align-items:center; justify-content:center;' +
          ' width:22px; height:22px; border-radius:4px; font-size:12px; font-weight:bold;' +
          ' background:' + (LANE_BG[ln] || '#ccc') + '; color:' + (LANE_FG[ln] || '#333') + ';' +
          ' border:1px solid rgba(0,0,0,0.12);';
        badge.textContent = parts[i];
        comboRow.appendChild(badge);
      }
      card.appendChild(comboRow);
    }

    // 払戻金額（大） + 日付
    var footer = document.createElement('div');
    footer.style.cssText = 'display:flex; justify-content:space-between; align-items:flex-end;';

    var payoutEl = document.createElement('span');
    payoutEl.style.cssText = 'font-size:18px; font-weight:bold; color:#e91e8c;';
    payoutEl.textContent = Number(hit.payout).toLocaleString() + '円';

    var dateEl = document.createElement('span');
    dateEl.style.cssText = 'font-size:11px; color:#a0aec0;';
    dateEl.textContent = hit.date || '';

    footer.appendChild(payoutEl);
    footer.appendChild(dateEl);
    card.appendChild(footer);

    return card;
  }

  async function loadHits() {
    var container = document.getElementById('hitsList');
    if (!container) return;
    try {
      var res = await fetch(API_HOST + '/get_hits.php');
      if (!res.ok) throw new Error('fetch error');
      var data = await res.json();
      var hits = (data.hits || []).slice(0, 5);
      if (hits.length === 0) {
        container.innerHTML = '<div style="text-align:center; color:#999; font-size:12px; padding:20px 0;">まだ的中データがありません</div>';
        return;
      }
      container.innerHTML = '';
      hits.forEach(function(hit) { container.appendChild(renderHitCard(hit)); });
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
