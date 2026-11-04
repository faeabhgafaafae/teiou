<?php
use PHPUnit\Framework\TestCase;

/**
 * サイト点検(design_site_audit_20261105.md)の対応内容の静的チェック:
 *  - 締切前の予想・買い目生成(live.yml + pregenerate_upcoming.py)と管理画面の表示
 *  - 廃止済み api_v2_batch.php の削除
 *  - 未ログイン時の表示(me.php は 200+null、ログイン状態の取得はページ内1回、ログイン誘導)
 *  - 説明文の食い違い(AI予想・1点100円換算・未実装の特典)
 *  - 開催0場の表示、今日の的中のリーク期間
 */
final class SiteFixesTest extends TestCase
{
    private function src(string $f): string
    {
        return file_get_contents(dirname(__DIR__) . '/' . $f);
    }

    public function test_pregeneration_job_runs_before_deadline(): void
    {
        $yml = $this->src('.github/workflows/live.yml');
        $this->assertStringContainsString('pregenerate_upcoming:', $yml);
        $this->assertStringContainsString('python pregenerate_upcoming.py', $yml);
        $py = $this->src('pregenerate_upcoming.py');
        $this->assertStringContainsString("'all': '1'", $py, '当日の全レースから締切が近いものを選ぶ');
        $this->assertMatchesRegularExpression('/0 <= m <= WINDOW_MIN/', $py, '締切を過ぎたレースは呼ばない');
        $this->assertStringContainsString('API_PREDICT', $py);
    }

    public function test_admin_shows_late_generation_and_odds_note(): void
    {
        $admin = $this->src('admin.php');
        $this->assertStringContainsString('ms_strategy_timing(', $admin);
        $this->assertStringContainsString('締切後に初めて生成された買い目', $admin);
        $this->assertStringContainsString('締切前に生成された割合', $admin);
        $this->assertStringContainsString('確定オッズ', $admin, 'バランス・一撃重視のオッズ条件の注記');
    }

    public function test_retired_v2_batch_removed(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__) . '/api_v2_batch.php');
    }

    public function test_guest_handling(): void
    {
        $me = $this->src('me.php');
        $this->assertStringNotContainsString('require_login()', $me, '未ログインでも 401 にしない');
        $this->assertStringContainsString("'user' => current_user()", $me);

        // ログイン状態の取得は sidebar.php の getMe() に集約(ページ内で1回だけ fetch)
        $this->assertStringContainsString('window.getMe = function', $this->src('sidebar.php'));
        foreach (['app.js', 'common-header.js', 'mypage.php', 'ai-predict.php', 'predictions.php', 'strategy.php'] as $f) {
            $this->assertStringNotContainsString("fetch('me.php')", $this->src($f), $f);
        }

        $this->assertStringContainsString("header('Location: login.html')", $this->src('mypage.php'));
        $this->assertStringContainsString("header('Location: login.html')", $this->src('my-picks.php'));
        $this->assertStringNotContainsString("Location: login.php", $this->src('my-picks.php'), 'login.php は POST 専用');
        $this->assertStringContainsString('showExplainLoginPrompt', $this->src('ai-predict.php'));
    }

    public function test_wording_matches_implementation(): void
    {
        $files = ['plan-features.js', 'predict.php', 'predictions.php', 'sidebar.php', 'ai-predict.php',
                  'performance.php', 'strategy.php', 'strategy_detail.php', 'gemini_explain.php', 'mypage.php', 'index.php'];
        foreach ($files as $f) {
            $s = $this->src($f);
            $this->assertDoesNotMatchRegularExpression('/AI ?(予測|予想)/u', $s, "$f: 予想は統計モデル");
            $this->assertStringNotContainsString('予測の精度', $s, $f);
            $this->assertStringNotContainsString('1点100円換算', $s, $f);
        }
        $plan = $this->src('plan-features.js');
        $this->assertDoesNotMatchRegularExpression("/label: '(広告非表示|優先サポート)'/u", $plan, '未実装の特典は載せない');
        $this->assertStringNotContainsString('広告非表示', $this->src('sidebar.php'));
        $this->assertStringContainsString('一部STANDARD+', $this->src('sidebar.php'), '無料部分のある項目');
    }

    public function test_no_race_day_messages(): void
    {
        $this->assertStringContainsString('本日の開催はありません', $this->src('app.js'));
        $this->assertStringContainsString('本日の開催はありません', $this->src('sidebar.php'));
        $this->assertStringNotContainsString('statsBadge', $this->src('app.js'), '本日のレース数は sidebar.php に集約');
    }

    public function test_hits_hide_leak_period(): void
    {
        $api = $this->src('get_hits.php');
        $this->assertStringContainsString('public_exclusion_sql(', $api);
        $this->assertStringContainsString('public_excluded_periods()', $api);
        $this->assertStringContainsString('この期間の的中は表示していません', $this->src('home-races.js'));
    }

    public function test_waf_no_template_literals(): void
    {
        foreach (['app.js', 'common-header.js', 'home-races.js', 'sidebar.php', 'mypage.php', 'ai-predict.php', 'admin.php'] as $f) {
            preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $this->src($f), $m);
            $js = str_ends_with($f, '.js') ? $this->src($f) : implode("\n", $m[1]);
            $this->assertStringNotContainsString('`', preg_replace('/<\?(php|=).*?\?>/s', '', $js), "$f: テンプレートリテラル不可");
        }
    }
}
