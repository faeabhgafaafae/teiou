"""
backfill_grades.py — 過去日の会場グレード・開催名を races に保存する(2026-11-07〜)

出走表取込み(scrape_racelist.py)は当日分のグレードを保存する。それより前の日付は、boatrace.jp の開催一覧
(race/index?hd=YYYYMMDD、1日1リクエスト)から取得して import_grades.php に送る。

使い方: python backfill_grades.py --from 2026-10-01 --to 2026-11-06
環境変数: API_KEY / API_URL_GRADES(省略時は本番の import_grades.php)
"""
import argparse
import datetime as dt
import time

from scrape_racelist import send_grades


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--from', dest='date_from', required=True)
    ap.add_argument('--to', dest='date_to', required=True)
    args = ap.parse_args()
    d = dt.date.fromisoformat(args.date_from)
    end = dt.date.fromisoformat(args.date_to)
    while d <= end:
        print(d.isoformat())
        send_grades(d.strftime('%Y%m%d'))
        time.sleep(2)
        d += dt.timedelta(days=1)


if __name__ == '__main__':
    main()
