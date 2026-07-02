(function() {
  var VENUES = ['桐生','戸田','江戸川','平和島','多摩川','浜名湖','蒲郡','常滑','津','三国','琵琶湖','住之江','尼崎','鳴門','高松','丸亀','児島','宮島','徳山','下関','若松','芦屋','福岡','唐津','大村'];
  var _now = new Date();
  var today = _now.getFullYear() + '-' + String(_now.getMonth()+1).padStart(2,'0') + '-' + String(_now.getDate()).padStart(2,'0');
  var date = (window.PAGE_DATE && window.PAGE_DATE <= today) ? window.PAGE_DATE : today;
  var isToday = (date === today);
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
        '<span class="urgent-venue">' + race.venue + '</span>' +
        '<span class="urgent-no">' + race.race_no + 'R</span>' +
        '<span class="urgent-time ' + countdown.cls + '">' + countdown.text + '</span>' +
      '</div>' +
      '<div class="urgent-players" id="urg-p-' + race.venue + '-' + race.race_no + '">' +
        '<div style="font-size:10px; color:#bbb;">選手読込中...</div>' +
      '</div>' +
      '<div class="urgent-btn-group">' +
        '<a class="urgent-btn" href="racelist.php?venue=' + encodeURIComponent(race.venue) + '&date=' + date + '&race_no=' + race.race_no + '">出走表</a>' +
        '<a class="urgent-btn main-btn" href="ai-predict.php?venue=' + encodeURIComponent(race.venue) + '&date=' + date + '&race_no=' + race.race_no + '">AI予想</a>' +
      '</div>';

    return card;
  }

  async function loadPlayers(venue, raceNo) {
    try {
      var res = await fetch(API_HOST + '/predict.php?date=' + date + '&venue=' + encodeURIComponent(venue) + '&race_no=' + raceNo);
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
        var res = await fetch(API_HOST + '/races.php?date=' + date + '&venue=' + encodeURIComponent(v));
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

  window.addEventListener('DOMContentLoaded', function() {
    if (!isToday) return;
    init();
    setInterval(init, 60000);
  });
})();
