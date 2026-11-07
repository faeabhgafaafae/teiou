<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../health_lib.php';

/**
 * 毎時ヘルスチェック・バッチ起動・表記統一(design_site_audit_20261105.md #1, #2, #21, #23):
 *  1. health_lib.php: 締切前生成の判定と理由の分類、1日の集計、チェック結果の記録
 *  2. 静的: boatrace.yml の job 指定起動・二重起動対策、healthcheck、送信の再試行
 *  3. 静的: 「回収率」の表記統一、戦略説明のレース日対応
 */
final class HealthCheckTest extends TestCase
{
    private function src(string $f): string
    {
        return file_get_contents(dirname(__DIR__) . '/' . $f);
    }

    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE races (id INTEGER PRIMARY KEY, date TEXT, venue TEXT, race_no INTEGER, scheduled_time TEXT)',
            'CREATE TABLE entries (race_id INTEGER, lane INTEGER, created_at TEXT)',
            'CREATE TABLE odds_3t (race_id INTEGER, combo TEXT, odds REAL)',
            'CREATE TABLE strategies (id INTEGER PRIMARY KEY, race_id INTEGER, strategy_type TEXT, combinations TEXT, created_at TEXT)',
            'CREATE TABLE results (race_id INTEGER, lane INTEGER, actual_rank INTEGER)',
        ] as $sql) {
            $pdo->exec($sql);
        }
        return $pdo;
    }

    private function race(PDO $pdo, int $id, string $time, ?string $entryAt, ?string $stratAt, bool $settled = false,
                          ?string $venue = '桐生', array $balance = ['1-2-3'], array $odds = []): void
    {
        $pdo->prepare('INSERT INTO races VALUES (?, ?, ?, ?, ?)')->execute([$id, '2026-11-06', $venue, $id, $time]);
        if ($entryAt) $pdo->prepare('INSERT INTO entries VALUES (?, 1, ?)')->execute([$id, $entryAt]);
        if ($stratAt) {
            $pdo->prepare("INSERT INTO strategies (race_id, strategy_type, combinations, created_at) VALUES (?, 'バランス', ?, ?)")
                ->execute([$id, json_encode($balance), $stratAt]);
        }
        foreach ($odds as $c => $o) $pdo->prepare('INSERT INTO odds_3t VALUES (?, ?, ?)')->execute([$id, $c, $o]);
        if ($settled) $pdo->prepare('INSERT INTO results VALUES (?, 1, 1)')->execute([$id]);
    }

    public function test_late_reasons_and_summary(): void
    {
        $pdo = $this->db();
        // 1: 締切前に生成 / 2: 出走表が締切後 / 3: 出走表が締切20分前(35分の窓に間に合わず)
        // 4: 出走表は朝にあったが生成されず(取りこぼし) / 5: 出走表なし / 6: 締切前(未来)のレース
        $this->race($pdo, 1, '10:00', '2026-11-06 07:30:00', '2026-11-06 09:40:00', true, '桐生', ['1-2-3', '6-5-4'], ['6-5-4' => 250.0]);
        $this->race($pdo, 2, '10:00', '2026-11-06 11:00:00', '2026-11-07 03:00:00', true);
        $this->race($pdo, 3, '10:00', '2026-11-06 09:40:00', '2026-11-07 03:00:00');
        $this->race($pdo, 4, '10:00', '2026-11-06 07:30:00', null);
        $this->race($pdo, 5, '10:00', null, null, false, '戸田');
        $this->race($pdo, 6, '12:30', '2026-11-06 07:30:00', null);

        $rows = hc_race_rows($pdo, '2026-11-06');
        $this->assertNull(hc_late_reason($rows[1]));
        $this->assertSame('racelist_after_deadline', hc_late_reason($rows[2]));
        $this->assertSame('racelist_too_close', hc_late_reason($rows[3]));
        $this->assertSame('pregen_missed', hc_late_reason($rows[4]));
        $this->assertSame('no_racelist', hc_late_reason($rows[5]));
        $this->assertTrue($rows[1]['balance_without_odds'], '現在のオッズで100倍超の組がバランスに残っている = 生成時にオッズなし');
        $this->assertFalse($rows[2]['balance_without_odds']);

        $s = hc_day_summary($rows, '2026-11-06 12:00:00');
        $this->assertSame(5, $s['past_deadline'], 'レース6は締切前なので対象外');
        $this->assertSame(1, $s['pregenerated']);
        $this->assertSame(20.0, $s['pregenerated_rate']);
        $this->assertSame(['no_racelist' => 1, 'racelist_after_deadline' => 1, 'racelist_too_close' => 1, 'pregen_missed' => 1,
                           'unknown_deadline' => 0], $s['reasons']);
        $this->assertSame(2, $s['missing_strategy']);
        $this->assertSame(['桐生'], $s['venues_with_entries']);
        $this->assertSame(2, $s['unsettled_past_deadline'], '出走表のある締切済みレース 1〜4 のうち未清算の 3, 4');
        $this->assertSame(1, $s['upcoming'], 'レース6(締切まで30分)');
        $this->assertSame(1, $s['odds_missing_upcoming']);
    }

    public function test_record_counts_attempts(): void
    {
        $pdo = $this->db();
        hc_record($pdo, '2026-11-06', 'results', 'retrying', '未清算 156R', true, '2026-11-07 01:20:00');
        hc_record($pdo, '2026-11-06', 'results', 'retrying', '結果待ち', false, '2026-11-07 02:20:00');
        hc_record($pdo, '2026-11-06', 'results', 'retrying', '2回目', true, '2026-11-07 03:20:00');
        $rows = hc_list($pdo, '2026-11-05');
        $this->assertCount(1, $rows);
        $this->assertSame(2, (int)$rows[0]['attempts']);
        $this->assertSame('2026-11-07 03:20:00', $rows[0]['last_action_at']);
        hc_record($pdo, '2026-11-06', 'results', 'ok', '問題なし', false, '2026-11-07 05:20:00');
        $this->assertSame('ok', hc_list($pdo, '2026-11-05')[0]['status']);
    }

    public function test_batch_dispatch_and_double_run_safety(): void
    {
        $yml = $this->src('.github/workflows/boatrace.yml');
        foreach (['morning', 'racelist', 'odds', 'odds_evening', 'results_yesterday', 'results_today', 'results'] as $job) {
            $this->assertStringContainsString("github.event.inputs.job == '$job'", $yml, "job=$job で起動できる");
        }
        $this->assertSame(3, substr_count($yml, '${{ !cancelled() &&'), 'job=odds で朝のオッズだけ動かせる(! で始まる if は ${{ }} で囲む)');
        $this->assertStringContainsString('git pull --rebase --autostash && git push', $yml, '二重起動時の push 衝突対策');
        $this->assertStringContainsString("TZ=Asia/Tokyo date +%Y-%m-%d", $yml, 'results_today は JST の当日');

        $hc = $this->src('.github/workflows/healthcheck.yml');
        $this->assertStringContainsString('actions: write', $hc);
        $this->assertStringContainsString('python healthcheck.py', $hc);
        $py = $this->src('healthcheck.py');
        $this->assertStringContainsString("'gh', 'workflow', 'run', 'boatrace.yml'", $py);
        $this->assertStringContainsString('MAX_ATTEMPTS = 2', $py);
        $this->assertStringContainsString("'レース中止' in html", $py, '中止レースは未清算として扱わない');
        $this->assertStringContainsString("'unsettled_races'", $this->src('health_check.php'));

        foreach (['scrape_racelist.py', 'scrape_boatrace.py'] as $f) {
            $this->assertStringContainsString('SEND_RETRY_WAITS', $this->src($f), "$f: 送信の再試行");
        }
        $this->assertStringContainsString('refresh_odds(', $this->src('pregenerate_upcoming.py'), '生成前にオッズを取得');
        $this->assertStringContainsString('hc_list(', $this->src('admin.php'), '管理画面にヘルスチェックの警告');
    }

    public function test_roi_means_payout_over_cost(): void
    {
        foreach (['performance.php', 'strategy.php', 'my-picks.php', 'ai-predict.php'] as $f) {
            $s = $this->src($f);
            $this->assertStringNotContainsString('回収率(損益率', $s, "$f: 回収率と損益率を混ぜない");
            $this->assertDoesNotMatchRegularExpression("/roi\s*>=\s*0\s*\?\s*'\+'/", $s, "$f: 符号付きの損益率を回収率として出さない");
        }
        $this->assertStringContainsString('(roi + 100).toFixed(1)', $this->src('performance.php'));
        $this->assertStringContainsString("'損益率(%)の推移", $this->src('performance.php'), 'グラフは損益率と明記');
    }

    public function test_strategy_description_follows_race_date(): void
    {
        $js = $this->src('strategy-colors.js');
        $this->assertStringContainsString("{ from: '" . TOKKA_FROM_DATE . "', desc: '上位" . TOKKA_POOL_SIZE . '艇から確率上位' . TOKKA_POINTS . "点' }", $js,
            'JS の最新ロジック = generate_strategies.php の定数');
        $this->assertStringContainsString("desc: '上位" . TOKKA_LEGACY_POOL . '艇から確率上位' . TOKKA_LEGACY_POINTS . "点'", $js);
        foreach (['ai-predict.php', 'strategy.php'] as $f) {
            $this->assertStringContainsString('var STRAT_DEFS = strategyDefs(date);', $this->src($f), "$f: 4戦略ともレース日の説明(共通定義)");
        }
        $this->assertStringContainsString("strategyDescription(t, raceDate)", $js);
    }
}
