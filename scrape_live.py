#!/usr/bin/env python3
"""
艇王 - 締切間近レースの直前情報・オッズ軽量取得
get_pending_races.php で対象レースを絞り込み、
直前情報(beforeinfo)とオッズ(odds3t)を取得してAPIに送信する
"""

import os
import time
import requests
from bs4 import BeautifulSoup

from scrape_boatrace import (
    BASE_URL, HEADERS, VENUES,
    fetch, scrape_beforeinfo, send_data,
)

# ─── 設定 ──────────────────────────────────────────────
API_PENDING    = os.environ.get('API_PENDING',    'https://2410049.moo.jp/get_pending_races.php')
API_BEFOREINFO = os.environ.get('API_URL',        'https://2410049.moo.jp/import_beforeinfo.php')
API_ODDS       = os.environ.get('API_ODDS',       'https://2410049.moo.jp/import_odds.php')
API_KEY        = os.environ.get('API_KEY',         'teio2025')
WITHIN         = int(os.environ.get('WITHIN',      '40'))
SLEEP_SEC      = 3

VENUE_TO_JCD = {v: k for k, v in VENUES.items()}


def get_pending_races() -> list:
    res = requests.get(API_PENDING, params={
        'api_key': API_KEY,
        'within':  WITHIN,
    }, timeout=15)
    res.raise_for_status()
    data = res.json()
    if 'error' in data:
        raise RuntimeError(f'get_pending_races error: {data["error"]}')
    return data.get('races', [])


def scrape_odds(jcd: str, rno: int, hd: str) -> dict | None:
    url = f'{BASE_URL}/owpc/pc/race/odds3t?rno={rno}&jcd={jcd}&hd={hd}'
    try:
        res = fetch(url, timeout=30)
        soup = BeautifulSoup(res.text, 'html.parser')
    except Exception as e:
        print(f'    [ODDS ERROR] {e}')
        return None

    tables = soup.find_all('table')
    if len(tables) < 2:
        print('    [ODDS ERROR] オッズテーブルが見つかりません')
        return None

    odds_table = tables[1]

    thead = odds_table.find('thead')
    first_boats = []
    for th in thead.find_all('th'):
        cls = th.get('class', [])
        if any('is-boatColor' in c for c in cls) and 'is-borderLeftNone' not in cls:
            try:
                first_boats.append(int(th.get_text(strip=True)))
            except ValueError:
                pass
    if len(first_boats) != 6:
        print(f'    [ODDS ERROR] 1着ヘッダ数が不正: {len(first_boats)}')
        return None

    tbody = odds_table.find('tbody')
    if not tbody:
        print('    [ODDS ERROR] tbody が見つかりません')
        return None

    trs = tbody.find_all('tr')
    if len(trs) != 20:
        print(f'    [ODDS ERROR] 行数が不正: {len(trs)} (期待: 20)')
        return None

    odds = {}
    for block_start in range(0, 20, 4):
        block_trs = trs[block_start:block_start + 4]
        first_row_tds = block_trs[0].find_all('td')

        rs4_second_boats = []
        for td in first_row_tds:
            if td.get('rowspan') == '4':
                try:
                    rs4_second_boats.append(int(td.get_text(strip=True)))
                except ValueError:
                    rs4_second_boats.append(None)

        for row_offset, tr in enumerate(block_trs):
            all_tds = tr.find_all('td')
            if row_offset == 0:
                for grp in range(6):
                    base = grp * 3
                    if base + 2 >= len(all_tds):
                        continue
                    second = int(all_tds[base].get_text(strip=True))
                    third = int(all_tds[base + 1].get_text(strip=True))
                    odds_val = all_tds[base + 2].get_text(strip=True)
                    combo = f'{first_boats[grp]}-{second}-{third}'
                    try:
                        odds[combo] = float(odds_val)
                    except ValueError:
                        odds[combo] = odds_val
            else:
                for grp in range(6):
                    base = grp * 2
                    if base + 1 >= len(all_tds):
                        continue
                    third = int(all_tds[base].get_text(strip=True))
                    odds_val = all_tds[base + 1].get_text(strip=True)
                    second = rs4_second_boats[grp]
                    combo = f'{first_boats[grp]}-{second}-{third}'
                    try:
                        odds[combo] = float(odds_val)
                    except ValueError:
                        odds[combo] = odds_val

    return odds


def send_odds(date_str: str, venue: str, race_no: int, odds: dict) -> bool:
    try:
        res = requests.post(API_ODDS, json={
            'api_key': API_KEY,
            'data': {
                'date':    date_str,
                'venue':   venue,
                'race_no': race_no,
                'odds':    odds,
            },
        }, timeout=30)
        result = res.json()
        if result.get('error'):
            print(f'    [ODDS API ERROR] {result["error"]}')
            return False
        ok = result.get('ok', 0)
        errors = result.get('errors', [])
        print(f'    → オッズ {ok}件登録', end='')
        if errors:
            print(f' (エラー{len(errors)}件)')
        else:
            print()
        return True
    except Exception as e:
        print(f'    [ODDS SEND ERROR] {e}')
        return False


def main():
    import scrape_boatrace
    scrape_boatrace.API_URL = API_BEFOREINFO
    scrape_boatrace.API_KEY = API_KEY

    print(f'[scrape_live] 対象レース取得中 (within={WITHIN}分)...')
    try:
        races = get_pending_races()
    except Exception as e:
        print(f'[ERROR] 対象レース取得失敗: {e}')
        return

    print(f'  対象: {len(races)}レース')
    if not races:
        print('  対象レースなし')
        return

    before_ok = 0
    odds_ok = 0
    total = len(races)

    for race in races:
        date_str  = race['date']
        venue     = race['venue']
        race_no   = int(race['race_no'])
        sched     = race.get('scheduled_time', '??:??')
        jcd       = VENUE_TO_JCD.get(venue)

        print(f'\n  [{venue}] {race_no}R (締切{sched})')

        if not jcd:
            print(f'    [SKIP] 会場コード不明: {venue}')
            continue

        hd = date_str.replace('-', '')

        try:
            # 直前情報
            data = scrape_beforeinfo(jcd, race_no, hd)
            if data and data['players']:
                print(f'    直前情報: {len(data["players"])}選手', end=' ')
                if send_data(data):
                    before_ok += 1
            else:
                print(f'    直前情報: データなし')

            time.sleep(SLEEP_SEC)

            # オッズ
            odds = scrape_odds(jcd, race_no, hd)
            if odds:
                print(f'    オッズ: {len(odds)}通り', end=' ')
                if send_odds(date_str, venue, race_no, odds):
                    odds_ok += 1
            else:
                print(f'    オッズ: データなし')

            time.sleep(SLEEP_SEC)

        except Exception as e:
            print(f'    [ERROR] {e}')

    print(f'\n完了! 対象{total}レース中、直前情報{before_ok}件・オッズ{odds_ok}件 成功')


if __name__ == '__main__':
    main()
