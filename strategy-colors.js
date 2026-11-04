/**
 * 4戦略の表示色(サイト全体で共通。色を変えるときはここだけを直す)
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

/** 戦略の淡色(背景用) */
function strategyBgColor(type) {
  return (STRATEGY_PALETTE[type] || STRATEGY_PALETTE_DEFAULT).bg;
}
