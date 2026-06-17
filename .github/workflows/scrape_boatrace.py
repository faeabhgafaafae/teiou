#!/usr/bin/env python3
"""
艇王 - boatrace.jp スクレイピング
当日の直前情報（展示タイム・ST・気象）を取得してDBに登録する

使い方:
  # 今日の全場・全レースを取得
  python scrape_boatrace.py

  # 場コード指定
  python scrape_boatrace.py --jcd 01

  # 日付指定
  python scrape_boatrace.py --date 20260617
"""

import re
import sys
import time
import argparse
import requests
from datetime import date
from bs4 import BeautifulSoup

# ─── 設定 ──────────────────────────────────────────────
API_URL  = os.environ.get('API_URL', 'https://2410049.moo.jp/import_beforeinfo.php')
API_KEY  = os.environ.get('API_KEY', 'teio2025')
SLEEP_SEC = 3

BASE_URL = 'https://www.boatrace.jp'

# 場コードマスタ
VENUES = {
    '01': '桐生', '02': '戸田', '03': '江戸川', '04': '平和島',
    '05': '多摩川', '06': '浜名湖', '07': '蒲郡', '08': '常滑',
    '09': '津',   '10': '三国', '11': '琵琶湖', '12': '住之江',
    '13': '尼崎', '14': '鳴門', '15': '高松', '16': '丸亀',
    '17': '児島', '18': '宮島', '19': '徳山', '20': '下関',
    '21': '若松', '22': '芦屋', '23': '福岡', '24': '唐津',
    '25': '大村',
}

HEADERS = {
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
}


# ─── 今日の開催場を取得 ────────────────────────────────
def get_today_venues(hd: str) -> list[str]:
    """本日のレース一覧ページから開催場コードを取得"""
    url = f'{BASE_URL}/owpc/pc/race/index'
    res = requests.get(url, headers=HEADERS, timeout=10)
    soup = BeautifulSoup(res.text, 'html.parser')

    jcds = []
    # 出走表リンクからjcdを抽出
    for a in soup.find_all('a', href=True):
        m = re.search(r'jcd=(\d{2})', a['href'])
        if m:
            jcd = m.group(1)
            if jcd not in jcds:
                jcds.append(jcd)
    return jcds


# ─── 直前情報スクレイピング ────────────────────────────
def scrape_beforeinfo(jcd: str, rno: int, hd: str) -> dict | None:
    """1レース分の直前情報を取得"""
    url = f'{BASE_URL}/owpc/pc/race/beforeinfo?rno={rno}&jcd={jcd}&hd={hd}'
    try:
        res = requests.get(url, headers=HEADERS, timeout=10)
        if res.status_code != 200:
            return None
        soup = BeautifulSoup(res.text, 'html.parser')
    except Exception as e:
        print(f'    [ERROR] {e}')
        return None

    result = {
        'jcd':      jcd,
        'venue':    VENUES.get(jcd, jcd),
        'race_no':  rno,
        'date':     f'{hd[:4]}-{hd[4:6]}-{hd[6:]}',
        'players':  [],
        'weather':  {},
        'start_exhibition': [],
    }

    # ── 選手ごとの展示タイム・チルト ──
    for tbody in soup.select('table.is-w748 tbody'):
        tds = tbody.find_all('td')
        if not tds:
            continue

        # 枠番
        waku_td = tbody.select_one('td[class*="is-boatColor"]')
        if not waku_td:
            continue
        try:
            waku = int(waku_td.get_text(strip=True))
        except:
            continue

        # 登番
        player_link = tbody.select_one('a[href*="toban="]')
        if not player_link:
            continue
        m = re.search(r'toban=(\d+)', player_link['href'])
        if not m:
            continue
        player_id = int(m.group(1))

        # 展示タイム・チルト（rowspan=4のtd群）
        all_tds = tbody.find_all('td')
        exhibit_time = None
        chilt = None

        # 展示タイムはrowspan=4のtd[4]
        rowspan4 = [td for td in all_tds if td.get('rowspan') == '4']
        if len(rowspan4) >= 4:
            try:
                exhibit_time = float(rowspan4[3].get_text(strip=True))
            except:
                pass
            try:
                chilt = float(rowspan4[4].get_text(strip=True))
            except:
                pass

        result['players'].append({
            'waku':         waku,
            'player_id':    player_id,
            'exhibit_time': exhibit_time,
            'chilt':        chilt,
        })

    # ── スタート展示ST ──
    for span in soup.select('.table1_boatImage1Time'):
        st_text = span.get_text(strip=True)
        is_f = 'F' in st_text
        try:
            st_val = float(st_text.replace('F', '')) * -1 if is_f else float('0' + st_text)
        except:
            st_val = None
        result['start_exhibition'].append({
            'st': st_val,
            'is_flying': is_f,
        })

    # ── 気象情報 ──
    weather = {}
    wind_speed = soup.select_one('.weather1_bodyUnit.is-wind .weather1_bodyUnitLabelData')
    if wind_speed:
        try:
            weather['wind_speed'] = float(wind_speed.get_text(strip=True).replace('m', ''))
        except:
            pass

    wave = soup.select_one('.weather1_bodyUnit.is-wave .weather1_bodyUnitLabelData')
    if wave:
        try:
            weather['wave_height'] = int(wave.get_text(strip=True).replace('cm', ''))
        except:
            pass

    # 風向はCSSクラスから取得 (is-wind10 など)
    wind_dir_el = soup.select_one('.weather1_bodyUnit.is-windDirection p')
    if wind_dir_el:
        m = re.search(r'is-wind(\d+)', wind_dir_el.get('class', [''])[0] if wind_dir_el.get('class') else '')
        if m:
            weather['wind_dir_code'] = int(m.group(1))

    result['weather'] = weather

    return result


# ─── API送信 ───────────────────────────────────────────
def send_data(data: dict) -> bool:
    try:
        res = requests.post(API_URL, json={
            'api_key': API_KEY,
            'data':    data,
        }, timeout=15)
        result = res.json()
        if result.get('error'):
            print(f'    [API ERROR] {result["error"]}')
            return False
        print(f'    → 登録OK')
        return True
    except Exception as e:
        print(f'    [SEND ERROR] {e}')
        return False


# ─── メイン ───────────────────────────────────────────
def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--date', default=None, help='日付 YYYYMMDD')
    parser.add_argument('--jcd',  default=None, help='場コード 01〜25')
    parser.add_argument('--rno',  default=None, type=int, help='レース番号 1〜12')
    args = parser.parse_args()

    hd = args.date or date.today().strftime('%Y%m%d')

    # 対象場を決定
    if args.jcd:
        jcds = [args.jcd]
    else:
        print(f'[{hd}] 開催場を取得中...')
        jcds = get_today_venues(hd)
        print(f'  開催場: {[VENUES.get(j, j) for j in jcds]}')
        time.sleep(SLEEP_SEC)

    for jcd in jcds:
        venue_name = VENUES.get(jcd, jcd)
        rnos = [args.rno] if args.rno else range(1, 13)

        for rno in rnos:
            print(f'  [{venue_name}] {rno}R 取得中...', end=' ')
            data = scrape_beforeinfo(jcd, rno, hd)

            if data is None or not data['players']:
                print('データなし')
                time.sleep(1)
                continue

            print(f'{len(data["players"])}選手', end=' ')
            send_data(data)
            time.sleep(SLEEP_SEC)

    print('\n完了!')


if __name__ == '__main__':
    main()
