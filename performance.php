<?php
require_once __DIR__ . '/auth.php';
$user = current_user();
$plan = $user['plan'] ?? 'free';
$isPremium = ($plan === 'standard' || $plan === 'premium');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>艇王 - 成績・回収率</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, 'Hiragino Sans', 'Meiryo', sans-serif; background: #f0f2f5; color: #333; min-height: 100vh; }
header { background: #fff; border-bottom: 3px solid #0055a4; padding: 12px 20px; display: flex; align-items: center; gap: 14px; }
.back-btn { color: #0055a4; text-decoration: none; font-size: 20px; line-height: 1; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: background 0.15s; }
.back-btn:hover { background: #e8f0fd; }
header h1 { font-size: 18px; font-weight: 700; color: #222; }
.container { max-width: 1000px; margin: 0 auto; padding: 20px 16px; }

.card { background: #fff; border: 1px solid #e0e3e8; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
.card h2 { font-size: 14px; font-weight: 700; color: #222; margin-bottom: 12px; }

.note { font-size: 11px; color: #a0724b; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; padding: 8px 12px; margin-bottom: 12px; line-height: 1.6; }
.loading { text-align: center; padding: 30px; color: #999; font-size: 13px; }
.error-msg { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; padding: 14px; color: #dc2626; font-size: 13px; }

/* 戦略カード(サマリー) */
.strategy-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.strategy-card { border: 1px solid #e0e3e8; border-radius: 10px; padding: 14px; background: #f7f8fa; }
.strategy-card-name { font-size: 13px; font-weight: 800; color: #0055a4; margin-bottom: 8px; }
.strategy-card-row { display: flex; justify-content: space-between; font-size: 12px; color: #555; padding: 3px 0; }
.strategy-card-row strong { color: #222; font-variant-numeric: tabular-nums; }
.strategy-card-row.profit-plus strong { color: #16a34a; }
.strategy-card-row.profit-minus strong { color: #dc2626; }

.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 10px; border: 1px solid #e0e3e8; }
table.data-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 480px; }
table.data-table th { background: #f7f8fa; font-size: 10px; font-weight: 700; color: #999; padding: 8px 6px; border-bottom: 2px solid #e0e3e8; white-space: nowrap; text-align: center; }
table.data-table td { padding: 8px 6px; border-bottom: 1px solid #f0f0f0; text-align: center; white-space: nowrap; }
table.data-table tr:last-child td { border-bottom: none; }
.roi-plus { color: #16a34a; font-weight: 700; }
.roi-minus { color: #dc2626; font-weight: 700; }

/* プレミアム限定バナー */
.premium-lock { text-align: center; padding: 30px 16px; }
.premium-lock-icon { font-size: 28px; margin-bottom: 10px; display: block; }
.premium-lock p { font-size: 13px; color: #666; margin-bottom: 14px; }
.premium-lock a { display: inline-block; padding: 9px 22px; border-radius: 8px; background: #d97706; color: #fff; font-size: 13px; font-weight: 700; text-decoration: none; }
.premium-lock a:hover { background: #b45309; }

/* 折れ線グラフ */
.chart-legend { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; font-size: 11px; }
.chart-legend-item { display: flex; align-items: center; gap: 4px; }
.chart-legend-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
svg.trend-chart { width: 100%; height: auto; }

@media (max-width: 600px) {
  .strategy-grid { grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); }
}
</style>
</head>
<body>

<header>
  <a class="back-btn" href="index.php">&larr;</a>
  <h1>成績・回収率</h1>
</header>

<div class="container">

  <!-- 1. 全体サマリー(無料) -->
  <div class="card">
    <h2>全体サマリー</h2>
    <div id="summaryResult"><div class="loading">読み込み中...</div></div>
  </div>

  <!-- 2. 日別推移グラフ(premium) -->
  <div class="card">
    <h2>日別推移(的中率・回収率)</h2>
    <?php if ($isPremium): ?>
      <div id="dailyResult"><div class="loading">読み込み中...</div></div>
    <?php else: ?>
      <div class="premium-lock">
        <span class="premium-lock-icon">&#128274;</span>
        <p>日別推移グラフは Standard / Premium プラン限定機能です。</p>
        <a href="upgrade.html">プランをアップグレード</a>
      </div>
    <?php endif; ?>
  </div>

  <!-- 3. 会場別内訳(premium) -->
  <div class="card">
    <h2>会場別 内訳</h2>
    <?php if ($isPremium): ?>
      <div id="venueResult"><div class="loading">読み込み中...</div></div>
    <?php else: ?>
      <div class="premium-lock">
        <span class="premium-lock-icon">&#128274;</span>
        <p>会場別の内訳は Standard / Premium プラン限定機能です。</p>
        <a href="upgrade.html">プランをアップグレード</a>
      </div>
    <?php endif; ?>
  </div>

  <!-- 4. 戦略比較表(premium) -->
  <div class="card">
    <h2>戦略比較表</h2>
    <?php if ($isPremium): ?>
      <div id="compareResult"><div class="loading">読み込み中...</div></div>
    <?php else: ?>
      <div class="premium-lock">
        <span class="premium-lock-icon">&#128274;</span>
        <p>戦略比較表は Standard / Premium プラン限定機能です。</p>
        <a href="upgrade.html">プランをアップグレード</a>
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
var API_HOST = 'https://' + '2410049.moo.jp';
var IS_PREMIUM = <?php echo $isPremium ? 'true' : 'false'; ?>;
var STRATEGY_COLORS = { '的中特化': '#0055a4', 'バランス': '#16a34a', '一撃重視': '#dc2626', '絞り込み': '#d97706' };

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
function roiSpan(roi) {
  var span = document.createElement('span');
  span.className = roi >= 0 ? 'roi-plus' : 'roi-minus';
  span.textContent = (roi >= 0 ? '+' : '') + roi.toFixed(1) + '%';
  return span;
}

// ============================================================
// 1. 全体サマリー
// ============================================================
async function loadSummary() {
  var el = document.getElementById('summaryResult');
  el.textContent = '';
  el.appendChild(makeLoading('読み込み中...'));

  try {
    var res = await fetch(API_HOST + '/get_performance_summary.php');
    var data = await res.json();
    if (data.error) throw new Error(data.error);

    el.textContent = '';

    var note = document.createElement('div');
    note.className = 'note';
    note.textContent = '集計期間: ' + data.date_range.min_date + ' 〜 ' + data.date_range.max_date +
      '(対象 ' + data.date_range.race_count.toLocaleString() + 'レース)。運用開始から間もないため、サンプル数はまだ少ない点にご留意ください。';
    el.appendChild(note);

    if (!data.stats || data.stats.length === 0) {
      el.appendChild(makeError('集計対象データがありません'));
      return;
    }

    var grid = document.createElement('div');
    grid.className = 'strategy-grid';
    data.stats.forEach(function(s) {
      var card = document.createElement('div');
      card.className = 'strategy-card';

      var name = document.createElement('div');
      name.className = 'strategy-card-name';
      name.textContent = s.strategy_type;
      card.appendChild(name);

      var rows = [
        ['対象レース数', s.total_races + '件'],
        ['的中率', s.hit_rate.toFixed(1) + '%'],
        ['投資額', s.total_cost.toLocaleString() + '円'],
        ['払戻額', s.total_payout.toLocaleString() + '円'],
      ];
      rows.forEach(function(r) {
        var rowEl = document.createElement('div');
        rowEl.className = 'strategy-card-row';
        var lbl = document.createElement('span');
        lbl.textContent = r[0];
        var val = document.createElement('strong');
        val.textContent = r[1];
        rowEl.appendChild(lbl); rowEl.appendChild(val);
        card.appendChild(rowEl);
      });

      var roiRow = document.createElement('div');
      roiRow.className = 'strategy-card-row ' + (s.profit >= 0 ? 'profit-plus' : 'profit-minus');
      var roiLbl = document.createElement('span');
      roiLbl.textContent = '回収率(損益率)';
      var roiVal = document.createElement('strong');
      roiVal.textContent = (s.roi >= 0 ? '+' : '') + s.roi.toFixed(1) + '%';
      roiRow.appendChild(roiLbl); roiRow.appendChild(roiVal);
      card.appendChild(roiRow);

      grid.appendChild(card);
    });
    el.appendChild(grid);
  } catch (e) {
    el.textContent = '';
    el.appendChild(makeError('データの取得に失敗しました'));
  }
}
loadSummary();

// ============================================================
// 2. 日別推移グラフ(SVG、premiumのみ)
// ============================================================
function buildTrendChart(daily, metricKey, metricSuffix) {
  var strategies = {};
  daily.forEach(function(d) {
    if (!strategies[d.strategy_type]) strategies[d.strategy_type] = [];
    strategies[d.strategy_type].push(d);
  });

  var dates = [];
  daily.forEach(function(d) { if (dates.indexOf(d.date) === -1) dates.push(d.date); });
  dates.sort();

  var W = 640, H = 220, padL = 40, padR = 10, padT = 10, padB = 24;
  var plotW = W - padL - padR, plotH = H - padT - padB;

  var values = daily.map(function(d) { return d[metricKey]; });
  var minV = Math.min(0, Math.min.apply(null, values));
  var maxV = Math.max(10, Math.max.apply(null, values));
  var range = maxV - minV || 1;

  function xPos(i) { return padL + (dates.length <= 1 ? 0 : (i / (dates.length - 1)) * plotW); }
  function yPos(v) { return padT + plotH - ((v - minV) / range) * plotH; }

  var svgParts = [];
  svgParts.push('<svg class="trend-chart" viewBox="0 0 ' + W + ' ' + H + '" xmlns="http://www.w3.org/2000/svg">');
  // 0ライン
  var zeroY = yPos(0);
  svgParts.push('<line x1="' + padL + '" y1="' + zeroY + '" x2="' + (W - padR) + '" y2="' + zeroY + '" stroke="#e0e3e8" stroke-width="1" />');

  Object.keys(strategies).forEach(function(type) {
    var color = STRATEGY_COLORS[type] || '#888';
    var points = [];
    dates.forEach(function(date, i) {
      var row = strategies[type].filter(function(d) { return d.date === date; })[0];
      if (row) points.push(xPos(i) + ',' + yPos(row[metricKey]));
    });
    if (points.length > 0) {
      svgParts.push('<polyline points="' + points.join(' ') + '" fill="none" stroke="' + color + '" stroke-width="2" />');
    }
  });

  // x軸ラベル(先頭・末尾のみ)
  if (dates.length > 0) {
    svgParts.push('<text x="' + padL + '" y="' + (H - 6) + '" font-size="10" fill="#999">' + dates[0] + '</text>');
    svgParts.push('<text x="' + (W - padR) + '" y="' + (H - 6) + '" font-size="10" fill="#999" text-anchor="end">' + dates[dates.length - 1] + '</text>');
  }

  svgParts.push('</svg>');

  var wrap = document.createElement('div');
  wrap.innerHTML = svgParts.join('');
  return wrap.firstChild;
}

function buildLegend(types) {
  var legend = document.createElement('div');
  legend.className = 'chart-legend';
  types.forEach(function(t) {
    var item = document.createElement('div');
    item.className = 'chart-legend-item';
    var dot = document.createElement('span');
    dot.className = 'chart-legend-dot';
    dot.style.background = STRATEGY_COLORS[t] || '#888';
    var label = document.createElement('span');
    label.textContent = t;
    item.appendChild(dot); item.appendChild(label);
    legend.appendChild(item);
  });
  return legend;
}

async function loadDaily() {
  if (!IS_PREMIUM) return;
  var el = document.getElementById('dailyResult');
  el.textContent = '';
  el.appendChild(makeLoading('読み込み中...'));

  try {
    var res = await fetch(API_HOST + '/get_performance_daily.php');
    var data = await res.json();
    if (data.error) throw new Error(data.message || data.error);

    el.textContent = '';
    if (!data.daily || data.daily.length === 0) {
      el.appendChild(makeError('データがありません'));
      return;
    }

    var types = [];
    data.daily.forEach(function(d) { if (types.indexOf(d.strategy_type) === -1) types.push(d.strategy_type); });

    var note = document.createElement('div');
    note.className = 'note';
    note.textContent = '運用開始から日が浅いため、日別の変動が大きく出る場合があります。';
    el.appendChild(note);

    var titleR1 = document.createElement('div');
    titleR1.style.fontSize = '12px'; titleR1.style.fontWeight = '700'; titleR1.style.color = '#555'; titleR1.style.margin = '8px 0 4px';
    titleR1.textContent = '的中率(%)の推移';
    el.appendChild(titleR1);
    el.appendChild(buildLegend(types));
    el.appendChild(buildTrendChart(data.daily, 'hit_rate'));

    var titleR2 = document.createElement('div');
    titleR2.style.fontSize = '12px'; titleR2.style.fontWeight = '700'; titleR2.style.color = '#555'; titleR2.style.margin = '18px 0 4px';
    titleR2.textContent = '回収率(損益率, %)の推移';
    el.appendChild(titleR2);
    el.appendChild(buildLegend(types));
    el.appendChild(buildTrendChart(data.daily, 'roi'));
  } catch (e) {
    el.textContent = '';
    el.appendChild(makeError('データの取得に失敗しました'));
  }
}
loadDaily();

// ============================================================
// 3. 会場別内訳(premium)
// ============================================================
async function loadVenue() {
  if (!IS_PREMIUM) return;
  var el = document.getElementById('venueResult');
  el.textContent = '';
  el.appendChild(makeLoading('読み込み中...'));

  try {
    var res = await fetch(API_HOST + '/get_performance_venue.php');
    var data = await res.json();
    if (data.error) throw new Error(data.message || data.error);

    el.textContent = '';
    if (!data.by_venue || data.by_venue.length === 0) {
      el.appendChild(makeError('データがありません'));
      return;
    }

    var note = document.createElement('div');
    note.className = 'note';
    note.textContent = '賭式別(3連単/2連単等)の内訳は、現在すべての戦略が3連単のみを対象としているため区別できるデータがなく、非表示にしています。';
    el.appendChild(note);

    var wrap = document.createElement('div');
    wrap.className = 'table-wrap';
    var table = document.createElement('table');
    table.className = 'data-table';
    var thead = document.createElement('thead');
    var hrow = document.createElement('tr');
    ['会場', '戦略', '対象数', '的中率', '回収率'].forEach(function(h) {
      var th = document.createElement('th');
      th.textContent = h;
      hrow.appendChild(th);
    });
    thead.appendChild(hrow);
    table.appendChild(thead);
    var tbody = document.createElement('tbody');
    data.by_venue.forEach(function(r) {
      var tr = document.createElement('tr');
      var tdVenue = document.createElement('td'); tdVenue.textContent = r.venue; tr.appendChild(tdVenue);
      var tdType = document.createElement('td'); tdType.textContent = r.strategy_type; tr.appendChild(tdType);
      var tdCount = document.createElement('td'); tdCount.textContent = r.total_races; tr.appendChild(tdCount);
      var tdHit = document.createElement('td'); tdHit.textContent = r.hit_rate.toFixed(1) + '%'; tr.appendChild(tdHit);
      var tdRoi = document.createElement('td'); tdRoi.appendChild(roiSpan(r.roi)); tr.appendChild(tdRoi);
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    wrap.appendChild(table);
    el.appendChild(wrap);
  } catch (e) {
    el.textContent = '';
    el.appendChild(makeError('データの取得に失敗しました'));
  }
}
loadVenue();

// ============================================================
// 4. 戦略比較表(premium、summaryと同じデータを再利用)
// ============================================================
async function loadCompare() {
  if (!IS_PREMIUM) return;
  var el = document.getElementById('compareResult');
  el.textContent = '';
  el.appendChild(makeLoading('読み込み中...'));

  try {
    var res = await fetch(API_HOST + '/get_performance_summary.php');
    var data = await res.json();
    if (data.error) throw new Error(data.error);

    el.textContent = '';
    if (!data.stats || data.stats.length === 0) {
      el.appendChild(makeError('データがありません'));
      return;
    }

    var wrap = document.createElement('div');
    wrap.className = 'table-wrap';
    var table = document.createElement('table');
    table.className = 'data-table';
    var thead = document.createElement('thead');
    var hrow = document.createElement('tr');
    var headers = ['指標'].concat(data.stats.map(function(s) { return s.strategy_type; }));
    headers.forEach(function(h) {
      var th = document.createElement('th');
      th.textContent = h;
      hrow.appendChild(th);
    });
    thead.appendChild(hrow);
    table.appendChild(thead);

    var tbody = document.createElement('tbody');
    var metricRows = [
      ['対象レース数', function(s) { return s.total_races + '件'; }],
      ['的中率', function(s) { return s.hit_rate.toFixed(1) + '%'; }],
      ['投資額', function(s) { return s.total_cost.toLocaleString() + '円'; }],
      ['払戻額', function(s) { return s.total_payout.toLocaleString() + '円'; }],
      ['回収率(損益率)', function(s) {
        var span = document.createElement('span');
        span.className = s.roi >= 0 ? 'roi-plus' : 'roi-minus';
        span.textContent = (s.roi >= 0 ? '+' : '') + s.roi.toFixed(1) + '%';
        return span;
      }],
    ];
    metricRows.forEach(function(mr) {
      var tr = document.createElement('tr');
      var tdLabel = document.createElement('td');
      tdLabel.style.fontWeight = '700';
      tdLabel.style.textAlign = 'left';
      tdLabel.style.paddingLeft = '10px';
      tdLabel.textContent = mr[0];
      tr.appendChild(tdLabel);
      data.stats.forEach(function(s) {
        var td = document.createElement('td');
        var v = mr[1](s);
        if (typeof v === 'string') td.textContent = v; else td.appendChild(v);
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    wrap.appendChild(table);
    el.appendChild(wrap);
  } catch (e) {
    el.textContent = '';
    el.appendChild(makeError('データの取得に失敗しました'));
  }
}
loadCompare();
</script>
</body>
</html>
