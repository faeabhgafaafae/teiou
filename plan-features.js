// プラン別の特典一覧(単一ソース)。mypage.php・upgrade.htmlの両方がこのデータを参照して
// プランカードの機能リストを描画する。ここを更新すれば両ページに反映される。
//
// tiers の値:
//   true / false : 利用可否のみ表示(true=○, false=×)
//   文字列       : 利用可能で、ラベルの末尾に "(文字列)" を付けて表示(例: お気に入り件数)
//
// 「広告非表示」「優先サポート」は現時点で未実装の予定機能。表記のみ先行して掲載している。
var PLAN_FEATURES = [
  { label: 'レース場一覧の閲覧', tiers: { free: true, standard: true, premium: true } },
  { label: 'お気に入りレース場', tiers: { free: '3件', standard: '無制限', premium: '無制限' } },
  { label: 'AI予測の閲覧', tiers: { free: false, standard: true, premium: true } },
  { label: '成績・回収率の詳細', tiers: { free: false, standard: true, premium: true } },
  { label: 'データ分析', tiers: { free: '一部', standard: true, premium: true } },
  { label: '広告非表示', tiers: { free: false, standard: true, premium: true } },
  { label: 'スコア内訳フル開示', tiers: { free: false, standard: false, premium: true } },
  { label: 'マイ的中トラッカー', tiers: { free: false, standard: false, premium: true } },
  { label: '優先サポート', tiers: { free: false, standard: false, premium: true } }
];
