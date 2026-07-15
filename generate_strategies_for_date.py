#!/usr/bin/env python3
"""
艇王 - 指定日の全レース分「戦略買い目」を事前生成する

的中速報(strategy_results)は import_results.php が結果取込み時点で
存在する strategies 行としか照合しない。従来は誰かが ai-predict.php を
閲覧した(=api_predict.php が呼ばれた)レースにしか strategies が
存在せず、閲覧のなかったレースは結果を取り込んでも永久に的中判定
されなかった。

これを解消するため、fetch_results ジョブで download_results.py を
実行する前に、対象日の全レースへ api_predict.php を機械的に叩いて
strategies を生成しておく(閲覧有無に依存させない)。

使い方:
  python generate_strategies_for_date.py --date 2026-07-14
"""

import argparse
import os
import time

import requests

API_VENUES  = os.environ.get('API_VENUES',  'https://2410049.moo.jp/venues.php')
API_PREDICT = os.environ.get('API_PREDICT', 'https://2410049.moo.jp/api_predict.php')
SLEEP_SEC   = 0.3


def get_venues(date_str: str) -> list:
    res = requests.get(API_VENUES, params={'date': date_str}, timeout=15)
    res.raise_for_status()
    data = res.json()
    if 'error' in data:
        raise RuntimeError(f'venues.php error: {data["error"]}')
    return data.get('venues', [])


def generate_for_race(date_str: str, venue: str, race_no: int) -> bool:
    try:
        res = requests.get(API_PREDICT, params={
            'date':    date_str,
            'venue':   venue,
            'race_no': race_no,
        }, timeout=30)
        res.raise_for_status()
        data = res.json()
        if 'error' in data:
            print(f'  [{venue}] {race_no}R [SKIP] {data["error"]}')
            return False
        return True
    except Exception as e:
        print(f'  [{venue}] {race_no}R [ERROR] {e}')
        return False


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--date', required=True, help='YYYY-MM-DD')
    args = parser.parse_args()

    print(f'[generate_strategies_for_date] 対象日: {args.date}')
    try:
        venues = get_venues(args.date)
    except Exception as e:
        print(f'[ERROR] 開催場一覧取得失敗: {e}')
        return

    if not venues:
        print('  対象レースなし')
        return

    total_races = sum(int(v['race_count']) for v in venues)
    print(f'  対象: {len(venues)}会場 / {total_races}レース')

    ok = 0
    for v in venues:
        venue      = v['venue']
        race_count = int(v['race_count'])
        for race_no in range(1, race_count + 1):
            if generate_for_race(args.date, venue, race_no):
                ok += 1
            time.sleep(SLEEP_SEC)

    print(f'完了: {ok}/{total_races}レース分の戦略を生成')


if __name__ == '__main__':
    main()
