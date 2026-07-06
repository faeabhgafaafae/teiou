// 会場名の「DB上の値 → 画面表示用の値」変換マップ
// DB・URLパラメータ・API呼び出しは従来通りのDB値を使い続け、
// 画面に文字列を出す直前にのみこの関数を通す。
var VENUE_DISPLAY_NAMES = {
  '琵琶湖': 'びわこ'
};

function venueDisplayName(v) {
  return VENUE_DISPLAY_NAMES[v] || v;
}
