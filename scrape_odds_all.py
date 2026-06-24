#!/usr/bin/env python3
"""
艇王 - 当日全レースのオッズ一括取得
get_pending_races.php?all=1 で当日の全レースを取得し、
各レースのオッズ(odds3t)をスクレイピングしてAPIに送信する
"""

import os
import time
import requests

from scrape_boatrace import VENUES
from scrape_live import (
    API_PENDING, API_KEY, SLEEP_SEC,
    VENUE_TO_JCD, scrape_odds, send_odds,
)

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

        try:
            odds = scrape_odds(jcd, race_no, hd)
            if odds:
                print(f' オッズ: {len(odds)}通り', end=' ')
                if send_odds(date_str, venue, race_no, odds):
                    odds_ok += 1
            else:
                print(f' オッズ: データなし')
        except Exception as e:
            print(f' [ERROR] {e}')

        time.sleep(SLEEP_SEC)

    print(f'\n完了! 対象{total}レース中、オッズ{odds_ok}件 成功')


if __name__ == '__main__':
    main()
