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
import shutil
import platform
import requests
import argparse
import tempfile
import subprocess
from datetime import date, datetime, timezone, timedelta
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

# ─── 設定 ──────────────────────────────────────────────
API_URL          = os.environ.get('API_URL', 'https://2410049.moo.jp/import_results.php')
API_URL_PAYOUTS  = os.environ.get('API_URL_PAYOUTS', 'https://2410049.moo.jp/save_payouts.php')
API_KEY          = os.environ.get('API_KEY', 'teio2025')
SLEEP_SEC = 3


def write_summary(text: str) -> None:
    path = os.environ.get('GITHUB_STEP_SUMMARY')
    if not path:
        return
    with open(path, 'a', encoding='utf-8') as f:
        f.write(text)

def get_7zip():
    if platform.system() != 'Windows':
        return shutil.which('7z') or '7z'
    return r'C:\Program Files\7-Zip\7z.exe'

SEVENZIP = get_7zip()

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

# ─── 払戻金ブロック ────────────────────────────────────
# 例: "        ３連単   4-1-2    15610  人気    42 "
#     "        複勝     4          130  1          120  "
BET_TYPE_NORMALIZE = {
    '単勝':   '単勝',
    '複勝':   '複勝',
    '２連単': '2連単',
    '２連複': '2連複',
    '拡連複': '拡連複',
    '３連単': '3連単',
    '３連複': '3連複',
}
CONTINUABLE_LABELS = ('複勝', '拡連複')

payout_label_pattern = re.compile(
    r'^[ 　]*(単勝|複勝|２連単|２連複|拡連複|３連単|３連複)[ 　]*(.*)$'
)
# 組番（ハイフン区切り）＋ 金額 ＋（任意で）人気
combo_amount_pattern = re.compile(
    r'(\d+(?:[-－]\d+)+)[ 　]+([\d,]+)(?:[ 　]*人気[ 　]*(\d+))?'
)
# 単勝・複勝用（枠番 ＋ 金額のペア。複勝は同一行に複数ペア）
lane_amount_pattern = re.compile(
    r'(\d+)[ 　]+([\d,]+)'
)
# レースタイム（着順タイム）: "1.49.7" = 1分49秒7
race_time_pattern = re.compile(r'^\d\.\d{2}\.\d$')


def normalize_venue(raw):
    s = raw.replace('\u3000', '　').strip()
    return VENUE_MAP.get(s, s)


def parse_result_line(line):
    if len(line) < 58:
        return None
    try:
        rank_s      = line[2:4].strip()
        lane_s      = line[6:7].strip()
        pid_s       = line[8:12].strip()
        exhibit_s   = line[31:35].strip()
        course_s    = line[38:39].strip()
        st_s        = line[43:47].strip()
        race_time_s = line[52:58].strip()

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

        race_time = race_time_s if race_time_pattern.match(race_time_s) else None

        return {
            'actual_rank':  int(rank_s),
            'lane':         int(lane_s),
            'player_id':    int(pid_s),
            'exhibit_time': exhibit,
            'course':       course,
            'start_timing': st,
            'race_time':    race_time,
        }
    except Exception:
        return None


def _extract_payout_entries(label, remainder):
    """ラベル除去後の残り文字列から (combo, amount, popularity) のリストを返す"""
    entries = []
    if label in ('単勝', '複勝'):
        for lane_s, amount_s in lane_amount_pattern.findall(remainder):
            entries.append((lane_s, int(amount_s.replace(',', '')), None))
    else:
        for combo_s, amount_s, pop_s in combo_amount_pattern.findall(remainder):
            combo = combo_s.replace('－', '-')
            popularity = int(pop_s) if pop_s else None
            entries.append((combo, int(amount_s.replace(',', '')), popularity))
    return entries


def parse_payout_block(lines, start_idx):
    """
    lines[start_idx] が払戻金ブロックの先頭行（"単勝"行）である前提で、
    ブロックが終わる（空行 or 後続行が payout パターンに一致しない）まで読み進める。

    拡連複・複勝はラベルの付かない継続行が続くことがあるため、
    直前のラベルを引き継いで読み取る。

    戻り値: (payouts, next_idx)
      payouts  : [{'bet_type', 'combo', 'amount', 'popularity'}, ...]
      next_idx : ブロックの次に読み始めるべき行インデックス
    """
    payouts = []
    current_label = None
    i = start_idx
    n = len(lines)

    while i < n:
        line = lines[i]
        if not line.strip():
            break

        m = payout_label_pattern.match(line)
        if m:
            current_label = m.group(1)
            for combo, amount, popularity in _extract_payout_entries(current_label, m.group(2)):
                payouts.append({
                    'bet_type':   BET_TYPE_NORMALIZE[current_label],
                    'combo':      combo,
                    'amount':     amount,
                    'popularity': popularity,
                })
            i += 1
            continue

        if current_label in CONTINUABLE_LABELS:
            entries = _extract_payout_entries(current_label, line)
            if entries:
                for combo, amount, popularity in entries:
                    payouts.append({
                        'bet_type':   BET_TYPE_NORMALIZE[current_label],
                        'combo':      combo,
                        'amount':     amount,
                        'popularity': popularity,
                    })
                i += 1
                continue

        break

    return payouts, i


def parse_txt(content, file_date):
    lines = content.splitlines()
    records = []
    payouts = []
    current_venue   = None
    current_race    = None
    current_weather = {}

    i = 0
    n = len(lines)
    while i < n:
        line = lines[i]

        m = venue_pattern.match(line)
        if m:
            current_venue = normalize_venue(m.group(1))
            current_race  = None
            i += 1
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
            i += 1
            continue

        if line.lstrip().startswith('単勝') and current_venue and current_race:
            block, next_i = parse_payout_block(lines, i)
            for p in block:
                p.update({
                    'date':    file_date.isoformat(),
                    'venue':   current_venue,
                    'race_no': current_race,
                })
            payouts.extend(block)
            i = next_i
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

        i += 1

    return records, payouts


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
        if data.get('first_error'):
            print(f'  [FIRST ERROR] {data["first_error"]}')
        return True
    except Exception as e:
        print(f'  [SEND ERROR] {e}')
        return False


def send_payouts(payouts, target_date):
    if not payouts:
        return True
    try:
        res = requests.post(API_URL_PAYOUTS, json={
            'api_key': API_KEY,
            'date':    target_date.isoformat(),
            'payouts': payouts,
        }, timeout=30)
        data = res.json()
        if data.get('error'):
            print(f'  [PAYOUT API ERROR] {data["error"]}')
            return False
        print(f'  → 払戻金 {data.get("ok", 0)}件登録 / {data.get("skip", 0)}件スキップ')
        if data.get('first_error'):
            print(f'  [PAYOUT FIRST ERROR] {data["first_error"]}')
        return True
    except Exception as e:
        print(f'  [PAYOUT SEND ERROR] {e}')
        return False


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--start', default=None)
    parser.add_argument('--end',   default=None)
    parser.add_argument('--download-only', action='store_true')
    args = parser.parse_args()

    JST = timezone(timedelta(hours=9))
    today = datetime.now(JST).date()
    start = date.fromisoformat(args.start) if args.start else date(today.year - 2, today.month, today.day)
    end   = date.fromisoformat(args.end)   if args.end   else today - timedelta(days=1)

    print(f'期間: {start} 〜 {end}')
    print()

    current = start
    ok_days = skip_days = 0
    failed_days = []

    while current <= end:
        print(f'[{current}] ', end='', flush=True)

        result = download_and_parse(current)

        if result is None:
            print('レースなし')
            skip_days += 1
            current += timedelta(days=1)
            time.sleep(1)
            continue

        records, payouts = result
        print(f'{len(records)}件パース / 払戻{len(payouts)}件 ', end='', flush=True)

        if args.download_only:
            print('(送信スキップ)')
        else:
            ok_records = send_records(records, current)
            ok_payouts = send_payouts(payouts, current)
            if not (ok_records and ok_payouts):
                failed_days.append(current.isoformat())

        ok_days += 1
        current += timedelta(days=1)
        time.sleep(SLEEP_SEC)

    print()
    print(f'完了: 処理 {ok_days}日 / スキップ {skip_days}日')

    if failed_days:
        print(f'[FAILED] 送信失敗: {len(failed_days)}日 → {", ".join(failed_days)}')
        write_summary(
            f'## ⚠️ 競走成績取込 一部失敗\n\n'
            f'- 失敗日数: {len(failed_days)}日\n'
            f'- 失敗日: {", ".join(failed_days)}\n'
        )
        sys.exit(1)


if __name__ == '__main__':
    main()
