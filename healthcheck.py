"""
healthcheck.py — データ取得・生成・清算の毎時ヘルスチェックと自動再実行(2026-11-07〜)

cron-job.org から毎時 healthcheck.yml を起動して実行する。当日と前日について health_check.php で
状態を確認し、欠けがあれば boatrace.yml を job / target_date を指定して再実行する。
同じ日・同じ項目の再実行は最大 MAX_ATTEMPTS 回まで。それでも直らなければ alert として記録し、
admin.php のデータ品質欄に警告を出す(再実行の直後は結果待ちとして WAIT_MIN 分は再実行しない)。

| 項目 | 対象日・時刻(JST) | 欠けの判定 | 再実行 |
|---|---|---|---|
| racelist(出走表) | 当日 8:00〜20:00 | boatrace.jp の開催場のうち、出走表が1件も無い場 | job=racelist |
| odds(オッズ) | 当日 8:30〜20:30 | 締切60分以内のレースの半数以上(3R以上)でオッズなし | job=odds |
| results(成績・買い目) | 前日(終日)・当日 23:00〜 | 締切済みで未清算、または買い目なしのレースが3R以上 | job=results + target_date |

環境変数: API_KEY / HEALTH_URL / GH_TOKEN(GITHUB_TOKEN、actions: write)/ GITHUB_REPOSITORY
"""
import datetime as dt
import json
import os
import subprocess
import sys

import requests

from scrape_racelist import get_today_venues, VENUES

API_KEY      = os.environ.get('API_KEY', '')
HEALTH_URL   = os.environ.get('HEALTH_URL') or 'https://2410049.moo.jp/health_check.php'
REPO         = os.environ.get('GITHUB_REPOSITORY', 'faeabhgafaafae/teiou')
DRY_RUN      = os.environ.get('HEALTH_DRY_RUN') == '1'   # 1 なら再実行・記録をせず判定だけ表示
MAX_ATTEMPTS = 2
WAIT_MIN     = 75     # 再実行してからこの分数は結果待ち(boatrace.yml の各ジョブは長いもので約60分)
MIN_BAD      = 3      # 中止レース等の誤検知を避けるため、これ未満の欠けは問題にしない
JST          = dt.timezone(dt.timedelta(hours=9))


def get_status(date: str) -> dict:
    res = requests.get(HEALTH_URL, params={'api_key': API_KEY, 'date': date}, timeout=60)
    res.raise_for_status()
    return res.json()


def record(date: str, check: str, status: str, detail: str, action: bool) -> None:
    print(f'  [{date} {check}] {status}{" (再実行)" if action else ""}: {detail}')
    if DRY_RUN:
        return
    requests.post(HEALTH_URL, params={'api_key': API_KEY}, timeout=60, json={
        'date': date, 'check': check, 'status': status, 'detail': detail, 'action': action,
    }).raise_for_status()


def dispatch(job: str, target_date: str | None = None) -> bool:
    cmd = ['gh', 'workflow', 'run', 'boatrace.yml', '--repo', REPO, '-f', f'job={job}']
    if target_date:
        cmd += ['-f', f'target_date={target_date}']
    print('  → ' + ' '.join(cmd))
    if DRY_RUN:
        return True
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.returncode != 0:
        print(f'  [DISPATCH ERROR] {r.stderr.strip()}')
    return r.returncode == 0


def handle(date: str, check: str, problem: str | None, job: str, target_date: str | None,
           prev: dict | None, now: dt.datetime) -> None:
    """problem が None なら ok。あれば再実行の上限・待ち時間に応じて retrying / alert を記録する"""
    if problem is None:
        record(date, check, 'ok', '問題なし', False)
        return
    attempts = int(prev['attempts']) if prev else 0
    last = prev.get('last_action_at') if prev else None
    if last:
        since = (now - dt.datetime.strptime(last, '%Y-%m-%d %H:%M:%S').replace(tzinfo=JST)).total_seconds() / 60
        if since < WAIT_MIN:
            record(date, check, 'retrying', f'{problem}(再実行{attempts}回目の結果待ち)', False)
            return
    if attempts >= MAX_ATTEMPTS:
        record(date, check, 'alert', f'{problem}(自動再実行 {attempts}回でも解消せず。手動対応が必要)', False)
        return
    ok = dispatch(job, target_date)
    record(date, check, 'retrying' if ok else 'alert',
           f'{problem}(自動再実行 {attempts + 1}回目)' if ok else f'{problem}(再実行の起動に失敗)', ok)


def main() -> int:
    if not API_KEY:
        print('[ERROR] API_KEY が未設定です')
        return 1
    now = dt.datetime.now(JST)
    today = now.strftime('%Y-%m-%d')
    yesterday = (now - dt.timedelta(days=1)).strftime('%Y-%m-%d')
    hm = now.strftime('%H:%M')
    print(f'[healthcheck] {now:%Y-%m-%d %H:%M} JST')

    st_today = get_status(today)
    st_yday = get_status(yesterday)
    prev = {(c['check_date'], c['check_name']): c for c in st_today['checks'] + st_yday['checks']}
    s = st_today['summary']

    # 出走表: boatrace.jp の開催場と、DB で出走表のある場を比べる
    if '08:00' <= hm <= '20:00':
        expected = [VENUES.get(j, j) for j in get_today_venues(now.strftime('%Y%m%d'))]
        have = set(s['venues_with_entries'])
        missing = [v for v in expected if v not in have]
        problem = f'出走表なし {len(missing)}場({"・".join(missing)})/ 開催{len(expected)}場' if missing else None
        handle(today, 'racelist', problem, 'racelist', None, prev.get((today, 'racelist')), now)

    # オッズ: 締切60分以内のレースのオッズ
    if '08:30' <= hm <= '20:30':
        bad, total = s['odds_missing_upcoming'], s['upcoming']
        problem = (f'締切60分以内の{total}R中{bad}Rでオッズなし'
                   if bad >= MIN_BAD and bad * 2 >= total else None)
        handle(today, 'odds', problem, 'odds', None, prev.get((today, 'odds')), now)

    # 成績・買い目: 前日は終日、当日は 23:00 以降
    targets = [(yesterday, st_yday['summary'])] + ([(today, s)] if hm >= '23:00' else [])
    for date, sm in targets:
        if sm['races'] == 0:
            continue
        parts = []
        if sm['unsettled_past_deadline'] >= MIN_BAD:
            parts.append(f'未清算 {sm["unsettled_past_deadline"]}R')
        if sm['missing_strategy'] >= MIN_BAD:
            parts.append(f'買い目なし {sm["missing_strategy"]}R')
        problem = ' / '.join(parts) + f'(締切済み {sm["past_deadline"]}R)' if parts else None
        handle(date, 'results', problem, 'results', date, prev.get((date, 'results')), now)
    return 0


if __name__ == '__main__':
    sys.exit(main())
