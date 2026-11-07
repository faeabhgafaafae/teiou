/**
 * 4戦略の表示色と説明(サイト全体で共通。色・説明を変えるときはここだけを直す)
 *   main: 文字・線・グラフの色 / bg: バッジやアイコンの背景色(main の淡色)
 *   的中特化=青 / バランス=緑 / 一撃重視=赤 / 絞り込み=紫
 * 参照: ai-predict.php・strategy.php(戦略カード)、performance.php(グラフ・凡例)、
 *       home-races.js(今日の的中のアイコン)
 * WAF 制約によりテンプレートリテラルは使わない。
 */
var STRATEGY_PALETTE = {
  '的中特化': { main: '#2563eb', bg: '#dbeafe' },
  'バランス':  { main: '#16a34a', bg: '#d1fae5' },
  '一撃重視': { main: '#dc2626', bg: '#fee2e2' },
  '絞り込み': { main: '#7c3aed', bg: '#ede9fe' }
};
var STRATEGY_PALETTE_DEFAULT = { main: '#888888', bg: '#e2e8f0' };

/** 戦略の主色(未知の戦略は灰色) */
function strategyColor(type) {
  return (STRATEGY_PALETTE[type] || STRATEGY_PALETTE_DEFAULT).main;
}

/**
 * 戦略の説明(ロジックの変更履歴。レース日に応じて、その日に使われていたロジックの説明を返す)
 * 変更日はレース日基準。generate_strategies.php の冒頭コメント・定数(TOKKA_FROM_DATE 等)と合わせる
 */
var STRATEGY_LOGIC_HISTORY = {
  '的中特化': [
    { from: '2026-11-05', desc: '上位5艇から確率上位15点' },
    { from: '2026-09-17', desc: '上位4艇から確率上位9点' },
    { from: '2026-09-09', desc: '上位4艇から確率上位6点' },
    { from: '',           desc: '上位3艇の全順列(最大6点)' }
  ],
  'バランス': [
    { from: '',           desc: '上位2艇固定×上位4艇流し（最大12点）' }
  ],
  '一撃重視': [
    { from: '',           desc: '1位固定・中穴狙い（最大6点）' }
  ],
  '絞り込み': [
    { from: '2026-09-09', desc: '上位3艇から確率上位3点' },
    { from: '',           desc: '上位3艇を枠番順に1点' }
  ]
};

/** レース日('YYYY-MM-DD')に使われていた戦略ロジックの説明。日付省略時は現行 */
function strategyDescription(type, raceDate) {
  var hist = STRATEGY_LOGIC_HISTORY[type] || [];
  for (var i = 0; i < hist.length; i++) {
    if (!raceDate || raceDate >= hist[i].from) return hist[i].desc;
  }
  return '';
}

/**
 * 4戦略の表示定義(表示順・色・レース日の説明)。ai-predict.php と strategy.php で同じ定義を使う
 * (2026-11-07 に両ページの同一の STRAT_DEFS をここへ移動)
 */
function strategyDefs(raceDate) {
  return ['的中特化', 'バランス', '一撃重視', '絞り込み'].map(function(t) {
    return { type: t, color: strategyColor(t), desc: strategyDescription(t, raceDate) };
  });
}

/** 戦略の淡色(背景用) */
function strategyBgColor(type) {
  return (STRATEGY_PALETTE[type] || STRATEGY_PALETTE_DEFAULT).bg;
}
