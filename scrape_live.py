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
ODDS_SLEEP_SEC = 1


def get_pending_races() -> list:
    res = requests.get(API_PENDING, params={
        'api_key': API_KEY,
        'within':  WITHIN,
    }, timeout=15)
    res.raise_for_status()
    data = res.json()
    if 'error' in data:
        raise RuntimeError(f'get_pending_races error: {data["error"]}')
    races = data.get('races', [])
    # -20 <= minutes_until_deadline <= 30 のレースのみ対象
    return [r for r in races if -20 <= r.get('minutes_until_deadline', 999) <= 30]


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


def _expand_grid(table) -> list:
    """rowspanを考慮してtbody内の<tr>をrow x colの密な二次元配列に展開する"""
    ncols = len(table.find_all('colgroup'))
    tbody = table.find('tbody')
    if not tbody or ncols == 0:
        return []

    grid = []
    pending = {}
    for tr in tbody.find_all('tr'):
        tds = iter(tr.find_all('td'))
        row = []
        for col in range(ncols):
            if col in pending:
                text, classes, remaining = pending[col]
                row.append((text, classes))
                if remaining - 1 <= 0:
                    del pending[col]
                else:
                    pending[col] = (text, classes, remaining - 1)
                continue
            td = next(tds, None)
            if td is None:
                row.append(('', []))
                continue
            text = td.get_text(strip=True)
            classes = td.get('class') or []
            rowspan = int(td.get('rowspan', 1) or 1)
            row.append((text, classes))
            if rowspan > 1:
                pending[col] = (text, classes, rowspan - 1)
        grid.append(row)
    return grid


def _fetch_odds_page(path: str, jcd: str, rno: int, hd: str):
    url = f'{BASE_URL}/owpc/pc/race/{path}?rno={rno}&jcd={jcd}&hd={hd}'
    res = fetch(url, timeout=30)
    return BeautifulSoup(res.text, 'html.parser')


def scrape_odds3f(jcd: str, rno: int, hd: str) -> dict | None:
    """3連複オッズ"""
    try:
        soup = _fetch_odds_page('odds3f', jcd, rno, hd)
    except Exception as e:
        print(f'    [ODDS3F ERROR] {e}')
        return None

    tables = soup.find_all('table')
    if len(tables) < 2:
        print('    [ODDS3F ERROR] オッズテーブルが見つかりません')
        return None

    grid = _expand_grid(tables[1])
    odds = {}
    for row in grid:
        for g in range(6):
            base = g * 3
            if base + 2 >= len(row):
                continue
            second_cell, third_cell, odds_cell = row[base], row[base + 1], row[base + 2]
            if 'is-disabled' in odds_cell[1]:
                continue
            try:
                nums = sorted([g + 1, int(second_cell[0]), int(third_cell[0])])
                val = float(odds_cell[0])
            except ValueError:
                continue
            odds['-'.join(str(n) for n in nums)] = val
    return odds


def scrape_oddsk(jcd: str, rno: int, hd: str) -> dict | None:
    """拡連複オッズ（値は "1.1-1.3" のようなレンジ文字列）"""
    try:
        soup = _fetch_odds_page('oddsk', jcd, rno, hd)
    except Exception as e:
        print(f'    [ODDSK ERROR] {e}')
        return None

    tables = soup.find_all('table')
    if len(tables) < 2:
        print('    [ODDSK ERROR] オッズテーブルが見つかりません')
        return None

    grid = _expand_grid(tables[1])
    odds = {}
    for row in grid:
        for g in range(6):
            base = g * 2
            if base + 1 >= len(row):
                continue
            num_cell, odds_cell = row[base], row[base + 1]
            if 'is-disabled' in odds_cell[1]:
                continue
            try:
                nums = sorted([g + 1, int(num_cell[0])])
            except ValueError:
                continue
            if not odds_cell[0]:
                continue
            odds['-'.join(str(n) for n in nums)] = odds_cell[0]
    return odds


def scrape_odds2tf(jcd: str, rno: int, hd: str):
    """2連単・2連複オッズ。(rentan2, renfuku2) のタプルを返す"""
    try:
        soup = _fetch_odds_page('odds2tf', jcd, rno, hd)
    except Exception as e:
        print(f'    [ODDS2TF ERROR] {e}')
        return None

    tables = soup.find_all('table')
    if len(tables) < 3:
        print('    [ODDS2TF ERROR] オッズテーブルが見つかりません')
        return None

    rentan2 = {}
    for row in _expand_grid(tables[1]):
        for g in range(6):
            base = g * 2
            if base + 1 >= len(row):
                continue
            num_cell, odds_cell = row[base], row[base + 1]
            if 'is-disabled' in odds_cell[1]:
                continue
            try:
                second = int(num_cell[0])
                val = float(odds_cell[0])
            except ValueError:
                continue
            rentan2[f'{g + 1}-{second}'] = val

    renfuku2 = {}
    for row in _expand_grid(tables[2]):
        for g in range(6):
            base = g * 2
            if base + 1 >= len(row):
                continue
            num_cell, odds_cell = row[base], row[base + 1]
            if 'is-disabled' in odds_cell[1]:
                continue
            try:
                nums = sorted([g + 1, int(num_cell[0])])
                val = float(odds_cell[0])
            except ValueError:
                continue
            renfuku2['-'.join(str(n) for n in nums)] = val

    return rentan2, renfuku2


def scrape_oddstf(jcd: str, rno: int, hd: str):
    """単勝・複勝オッズ。(tansho, fukusho) のタプルを返す（複勝はレンジ文字列）"""
    try:
        soup = _fetch_odds_page('oddstf', jcd, rno, hd)
    except Exception as e:
        print(f'    [ODDSTF ERROR] {e}')
        return None

    tables = soup.find_all('table')
    if len(tables) < 3:
        print('    [ODDSTF ERROR] オッズテーブルが見つかりません')
        return None

    tansho = {}
    for tbody in tables[1].find_all('tbody'):
        tr = tbody.find('tr')
        if not tr:
            continue
        tds = tr.find_all('td')
        if len(tds) < 3:
            continue
        try:
            boat = int(tds[0].get_text(strip=True))
            val = float(tds[2].get_text(strip=True))
        except ValueError:
            continue
        tansho[str(boat)] = val

    fukusho = {}
    for tbody in tables[2].find_all('tbody'):
        tr = tbody.find('tr')
        if not tr:
            continue
        tds = tr.find_all('td')
        if len(tds) < 3:
            continue
        try:
            boat = int(tds[0].get_text(strip=True))
        except ValueError:
            continue
        val = tds[2].get_text(strip=True)
        if not val:
            continue
        fukusho[str(boat)] = val

    return tansho, fukusho


def scrape_all_odds(jcd: str, rno: int, hd: str) -> dict:
    """3連単以外の全券種オッズをまとめて取得する"""
    result = {}

    sanrenfuku = scrape_odds3f(jcd, rno, hd)
    if sanrenfuku:
        result['sanrenfuku'] = sanrenfuku
    time.sleep(ODDS_SLEEP_SEC)

    tf2 = scrape_odds2tf(jcd, rno, hd)
    if tf2:
        rentan2, renfuku2 = tf2
        if rentan2:
            result['rentan2'] = rentan2
        if renfuku2:
            result['renfuku2'] = renfuku2
    time.sleep(ODDS_SLEEP_SEC)

    kakurenku = scrape_oddsk(jcd, rno, hd)
    if kakurenku:
        result['kakurenku'] = kakurenku
    time.sleep(ODDS_SLEEP_SEC)

    tf = scrape_oddstf(jcd, rno, hd)
    if tf:
        tansho, fukusho = tf
        if tansho:
            result['tansho'] = tansho
        if fukusho:
            result['fukusho'] = fukusho

    return result


def send_odds(date_str: str, venue: str, race_no: int, odds: dict, odds_multi: dict | None = None) -> bool:
    try:
        payload = {
            'date':    date_str,
            'venue':   venue,
            'race_no': race_no,
            'odds':    odds,
        }
        if odds_multi:
            payload['odds_multi'] = odds_multi

        res = requests.post(API_ODDS, json={
            'api_key': API_KEY,
            'data': payload,
        }, timeout=30)
        result = res.json()
        if result.get('error'):
            print(f'    [ODDS API ERROR] {result["error"]}')
            return False
        ok = result.get('ok', 0)
        ok_multi = result.get('ok_multi', 0)
        errors = result.get('errors', [])
        print(f'    → オッズ {ok}件登録 (他券種{ok_multi}件)', end='')
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
        mins      = race.get('minutes_until_deadline')
        jcd       = VENUE_TO_JCD.get(venue)

        label = f'締切{sched}'
        if mins is not None:
            if mins < 0:
                label += f' (締切後{abs(mins)}分=確定オッズ)'
            elif mins <= 10:
                label += f' (残{mins}分=直前)'
            else:
                label += f' (残{mins}分)'
        print(f'\n  [{venue}] {race_no}R ({label})')

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

            # オッズ(3連単 + 他券種)
            odds = scrape_odds(jcd, race_no, hd)
            time.sleep(ODDS_SLEEP_SEC)
            odds_multi = scrape_all_odds(jcd, race_no, hd)
            if odds:
                print(f'    オッズ: {len(odds)}通り', end=' ')
                if send_odds(date_str, venue, race_no, odds, odds_multi):
                    odds_ok += 1
            else:
                print(f'    オッズ: データなし')

            time.sleep(SLEEP_SEC)

        except Exception as e:
            print(f'    [ERROR] {e}')

    print(f'\n完了! 対象{total}レース中、直前情報{before_ok}件・オッズ{odds_ok}件 成功')


if __name__ == '__main__':
    main()
