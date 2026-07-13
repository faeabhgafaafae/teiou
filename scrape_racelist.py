#!/usr/bin/env python3
"""
艇王 - 出走表スクレイピング
boatrace.jp の出走表から選手情報・モーター2連率・勝率を取得して
ロリポップのPHP APIに送信しDBに登録する

使い方:
  # 今日の全場を取得
  python scrape_racelist.py

  # 場コード指定
  python scrape_racelist.py --jcd 01

  # 日付指定
  python scrape_racelist.py --date 20260617
"""

import os
import re
import sys
import time
import argparse
import requests
from datetime import datetime, timezone, timedelta
from bs4 import BeautifulSoup
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

# ─── 設定 ──────────────────────────────────────────────
API_URL  = os.environ.get('API_URL_RACELIST', 'https://2410049.moo.jp/import_racelist.php')
API_KEY  = os.environ.get('API_KEY', 'teio2025')
SLEEP_SEC = 3

BASE_URL = 'https://www.boatrace.jp'

VENUES = {
    '01': '桐生', '02': '戸田', '03': '江戸川', '04': '平和島',
    '05': '多摩川', '06': '浜名湖', '07': '蒲郡', '08': '常滑',
    '09': '津',   '10': '三国', '11': '琵琶湖', '12': '住之江',
    '13': '尼崎', '14': '鳴門', '15': '丸亀', '16': '児島',
    '17': '宮島', '18': '徳山', '19': '下関', '20': '若松',
    '21': '芦屋', '22': '福岡', '23': '唐津', '24': '大村',
}

HEADERS = {
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
}


def make_session():
    session = requests.Session()
    retry = Retry(
        total=4,
        backoff_factor=1,
        status_forcelist=[429, 500, 502, 503, 504],
        allowed_methods=["GET"],
    )
    adapter = HTTPAdapter(max_retries=retry)
    session.mount("https://", adapter)
    session.mount("http://", adapter)
    return session

SESSION = make_session()

def fetch(url, *, timeout=30):
    res = SESSION.get(url, headers=HEADERS, timeout=timeout)
    res.raise_for_status()
    return res


def scrape_schedule_times(jcd: str, hd: str) -> dict[int, str]:
    """raceindexページから全レースの締切予定時刻を取得"""
    url = f'{BASE_URL}/owpc/pc/race/raceindex?jcd={jcd}&hd={hd}'
    try:
        res = fetch(url, timeout=30)
        soup = BeautifulSoup(res.text, 'html.parser')
    except Exception as e:
        print(f'    [時刻取得ERROR] {e}')
        return {}

    times = {}
    time_pattern = re.compile(r'^\d{1,2}:\d{2}$')
    race_no = 1
    for td in soup.find_all('td'):
        text = td.get_text(strip=True)
        if time_pattern.match(text) and race_no <= 12:
            times[race_no] = text
            race_no += 1

    return times


def get_today_venues(hd: str) -> list[str]:
    """本日の開催場コードを取得"""
    url = f'{BASE_URL}/owpc/pc/race/index'
    res = fetch(url, timeout=30)
    soup = BeautifulSoup(res.text, 'html.parser')
    jcds = []
    for a in soup.find_all('a', href=True):
        m = re.search(r'jcd=(\d{2})', a['href'])
        if m:
            jcd = m.group(1)
            if jcd not in jcds:
                jcds.append(jcd)
    return jcds


def scrape_racelist(jcd: str, rno: int, hd: str) -> dict | None:
    """1レース分の出走表を取得"""
    url = f'{BASE_URL}/owpc/pc/race/racelist?rno={rno}&jcd={jcd}&hd={hd}'
    try:
        res = fetch(url, timeout=30)
        soup = BeautifulSoup(res.text, 'html.parser')
    except Exception as e:
        print(f'    [ERROR] {e}')
        return None

    result = {
        'jcd':     jcd,
        'venue':   VENUES.get(jcd, jcd),
        'race_no': rno,
        'date':    f'{hd[:4]}-{hd[4:6]}-{hd[6:]}',
        'players': [],
    }

    # 各選手のtbodyを処理
    for tbody in soup.select('table tbody'):
        tds = tbody.find_all('td', recursive=False)
        if not tds:
            trs = tbody.find_all('tr')
            if not trs:
                continue
            # 枠番チェック
            waku_td = tbody.select_one('td[class*="is-boatColor"]')
            if not waku_td:
                continue
            try:
                waku = int(waku_td.get_text(strip=True).replace('１','1').replace('２','2')
                           .replace('３','3').replace('４','4').replace('５','5').replace('６','6'))
            except:
                # 全角数字変換
                text = waku_td.get_text(strip=True)
                num_map = {'１':1,'２':2,'３':3,'４':4,'５':5,'６':6}
                waku = num_map.get(text)
                if not waku:
                    continue

            # 登番
            player_link = tbody.select_one('a[href*="toban="]')
            if not player_link:
                continue
            m = re.search(r'toban=(\d+)', player_link['href'])
            if not m:
                continue
            player_id = int(m.group(1))

            # rowspan=4のtdを取得（主要データ）
            rowspan4_tds = [td for td in tbody.find_all('td') if td.get('rowspan') == '4']

            # F数・L数・平均ST (rowspan=4の4番目)
            f_count = l_count = avg_st = None
            if len(rowspan4_tds) >= 4:
                fl_text = rowspan4_tds[3].get_text(strip=True)
                # 'F0\nL0\n0.19' 形式
                fl_lines = [x.strip() for x in rowspan4_tds[3].get_text().split('\n') if x.strip()]
                for line in fl_lines:
                    if line.startswith('F'):
                        try: f_count = int(line[1:])
                        except: pass
                    elif line.startswith('L'):
                        try: l_count = int(line[1:])
                        except: pass
                    elif '.' in line:
                        try: avg_st = float(line)
                        except: pass

            # 全国勝率・2連率・3連率 (rowspan=4の5番目)
            win_rate_n = fukusho_n = rank3_n = None
            if len(rowspan4_tds) >= 5:
                lines = [x.strip() for x in rowspan4_tds[4].get_text().split('\n') if x.strip()]
                if len(lines) >= 1:
                    try: win_rate_n = float(lines[0])
                    except: pass
                if len(lines) >= 2:
                    try: fukusho_n = float(lines[1])
                    except: pass
                if len(lines) >= 3:
                    try: rank3_n = float(lines[2])
                    except: pass

            # 当地勝率・2連率・3連率 (rowspan=4の6番目)
            win_rate_l = fukusho_l = rank3_l = None
            if len(rowspan4_tds) >= 6:
                lines = [x.strip() for x in rowspan4_tds[5].get_text().split('\n') if x.strip()]
                if len(lines) >= 1:
                    try: win_rate_l = float(lines[0])
                    except: pass
                if len(lines) >= 2:
                    try: fukusho_l = float(lines[1])
                    except: pass
                if len(lines) >= 3:
                    try: rank3_l = float(lines[2])
                    except: pass

            # モーター番号・2連率 (rowspan=4の7番目)
            motor_no = motor_2rate = None
            if len(rowspan4_tds) >= 7:
                lines = [x.strip() for x in rowspan4_tds[6].get_text().split('\n') if x.strip()]
                if len(lines) >= 1:
                    try: motor_no = int(lines[0])
                    except: pass
                if len(lines) >= 2:
                    try: motor_2rate = float(lines[1])
                    except: pass

            # ボート番号・2連率 (rowspan=4の8番目)
            boat_no = boat_2rate = None
            if len(rowspan4_tds) >= 8:
                lines = [x.strip() for x in rowspan4_tds[7].get_text().split('\n') if x.strip()]
                if len(lines) >= 1:
                    try: boat_no = int(lines[0])
                    except: pass
                if len(lines) >= 2:
                    try: boat_2rate = float(lines[1])
                    except: pass

            result['players'].append({
                'waku':        waku,
                'player_id':   player_id,
                'f_count':     f_count,
                'l_count':     l_count,
                'avg_st':      avg_st,
                'win_rate_national':    win_rate_n,
                'fukusho_national':     fukusho_n,
                'rank3_national':       rank3_n,
                'win_rate_local':       win_rate_l,
                'fukusho_local':        fukusho_l,
                'rank3_local':          rank3_l,
                'motor_no':    motor_no,
                'motor_2rate': motor_2rate,
                'boat_no':     boat_no,
                'boat_2rate':  boat_2rate,
            })

    return result if result['players'] else None


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
        print(f'    → {result.get("ok", 0)}件登録')
        return True
    except Exception as e:
        print(f'    [SEND ERROR] {e}')
        return False


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--date', default=None)
    parser.add_argument('--jcd',  default=None)
    parser.add_argument('--rno',  default=None, type=int)
    args = parser.parse_args()

    JST = timezone(timedelta(hours=9))
    hd = args.date or datetime.now(JST).strftime('%Y%m%d')

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

        print(f'  [{venue_name}] 締切時刻を取得中...')
        schedule_times = scrape_schedule_times(jcd, hd)
        if schedule_times:
            print(f'    → {len(schedule_times)}レース分の時刻を取得')
        else:
            print(f'    → 時刻データなし')
        time.sleep(SLEEP_SEC)

        for rno in rnos:
            print(f'  [{venue_name}] {rno}R 取得中...', end=' ')
            data = scrape_racelist(jcd, rno, hd)

            if data is None or not data['players']:
                print('データなし')
                time.sleep(1)
                continue

            if rno in schedule_times:
                data['scheduled_time'] = schedule_times[rno]

            print(f'{len(data["players"])}選手', end=' ')
            send_data(data)
            time.sleep(SLEEP_SEC)

    print('\n完了!')


if __name__ == '__main__':
    main()
