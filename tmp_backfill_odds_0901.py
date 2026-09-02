#!/usr/bin/env python3
"""
バックフィル: 2026-09-01分の odds_3t 再取得(使用後削除予定)
2026-09-01の出走表・直前情報取得ジョブがDB接続タイムアウトで失敗し、
当日オッズが1件も取得できていなかったための復旧スクリプト。
tmp_get_races_by_date.php から対象レース一覧を取得し、
boatrace.jp から odds3t を再スクレイピングして import_odds.php へ送信する。
"""

import os
import re
import sys
import time
import signal
import requests

from scrape_live import (
    API_PENDING, API_KEY, SLEEP_SEC,
    VENUE_TO_JCD, scrape_odds, scrape_all_odds, send_odds,
)

TARGET_DATE = '2026-09-01'

_base          = re.sub(r'/[^/]+$', '', API_PENDING)
API_RACES_BY_DATE = f'{_base}/tmp_get_races_by_date.php'

RACE_TIMEOUT_SEC = 60
HAS_ALARM = hasattr(signal, 'SIGALRM')  # Windowsでは未対応のためガードする


class RaceTimeoutError(Exception):
    pass


def _alarm_handler(signum, frame):
    raise RaceTimeoutError()


def get_races_by_date(date_str: str) -> list:
    res = requests.get(API_RACES_BY_DATE, params={'api_key': API_KEY, 'date': date_str}, timeout=15)
    res.raise_for_status()
    data = res.json()
    if 'error' in data:
        raise RuntimeError(f'get_races_by_date error: {data["error"]}')
    return data.get('races', [])


def main():
    print(f'[backfill] {TARGET_DATE} 対象レース一覧取得中...')
    try:
        races = get_races_by_date(TARGET_DATE)
    except Exception as e:
        print(f'[ERROR] レース一覧取得失敗: {e}')
        sys.exit(1)

    total = len(races)
    print(f'  対象: {total}レース\n')
    if not races:
        print('  対象レースなし。終了。')
        return

    odds_ok = 0
    odds_fail = 0

    for i, race in enumerate(races, 1):
        date_str = race['date']
        venue    = race['venue']
        race_no  = int(race['race_no'])
        jcd      = VENUE_TO_JCD.get(venue)
        hd       = date_str.replace('-', '')

        print(f'[{i:>3}/{total}] {date_str} {venue} {race_no:>2}R', end='', flush=True)

        if not jcd:
            print('  [SKIP] 会場コード不明')
            continue

        if HAS_ALARM:
            signal.signal(signal.SIGALRM, _alarm_handler)
            signal.alarm(RACE_TIMEOUT_SEC)
        try:
            odds = scrape_odds(jcd, race_no, hd)
            time.sleep(1)
            odds_multi = scrape_all_odds(jcd, race_no, hd)
            if odds:
                print(f'  {len(odds)}通り', end='', flush=True)
                if send_odds(date_str, venue, race_no, odds, odds_multi):
                    odds_ok += 1
                else:
                    print('  [SEND FAIL]', end='')
                    odds_fail += 1
            else:
                print('  データなし', end='')
                odds_fail += 1
        except RaceTimeoutError:
            print('  [TIMEOUT]', end='')
            odds_fail += 1
        except Exception as e:
            print(f'  [ERROR] {e}', end='')
            odds_fail += 1
        finally:
            if HAS_ALARM:
                signal.alarm(0)

        print()
        time.sleep(SLEEP_SEC)

    print('\n--- オッズ取得完了 ---')
    print(f'成功: {odds_ok}件 / 失敗: {odds_fail}件 / 合計: {total}件')

    if odds_ok == 0 and total > 0:
        sys.exit(1)


if __name__ == '__main__':
    main()
