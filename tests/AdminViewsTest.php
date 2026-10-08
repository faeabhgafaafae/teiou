<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../admin_views.php';

/**
 * 管理ツールのHTML表示(admin_views.php、2026-10-08)のテスト。
 *  1. ?format=json のときは従来と同一の JSON を返す
 *  2. 表示関数: 項目名が日本語、数値が丸められている、欠損日の行が残る
 *  3. 静的: 各ツールが av_output() を使い、管理者チェックを維持し、WAF制約(テンプレートリテラル不可)に従う
 */
final class AdminViewsTest extends TestCase
{
    /** 英語の項目名(JSONのキー)が画面テキストに出ていないこと */
    private const RAW_KEYS = [
        'races_with_v2', 'races_with_v3w', 'races_settled', 'hit_rate', 'total_cost', 'total_payout',
        'v3w_lane1_rank1', 'lane1_wins', 'rewritten_races', 'entry_rate_pct',
        'hit_rate_active', 'avg_hit_payout', 'races_matched', 'exhibit_null', 'return_rate', 'roi',
    ];

    protected function tearDown(): void
    {
        unset($_GET['format']);
    }

    /** タグを除いた表示テキスト */
    private function text(string $html): string
    {
        return html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES, 'UTF-8');
    }

    private function assertReadable(string $html): void
    {
        $text = $this->text($html);
        foreach (self::RAW_KEYS as $k) {
            $this->assertDoesNotMatchRegularExpression('/\b' . preg_quote($k, '/') . '\b/', $text, "英語の項目名 {$k} が表示されている");
        }
        $this->assertDoesNotMatchRegularExpression('/\d\.\d{2,}%/', $text, '割合は小数1桁');
        $this->assertDoesNotMatchRegularExpression('/\d+\.\d{3,}/', $text, '丸められていない小数');
    }

    // ── 1. JSON モード ────────────────────────────────────────

    public function test_json_mode_returns_identical_json(): void
    {
        $data = ['from' => '2026-10-01', 'daily' => ['2026-10-01' => ['hit_rate' => 35.399999999999999, 'note' => '日本語']]];
        $_GET['format'] = 'json';
        ob_start();
        av_output($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT, 'model_switch_status', ['title' => 't', 'purpose' => 'p']);
        $out = ob_get_clean();
        $this->assertSame(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $out);
    }

    public function test_json_url_adds_format_param(): void
    {
        $_SERVER['REQUEST_URI'] = '/model_switch_status.php?from=2026-09-30&to=2026-10-08';
        $_GET = ['from' => '2026-09-30', 'to' => '2026-10-08'];
        $this->assertSame('/model_switch_status.php?from=2026-09-30&to=2026-10-08&format=json', av_json_url());
        $this->assertFalse(av_wants_json());
    }

    public function test_formatters(): void
    {
        $this->assertSame('35.4%', av_pct(35.399999999999999));
        $this->assertSame('-', av_pct(null));
        $this->assertSame('55.0%', av_rate(55, 100));
        $this->assertSame('-', av_rate(1, 0));
        $this->assertSame('1,234,567円', av_yen(1234567));
        $this->assertSame('12,345', av_int('12345'));
    }

    // ── 2. 表示関数 ──────────────────────────────────────────

    private function statusData(): array
    {
        $k = fn($n, $h, $c, $p) => ['n' => $n, 'hits' => $h, 'cost' => $c, 'payout' => $p,
                                    'hit_rate' => round($h / $n * 100, 1), 'roi' => round($p / $c * 100, 1)];
        return [
            'from' => '2026-10-04', 'to' => '2026-10-06',
            'config' => ['STRATEGY_MODEL_MAP' => ['的中特化' => 'v3w', 'バランス' => 'v3w', '一撃重視' => 'v2', '絞り込み' => 'v3w'],
                         'STRATEGY_MODEL_FALLBACK' => 'v2', 'PREDICTION_DISPLAY_MODEL' => 'v3w'],
            'daily' => [
                '2026-10-04' => ['races' => 156, 'races_with_v2' => 156, 'races_with_v3w' => 156, 'races_settled' => 156,
                    'strategies' => ['一撃重視' => ['v3w' => 156]],
                    'strategy_results' => ['一撃重視' => ['v3w' => $k(156, 14, 345600, 294538)]],
                    'top1' => ['races' => 156, 'v2_hits' => 77, 'v3w_hits' => 95, 'lane1_wins' => 93,
                               'v2_lane1_rank1' => 96, 'v3w_lane1_rank1' => 118]],
                '2026-10-05' => ['races' => 144, 'races_with_v2' => 0, 'races_with_v3w' => 0, 'races_settled' => 144,
                    'strategies' => [], 'strategy_results' => []],
                '2026-10-06' => ['races' => 156, 'races_with_v2' => 156, 'races_with_v3w' => 156, 'races_settled' => 156,
                    'strategies' => ['一撃重視' => ['v2' => 156]],
                    'strategy_results' => ['一撃重視' => ['v2' => $k(156, 9, 330000, 184470)]],
                    'top1' => ['races' => 156, 'v2_hits' => 78, 'v3w_hits' => 75, 'lane1_wins' => 89,
                               'v2_lane1_rank1' => 102, 'v3w_lane1_rank1' => 118]],
            ],
        ];
    }

    public function test_model_switch_status_view(): void
    {
        $html = av_view_model_switch_status($this->statusData());
        $this->assertReadable($html);
        $text = $this->text($html);
        foreach (['現在の設定', '1着的中率', '4戦略の成績', 'v2予測あり', 'v3w予測あり', 'フォールバック先', '期間累計'] as $s) {
            $this->assertStringContainsString($s, $text);
        }
        $this->assertMatchesRegularExpression('/2026-10-05\s+欠損/u', $text, '欠損日の行を残して「欠損」と表示');
        $this->assertSame(1, substr_count($text, '集計バッチの障害'), '欠損の理由は上部の注意欄に1回だけ');
        $this->assertStringContainsString('60.9%', $text, '95/156 = 60.9%');
        $this->assertStringContainsString('345,600円', $text, '金額は3桁区切りの円');
        $this->assertStringContainsString('85.2%', $text, '回収率 294538/345600 = 85.2%');
        $this->assertStringContainsString('312', $text, '期間累計のレース数 156+156');
    }

    public function test_other_views_are_readable(): void
    {
        $this->assertReadable(av_view_audit_leak_rewrites([
            'totals' => ['settled_races' => 300, 'races_with_predictions' => 300, 'rewritten_races' => 0, 'rewritten_strategies' => 0,
                         'rewritten_strategy_results' => 0, 'top1_hits_rewritten' => 0, 'top1_hits_clean' => 150, 'clean_races' => 300,
                         'top1_rate_rewritten' => null, 'top1_rate_clean' => 48.799999999999997],
            'daily' => ['2026-10-01' => ['settled_races' => 168, 'races_with_predictions' => 168, 'rewritten_races' => 0,
                'rewritten_strategies' => 0, 'rewritten_strategy_results' => 0, 'top1_hits_rewritten' => 0, 'top1_hits_clean' => 74,
                'clean_races' => 168, 'first_rewrite_at' => null, 'last_rewrite_at' => null]],
        ]));
        $row = ['total_races' => 100, 'active_races' => 80, 'entry_rate_pct' => 80.0, 'avg_combos' => 7.123456, 'hits' => 30,
                'hit_rate_active' => 37.5, 'hit_rate_all' => 30.0, 'avg_hit_payout' => 2345, 'total_cost' => 56000,
                'total_payout' => 48000, 'profit' => -8000, 'roi' => 85.7];
        $this->assertReadable(av_view_simulate_balance([
            'simulation_days' => 30, 'date_from' => '2026-09-08', 'date_to' => '2026-10-08', 'v2_cutover' => '2026-08-28',
            'part1_diagnosis' => ['v2期(08-28〜)' => ['races' => 100, 'rank1_lane1_pct' => 62.5, 'combo_odds_le25_pct' => 40.333,
                'combo_odds_avg' => 33.66, 'nofilter' => ['hits' => 40, 'cost' => 120000, 'payout' => 90000, 'hit_rate' => 40.0, 'roi' => 75.0],
                'cap25' => ['hits' => 30, 'cost' => 50000, 'payout' => 40000, 'hit_rate' => 30.0, 'roi' => 80.0],
                'lost_hits_by_cap25' => 10, 'lost_payout_by_cap25' => 50000, 'avg_hit_payout_nofilter' => 2250, 'avg_hit_payout_cap25' => 1333]],
            'part2_variants' => ['v2_races' => 100, 'results' => [['variant' => '上限100倍'] + $row]],
        ]));
        $this->assertReadable(av_view_simulate_ichigeki([
            'simulation_days' => 7, 'race_count_total' => 100, 'date_from' => '2026-10-01', 'date_to' => '2026-10-08',
            'results' => [['pool' => '2-4位', 'min_odds' => 15.0] + $row],
        ]));
        foreach ([
            ['mode' => 'weekly', 'data' => [['week_start' => '2026-09-28', 'week_end' => '2026-10-04', 'total_races' => 900, 'hits' => 90,
                'hit_rate' => 10.0, 'total_cost' => 360000, 'total_payout' => 300000, 'roi' => -16.7, 'return_rate' => 83.3]]],
            ['mode' => 'odds_dist', 'start' => '2026-08-28', 'end' => '2026-09-07',
             'data' => [['odds_range' => '10-20', 'combos_checked' => 500, 'hits' => 40]]],
            ['mode' => 'payout_compare', 'data' => [['label' => '8/28-9/3', 'total_races' => 100, 'hits' => 10, 'hit_rate' => 10.0,
                'return_rate' => 90.1, 'avg_cost' => 412.0, 'avg_hit_payout' => 3999.6, 'max_hit_payout' => 12000]]],
        ] as $d) {
            $this->assertReadable(av_view_analyze_ichigeki($d));
        }
        $this->assertReadable(av_view_backfill_list_run([
            'race_count' => 1,
            'races' => [['race_id' => 150001, 'date' => '2026-10-01', 'venue' => '戸田', 'race_no' => 1, 'scheduled_time' => '10:30:00']],
            'entries_summary' => ['total_entries' => 100000, 'exhibit_null' => 1234, 'st_null' => 99],
            'entries_summary_week_0715_0722' => ['total_entries' => 5000, 'exhibit_null' => 12, 'st_null' => 3],
        ]));
        $this->assertReadable(av_view_shadow_eval_v3([
            'top1' => ['v2' => ['races' => 2675, 'hit_rate' => 62.200000000000003], 'v3' => ['races' => 2675, 'hit_rate' => 56.600000000000001]],
            'daily' => ['2026-09-29' => ['v2_hits' => 73, 'v2_races' => 144, 'v3_hits' => 85, 'v3_races' => 144]],
            'strategy_sim_v3' => ['的中特化' => ['races' => 144, 'hit_rate' => 23.100000000000001, 'roi' => 73.299999999999997, 'avg_combos' => 6]],
            'strategy_prod_v2' => ['的中特化' => ['races' => 144, 'hit_rate' => 37.3, 'roi' => 87.8]],
        ]));
        $this->assertReadable(av_view_export_lr_stats([
            'from' => '2026-10-01', 'to' => '2026-10-01',
            'stats' => [['date' => '2026-10-01', 'venue' => '戸田', 'races_total' => 12, 'races_matched' => 11]],
        ]));
    }

    // ── 3. 静的チェック ───────────────────────────────────────

    public function test_tool_pages_use_av_output_and_keep_admin_guard(): void
    {
        $root = dirname(__DIR__);
        $guards = [
            'model_switch_status.php' => 'require_admin_or_api_key', 'audit_leak_rewrites.php' => 'require_admin_or_api_key',
            'simulate_balance.php' => 'require_admin_or_api_key', 'simulate_ichigeki.php' => 'require_admin_or_api_key',
            'shadow_eval_v3.php' => 'require_admin_or_api_key', 'export_lr_data_v3.php' => 'require_admin_or_api_key',
            'analyze_ichigeki.php' => 'require_admin_json', 'backfill_list_run.php' => 'require_admin_json',
        ];
        foreach ($guards as $file => $guard) {
            $src = file_get_contents("$root/$file");
            $this->assertStringContainsString('av_output(', $src, "$file: HTML/JSON 切り替え");
            $g = strpos($src, $guard . '(');
            $this->assertNotFalse($g, "$file: 管理者チェック {$guard}() を維持");
            $this->assertLessThan(strpos($src, 'av_output('), $g, "$file: 出力より前に管理者チェック");
        }
        $this->assertStringContainsString('format=json', file_get_contents("$root/admin_v2.php"),
            'admin_v2.php は shadow_eval_v3.php の JSON を内部で使うため format=json を付ける');
    }

    public function test_views_follow_waf_constraints(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/admin_views.php');
        $this->assertStringNotContainsString('`', $src, 'WAF: テンプレートリテラル不可');
        $this->assertStringContainsString('JSONで見る', $src);
        $this->assertDoesNotMatchRegularExpression('/<link[^>]+href="(?!style\.css)/', $src, 'WAF: 追加の外部CSSなし');
    }
}
