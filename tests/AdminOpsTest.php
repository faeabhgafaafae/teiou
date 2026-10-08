<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../model_status_lib.php';
require_once __DIR__ . '/../data_quality.php';

/**
 * 管理画面のモデル運用状況・データ品質欄(2026-10-08)のテスト。
 *  1. data_quality.php の期間該当判定(performance.php と admin.php で共用)
 *  2. model_status_lib.php の集計(model_switch_status.php と admin.php で共用)を SQLite で検証
 *  3. 静的: 画面側が共用関数を使い、集計SQLを重複実装していないこと・WAF制約(テンプレートリテラル不可)
 */
final class AdminOpsTest extends TestCase
{
    private function root(): string { return dirname(__DIR__); }

    // ── 1. データ品質 ─────────────────────────────────────────

    public function test_issue_definitions_are_well_formed(): void
    {
        foreach (DATA_QUALITY_ISSUES as $i) {
            $this->assertContains($i['type'], ['leak', 'gap']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $i['from']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $i['to']);
            $this->assertLessThanOrEqual($i['to'], $i['from']);
            $this->assertNotSame('', $i['text']);
        }
    }

    public function test_issues_overlapping_period(): void
    {
        $types = fn(array $xs) => array_column($xs, 'type');
        $this->assertSame(['leak', 'gap'], $types(data_quality_issues('2026-06-29', '2026-10-08')));
        $this->assertSame(['gap'], $types(data_quality_issues('2026-09-09', '2026-10-08', ['gap'])));
        $this->assertSame(['leak'], $types(data_quality_issues('2026-09-28', '2026-09-28')), '期間の終端日を含む');
        $this->assertSame([], data_quality_issues('2026-09-29', '2026-10-04'), 'リーク後・欠損前は該当なし');
        $this->assertSame([], data_quality_issues('2026-10-06', '2026-10-08'), '欠損日の翌日以降は該当なし');
        $this->assertSame(['gap'], $types(data_quality_issues('2026-10-05', '2026-10-05')));
    }

    public function test_issue_on_date(): void
    {
        $this->assertSame('gap', data_quality_issue_on('2026-10-05')['type']);
        $this->assertSame('leak', data_quality_issue_on('2026-08-19')['type']);
        $this->assertNull(data_quality_issue_on('2026-10-06'));
    }

    // ── 2. 集計ライブラリ ─────────────────────────────────────

    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE races (id INTEGER PRIMARY KEY, date TEXT)',
            'CREATE TABLE entries (race_id INTEGER, lane INTEGER, player_id INTEGER)',
            'CREATE TABLE results (race_id INTEGER, lane INTEGER, player_id INTEGER, actual_rank INTEGER)',
            'CREATE TABLE predictions (race_id INTEGER, player_id INTEGER, predicted_rank INTEGER)',
            'CREATE TABLE predictions_v2 (race_id INTEGER, player_id INTEGER, predicted_rank INTEGER)',
            'CREATE TABLE strategies (id INTEGER PRIMARY KEY, race_id INTEGER, strategy_type TEXT, model_ref TEXT)',
            'CREATE TABLE strategy_results (id INTEGER PRIMARY KEY, strategy_id INTEGER, race_id INTEGER,
                is_hit INTEGER, cost INTEGER, payout INTEGER, model_ref TEXT)',
        ] as $ddl) {
            $pdo->exec($ddl);
        }
        return $pdo;
    }

    /** レース: 勝者枠・v2本命枠・v3w本命枠を指定(v3w が null なら predictions_v2 なし) */
    private function race(PDO $pdo, int $id, string $date, ?int $winner, int $v2top, ?int $v3top): void
    {
        $pdo->prepare('INSERT INTO races (id, date) VALUES (?, ?)')->execute([$id, $date]);
        for ($lane = 1; $lane <= 6; $lane++) {
            $pid = $id * 10 + $lane;
            $pdo->prepare('INSERT INTO entries VALUES (?, ?, ?)')->execute([$id, $lane, $pid]);
            if ($winner !== null) {
                $rank = $lane === $winner ? 1 : ($lane < $winner ? $lane + 1 : $lane);
                $pdo->prepare('INSERT INTO results VALUES (?, ?, ?, ?)')->execute([$id, $lane, $pid, $rank]);
            }
            $pdo->prepare('INSERT INTO predictions VALUES (?, ?, ?)')
                ->execute([$id, $pid, $lane === $v2top ? 1 : ($lane < $v2top ? $lane + 1 : $lane)]);
            if ($v3top !== null) {
                $pdo->prepare('INSERT INTO predictions_v2 VALUES (?, ?, ?)')
                    ->execute([$id, $pid, $lane === $v3top ? 1 : ($lane < $v3top ? $lane + 1 : $lane)]);
            }
        }
    }

    public function test_top1_daily_counts_only_settled_races_with_both_predictions(): void
    {
        $pdo = $this->db();
        // 10-01: 勝者1・v2本命1・v3w本命1 / 勝者3・v2本命1・v3w本命3
        $this->race($pdo, 1, '2026-10-01', 1, 1, 1);
        $this->race($pdo, 2, '2026-10-01', 3, 1, 3);
        // 10-02: 勝者2・v2本命2・v3w本命1
        $this->race($pdo, 3, '2026-10-02', 2, 2, 1);
        // 対象外: 結果なし / v3w 予測なし / 期間外
        $this->race($pdo, 4, '2026-10-02', null, 1, 1);
        $this->race($pdo, 5, '2026-10-02', 1, 1, null);
        $this->race($pdo, 6, '2026-09-29', 1, 1, 1);

        $daily = ms_top1_daily($pdo, '2026-09-30', '2026-10-02');
        $this->assertSame(['2026-10-01', '2026-10-02'], array_keys($daily));
        $this->assertSame(['races' => 2, 'v2_hits' => 1, 'v3w_hits' => 2, 'lane1_wins' => 1,
                           'v2_lane1_rank1' => 2, 'v3w_lane1_rank1' => 1], $daily['2026-10-01']);
        $sum = ms_sum_top1($daily);
        $this->assertSame(3, $sum['races']);
        $this->assertSame(2, $sum['v2_hits']);
        $this->assertSame(2, $sum['v3w_hits']);
        $this->assertSame(1, $sum['lane1_wins']);
    }

    public function test_strategy_results_by_model_ref_and_sum(): void
    {
        $pdo = $this->db();
        $pdo->exec("INSERT INTO races VALUES (1, '2026-10-07'), (2, '2026-10-08')");
        $pdo->exec("INSERT INTO strategies VALUES (1, 1, '一撃重視', 'v3w'), (2, 2, '一撃重視', 'v2'), (3, 2, '的中特化', 'v3w')");
        $pdo->exec("INSERT INTO strategy_results VALUES
            (1, 1, 1, 1, 600, 1800, 'v3w'),
            (2, 2, 2, 0, 600, 0,    'v2'),
            (3, 3, 2, 1, 900, 450,  'v3w')");

        $daily = ms_strategy_results($pdo, '2026-10-07', '2026-10-08');
        $this->assertSame(300.0, $daily['2026-10-07']['一撃重視']['v3w']['roi']);
        $sum = ms_sum_strategy_results($daily);
        $this->assertSame(['v3w', 'v2'], array_keys($sum['一撃重視']), '7日の model_ref 混在を区別できる');
        $this->assertSame(1, $sum['一撃重視']['v2']['n']);
        $this->assertSame(0.0, $sum['一撃重視']['v2']['hit_rate']);
        $this->assertSame(50.0, $sum['的中特化']['v3w']['roi']);

        $counts = ms_strategy_counts($pdo, '2026-10-07', '2026-10-08');
        $this->assertSame(['v2' => 1], $counts['2026-10-08']['一撃重視']);
    }

    public function test_prediction_coverage(): void
    {
        $pdo = $this->db();
        $this->race($pdo, 1, '2026-10-05', 1, 1, null);
        $this->race($pdo, 2, '2026-10-05', null, 1, 1);
        $cov = ms_prediction_coverage($pdo, '2026-10-05', '2026-10-05');
        $this->assertSame(['races' => 2, 'races_with_v2' => 2, 'races_with_v3w' => 1, 'races_settled' => 1],
                          $cov['2026-10-05']);
    }

    // ── 運用定数 ──────────────────────────────────────────────

    public function test_model_switch_history_constants(): void
    {
        $dates = array_column(MODEL_SWITCH_HISTORY, 'date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates, '履歴は日付順');
        $this->assertSame(V3W_PRODUCTION_FROM, $dates[0], 'v3w 本番化の起点 = 最初の切り替え日');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', NEXT_MODEL_REVIEW['date']);
    }

    // ── 3. 静的チェック ───────────────────────────────────────

    /** ページ内 <script> の中身(PHPタグを除く) */
    private function scripts(string $src): string
    {
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $src, $m);
        return preg_replace('/<\?(php|=).*?\?>/s', '', implode("\n", $m[1]));
    }

    public function test_admin_uses_shared_aggregation_and_waf_constraints(): void
    {
        $src = file_get_contents($this->root() . '/admin.php');
        foreach (['ms_top1_daily(', 'ms_strategy_results(', 'data_quality_issues(', 'STRATEGY_MODEL_MAP',
                  'PREDICTION_DISPLAY_MODEL', 'MODEL_SWITCH_HISTORY', 'NEXT_MODEL_REVIEW',
                  'model_switch_status.php', 'audit_leak_rewrites.php'] as $needle) {
            $this->assertStringContainsString($needle, $src);
        }
        $this->assertStringNotContainsString('FROM predictions', $src, '1着的中の集計SQLを重複実装しない');
        $this->assertStringNotContainsString('SHADOW_START', $src, 'シャドウ進捗は撤去済み');
        $this->assertStringNotContainsString('`', $this->scripts($src), 'WAF: テンプレートリテラル不可');
        $this->assertDoesNotMatchRegularExpression('/<link[^>]+href="(?!style\.css)/', $src, 'WAF: 追加の外部CSSなし');
    }

    public function test_status_api_and_performance_use_shared_definitions(): void
    {
        $api = file_get_contents($this->root() . '/model_switch_status.php');
        $this->assertStringContainsString('ms_top1_daily(', $api);
        $this->assertStringNotContainsString('FROM predictions', $api, '集計SQLは model_status_lib.php のみ');

        $perf = file_get_contents($this->root() . '/performance.php');
        $this->assertStringContainsString('data_quality_issues(', $perf);
        $this->assertStringNotContainsString('DATA_GAPS', $perf, '欠損日をページ内に直書きしない');
        $this->assertStringNotContainsString('2026-10-05', $perf);
        $this->assertStringNotContainsString('`', $this->scripts($perf), 'WAF: テンプレートリテラル不可');
    }
}
