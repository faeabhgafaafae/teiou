<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>艇王 - データ分析</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, 'Hiragino Sans', 'Meiryo', sans-serif; background: #f0f2f5; color: #333; min-height: 100vh; }
header { background: #fff; border-bottom: 3px solid #0055a4; padding: 12px 20px; display: flex; align-items: center; gap: 14px; }
.back-btn { color: #0055a4; text-decoration: none; font-size: 20px; line-height: 1; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: background 0.15s; }
.back-btn:hover { background: #e8f0fd; }
header h1 { font-size: 18px; font-weight: 700; color: #222; }
.container { max-width: 960px; margin: 0 auto; padding: 20px 16px; }

.tabs { display: flex; gap: 6px; overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 16px; }
.tab-btn { flex-shrink: 0; padding: 9px 16px; border-radius: 8px; background: #fff; border: 1px solid #e0e3e8; font-size: 13px; font-weight: 700; color: #555; cursor: pointer; white-space: nowrap; }
.tab-btn.active { background: #0055a4; color: #fff; border-color: #0055a4; }

.panel { display: none; }
.panel.active { display: block; }

.card { background: #fff; border: 1px solid #e0e3e8; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
.card h2 { font-size: 14px; font-weight: 700; color: #222; margin-bottom: 12px; }

.controls { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 14px; }
.controls select, .controls input { padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; color: #333; }
.controls button { padding: 7px 16px; border-radius: 6px; background: #0055a4; color: #fff; border: none; font-size: 13px; font-weight: 700; cursor: pointer; }
.controls button:hover { background: #003d7a; }
.controls label { font-size: 12px; color: #666; font-weight: 600; }
.seg { display: flex; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden; }
.seg button { border-radius: 0; background: #fff; color: #555; border: none; padding: 7px 14px; }
.seg button.active { background: #0055a4; color: #fff; }

.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 10px; border: 1px solid #e0e3e8; }
table.data-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 600px; }
table.data-table th { background: #f7f8fa; font-size: 10px; font-weight: 700; color: #999; padding: 8px 6px; border-bottom: 2px solid #e0e3e8; white-space: nowrap; text-align: center; cursor: pointer; }
table.data-table td { padding: 8px 6px; border-bottom: 1px solid #f0f0f0; text-align: center; white-space: nowrap; }
table.data-table tr:last-child td { border-bottom: none; }
.td-player { text-align: left; padding-left: 10px; }
.player-name { font-weight: 700; color: #222; }
.eg { font-size: 10px; font-weight: 700; padding: 1px 5px; border-radius: 3px; margin-left: 4px; }
.eg-A1 { background: #fff3cd; color: #b8860b; }
.eg-A2 { background: #dbeafe; color: #2563eb; }
.eg-B1 { background: #f3f4f6; color: #666; }
.eg-B2 { background: #f3f4f6; color: #aaa; }

.note { font-size: 11px; color: #a0724b; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; padding: 8px 12px; margin-bottom: 12px; line-height: 1.6; }
.loading { text-align: center; padding: 30px; color: #999; font-size: 13px; }
.error-msg { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; padding: 14px; color: #dc2626; font-size: 13px; }

.venue-btn { flex-shrink: 0; padding: 6px 12px; border-radius: 20px; background: #f1f5f9; border: 1px solid #cbd5e1; font-size: 12px; font-weight: 700; color: #0055a4; cursor: pointer; }
.venue-btn.active { background: #0055a4; color: #fff; border-color: #0055a4; }
.venue-picker { display: flex; gap: 6px; overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 14px; padding-bottom: 4px; }

.kimarite-row { display: flex; gap: 10px; margin-bottom: 6px; }
.kimarite-item { flex: 1; background: #f7f8fa; border-radius: 8px; padding: 10px; text-align: center; }
.kimarite-label { font-size: 11px; color: #888; margin-bottom: 4px; }
.kimarite-value { font-size: 18px; font-weight: 800; color: #0055a4; }

.race-search-list { display: flex; flex-wrap: wrap; gap: 8px; }
.race-search-item { padding: 8px 14px; border-radius: 8px; background: #f1f5f9; border: 1px solid #cbd5e1; font-size: 13px; font-weight: 700; color: #0055a4; text-decoration: none; }
.race-search-item:hover { background: #0055a4; color: #fff; }
.race-search-item.confirmed { background: #dcfce7; border-color: #16a34a; color: #16a34a; }
.race-search-item.confirmed:hover { background: #16a34a; color: #fff; }

@media (max-width: 600px) {
  .controls { flex-direction: column; align-items: stretch; }
}
</style>
</head>
<body>

<header>
  <a class="back-btn" href="index.php">&larr;</a>
  <h1>データ分析</h1>
</header>

<div class="container">

  <div class="tabs">
    <button class="tab-btn active" data-tab="players">選手ランキング</button>
    <button class="tab-btn" data-tab="venue">会場別データ</button>
    <button class="tab-btn" data-tab="search">過去レース検索</button>
    <button class="tab-btn" data-tab="payouts">払戻金傾向</button>
  </div>

  <!-- 選手ランキング -->
  <div class="panel active" id="panel-players">
    <div class="card">
      <h2>選手ランキング</h2>
      <div class="controls">
        <div class="seg" id="scopeSeg">
          <button class="active" data-scope="national">全国</button>
          <button data-scope="local">当地</button>
        </div>
        <select id="playersVenueSelect" style="display:none;"></select>
        <label>最低出走数</label>
        <input type="number" id="minRacesInput" value="20" min="1" style="width:70px;">
        <button id="playersSearchBtn">表示</button>
      </div>
      <div id="playersResult"><div class="loading">条件を選択して「表示」を押してください</div></div>
    </div>
  </div>

  <!-- 会場別データ -->
  <div class="panel" id="panel-venue">
    <div class="card">
      <h2>会場別データ</h2>
      <div class="venue-picker" id="venuePicker"></div>
      <div id="venueResult"><div class="loading">会場を選択してください</div></div>
    </div>
  </div>

  <!-- 過去レース検索 -->
  <div class="panel" id="panel-search">
    <div class="card">
      <h2>過去レース検索</h2>
      <div class="controls">
        <select id="searchVenueSelect"></select>
        <input type="date" id="searchDateInput">
        <button id="searchBtn">検索</button>
      </div>
      <div id="searchResult"><div class="loading">会場・日付を選択して検索してください</div></div>
    </div>
  </div>

  <!-- 払戻金傾向 -->
  <div class="panel" id="panel-payouts">
    <div class="card">
      <h2>賭式別 配当傾向</h2>
      <div id="payoutsByType"><div class="loading">読み込み中...</div></div>
    </div>
    <div class="card">
      <h2>3連単 人気別決着分布（荒れ具合の目安）</h2>
      <div id="payoutsPopularity"></div>
    </div>
  </div>

</div>

<script>
var ALL_VENUES = [
  '桐生','戸田','江戸川','平和島','多摩川','浜名湖',
  '蒲郡','常滑','津','三国','琵琶湖','住之江',
  '尼崎','鳴門','高松','丸亀','児島','宮島','徳山',
  '下関','若松','芦屋','福岡','唐津','大村'
];
var API_HOST = 'https://' + '2410049.moo.jp';

function formatName(n) { return n ? n.replace(/[\s　]+/g, ' ').trim() : ''; }

// --- タブ切り替え ---
var tabButtons = document.querySelectorAll('.tab-btn');
for (var i = 0; i < tabButtons.length; i++) {
  tabButtons[i].addEventListener('click', function() {
    var tab = this.getAttribute('data-tab');
    for (var j = 0; j < tabButtons.length; j++) tabButtons[j].classList.remove('active');
    this.classList.add('active');
    var panels = document.querySelectorAll('.panel');
    for (var k = 0; k < panels.length; k++) panels[k].classList.remove('active');
    document.getElementById('panel-' + tab).classList.add('active');
  });
}

// ============================================================
// 1. 選手ランキング
// ============================================================
var currentScope = 'national';

var playersVenueSelect = document.getElementById('playersVenueSelect');
ALL_VENUES.forEach(function(v) {
  var opt = document.createElement('option');
  opt.value = v; opt.textContent = v;
  playersVenueSelect.appendChild(opt);
});

var scopeSeg = document.getElementById('scopeSeg');
scopeSeg.querySelectorAll('button').forEach(function(btn) {
  btn.addEventListener('click', function() {
    scopeSeg.querySelectorAll('button').forEach(function(b) { b.classList.remove('active'); });
    this.classList.add('active');
    currentScope = this.getAttribute('data-scope');
    playersVenueSelect.style.display = currentScope === 'local' ? 'inline-block' : 'none';
  });
});

function renderPlayersTable(data) {
  var wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  var table = document.createElement('table');
  table.className = 'data-table';

  var thead = document.createElement('thead');
  var hrow = document.createElement('tr');
  var headers = ['順位', '選手', '出走数', '勝率(簡易)', '1着率', '2連対率', '3連対率', '平均ST'];
  headers.forEach(function(h) {
    var th = document.createElement('th');
    th.textContent = h;
    hrow.appendChild(th);
  });
  thead.appendChild(hrow);
  table.appendChild(thead);

  var tbody = document.createElement('tbody');
  data.players.forEach(function(p, idx) {
    var tr = document.createElement('tr');

    var tdRank = document.createElement('td');
    tdRank.textContent = (idx + 1);
    tr.appendChild(tdRank);

    var tdName = document.createElement('td');
    tdName.className = 'td-player';
    var nameSpan = document.createElement('span');
    nameSpan.className = 'player-name';
    nameSpan.textContent = formatName(p.name) || ('登番' + p.player_id);
    tdName.appendChild(nameSpan);
    if (p.grade) {
      var gr = document.createElement('span');
      gr.className = 'eg eg-' + p.grade.replace(/\s/g, '');
      gr.textContent = p.grade;
      tdName.appendChild(gr);
    }
    tr.appendChild(tdName);

    var tdCount = document.createElement('td');
    tdCount.textContent = p.race_count;
    tr.appendChild(tdCount);

    var tdWin = document.createElement('td');
    tdWin.textContent = p.win_score.toFixed(2);
    tr.appendChild(tdWin);

    var tdR1 = document.createElement('td');
    tdR1.textContent = p.rank1_rate.toFixed(1) + '%';
    tr.appendChild(tdR1);

    var tdR2 = document.createElement('td');
    tdR2.textContent = p.rank2_rate.toFixed(1) + '%';
    tr.appendChild(tdR2);

    var tdR3 = document.createElement('td');
    tdR3.textContent = p.rank3_rate.toFixed(1) + '%';
    tr.appendChild(tdR3);

    var tdSt = document.createElement('td');
    tdSt.textContent = p.avg_st != null ? p.avg_st.toFixed(2) : '-';
    tr.appendChild(tdSt);

    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  wrap.appendChild(table);
  return wrap;
}

async function loadPlayers() {
  var resultEl = document.getElementById('playersResult');
  resultEl.textContent = '';
  resultEl.appendChild(makeLoading('読み込み中...'));

  var minRaces = document.getElementById('minRacesInput').value || 20;
  var qs = 'scope=' + currentScope + '&min_races=' + encodeURIComponent(minRaces) + '&sort=rank1_rate&order=desc&limit=50';
  if (currentScope === 'local') {
    qs += '&venue=' + encodeURIComponent(playersVenueSelect.value);
  }

  try {
    var res = await fetch(API_HOST + '/get_analysis_players.php?' + qs);
    var data = await res.json();
    if (data.error) throw new Error(data.error);
    resultEl.textContent = '';
    if (!data.players || data.players.length === 0) {
      resultEl.appendChild(makeError('条件に合致する選手がいません(最低出走数を下げてお試しください)'));
      return;
    }
    resultEl.appendChild(renderPlayersTable(data));
    var footnote = document.createElement('div');
    footnote.className = 'note';
    footnote.style.marginTop = '10px';
    footnote.textContent = '勝率(簡易)は (1着数×2 + 2着数×1) ÷ 出走数 で算出した当サイト独自の簡易指標です。公式発表の勝率とは計算方法が異なる場合があります。';
    resultEl.appendChild(footnote);
  } catch (e) {
    resultEl.textContent = '';
    resultEl.appendChild(makeError('データの取得に失敗しました'));
  }
}
document.getElementById('playersSearchBtn').addEventListener('click', loadPlayers);

function makeLoading(text) {
  var d = document.createElement('div');
  d.className = 'loading';
  d.textContent = text;
  return d;
}
function makeError(text) {
  var d = document.createElement('div');
  d.className = 'error-msg';
  d.textContent = text;
  return d;
}

// ============================================================
// 2. 会場別データ
// ============================================================
var venuePicker = document.getElementById('venuePicker');
ALL_VENUES.forEach(function(v) {
  var btn = document.createElement('button');
  btn.className = 'venue-btn';
  btn.textContent = v;
  btn.addEventListener('click', function() {
    venuePicker.querySelectorAll('.venue-btn').forEach(function(b) { b.classList.remove('active'); });
    this.classList.add('active');
    loadVenueAnalysis(v);
  });
  venuePicker.appendChild(btn);
});

function renderRateTable(title, rows, keyLabel, keyField) {
  var box = document.createElement('div');
  var h = document.createElement('div');
  h.style.fontSize = '12px';
  h.style.fontWeight = '700';
  h.style.color = '#555';
  h.style.margin = '4px 0 8px';
  h.textContent = title;
  box.appendChild(h);

  var wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  var table = document.createElement('table');
  table.className = 'data-table';
  var thead = document.createElement('thead');
  var hrow = document.createElement('tr');
  [keyLabel, '件数', '1着率', '2連対率', '3連対率'].forEach(function(h2) {
    var th = document.createElement('th');
    th.textContent = h2;
    hrow.appendChild(th);
  });
  thead.appendChild(hrow);
  table.appendChild(thead);
  var tbody = document.createElement('tbody');
  rows.forEach(function(r) {
    var tr = document.createElement('tr');
    var tds = [r[keyField], r.race_count, r.rank1_rate.toFixed(1) + '%', r.rank2_rate.toFixed(1) + '%', r.rank3_rate.toFixed(1) + '%'];
    tds.forEach(function(v) {
      var td = document.createElement('td');
      td.textContent = v;
      tr.appendChild(td);
    });
    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  wrap.appendChild(table);
  box.appendChild(wrap);
  return box;
}

async function loadVenueAnalysis(venue) {
  var resultEl = document.getElementById('venueResult');
  resultEl.textContent = '';
  resultEl.appendChild(makeLoading('読み込み中...'));

  try {
    var res = await fetch(API_HOST + '/get_analysis_venue.php?venue=' + encodeURIComponent(venue));
    var data = await res.json();
    if (data.error) throw new Error(data.error);

    resultEl.textContent = '';

    if (data.lane_stats && data.lane_stats.length > 0) {
      resultEl.appendChild(renderRateTable('枠別 入着率(全期間)', data.lane_stats, '枠', 'lane'));
    }

    var note = document.createElement('div');
    note.className = 'note';
    note.style.marginTop = '16px';
    note.textContent = '進入コース別データ・決まり手推定は、進入コースの記録を開始した ' + data.course_data_since + ' 以降のデータのみで集計しています。サンプル数が少ないため参考値としてご覧ください。';
    resultEl.appendChild(note);

    if (data.course_stats && data.course_stats.length > 0) {
      resultEl.appendChild(renderRateTable('進入コース別 入着率(' + data.course_data_since + '〜)', data.course_stats, 'コース', 'course'));
    } else {
      var noCourse = document.createElement('div');
      noCourse.className = 'error-msg';
      noCourse.style.marginTop = '10px';
      noCourse.textContent = 'この会場は対象期間中のレースデータがありません。';
      resultEl.appendChild(noCourse);
    }

    if (data.kimarite_estimate) {
      var kh = document.createElement('div');
      kh.style.fontSize = '12px';
      kh.style.fontWeight = '700';
      kh.style.color = '#555';
      kh.style.margin = '16px 0 8px';
      kh.textContent = '決まり手 簡易推定(1着艇の進入コースのみで判定した近似値。実際の決まり手データではありません)';
      resultEl.appendChild(kh);

      var krow = document.createElement('div');
      krow.className = 'kimarite-row';
      var items = [
        ['逃げ(1コース)', data.kimarite_estimate.nige_rate],
        ['差し(2コース)', data.kimarite_estimate.sashi_rate],
        ['まくり系(3〜6コース)', data.kimarite_estimate.makuri_rate],
      ];
      items.forEach(function(it) {
        var box = document.createElement('div');
        box.className = 'kimarite-item';
        var lbl = document.createElement('div');
        lbl.className = 'kimarite-label';
        lbl.textContent = it[0];
        var val = document.createElement('div');
        val.className = 'kimarite-value';
        val.textContent = it[1].toFixed(1) + '%';
        box.appendChild(lbl); box.appendChild(val);
        krow.appendChild(box);
      });
      resultEl.appendChild(krow);
    }
  } catch (e) {
    resultEl.textContent = '';
    resultEl.appendChild(makeError('データの取得に失敗しました'));
  }
}

// ============================================================
// 3. 過去レース検索
// ============================================================
var searchVenueSelect = document.getElementById('searchVenueSelect');
ALL_VENUES.forEach(function(v) {
  var opt = document.createElement('option');
  opt.value = v; opt.textContent = v;
  searchVenueSelect.appendChild(opt);
});

function todayStr() {
  var d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}
document.getElementById('searchDateInput').value = todayStr();

async function searchRaces() {
  var resultEl = document.getElementById('searchResult');
  resultEl.textContent = '';
  resultEl.appendChild(makeLoading('検索中...'));

  var venue = searchVenueSelect.value;
  var date = document.getElementById('searchDateInput').value;
  if (!date) { resultEl.textContent = ''; resultEl.appendChild(makeError('日付を選択してください')); return; }

  try {
    var res = await fetch(API_HOST + '/races.php?date=' + encodeURIComponent(date) + '&venue=' + encodeURIComponent(venue));
    var data = await res.json();
    resultEl.textContent = '';
    if (!data.races || data.races.length === 0) {
      resultEl.appendChild(makeError('この会場・日付のレースは見つかりませんでした'));
      return;
    }
    var list = document.createElement('div');
    list.className = 'race-search-list';
    data.races.forEach(function(r) {
      var a = document.createElement('a');
      var q = 'venue=' + encodeURIComponent(venue) + '&date=' + date + '&race_no=' + r.race_no;
      if (r.has_result) {
        a.className = 'race-search-item confirmed';
        a.href = 'result.php?' + q;
        a.textContent = r.race_no + 'R (結果確定)';
      } else {
        a.className = 'race-search-item';
        a.href = 'racelist.php?' + q;
        a.textContent = r.race_no + 'R (出走表)';
      }
      list.appendChild(a);
    });
    resultEl.appendChild(list);
  } catch (e) {
    resultEl.textContent = '';
    resultEl.appendChild(makeError('検索に失敗しました'));
  }
}
document.getElementById('searchBtn').addEventListener('click', searchRaces);

// ============================================================
// 4. 払戻金傾向
// ============================================================
function renderPayoutsByType(rows) {
  var wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  var table = document.createElement('table');
  table.className = 'data-table';
  var thead = document.createElement('thead');
  var hrow = document.createElement('tr');
  ['式別', '件数', '平均配当', '最高配当', '最低配当'].forEach(function(h) {
    var th = document.createElement('th');
    th.textContent = h;
    hrow.appendChild(th);
  });
  thead.appendChild(hrow);
  table.appendChild(thead);
  var tbody = document.createElement('tbody');
  rows.forEach(function(r) {
    var tr = document.createElement('tr');
    var tds = [r.bet_type, r.count, r.avg_amount.toLocaleString() + '円', r.max_amount.toLocaleString() + '円', r.min_amount.toLocaleString() + '円'];
    tds.forEach(function(v) {
      var td = document.createElement('td');
      td.textContent = v;
      tr.appendChild(td);
    });
    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  wrap.appendChild(table);
  return wrap;
}

function renderPopularityDist(dist, total) {
  var wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  var table = document.createElement('table');
  table.className = 'data-table';
  var thead = document.createElement('thead');
  var hrow = document.createElement('tr');
  ['人気区分', '件数', '割合'].forEach(function(h) {
    var th = document.createElement('th');
    th.textContent = h;
    hrow.appendChild(th);
  });
  thead.appendChild(hrow);
  table.appendChild(thead);
  var tbody = document.createElement('tbody');
  dist.forEach(function(d) {
    var tr = document.createElement('tr');
    var tds = [d.bucket, d.count, d.rate.toFixed(1) + '%'];
    tds.forEach(function(v) {
      var td = document.createElement('td');
      td.textContent = v;
      tr.appendChild(td);
    });
    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  wrap.appendChild(table);
  return wrap;
}

async function loadPayouts() {
  var byTypeEl = document.getElementById('payoutsByType');
  var popEl = document.getElementById('payoutsPopularity');
  try {
    var res = await fetch(API_HOST + '/get_analysis_payouts.php');
    var data = await res.json();
    if (data.error) throw new Error(data.error);

    byTypeEl.textContent = '';
    var note = document.createElement('div');
    note.className = 'note';
    note.textContent = '集計期間: ' + data.date_range.min_date + ' 〜 ' + data.date_range.max_date + '(払戻金データの記録開始から)';
    byTypeEl.appendChild(note);
    byTypeEl.appendChild(renderPayoutsByType(data.by_bet_type));

    popEl.textContent = '';
    if (data.popularity_dist && data.popularity_dist.length > 0) {
      var popNote = document.createElement('div');
      popNote.className = 'note';
      popNote.textContent = '対象: 3連単 ' + data.sanrentan_total.toLocaleString() + '件。1番人気決着の割合が低いほど「荒れ」傾向です。';
      popEl.appendChild(popNote);
      popEl.appendChild(renderPopularityDist(data.popularity_dist, data.sanrentan_total));
    } else {
      popEl.appendChild(makeError('データがありません'));
    }
  } catch (e) {
    byTypeEl.textContent = '';
    byTypeEl.appendChild(makeError('データの取得に失敗しました'));
  }
}

loadPayouts();
</script>
</body>
</html>
