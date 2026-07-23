#!/usr/bin/env python3
"""
entries.exhibit_time / start_timing バックフィル (6/29〜7/14)
boatrace.jp の beforeinfo ページから過去日データを再取得し、
import_beforeinfo.php の COALESCE UPDATE で NULL のみを安全に埋める

使い方:
  python backfill_beforeinfo.py
  python backfill_beforeinfo.py --start 2026-07-10 --end 2026-07-11
"""

import os, sys, re, time, argparse, requests
from datetime import date, timedelta
from urllib.parse import quote
from bs4 import BeautifulSoup
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

# ─── 設定 ──────────────────────────────────────────────
API_BASE          = 'https://2410049.moo.jp'
API_KEY           = os.environ.get('API_KEY', 'teio2025')
API_BEFOREINFO    = f'{API_BASE}/import_beforeinfo.php'
BOATRACE_BASE     = 'https://www.boatrace.jp'
LOG_FILE          = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                  'backfill_beforeinfo.log')

SLEEP_SEC         = 8     # Akamai bot検知回避（レース間）
VENUE_CHECK_SLEEP = 0.15  # api_races.php呼び出し間隔
CONSEC_ERR_LIMIT  = 3     # 連続接続エラーでIPブロック判定し中断

HEADERS = {
    'User-Agent': (
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        'AppleWebKit/537.36 (KHTML, like Gecko) '
        'Chrome/120.0.0.0 Safari/537.36'
    ),
    'Referer':  'https://www.boatrace.jp/',
    'Accept':   'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Accept-Language': 'ja,en;q=0.9',
}

VENUES_JCD = {
    '桐生':'01','戸田':'02','江戸川':'03','平和島':'04','多摩川':'05',
    '浜名湖':'06','蒲郡':'07','常滑':'08','津':'09','三国':'10',
    '琵琶湖':'11','住之江':'12','尼崎':'13','鳴門':'14','丸亀':'15',
    '児島':'16','宮島':'17','徳山':'18','下関':'19','若松':'20',
    '芦屋':'21','福岡':'22','唐津':'23','大村':'24',
}

WIND_DIR_MAP = {
    1:'北', 2:'北北東', 3:'北東', 4:'東北東',
    5:'東', 6:'東南東', 7:'南東', 8:'南南東',
    9:'南', 10:'南南西', 11:'南西', 12:'西南西',
    13:'西', 14:'西北西', 15:'北西', 16:'北北西',
}

# ─── ロガー ────────────────────────────────────────────
_log_fh = None

def log(msg, end='\n'):
    global _log_fh
    line = msg + end
    sys.stdout.write(line); sys.stdout.flush()
    if _log_fh:
        _log_fh.write(line); _log_fh.flush()

def open_log():
    global _log_fh
    _log_fh = open(LOG_FILE, 'a', encoding='utf-8')

def close_log():
    global _log_fh
    if _log_fh:
        _log_fh.close(); _log_fh = None

# ─── HTTP セッション ────────────────────────────────────
def make_session(retries=2):
    s = requests.Session()
    adapter = HTTPAdapter(max_retries=Retry(
        total=retries, backoff_factor=2,
        status_forcelist=[429, 500, 502, 503, 504],
        allowed_methods=['GET'],
        raise_on_status=False,
    ))
    s.mount('https://', adapter)
    s.mount('http://',  adapter)
    return s

SESSION = make_session()

def is_ip_block_error(exc: Exception) -> bool:
    """接続リセット/空レスポンスならIPブロックの可能性"""
    msg = str(exc).lower()
    return any(kw in msg for kw in [
        'remotedisconnected', 'connection aborted', 'empty reply',
        'connection reset', 'broken pipe', 'max retries exceeded',
    ])

# ─── boatrace.jp scraping ──────────────────────────────
def scrape_beforeinfo(jcd: str, rno: int, hd: str) -> dict | None:
    """
    boatrace.jp beforeinfo ページをスクレイピング。
    戻り値: {date, venue, race_no, players, weather, start_exhibition}
    データなし → None
    IPブロック等の接続エラー → raise
    """
    url = f'{BOATRACE_BASE}/owpc/pc/race/beforeinfo?rno={rno}&jcd={jcd}&hd={hd}'
    try:
        res = SESSION.get(url, headers=HEADERS, timeout=20)
        res.raise_for_status()
        soup = BeautifulSoup(res.text, 'html.parser')
    except requests.exceptions.ConnectionError as e:
        raise  # 呼び出し元でIPブロック判定
    except requests.exceptions.HTTPError as e:
        if e.response is not None and e.response.status_code == 404:
            return None
        raise
    except Exception as e:
        raise

    result = {
        'jcd':              jcd,
        'venue':            {v: k for k, v in VENUES_JCD.items()}.get(jcd, jcd),
        'race_no':          rno,
        'date':             f'{hd[:4]}-{hd[4:6]}-{hd[6:]}',
        'players':          [],
        'weather':          {},
        'start_exhibition': [],
    }

    # 展示タイム・チルト等
    for tbody in soup.select('table.is-w748 tbody'):
        waku_td = tbody.select_one('td[class*="is-boatColor"]')
        if not waku_td:
            continue
        try:
            waku = int(waku_td.get_text(strip=True))
        except:
            continue

        player_link = tbody.select_one('a[href*="toban="]')
        if not player_link:
            continue
        m = re.search(r'toban=(\d+)', player_link['href'])
        if not m:
            continue
        player_id = int(m.group(1))

        exhibit_time  = None
        tilt          = None
        adjust_weight = None
        propeller_mark = None
        parts_exchange = None

        rowspan4 = [td for td in tbody.find_all('td') if td.get('rowspan') == '4']
        rowspan2 = [td for td in tbody.find_all('td') if td.get('rowspan') == '2']

        if len(rowspan4) >= 4:
            try: exhibit_time = float(rowspan4[3].get_text(strip=True))
            except: pass
            try: tilt = float(rowspan4[4].get_text(strip=True))
            except: pass
        if len(rowspan4) >= 6:
            pt = rowspan4[5].get_text(strip=True)
            if pt: propeller_mark = pt
        if len(rowspan4) >= 7:
            items = [li.get_text(strip=True) for li in rowspan4[6].select('li') if li.get_text(strip=True)]
            if items: parts_exchange = '・'.join(items)
        if rowspan2:
            try: adjust_weight = float(rowspan2[0].get_text(strip=True).replace('kg', ''))
            except: pass

        result['players'].append({
            'waku': waku, 'player_id': player_id,
            'exhibit_time': exhibit_time, 'tilt': tilt,
            'adjust_weight': adjust_weight,
            'propeller_mark': propeller_mark,
            'parts_exchange': parts_exchange,
        })

    if not result['players']:
        return None  # データなし

    # スタート展示
    start_by_lane = {}
    for i, div in enumerate(soup.select('.table1_boatImage1')):
        course_no = i + 1
        number_span = div.select_one('.table1_boatImage1Number')
        time_span   = div.select_one('.table1_boatImage1Time')
        if not number_span:
            continue
        type_cls = [c for c in (number_span.get('class') or []) if c.startswith('is-type')]
        if not type_cls:
            continue
        try: lane = int(type_cls[0].replace('is-type', ''))
        except: continue
        st_val = None
        is_f   = False
        if time_span:
            st_text = time_span.get_text(strip=True)
            is_f = 'F' in st_text
            try:
                st_val = float(st_text.replace('F', '')) * -1 if is_f else float('0' + st_text)
            except: pass
        start_by_lane[lane] = {'st': st_val, 'is_flying': is_f, 'course': course_no}
    result['start_exhibition'] = [start_by_lane.get(lane) for lane in range(1, 7)]

    # 気象
    weather = {}
    ws = soup.select_one('.weather1_bodyUnit.is-wind .weather1_bodyUnitLabelData')
    if ws:
        try: weather['wind_speed'] = float(ws.get_text(strip=True).replace('m', ''))
        except: pass
    wv = soup.select_one('.weather1_bodyUnit.is-wave .weather1_bodyUnitLabelData')
    if wv:
        try: weather['wave_height'] = int(wv.get_text(strip=True).replace('cm', ''))
        except: pass
    wd = soup.select_one('.weather1_bodyUnit.is-windDirection p')
    if wd:
        for cls in wd.get('class', []):
            m2 = re.search(r'is-wind(\d+)', cls)
            if m2:
                weather['wind_dir'] = WIND_DIR_MAP.get(int(m2.group(1)), m2.group(1))
                break
    wc = soup.select_one('.weather1_bodyUnit.is-weather .weather1_bodyUnitLabelTitle')
    if wc and wc.get_text(strip=True):
        weather['weather'] = wc.get_text(strip=True)
    tp = soup.select_one('.weather1_bodyUnit.is-direction .weather1_bodyUnitLabelData')
    if tp:
        try: weather['temperature'] = float(tp.get_text(strip=True).replace('℃', ''))
        except: pass
    wt = (soup.select_one('.weather1_bodyUnit.is-waterTemperature .weather1_bodyUnitLabelData')
          or soup.select_one('.weather1_bodyUnit.is-water .weather1_bodyUnitLabelData'))
    if wt:
        try: weather['water_temperature'] = float(wt.get_text(strip=True).replace('℃', ''))
        except: pass
    result['weather'] = weather
    return result


def send_beforeinfo(data: dict) -> tuple[bool, int]:
    """import_beforeinfo.php に送信。(success, ok_count) を返す"""
    try:
        res = requests.post(API_BEFOREINFO, json={
            'api_key': API_KEY,
            'data':    data,
        }, timeout=20)
        r = res.json()
        if r.get('error'):
            log(f'    [API ERROR] {r["error"]}')
            return False, 0
        ok_n = r.get('ok', 0)
        return True, ok_n
    except Exception as e:
        log(f'    [SEND ERROR] {e}')
        return False, 0


def get_active_races(date_str: str) -> dict[str, list[int]]:
    """
    api_races.php を全会場に対して叩いて、その日にレースのある
    {venue_name: [race_no, ...]} を返す。
    """
    active = {}
    for venue, jcd in VENUES_JCD.items():
        url = f'{API_BASE}/api_races.php?date={date_str}&venue={quote(venue)}'
        try:
            r = requests.get(url, timeout=10)
            data = r.json()
            races = data.get('races', [])
            if races:
                active[venue] = [int(rc['race_no']) for rc in races]
        except:
            pass
        time.sleep(VENUE_CHECK_SLEEP)
    return active


# ─── メイン ────────────────────────────────────────────
def main():
    parser = argparse.ArgumentParser(description='entries exhibit_time/start_timing バックフィル')
    parser.add_argument('--start', default='2026-06-29')
    parser.add_argument('--end',   default='2026-07-14')
    args = parser.parse_args()

    open_log()
    from datetime import datetime
    now_str = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    log(f'\n{"="*60}')
    log(f'beforeinfo バックフィル 開始: {now_str}')
    log(f'対象期間: {args.start} 〜 {args.end}')
    log(f'設定: SLEEP={SLEEP_SEC}s / IPブロック検知閾値={CONSEC_ERR_LIMIT}連続エラー')
    log(f'{"="*60}\n')

    start_date = date.fromisoformat(args.start)
    end_date   = date.fromisoformat(args.end)

    total_ok     = 0
    total_skip   = 0
    total_nodata = 0
    day_results  = []
    consec_errors = 0
    ip_blocked   = False

    cur = start_date
    while cur <= end_date:
        d_str  = str(cur)
        hd_str = cur.strftime('%Y%m%d')

        log(f'[{d_str}] 開催会場チェック中...')
        active = get_active_races(d_str)
        if not active:
            log(f'  → 開催なし\n')
            day_results.append({'date': d_str, 'venues': 0, 'ok': 0, 'nodata': 0})
            cur += timedelta(days=1)
            continue

        log(f'  → {len(active)}会場 ({", ".join(active.keys())})')
        day_ok = 0
        day_nodata = 0

        for venue, race_nos in active.items():
            jcd = VENUES_JCD[venue]
            for rno in race_nos:
                log(f'  [{venue}] {rno}R ', end='')

                try:
                    data = scrape_beforeinfo(jcd, rno, hd_str)
                    consec_errors = 0  # 成功でリセット
                except Exception as e:
                    if is_ip_block_error(e):
                        consec_errors += 1
                        log(f'[接続エラー #{consec_errors}] {str(e)[:80]}')
                        if consec_errors >= CONSEC_ERR_LIMIT:
                            log(f'\n{"!"*60}')
                            log(f'連続接続エラー{CONSEC_ERR_LIMIT}回: IPブロックの可能性')
                            log(f'最終処理: {d_str} {venue} {rno}R')
                            log(f'残り未処理日: {d_str} 〜 {end_date}')
                            log(f'{"!"*60}\n')
                            ip_blocked = True
                            break
                        time.sleep(SLEEP_SEC * 2)
                        continue
                    else:
                        log(f'[ERROR] {e}')
                        continue

                if ip_blocked:
                    break

                if data is None:
                    log('データなし')
                    day_nodata += 1
                    total_nodata += 1
                    time.sleep(1)
                    continue

                n_players = len(data.get('players', []))
                log(f'{n_players}選手 ', end='')
                ok, ok_n = send_beforeinfo(data)
                if ok:
                    log(f'→ {ok_n}件更新')
                    day_ok   += ok_n
                    total_ok += ok_n
                else:
                    log('→ 送信失敗')

                time.sleep(SLEEP_SEC)

            if ip_blocked:
                break

        day_results.append({
            'date': d_str, 'venues': len(active),
            'ok': day_ok, 'nodata': day_nodata,
        })
        log(f'  [{d_str}] 小計: 更新{day_ok}件 / データなし{day_nodata}件\n')

        if ip_blocked:
            break

        cur += timedelta(days=1)

    # ─── サマリー ─────────────────────────────────────
    log('='*60)
    if ip_blocked:
        log('【中断】IPブロック検知のため処理を停止しました')
        log('ブロック解除後に --start オプションで再開してください')
    else:
        log('【完了】全日付の処理が完了しました')

    log(f'  entries更新: {total_ok}件')
    log(f'  データなし(beforeinfo未公開): {total_nodata}件')
    log('')
    log('【日別結果】')
    log(f'{"日付":<12} {"会場数":>5} {"entries更新":>11} {"データなし":>10}')
    log('-'*42)
    for dr in day_results:
        log(f"{dr['date']:<12} {dr['venues']:>5} {dr['ok']:>11} {dr['nodata']:>10}")

    now_end = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    log(f'\nbeforeinfo バックフィル {"中断" if ip_blocked else "完了"}: {now_end}')
    log('='*60)
    close_log()
    return 1 if ip_blocked else 0


if __name__ == '__main__':
    sys.exit(main())
