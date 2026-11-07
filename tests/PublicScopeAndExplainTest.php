<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../plan_access.php';
require_once __DIR__ . '/../model_explain_lib.php';
require_once __DIR__ . '/../venue_grades_lib.php';

/**
 * design_site_audit_20261105.md #3, #4, #5, #9, #22 の対応:
 *  1. 予想の公開範囲(予測順位は全員、確率・買い目・AI解説・予想の根拠は Standard 以上)
 *  2. 予想の根拠(統計モデルの寄与)と AI 解説のプロンプト
 *  3. 会場グレード(固定値をやめ races.grade)
 *  4. 艇王について・フッター・夜のトップページ
 */
final class PublicScopeAndExplainTest extends TestCase
{
    private function src(string $f): string
    {
        return file_get_contents(dirname(__DIR__) . '/' . $f);
    }

    // ── 1. 公開範囲 ─────────────────────────────────────────

    public function test_plan_access(): void
    {
        $this->assertFalse(plan_can_view_details(null));
        $this->assertFalse(plan_can_view_details(['plan' => 'free']));
        $this->assertTrue(plan_can_view_details(['plan' => 'standard']));
        $this->assertTrue(plan_can_view_details(['plan' => 'premium']));

        $preds = [['player_id' => 1, 'predicted_rank' => 1, 'score_total' => 41.2, 'score_ability' => 30.0,
                   'factors' => ['up' => [], 'down' => []]]];
        $out = strip_prediction_details($preds);
        $this->assertSame(1, $out[0]['predicted_rank'], '予測順位は残す');
        $this->assertNull($out[0]['score_total'], '1着確率は消す');
        $this->assertArrayNotHasKey('factors', $out[0]);
        $this->assertArrayNotHasKey('score_ability', $out[0]);
    }

    public function test_apis_enforce_scope(): void
    {
        $api = $this->src('api_predict.php');
        $this->assertStringContainsString('strip_prediction_details($responseScores)', $api);
        $this->assertStringNotContainsString("\$s['predicted_rank'] = null", $api, '予測順位は全員に返す');
        $this->assertStringContainsString("unset(\$s['_v2'], \$s['score_ability']", $api, '旧 v1 内訳は誰にも返さない');

        $gp = $this->src('get_prediction.php');
        $this->assertStringContainsString('plan_can_view_details($user)', $gp);
        $this->assertStringContainsString('explain_race(', $gp);
        $this->assertStringNotContainsString("'breakdown'", $gp, '旧 v1 の内訳は廃止');

        $gx = $this->src('gemini_explain.php');
        $this->assertStringContainsString('plan_can_view_details($user)', $gx, 'AI解説は Standard 以上');
        $this->assertStringContainsString('EXPLAIN_PROMPT_VERSION', $gx, '古い版のキャッシュは作り直す');
        $this->assertStringNotContainsString('score_ability', $gx);
        $this->assertStringContainsString('explain_prompt_line', $this->src('model_explain_lib.php'));

        $this->assertStringContainsString('plan_can_view_details($user)', $this->src('get_performance_races.php'));
        $this->assertStringNotContainsString('renderPickLocked', $this->src('predictions.php'), 'Free にも予測1位を出す');
    }

    public function test_plan_table_matches_scope(): void
    {
        $js = $this->src('plan-features.js');
        $this->assertStringContainsString("{ label: '予測順位の閲覧', tiers: { free: true, standard: true, premium: true } }", $js);
        $this->assertStringContainsString("{ label: '1着確率・買い目・AI解説の閲覧', tiers: { free: false, standard: true, premium: true } }", $js);
        $this->assertStringNotContainsString('スコア内訳', $js);
        $this->assertStringContainsString('Standard: 1着確率・買い目・AI解説', $this->src('sidebar.php'));
    }

    // ── 2. 予想の根拠 ───────────────────────────────────────

    private function v3Entries(): array
    {
        $rows = [];
        foreach ([1 => [7.2, 0.30, 6.70, 0.10], 2 => [5.0, 0.10, 6.80, 0.15], 3 => [6.0, 0.20, 6.75, 0.12],
                  4 => [4.5, 0.05, 6.90, 0.20], 5 => [5.5, 0.15, 6.85, 0.18], 6 => [3.8, 0.00, 6.95, 0.25]] as $lane => [$g, $l, $ex, $st]) {
            $rows[] = ['lane' => $lane, 'player_id' => 100 + $lane, 'exhibit_time' => $ex, 'start_timing' => $st,
                       'motor_2rate' => 30 + $lane * 3, 'global_win_rate' => $g, 'local_win_rate' => $l, 'avg_st' => 0.15,
                       'grade' => $lane === 1 ? 'A1' : 'B1', 'course_rank1' => 2, 'course_count' => 10, 'course_rank2' => 4,
                       'recent10_avg_rank' => 3.0, 'recent10_win_rate' => 0.2, 'recent10_st_mean' => 0.15];
        }
        return $rows;
    }

    public function test_contributions_match_model(): void
    {
        $entries = $this->v3Entries();
        $w = ['wind_speed' => 3.0, 'wave_height' => 2.0];
        $contrib = PredictV3::contributions($entries, $w);
        $res = PredictV3::score_race($entries, $w);
        // ロジット = 切片 + 寄与の合計 → 確率の大小関係が寄与の合計の大小と一致する
        $logit = array_map(fn($c) => array_sum($c), $contrib);
        arsort($logit);
        $this->assertSame(array_keys($logit), array_keys($res), '寄与の合計の順 = 予測順位');
        $this->assertCount(count(PredictV3::FEATURE_KEYS), $contrib[101]);
        $this->assertCount(count(PredictV2::FEATURE_KEYS), current(PredictV2::contributions([
            ['lane' => 1, 'player_id' => 1, 'exhibit_time' => 6.8, 'start_timing' => 0.1, 'motor_2rate' => 40,
             'global_win_rate' => 6, 'global_2rate' => 40, 'local_win_rate' => 0.2, 'local_2rate' => 0.4]], [])));
    }

    public function test_factors_are_relative_to_race_average(): void
    {
        $entries = $this->v3Entries();
        $w = ['wind_speed' => 3.0, 'wave_height' => 2.0];
        $eff = explain_group_effects('v3w', PredictV3::contributions($entries, $w));
        foreach (array_keys(EXPLAIN_GROUPS['v3w']) as $g) {
            $this->assertEqualsWithDelta(0.0, array_sum(array_column($eff, $g)), 1e-9, "$g はレース平均との差(合計0)");
        }
        $f = explain_factors('v3w', $entries, $w);
        $up1 = array_column($f[101]['up'], 'key');
        $this->assertContains('course', $up1, '1号艇は枠番(その枠番での成績を含む)が評価を上げる');
        $this->assertContains('global', $up1, '全国勝率が最も高い');
        $this->assertContains('course', array_column($f[106]['down'], 'key'), '6号艇は枠番が評価を下げる');
        foreach ($f as $x) {
            $this->assertLessThanOrEqual(3, count($x['up']));
            $effects = array_column($x['up'], 'effect');
            $sorted = $effects; rsort($sorted);
            $this->assertSame($sorted, $effects, '強い順');
        }
        $line = explain_prompt_line($f[101]);
        $this->assertStringContainsString('評価を上げた要因: 枠番(進入コース)・その枠番での成績(1号艇・この枠番で10走1着2回・影響', $line);
        $this->assertStringNotContainsString('コース補正', $line);
    }

    public function test_frontend_uses_factors_not_v1_points(): void
    {
        foreach (['ai-predict.php', 'performance.php'] as $f) {
            $s = $this->src($f);
            $this->assertStringContainsString('prediction-factors.js', $s, $f);
            $this->assertDoesNotMatchRegularExpression('/選手能力 \(max 40pt\)|コース補正 \(max 35pt\)/u', $s, "$f: 旧 v1 の配点は表示しない");
        }
        $this->assertStringContainsString("'予測順位', '1着確率'", $this->src('predict.php'));
        $this->assertStringNotContainsString("'能力', 'コース', '当日', '気象'", $this->src('predict.php'));
        $this->assertStringNotContainsString('`', $this->src('prediction-factors.js'), 'WAF: テンプレートリテラル不可');
    }

    // ── 3. 会場グレード ─────────────────────────────────────

    public function test_venue_grades_from_races(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE races (id INTEGER PRIMARY KEY, date TEXT, venue TEXT, grade TEXT)');
        $pdo->exec("INSERT INTO races VALUES (1,'2026-11-06','住之江','G1'),(2,'2026-11-06','住之江','G1'),(3,'2026-11-06','桐生','一般'),
                    (4,'2026-11-06','戸田',NULL),(5,'2026-11-05','住之江','G3')");
        $this->assertEqualsCanonicalizing(['桐生' => '一般', '住之江' => 'G1'], venue_grades_from($pdo, '2026-11-06'));
    }

    public function test_no_hardcoded_venue_grades(): void
    {
        foreach (['app.js', 'ai-predict.php', 'odds.php', 'predictions.php', 'racelist.php', 'races.php', 'result.php', 'strategy.php'] as $f) {
            $s = $this->src($f);
            $this->assertDoesNotMatchRegularExpression("/'桐生' ?: ?'G3'/u", $s, "$f: 固定値を書かない");
            $this->assertMatchesRegularExpression('/venue_grades_json\(|VENUE_GRADES_FOR_DATE/', $s, "$f: races.grade を使う");
        }
        $this->assertStringContainsString('venue_grades_json($pageDate)', $this->src('index.php'));
        $py = $this->src('scrape_racelist.py');
        $this->assertStringContainsString('def parse_venue_grades', $py);
        $this->assertStringContainsString('send_grades(hd)', $py, '出走表取込みの最後にグレードを保存');
    }

    // ── 4. 艇王について・フッター・夜のトップ ──────────────────

    public function test_about_page_and_footer(): void
    {
        $about = $this->src('about.php');
        $this->assertStringContainsString('生成AIを使っているのは、予想をもとにした解説文(AI解説)だけです', $about);
        $this->assertStringContainsString('public_exclusion_sql(', $about, 'リーク込み期間を除いた本番の成績');
        $this->assertStringContainsString('回収率は100%を下回っています', $about);
        $this->assertStringContainsString('ms_top1_daily(', $about);
        $side = $this->src('sidebar.php');
        $this->assertStringNotContainsString('href="#"', $side, 'フッターのリンク切れなし');
        $this->assertStringContainsString('href="about.php"', $side);
        $idx = $this->src('index.php');
        $this->assertStringContainsString('last_race_day_before($today)', $idx, '夜〜朝は直近の開催日を表示');
        $this->assertStringContainsString('前回開催日(', $idx);
    }
}
