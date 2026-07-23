#!/usr/bin/env python3
"""
バックフィル状況チェック
6/29~7/14の全会場・全レースの成績データ有無を確認し、
未処理分のリストを返す
"""
import sys
import time
import requests
from datetime import date, timedelta
from urllib.parse import quote

API_BASE = 'https://2410049.moo.jp'

VENUES = [
    '桐生','戸田','江戸川','平和島','多摩川','浜名湖','蒲郡','常滑','津','三国',
    '琵琶湖','住之江','尼崎','鳴門','高松','丸亀','児島','宮島','徳山','下関',
    '若松','芦屋','福岡','唐津','大村',
]

START = date(2026, 6, 29)
END   = date(2026, 7, 14)

sess = requests.Session()
sess.headers['User-Agent'] = 'Mozilla/5.0'

def check_date(d: date):
    total_races   = 0
    races_no_result = 0
    active_venues = []

    for v in VENUES:
        url = f'{API_BASE}/api_races.php?date={d}&venue={quote(v)}'
        try:
            r = sess.get(url, timeout=10)
            data = r.json()
            races = data.get('races', [])
            if not races:
                continue
            active_venues.append(v)
            for race in races:
                total_races += 1
                if not race.get('has_result'):
                    races_no_result += 1
        except Exception as e:
            print(f'  [WARN] {d} {v}: {e}', flush=True)
        time.sleep(0.1)   # 軽いウェイト

    return {
        'date':           str(d),
        'active_venues':  active_venues,
        'total_races':    total_races,
        'races_with_result': total_races - races_no_result,
        'races_no_result':   races_no_result,
    }


def main():
    print(f'=== バックフィル状況チェック {START} ~ {END} ===\n', flush=True)

    summary = []
    cur = START
    while cur <= END:
        print(f'[{cur}] チェック中...', end=' ', flush=True)
        info = check_date(cur)
        summary.append(info)

        venues_str = ', '.join(info['active_venues']) if info['active_venues'] else 'なし'
        print(
            f"会場:{len(info['active_venues'])}場 "
            f"レース:{info['total_races']}件 "
            f"成績あり:{info['races_with_result']} "
            f"成績なし:{info['races_no_result']}",
            flush=True
        )
        cur += timedelta(days=1)

    print('\n' + '='*60)
    print('【集計結果】')
    total_races   = sum(s['total_races']        for s in summary)
    total_with    = sum(s['races_with_result']   for s in summary)
    total_without = sum(s['races_no_result']     for s in summary)
    print(f'  総レース数        : {total_races}')
    print(f'  成績あり          : {total_with}')
    print(f'  成績なし(未処理) : {total_without}')
    if total_races > 0:
        print(f'  欠損率            : {total_without/total_races*100:.1f}%')

    print('\n【日別サマリー】')
    print(f'{"日付":<12} {"会場数":>5} {"レース":>6} {"成績あり":>8} {"成績なし":>8}')
    print('-' * 46)
    for s in summary:
        print(
            f"{s['date']:<12} {len(s['active_venues']):>5} "
            f"{s['total_races']:>6} {s['races_with_result']:>8} {s['races_no_result']:>8}"
        )

    missing_dates = [s['date'] for s in summary if s['races_no_result'] > 0]
    if missing_dates:
        print(f'\n【未処理日付】 {len(missing_dates)}日')
        for d in missing_dates:
            print(f'  {d}')
    else:
        print('\n全日付のデータが完全に揃っています。バックフィルは完了済みです。')

    return missing_dates


if __name__ == '__main__':
    missing = main()
    if missing:
        print(f'\n再実行コマンド:')
        print(f'  python download_results.py --start {missing[0]} --end {missing[-1]}')
    sys.exit(0 if not missing else 1)
