/**
 * 予想の根拠(各艇の評価を上げた要因・下げた要因)の表示(2026-11-07〜)
 * get_prediction.php の predictions[].factors({up: [...], down: [...]})を描画する。
 * 各要因は {label, detail, effect}。effect は統計モデル(ロジスティック回帰)の
 * 係数 × 値 のレース平均との差(model_explain_lib.php)。
 * 参照: ai-predict.php(各艇の詳細)、performance.php(個別レース詳細)。
 * WAF 制約によりインラインスタイルのみ・テンプレートリテラル不可。
 */
function factorStrength(effect) {
  var a = Math.abs(effect);
  return a >= 0.3 ? '大' : (a >= 0.15 ? '中' : '小');
}

function renderFactorList(title, items, color, bg) {
  var box = document.createElement('div');
  box.style.cssText = 'margin-top:6px;';
  var h = document.createElement('div');
  h.style.cssText = 'font-size:11px; font-weight:700; color:' + color + '; margin-bottom:3px;';
  h.textContent = title;
  box.appendChild(h);
  if (!items || items.length === 0) {
    var none = document.createElement('div');
    none.style.cssText = 'font-size:11px; color:#999; padding:2px 0;';
    none.textContent = '目立った要因なし(レース平均並み)';
    box.appendChild(none);
    return box;
  }
  items.forEach(function(it) {
    var row = document.createElement('div');
    row.style.cssText = 'display:flex; align-items:center; gap:6px; padding:2px 0; font-size:11px; color:#333; flex-wrap:wrap;';
    var lbl = document.createElement('span');
    lbl.style.cssText = 'flex:1 1 140px; min-width:0;';
    lbl.textContent = it.label;
    var det = document.createElement('span');
    det.style.cssText = 'color:#666; font-variant-numeric:tabular-nums;';
    det.textContent = it.detail || '';
    var bar = document.createElement('span');
    bar.style.cssText = 'display:inline-block; width:60px; height:6px; background:#eef0f3; border-radius:3px; overflow:hidden; flex:0 0 auto;';
    var fill = document.createElement('span');
    fill.style.cssText = 'display:block; height:100%; background:' + color + '; width:' + Math.min(100, Math.abs(it.effect) / 0.6 * 100) + '%;';
    bar.appendChild(fill);
    var chip = document.createElement('span');
    chip.style.cssText = 'font-size:10px; font-weight:700; color:' + color + '; background:' + bg + '; border-radius:4px; padding:0 5px; flex:0 0 auto;';
    chip.textContent = '影響' + factorStrength(it.effect);
    row.appendChild(lbl);
    row.appendChild(det);
    row.appendChild(bar);
    row.appendChild(chip);
    box.appendChild(row);
  });
  return box;
}

/** factors が null(Free・未ログイン)のときは呼び出し側で鍵の表示を出す */
function renderPredictionFactors(factors) {
  var wrap = document.createElement('div');
  if (!factors) {
    var nd = document.createElement('div');
    nd.style.cssText = 'font-size:11px; color:#999; padding:4px 0;';
    nd.textContent = '予想の根拠を計算できませんでした。';
    wrap.appendChild(nd);
    return wrap;
  }
  var title = document.createElement('div');
  title.style.cssText = 'font-size:11px; font-weight:700; color:#555;';
  title.textContent = '予想の根拠(統計モデルの計算で、レース平均と比べて評価を上げた・下げた主な要因)';
  wrap.appendChild(title);
  wrap.appendChild(renderFactorList('▲ 評価を上げた要因', factors.up, '#16a34a', '#dcfce7'));
  wrap.appendChild(renderFactorList('▼ 評価を下げた要因', factors.down, '#dc2626', '#fee2e2'));
  return wrap;
}

/** Standard 未満向けの鍵の表示 */
function renderFactorsLock() {
  var lock = document.createElement('div');
  lock.style.cssText = 'background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:8px 12px; text-align:center; font-size:11px; color:#92400e;';
  lock.appendChild(document.createTextNode('🔒 1着確率と予想の根拠は Standard / Premium 会員限定です '));
  var a = document.createElement('a');
  a.href = 'mypage.php#planCardStandard';
  a.textContent = 'プランをアップグレード';
  a.style.cssText = 'color:#d97706; font-weight:700;';
  lock.appendChild(a);
  return lock;
}
