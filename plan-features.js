// プラン別の特典一覧(単一ソース)。mypage.phpがこのデータを参照して
// プランカードの機能リストを描画する。ここを更新すればページに反映される。
//
// tiers の値:
//   true / false : 利用可否のみ表示(true=○, false=×)
//   文字列       : 利用可能で、ラベルの末尾に "(文字列)" を付けて表示(例: お気に入り件数)
//
// 実装済みの機能だけを載せる(2026-11-05: 未実装だった「広告非表示」「優先サポート」を削除)。
// 予想の公開範囲(2026-11-07〜。plan_access.php と同じ方針):
//   予測順位は全員 / 1着確率・買い目・AI解説・予想の根拠は Standard 以上 / 比較・記録系は Premium
var PLAN_FEATURES = [
  { label: 'レース場一覧の閲覧', tiers: { free: true, standard: true, premium: true } },
  { label: 'お気に入りレース場', tiers: { free: '3件', standard: '無制限', premium: '無制限' } },
  { label: '予測順位の閲覧', tiers: { free: true, standard: true, premium: true } },
  { label: '1着確率・買い目・AI解説の閲覧', tiers: { free: false, standard: true, premium: true } },
  { label: '予想の根拠(評価を上げた・下げた要因)', tiers: { free: false, standard: true, premium: true } },
  { label: '成績・回収率の詳細(サマリーは無料)', tiers: { free: false, standard: true, premium: true } },
  { label: 'データ分析', tiers: { free: '一部', standard: true, premium: true } },
  { label: '戦略比較ビュー', tiers: { free: false, standard: false, premium: true } },
  { label: '会場横断の的中率比較', tiers: { free: false, standard: false, premium: true } },
  { label: 'マイ的中トラッカー', tiers: { free: false, standard: false, premium: true } },
  { label: '過去レース高度検索', tiers: { free: false, standard: false, premium: true } }
];
