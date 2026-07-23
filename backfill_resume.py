#!/usr/bin/env python3
"""
バックフィル再開スクリプト
未処理の日付を対象に成績データをダウンロード・登録する
ログは backfill_resume.log に出力

使い方:
  python backfill_resume.py
  python backfill_resume.py --dates 2026-06-29,2026-07-10,2026-07-11
"""
import os
import re
import sys
import glob
import time
import shutil
import platform
import requests
import argparse
import tempfile
import subprocess
from datetime import date, datetime, timezone, timedelta

# ─── 設定 ──────────────────────────────────────────────
API_URL         = os.environ.get('API_URL',         'https://2410049.moo.jp/import_results.php')
API_URL_PAYOUTS = os.environ.get('API_URL_PAYOUTS', 'https://2410049.moo.jp/save_payouts.php')
API_KEY         = os.environ.get('API_KEY',         'teio2025')
LOG_FILE        = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'backfill_resume.log')
SLEEP_SEC       = 8   # Akamai bot検知回避のため余裕を持たせる

def get_7zip():
    if platform.system() != 'Windows':
        return shutil.which('7z') or '7z'
    return r'C:\Program Files\7-Zip\7z.exe'

SEVENZIP = get_7zip()
HEADERS  = {
    'User-Agent': (
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        'AppleWebKit/537.36 (KHTML, like Gecko) '
        'Chrome/120.0.0.0 Safari/537.36'
    ),
    'Referer': 'http://www1.mbrace.or.jp/',
    'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
}

# ─── ロガー ────────────────────────────────────────────
_log_fh = None

def log(msg, end='\n', flush=True):
    global _log_fh
    full = msg + end
    sys.stdout.write(full)
    sys.stdout.flush()
    if _log_fh:
        _log_fh.write(full)
        if flush:
            _log_fh.flush()

def open_log():
    global _log_fh
    _log_fh = open(LOG_FILE, 'a', encoding='utf-8')

def close_log():
    global _log_fh
    if _log_fh:
        _log_fh.close()
        _log_fh = None

# ─── HTTPセッション ────────────────────────────────────
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

def make_session():
    session = requests.Session()
    retry = Retry(total=4, backoff_factor=1,
                  status_forcelist=[429, 500, 502, 503, 504],
                  allowed_methods=["GET"])
    adapter = HTTPAdapter(max_retries=retry)
    session.mount("https://", adapter)
    session.mount("http://",  adapter)
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
race_time_pattern = re.compile(r'^\d\.\d{2}\.\d$')

BET_TYPE_NORMALIZE = {
    '単勝': '単勝', '複勝': '複勝', '２連単': '2連単',
    '２連複': '2連複', '拡連複': '拡連複', '３連単': '3連単', '３連複': '3連複',
}
CONTINUABLE_LABELS = ('複勝', '拡連複')
payout_label_pattern = re.compile(
    r'^[ 　]*(単勝|複勝|２連単|２連複|拡連複|３連単|３連複)[ 　]*(.*)$'
)
combo_amount_pattern = re.compile(
    r'(\d+(?:[-－]\d+)+)[ 　]+([\d,]+)(?:[ 　]*人気[ 　]*(\d+))?'
)
lane_amount_pattern = re.compile(r'(\d+)[ 　]+([\d,]+)')


def normalize_venue(raw):
    s = raw.replace('　', '　').strip()
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
                payouts.append({'bet_type': BET_TYPE_NORMALIZE[current_label],
                                'combo': combo, 'amount': amount, 'popularity': popularity})
            i += 1
            continue
        if current_label in CONTINUABLE_LABELS:
            entries = _extract_payout_entries(current_label, line)
            if entries:
                for combo, amount, popularity in entries:
                    payouts.append({'bet_type': BET_TYPE_NORMALIZE[current_label],
                                    'combo': combo, 'amount': amount, 'popularity': popularity})
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
                current_weather = {'wind_dir': wm.group(1).strip(),
                                   'wind_speed': float(wm.group(2)),
                                   'wave_height': int(wm.group(3))}
            i += 1
            continue
        if line.lstrip().startswith('単勝') and current_venue and current_race:
            block, next_i = parse_payout_block(lines, i)
            for p in block:
                p.update({'date': file_date.isoformat(), 'venue': current_venue, 'race_no': current_race})
            payouts.extend(block)
            i = next_i
            continue
        r = parse_result_line(line)
        if r and current_venue and current_race:
            r.update({'date': file_date.isoformat(), 'venue': current_venue,
                      'race_no': current_race, 'wind_dir': current_weather.get('wind_dir'),
                      'wind_speed': current_weather.get('wind_speed'),
                      'wave_height': current_weather.get('wave_height')})
            records.append(r)
        i += 1
    return records, payouts


def download_and_parse(target_date):
    yyyymm = target_date.strftime('%Y%m')
    yymmdd = target_date.strftime('%y%m%d')
    url    = f'https://www1.mbrace.or.jp/od2/K/{yyyymm}/k{yymmdd}.lzh'
    try:
        res = fetch(url, timeout=30)
        with tempfile.TemporaryDirectory() as tmpdir:
            lzh_path = os.path.join(tmpdir, f'k{yymmdd}.lzh')
            with open(lzh_path, 'wb') as f:
                f.write(res.content)
            result = subprocess.run(
                [SEVENZIP, 'x', lzh_path, f'-o{tmpdir}', '-y'],
                capture_output=True, text=True
            )
            if result.returncode != 0:
                log(f'  [7Z ERROR] {result.stderr[:100]}')
                return None
            txts = glob.glob(os.path.join(tmpdir, '*.txt')) + \
                   glob.glob(os.path.join(tmpdir, '*.TXT'))
            if not txts:
                log('  [ERROR] txtが見つかりません')
                return None
            with open(txts[0], 'rb') as f:
                content = f.read().decode('shift_jis', errors='replace')
            return parse_txt(content, target_date)
    except Exception as e:
        log(f'  [ERROR] {e}')
        return None


def send_records(records, target_date):
    if not records:
        return True, 0, 0
    try:
        res = requests.post(API_URL, json={
            'api_key': API_KEY, 'date': target_date.isoformat(), 'records': records,
        }, timeout=30)
        data = res.json()
        if data.get('error'):
            log(f'  [API ERROR] {data["error"]}')
            return False, 0, 0
        ok_n   = data.get('ok', 0)
        skip_n = data.get('skip', 0)
        log(f'  → 成績: {ok_n}件登録 / {skip_n}件スキップ(既存)')
        if data.get('first_error'):
            log(f'  [FIRST ERROR] {data["first_error"]}')
        return True, ok_n, skip_n
    except Exception as e:
        log(f'  [SEND ERROR] {e}')
        return False, 0, 0


def send_payouts(payouts, target_date):
    if not payouts:
        return True, 0, 0
    try:
        res = requests.post(API_URL_PAYOUTS, json={
            'api_key': API_KEY, 'date': target_date.isoformat(), 'payouts': payouts,
        }, timeout=30)
        data = res.json()
        if data.get('error'):
            log(f'  [PAYOUT API ERROR] {data["error"]}')
            return False, 0, 0
        ok_n   = data.get('ok', 0)
        skip_n = data.get('skip', 0)
        log(f'  → 払戻金: {ok_n}件登録 / {skip_n}件スキップ(既存)')
        if data.get('first_error'):
            log(f'  [PAYOUT FIRST ERROR] {data["first_error"]}')
        return True, ok_n, skip_n
    except Exception as e:
        log(f'  [PAYOUT SEND ERROR] {e}')
        return False, 0, 0


def main():
    parser = argparse.ArgumentParser(description='バックフィル再開')
    parser.add_argument('--start',  default=None, help='開始日 (YYYY-MM-DD)')
    parser.add_argument('--end',    default=None, help='終了日 (YYYY-MM-DD)')
    parser.add_argument('--dates',  default=None, help='カンマ区切りの日付リスト')
    args = parser.parse_args()

    open_log()

    now_str = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    log(f'\n{"="*60}')
    log(f'バックフィル再開 開始: {now_str}')
    log(f'{"="*60}')

    # 対象日付リストの構築
    if args.dates:
        target_dates = sorted(set(
            date.fromisoformat(d.strip()) for d in args.dates.split(',')
        ))
    else:
        # デフォルト: 未処理の日付のみ
        missing = [
            '2026-06-29', '2026-06-30', '2026-07-01', '2026-07-02',
            '2026-07-03', '2026-07-04', '2026-07-05', '2026-07-06',
            '2026-07-10', '2026-07-11',
        ]
        if args.start or args.end:
            JST = timezone(timedelta(hours=9))
            today = datetime.now(JST).date()
            s = date.fromisoformat(args.start) if args.start else date(2026, 6, 29)
            e = date.fromisoformat(args.end)   if args.end   else date(2026, 7, 14)
            cur = s
            target_dates = []
            while cur <= e:
                target_dates.append(cur)
                cur += timedelta(days=1)
        else:
            target_dates = [date.fromisoformat(d) for d in missing]

    log(f'処理対象: {len(target_dates)}日')
    log(f'  {[str(d) for d in target_dates]}')
    log('')

    # ─── BEFORE 集計 ───────────────────────────────────
    before_total = 2832   # チェック時の総レース数
    before_with  = 2232   # チェック時の成績ありレース数
    before_missing = 600  # チェック時の成績なしレース数

    log(f'【BEFORE】成績あり {before_with}/{before_total} レース '
        f'(欠損率 {before_missing/before_total*100:.1f}%)')
    log('')

    # ─── 処理ループ ───────────────────────────────────
    total_ok     = 0
    total_skip   = 0
    total_p_ok   = 0
    total_p_skip = 0
    ok_days      = 0
    fail_days    = 0
    day_results  = []

    for target_date in target_dates:
        log(f'[{target_date}] ダウンロード中...', end=' ')

        result = download_and_parse(target_date)
        if result is None:
            log('データなし (lzhファイルが存在しないかパース失敗)')
            fail_days += 1
            day_results.append({'date': str(target_date), 'status': 'no_data',
                                 'ok': 0, 'skip': 0})
            time.sleep(1)
            continue

        records, payouts = result
        log(f'{len(records)}件パース / 払戻{len(payouts)}件')

        ok_r, ok_n, skip_n = send_records(records, target_date)
        _, p_ok, p_skip    = send_payouts(payouts, target_date)

        total_ok   += ok_n
        total_skip += skip_n
        total_p_ok   += p_ok
        total_p_skip += p_skip

        day_results.append({
            'date':   str(target_date),
            'status': 'ok' if ok_r else 'error',
            'ok':     ok_n,
            'skip':   skip_n,
            'p_ok':   p_ok,
            'p_skip': p_skip,
        })

        if ok_r:
            ok_days += 1
        else:
            fail_days += 1

        time.sleep(SLEEP_SEC)

    # ─── サマリー ─────────────────────────────────────
    log('')
    log('='*60)
    log(f'【処理完了】')
    log(f'  処理成功: {ok_days}日 / 失敗: {fail_days}日')
    log(f'  成績登録: 新規{total_ok}件 / スキップ(既存){total_skip}件')
    log(f'  払戻登録: 新規{total_p_ok}件 / スキップ(既存){total_p_skip}件')
    log('')

    log('【日別結果】')
    log(f'{"日付":<12} {"状態":>6} {"成績新規":>8} {"成績既存":>8} {"払戻新規":>8}')
    log('-' * 50)
    for dr in day_results:
        log(f"{dr['date']:<12} {dr['status']:>6} "
            f"{dr.get('ok', 0):>8} {dr.get('skip', 0):>8} {dr.get('p_ok', 0):>8}")

    # ─── AFTER 推定 ───────────────────────────────────
    after_with = before_with + total_ok
    log('')
    log(f'【BEFORE → AFTER 推定】')
    log(f'  成績あり: {before_with}件 → {after_with}件 (+{total_ok}件)')
    log(f'  欠損率  : {before_missing/before_total*100:.1f}% → '
        f'{max(0, before_missing - total_ok)/before_total*100:.1f}%')

    now_end = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    log(f'\nバックフィル再開 完了: {now_end}')
    log('='*60)

    close_log()
    return 0 if fail_days == 0 else 1


if __name__ == '__main__':
    sys.exit(main())
