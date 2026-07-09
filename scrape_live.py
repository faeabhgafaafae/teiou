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
SLEEP_SEC      = 3

VENUE_TO_JCD = {v: k for k, v in VENUES.items()}
ODDS_SLEEP_SEC = 1


def get_pending_races() -> list:
    """締切60分前〜締切5分後のレース一覧を取得する（絞り込みはAPI側で実施）"""
    res = requests.get(API_PENDING, params={
        'api_key': API_KEY,
    }, timeout=15)
    res.raise_for_status()
    data = res.json()
    if 'error' in data:
        raise RuntimeError(f'get_pending_races error: {data["error"]}')
    races = data.get('races', [])

    # API側でもソート済みだが、念のためここでも
    # 「exhibit_time/start_timing未取得(needs_scrape)」→「締切が近い順」で並べ直す。
    # timeout-minutesに引っかかって処理が途中で切れても、優先度の高いレースから確実に処理されるようにする。
    races.sort(key=lambda r: (
        not r.get('needs_scrape', True),
        r.get('minutes_until_deadline') if r.get('minutes_until_deadline') is not None else 9999,
    ))
    return races


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


# ─── 2フェーズ制のタイムバジェット ─────────────────────
# ワークフローの timeout-minutes: 15 (900秒) に対して、
# フェーズ1(直前情報)5分 + フェーズ2(オッズ)9分 = 14分とし、
# GitHub Actionsのcheckout/pip install等のセットアップ余白を1分確保する。
PHASE1_BUDGET_SEC = 300   # 直前情報: 約5分
PHASE2_BUDGET_SEC = 540   # オッズ: 約9分

# 優先度設計③: オッズがこの分数以内に更新済みなら、締切間際でない限り再取得をスキップする
ODDS_SKIP_RECENT_MIN = 5
# 締切までこの分数以内のレースは、直近更新済みでも必ず再取得する(価値が最も高いため)
ODDS_URGENT_DEADLINE_MIN = 15


def _race_label(race) -> str:
    sched = race.get('scheduled_time', '??:??')
    mins  = race.get('minutes_until_deadline')
    label = f'締切{sched}'
    if mins is not None:
        label += f' (残{mins}分=直前)' if mins <= 10 else f' (残{mins}分)'
    return f'[{race["venue"]}] {int(race["race_no"])}R ({label})'


def _scrape_beforeinfo_for_race(race) -> bool:
    """直前情報を1レース分取得・送信する。成功したらTrueを返す"""
    venue   = race['venue']
    race_no = int(race['race_no'])
    jcd     = VENUE_TO_JCD.get(venue)

    print(f'\n  {_race_label(race)}')

    if not jcd:
        print(f'    [SKIP] 会場コード不明: {venue}')
        return False

    hd = race['date'].replace('-', '')

    try:
        data = scrape_beforeinfo(jcd, race_no, hd)
        ok = False
        if data and data['players']:
            print(f'    直前情報: {len(data["players"])}選手', end=' ')
            ok = send_data(data)
        else:
            print('    直前情報: データなし')
        time.sleep(SLEEP_SEC)
        return ok
    except Exception as e:
        print(f'    [ERROR] {e}')
        return False


def _scrape_odds_for_race(race) -> bool:
    """オッズ(3連単 + 他券種)を1レース分取得・送信する。成功したらTrueを返す"""
    date_str = race['date']
    venue    = race['venue']
    race_no  = int(race['race_no'])
    jcd      = VENUE_TO_JCD.get(venue)

    print(f'\n  {_race_label(race)}')

    if not jcd:
        print(f'    [SKIP] 会場コード不明: {venue}')
        return False

    hd = date_str.replace('-', '')

    try:
        odds = scrape_odds(jcd, race_no, hd)
        time.sleep(ODDS_SLEEP_SEC)
        odds_multi = scrape_all_odds(jcd, race_no, hd)
        ok = False
        if odds:
            print(f'    オッズ: {len(odds)}通り', end=' ')
            ok = send_odds(date_str, venue, race_no, odds, odds_multi)
        else:
            print('    オッズ: データなし')
        time.sleep(SLEEP_SEC)
        return ok
    except Exception as e:
        print(f'    [ERROR] {e}')
        return False


def _should_skip_odds(race) -> bool:
    """優先度設計③: 直近更新済み(かつ締切間際でない)レースはオッズ再取得をスキップする"""
    mins_since_update = race.get('minutes_since_odds_update')
    if mins_since_update is None:
        return False  # 未取得(NULL)は必ず取得する

    mins_until_deadline = race.get('minutes_until_deadline')
    is_urgent = mins_until_deadline is not None and mins_until_deadline <= ODDS_URGENT_DEADLINE_MIN
    if is_urgent:
        return False  # 締切間際は直近更新済みでも必ず再取得する

    return mins_since_update < ODDS_SKIP_RECENT_MIN


def main():
    import scrape_boatrace
    scrape_boatrace.API_URL = API_BEFOREINFO
    scrape_boatrace.API_KEY = API_KEY

    print('[scrape_live] 対象レース取得中...')
    try:
        races = get_pending_races()
    except Exception as e:
        print(f'[ERROR] 対象レース取得失敗: {e}')
        return

    print(f'  対象: {len(races)}レース')
    if not races:
        print('  対象レースなし')
        return

    # ─── フェーズ1: 直前情報 (needs_scrape優先ソートのまま、予算5分) ───
    print(f'\n[フェーズ1] 直前情報取得 (予算{PHASE1_BUDGET_SEC}秒)')
    phase1_start = time.time()
    before_ok = 0
    phase1_processed = 0
    for race in races:
        if time.time() - phase1_start >= PHASE1_BUDGET_SEC:
            print(f'  [予算超過] フェーズ1を打ち切り ({phase1_processed}/{len(races)}件処理)')
            break
        if _scrape_beforeinfo_for_race(race):
            before_ok += 1
        phase1_processed += 1

    # ─── フェーズ2: オッズ (締切近接順に再ソート、予算9分) ───
    # needs_scrapeを無視し、締切に近いレースほど優先して再取得する(優先度設計①)
    odds_races = sorted(
        races,
        key=lambda r: r.get('minutes_until_deadline') if r.get('minutes_until_deadline') is not None else 9999,
    )

    print(f'\n[フェーズ2] オッズ取得 (予算{PHASE2_BUDGET_SEC}秒)')
    phase2_start = time.time()
    odds_ok = 0
    odds_skipped = 0
    phase2_processed = 0
    for race in odds_races:
        if time.time() - phase2_start >= PHASE2_BUDGET_SEC:
            print(f'  [予算超過] フェーズ2を打ち切り ({phase2_processed}/{len(odds_races)}件処理, {odds_skipped}件スキップ)')
            break

        if _should_skip_odds(race):
            odds_skipped += 1
            continue

        if _scrape_odds_for_race(race):
            odds_ok += 1
        phase2_processed += 1

    print(
        f'\n完了! 対象{len(races)}レース中、'
        f'直前情報{before_ok}件・オッズ{odds_ok}件 成功 '
        f'(オッズ{odds_skipped}件は直近更新のためスキップ)'
    )


if __name__ == '__main__':
    main()
