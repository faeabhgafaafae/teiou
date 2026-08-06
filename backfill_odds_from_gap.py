#!/usr/bin/env python3
"""
バックフィル: is_hit=1 & payout=0 の357レース分 odds_3t を再スクレイピング

手順:
  1. get_gap_races.php から対象レース一覧を取得
  2. boatrace.jp から odds3t を再スクレイピング (3連単のみ / payout計算に必要な最小限)
  3. import_odds.php へ送信して odds_3t を更新
  4. backfill_payout.php で strategy_results.payout を再計算し結果を出力
"""

import os
import re
import sys
import time
import signal
import requests

from scrape_live import (
    API_PENDING, API_KEY, SLEEP_SEC,
    VENUE_TO_JCD, scrape_odds, send_odds,
)

# API_PENDING の同一ホストからエンドポイントを導出
_base            = re.sub(r'/[^/]+$', '', API_PENDING)
API_GAP_RACES    = f'{_base}/get_gap_races.php'
API_BACKFILL_PAY = f'{_base}/backfill_payout.php'

RACE_TIMEOUT_SEC = 60


class RaceTimeoutError(Exception):
    pass


def _alarm_handler(signum, frame):
    raise RaceTimeoutError()


def get_gap_races() -> list:
    res = requests.get(API_GAP_RACES, params={'api_key': API_KEY}, timeout=15)
    res.raise_for_status()
    data = res.json()
    if 'error' in data:
        raise RuntimeError(f'get_gap_races error: {data["error"]}')
    return data.get('races', [])


def trigger_payout_recalc() -> dict:
    res = requests.post(API_BACKFILL_PAY, json={'api_key': API_KEY}, timeout=120)
    res.raise_for_status()
    return res.json()


def main():
    print('[backfill] ギャップレース一覧取得中...')
    try:
        races = get_gap_races()
    except Exception as e:
        print(f'[ERROR] ギャップレース取得失敗: {e}')
        sys.exit(1)

    total = len(races)
    print(f'  対象: {total}レース\n')
    if not races:
        print('  対象レースなし。終了。')
        return

    odds_ok   = 0
    odds_fail = 0

    for i, race in enumerate(races, 1):
        date_str = race['date']
        venue    = race['venue']
        race_no  = int(race['race_no'])
        jcd      = VENUE_TO_JCD.get(venue)
        hd       = date_str.replace('-', '')

        print(f'[{i:>3}/{total}] {date_str} {venue} {race_no:>2}R', end='', flush=True)

        if not jcd:
            print(f'  [SKIP] 会場コード不明')
            continue

        signal.signal(signal.SIGALRM, _alarm_handler)
        signal.alarm(RACE_TIMEOUT_SEC)
        try:
            odds = scrape_odds(jcd, race_no, hd)
            if odds:
                print(f'  {len(odds)}通り', end='', flush=True)
                if send_odds(date_str, venue, race_no, odds, None):
                    odds_ok += 1
                else:
                    print('  [SEND FAIL]', end='')
                    odds_fail += 1
            else:
                print('  データなし(期限切れ?)', end='')
                odds_fail += 1
        except RaceTimeoutError:
            print('  [TIMEOUT]', end='')
            odds_fail += 1
        except Exception as e:
            print(f'  [ERROR] {e}', end='')
            odds_fail += 1
        finally:
            signal.alarm(0)

        print()
        time.sleep(SLEEP_SEC)

    print(f'\n--- オッズ取得完了 ---')
    print(f'成功: {odds_ok}件 / 失敗: {odds_fail}件 / 合計: {total}件\n')

    if odds_ok == 0:
        print('[WARN] 1件もoddsを取得できなかったため payout 再計算をスキップ')
        sys.exit(1)

    print('[backfill] payout再計算中...')
    try:
        result = trigger_payout_recalc()
    except Exception as e:
        print(f'[ERROR] payout再計算失敗: {e}')
        sys.exit(1)

    updated = result.get('updated', '?')
    skipped = result.get('skipped', '?')
    print(f'  更新: {updated}件 / スキップ(oddsなし): {skipped}件\n')

    before = result.get('before_stats', [])
    after  = result.get('after_stats',  [])
    after_map = {r['strategy_type']: r for r in after}

    print(f"{'戦略':<10} {'before ROI':>11} {'after ROI':>11} {'変化':>9}  "
          f"{'before payout':>14} {'after payout':>14}")
    print('-' * 75)
    for b in before:
        st = b['strategy_type']
        a  = after_map.get(st, {})
        br = b.get('roi', 0)
        ar = a.get('roi', 0)
        diff = ar - br
        bp = b.get('total_payout', 0)
        ap = a.get('total_payout', 0)
        gap  = b.get('remaining_gap', '?')
        agap = a.get('remaining_gap', '?')
        print(f'{st:<10} {br:>10.1f}% {ar:>10.1f}% {diff:>+8.1f}pt  '
              f'{bp:>14,} {ap:>14,}  (gap残: {agap}件)')
    print()
    print('完了!')


if __name__ == '__main__':
    main()
