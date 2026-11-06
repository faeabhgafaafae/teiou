"""
pregenerate_upcoming.py — 締切が近いレースの予想・買い目を締切前に生成する(2026-11-05〜)

閲覧の有無に関係なく、全レースの予想(predictions / predictions_v2)と戦略買い目(strategies)を
締切前に作るためのジョブ。live.yml(cron-job.org から約15分ごとに起動)の各実行で、
締切まで WINDOW_MIN 分以内のレースについて api_predict.php を呼ぶ(api_predict.php が予測の保存と
買い目生成を行う。締切前なら呼ぶたびに最新の直前情報・オッズで更新される)。

生成の直前にそのレースの3連単オッズを取得・保存する(2026-11-07〜)。オッズが未取得のまま生成すると
バランス・一撃重視のオッズ条件が効かない(11-05: 50R、11-06: 79R で発生。health_check.php の balance_without_odds)。

締切を過ぎたレースは呼ばない。締切後は保存済みの予想・買い目が凍結され(prediction_guard_lib.php)、
締切前に作れなかったレースだけを結果取込み前の夜バッチ(generate_strategies_for_date.py)が補完する。

環境変数:
  API_KEY      get_pending_races.php の認証
  API_PENDING  get_pending_races.php の URL(live.yml の API_URL_PENDING と同じ)
  API_PREDICT  api_predict.php の URL
  API_ODDS     import_odds.php の URL(scrape_live.py と同じ)
  PREGEN_WINDOW_MIN  対象にする締切までの分数(既定 35。live.yml の起動間隔15分で各レース2回以上呼ばれる)
"""
import datetime as dt
import os
import sys
import time

import requests

from scrape_live import scrape_odds, send_odds, VENUE_TO_JCD, ODDS_SLEEP_SEC

API_KEY     = os.environ.get('API_KEY', '')
API_PENDING = os.environ.get('API_PENDING') or 'https://2410049.moo.jp/get_pending_races.php'
API_PREDICT = os.environ.get('API_PREDICT') or 'https://2410049.moo.jp/api_predict.php'
WINDOW_MIN  = int(os.environ.get('PREGEN_WINDOW_MIN', '35'))
BUDGET_SEC  = 360   # このジョブの時間予算(締切の近い順に処理し、超えたら打ち切る。1レース約3〜4秒)
SLEEP_SEC   = 0.3   # 自サーバーへの内部リクエストなので短くてよい
JST         = dt.timezone(dt.timedelta(hours=9))


def write_summary(text: str) -> None:
    path = os.environ.get('GITHUB_STEP_SUMMARY')
    if path:
        with open(path, 'a', encoding='utf-8') as f:
            f.write(text)


def upcoming_races(date_str: str) -> list:
    """当日の全レースのうち、締切まで 0〜WINDOW_MIN 分のもの(締切の近い順)"""
    res = requests.get(API_PENDING, params={'api_key': API_KEY, 'date': date_str, 'all': '1'}, timeout=30)
    res.raise_for_status()
    data = res.json()
    if 'error' in data:
        raise RuntimeError(f'get_pending_races.php error: {data["error"]}')
    races = []
    for r in data.get('races', []):
        m = r.get('minutes_until_deadline')
        if m is None:
            continue
        m = int(m)
        if 0 <= m <= WINDOW_MIN:
            races.append({**r, 'minutes_until_deadline': m})
    return sorted(races, key=lambda r: r['minutes_until_deadline'])


def refresh_odds(date_str: str, venue: str, race_no: int) -> bool:
    """生成前に3連単オッズを取得して保存する(バランス・一撃重視のオッズ条件用)。失敗しても生成は続ける"""
    jcd = VENUE_TO_JCD.get(venue)
    if not jcd:
        return False
    try:
        odds = scrape_odds(jcd, race_no, date_str.replace('-', ''))
        time.sleep(ODDS_SLEEP_SEC)
        if not odds:
            print(f'  [{venue}] {race_no}R オッズ: データなし')
            return False
        return send_odds(date_str, venue, race_no, odds)
    except Exception as e:
        print(f'  [{venue}] {race_no}R [ODDS ERROR] {e}')
        return False


def generate(date_str: str, venue: str, race_no: int) -> str:
    """api_predict.php を呼ぶ。'ok' / 'skip'(出走表なし等) / 'error'"""
    for attempt in range(2):
        try:
            res = requests.get(API_PREDICT, params={'date': date_str, 'venue': venue, 'race_no': race_no}, timeout=30)
            res.raise_for_status()
            data = res.json()
            if 'error' in data:
                print(f'  [{venue}] {race_no}R [SKIP] {data["error"]}')
                return 'skip'
            return 'ok'
        except Exception as e:
            print(f'  [{venue}] {race_no}R [ERROR] {e}' + (' (再試行)' if attempt == 0 else ''))
            time.sleep(2)
    return 'error'


def main() -> int:
    if not API_KEY:
        print('[ERROR] API_KEY が未設定です')
        return 1
    date_str = dt.datetime.now(JST).strftime('%Y-%m-%d')
    print(f'[pregenerate_upcoming] {date_str} 締切まで0〜{WINDOW_MIN}分のレース')
    races = upcoming_races(date_str)
    print(f'  対象 {len(races)}レース')

    start = time.time()
    counts = {'ok': 0, 'skip': 0, 'error': 0}
    odds_ok = 0
    for r in races:
        if time.time() - start >= BUDGET_SEC:
            print(f'  [予算超過] {sum(counts.values())}/{len(races)}件で打ち切り')
            break
        if refresh_odds(date_str, r['venue'], int(r['race_no'])):
            odds_ok += 1
        result = generate(date_str, r['venue'], int(r['race_no']))
        counts[result] += 1
        print(f'  [{r["venue"]}] {r["race_no"]}R 締切{r["minutes_until_deadline"]}分前 {result}')
        time.sleep(SLEEP_SEC)

    summary = (f'### 締切前の予想・買い目生成 {date_str}\n\n'
               f'対象 {len(races)}R(締切まで0〜{WINDOW_MIN}分) / オッズ取得 {odds_ok} / 生成 {counts["ok"]} / '
               f'スキップ {counts["skip"]} / エラー {counts["error"]}\n')
    print(summary)
    write_summary(summary)
    # 1件でも生成できていれば成功扱い(全件エラーのときだけ失敗にして気づけるようにする)
    return 1 if races and counts['ok'] == 0 and counts['error'] > 0 else 0


if __name__ == '__main__':
    sys.exit(main())
