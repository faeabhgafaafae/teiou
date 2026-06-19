#!/usr/bin/env python3
"""
艇王 - 競走成績ダウンローダー
mbrace.or.jp から競走成績ファイルをダウンロードして
ロリポップのPHP APIに送信しDBに登録する

使い方:
  # 直近2年分をダウンロード＆登録
  python download_results.py

  # 期間指定
  python download_results.py --start 2024-01-01 --end 2024-12-31

  # ダウンロードのみ（送信しない）
  python download_results.py --download-only
"""

import os
import re
import sys
import time
import glob
import requests
import argparse
import tempfile
import subprocess
from datetime import date, timedelta
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

# ─── 設定 ──────────────────────────────────────────────
API_URL   = os.environ.get('API_URL', 'https://2410049.moo.jp/import_results.php')
API_KEY   = os.environ.get('API_KEY', 'teio2025')
SLEEP_SEC = 3
SEVENZIP  = r'C:\Program Files\7-Zip\7z.exe'

HEADERS = {
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
}


def make_session():
    session = requests.Session()
    retry = Retry(
        total=4,
        backoff_factor=2,
        status_forcelist=[429, 500, 502, 503, 504],
        allowed_methods=["GET"],
    )
    adapter = HTTPAdapter(max_retries=retry)
    session.mount("https://", adapter)
    session.mount("http://", adapter)
    return session

SESSION = make_session()

def fetch(url, *, timeout=30, retries=3):
    last_err = None
    for i in range(retries):
        try:
            res = SESSION.get(url, headers=HEADERS, timeout=timeout)
            res.raise_for_status()
            return res
        except (requests.exceptions.Timeout,
                requests.exceptions.ConnectionError) as e:
            last_err = e
            wait = 5 * (i + 1)
            print(f"  取得失敗({i+1}/{retries}): {e} → {wait}秒後に再試行")
            time.sleep(wait)
    raise last_err

# ─── 場名マスタ ────────────────────────────────────────
VENUE_MAP = {
    '桐　生': '桐生', '戸　田': '戸田', '江戸川': '江戸川',
    '平和島': '平和島', '多摩川': '多摩川', '浜名湖': '浜名湖',
    '蒲　郡': '蒲郡', '常　滑': '常滑', '津　　': '津',
    '　津　': '津',   '三　国': '三国', 'びわこ': '琵琶湖',
    '住之江': '住之江', '尼　崎': '尼崎', '鳴　門': '鳴門',
    '高　松': '高松', '丸　亀': '丸亀', '児　島': '児島',
    '宮　島': '宮島', '徳　山': '徳山', '下　関': '下関',
    '若　松': '若松', '芦　屋': '芦屋', '福　岡': '福岡',
    '唐　津': '唐津', '大　村': '大村',
}

venue_pattern   = re.compile(r'^(.{3}|.{4})［成績］\s+(\d+)/\s*(\d+)')
race_pattern    = re.compile(r'^\s{1,3}(\d{1,2})R\s')
weather_pattern = re.compile(r'風\s+(\S+)\s+(\d+)m.*波\s+(\d+)cm')


def normalize_venue(raw):
    s = raw.replace('\u3000', '　').strip()
    return VENUE_MAP.get(s, s)


def parse_result_line(line):
    if len(line) < 47:
        return None
    try:
        rank_s    = line[2:4].strip()
        lane_s    = line[6:7].strip()
        pid_s     = line[8:12].strip()
        exhibit_s = line[31:35].strip()
        course_s  = line[38:39].strip()
        st_s      = line[43:47].strip()

        if not (rank_s.isdigit() and lane_s.isdigit() and pid_s.isdigit()):
            return None

        exhibit = None
        try: exhibit = float(exhibit_s)
        except: pass

        course = None
        try: course = int(course_s)
        except: pass

        st = None
        if st_s.startswith('F'):
            st = -0.001
        elif st_s:
            try: st = float(st_s)
            except: pass

        return {
            'actual_rank':  int(rank_s),
            'lane':         int(lane_s),
            'player_id':    int(pid_s),
            'exhibit_time': exhibit,
            'course':       course,
            'start_timing': st,
        }
    except Exception:
        return None


def parse_txt(content, file_date):
    lines = content.splitlines()
    records = []
    current_venue   = None
    current_race    = None
    current_weather = {}

    for line in lines:
        m = venue_pattern.match(line)
        if m:
            current_venue = normalize_venue(m.group(1))
            current_race  = None
            continue

        m = race_pattern.match(line)
        if m and current_venue:
            current_race = int(m.group(1))
            wm = weather_pattern.search(line)
            if wm:
                current_weather = {
                    'wind_dir':    wm.group(1).strip(),
                    'wind_speed':  float(wm.group(2)),
                    'wave_height': int(wm.group(3)),
                }
            continue

        r = parse_result_line(line)
        if r and current_venue and current_race:
            r.update({
                'date':        file_date.isoformat(),
                'venue':       current_venue,
                'race_no':     current_race,
                'wind_dir':    current_weather.get('wind_dir'),
                'wind_speed':  current_weather.get('wind_speed'),
                'wave_height': current_weather.get('wave_height'),
            })
            records.append(r)

    return records


def download_and_parse(target_date):
    """lzhをダウンロード→7-Zipで解凍→パース"""
    yyyymm = target_date.strftime('%Y%m')
    yymmdd = target_date.strftime('%y%m%d')
    url    = f'http://www1.mbrace.or.jp/od2/K/{yyyymm}/k{yymmdd}.lzh'

    try:
        res = fetch(url, timeout=30)

        # 一時ディレクトリに保存して解凍
        with tempfile.TemporaryDirectory() as tmpdir:
            lzh_path = os.path.join(tmpdir, f'k{yymmdd}.lzh')
            with open(lzh_path, 'wb') as f:
                f.write(res.content)

            # 7-Zipで解凍
            result = subprocess.run(
                [SEVENZIP, 'x', lzh_path, f'-o{tmpdir}', '-y'],
                capture_output=True, text=True
            )
            if result.returncode != 0:
                print(f'  [7Z ERROR] {result.stderr[:100]}')
                return None

            # 解凍されたtxtファイルを探す
            txts = glob.glob(os.path.join(tmpdir, '*.txt')) + \
                   glob.glob(os.path.join(tmpdir, '*.TXT'))
            if not txts:
                print('  [ERROR] txtが見つかりません')
                return None

            with open(txts[0], 'rb') as f:
                content = f.read().decode('shift_jis', errors='replace')

            return parse_txt(content, target_date)

    except Exception as e:
        print(f'  [ERROR] {e}')
        return None


def send_records(records, target_date):
    if not records:
        return True
    try:
        res = requests.post(API_URL, json={
            'api_key': API_KEY,
            'date':    target_date.isoformat(),
            'records': records,
        }, timeout=30)
        data = res.json()
        if data.get('error'):
            print(f'  [API ERROR] {data["error"]}')
            return False
        print(f'  → {data.get("ok", 0)}件登録 / {data.get("skip", 0)}件スキップ')
        return True
    except Exception as e:
        print(f'  [SEND ERROR] {e}')
        return False


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--start', default=None)
    parser.add_argument('--end',   default=None)
    parser.add_argument('--download-only', action='store_true')
    args = parser.parse_args()

    today = date.today()
    start = date.fromisoformat(args.start) if args.start else date(today.year - 2, today.month, today.day)
    end   = date.fromisoformat(args.end)   if args.end   else today - timedelta(days=1)

    print(f'期間: {start} 〜 {end}')
    print()

    current = start
    ok_days = skip_days = 0

    while current <= end:
        print(f'[{current}] ', end='', flush=True)

        records = download_and_parse(current)

        if records is None:
            print('レースなし')
            skip_days += 1
            current += timedelta(days=1)
            time.sleep(1)
            continue

        print(f'{len(records)}件パース ', end='', flush=True)

        if args.download_only:
            print('(送信スキップ)')
        else:
            send_records(records, current)

        ok_days += 1
        current += timedelta(days=1)
        time.sleep(SLEEP_SEC)

    print()
    print(f'完了: 処理 {ok_days}日 / スキップ {skip_days}日')


if __name__ == '__main__':
    main()
