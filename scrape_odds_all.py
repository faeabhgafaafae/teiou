#!/usr/bin/env python3
"""
艇王 - 当日全レースのオッズ一括取得
get_pending_races.php?all=1 で当日の全レースを取得し、
各レースのオッズ(odds3t)をスクレイピングしてAPIに送信する
"""

import os
import time
import signal
import requests

from scrape_boatrace import VENUES
from scrape_live import (
    API_PENDING, API_KEY, SLEEP_SEC, ODDS_SLEEP_SEC,
    VENUE_TO_JCD, scrape_odds, scrape_all_odds, send_odds,
)

# 1レースあたりの処理時間の上限。boatrace.jp側の応答が遅延・エラーを繰り返すと
# fetch()のリトライが重なって1レースが異常に長引くことがあるため、
# signal.alarmでブロッキング中の通信呼び出し自体を強制中断し、次のレースへ進める。
# (GitHub Actionsのubuntu-latestランナー=Unix環境が前提。Windows等では動作しない)
RACE_TIMEOUT_SEC = 60


class RaceTimeoutError(Exception):
    pass


def _alarm_handler(signum, frame):
    raise RaceTimeoutError()


def get_all_races() -> list:
    res = requests.get(API_PENDING, params={
        'api_key': API_KEY,
        'all':     '1',
    }, timeout=15)
    res.raise_for_status()
    data = res.json()
    if 'error' in data:
        raise RuntimeError(f'get_all_races error: {data["error"]}')
    return data.get('races', [])


def main():
    print('[scrape_odds_all] 当日全レースのオッズ取得開始...')
    try:
        races = get_all_races()
    except Exception as e:
        print(f'[ERROR] レース一覧取得失敗: {e}')
        return

    print(f'  対象: {len(races)}レース')
    if not races:
        print('  対象レースなし')
        return

    odds_ok = 0
    total = len(races)

    for race in races:
        date_str = race['date']
        venue    = race['venue']
        race_no  = int(race['race_no'])
        jcd      = VENUE_TO_JCD.get(venue)

        print(f'\n  [{venue}] {race_no}R', end='')

        if not jcd:
            print(f' [SKIP] 会場コード不明: {venue}')
            continue

        hd = date_str.replace('-', '')

        race_start = time.time()
        signal.signal(signal.SIGALRM, _alarm_handler)
        signal.alarm(RACE_TIMEOUT_SEC)
        try:
            odds = scrape_odds(jcd, race_no, hd)
            time.sleep(ODDS_SLEEP_SEC)
            odds_multi = scrape_all_odds(jcd, race_no, hd)
            if odds:
                print(f' オッズ: {len(odds)}通り', end=' ')
                if send_odds(date_str, venue, race_no, odds, odds_multi):
                    odds_ok += 1
            else:
                print(f' オッズ: データなし')
        except RaceTimeoutError:
            elapsed = time.time() - race_start
            print(f' [TIMEOUT SKIP] {elapsed:.0f}秒経過のため打ち切り')
        except Exception as e:
            print(f' [ERROR] {e}')
        finally:
            signal.alarm(0)

        time.sleep(SLEEP_SEC)

    print(f'\n完了! 対象{total}レース中、オッズ{odds_ok}件 成功')


if __name__ == '__main__':
    main()
