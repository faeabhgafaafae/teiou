<?php
use PHPUnit\Framework\TestCase;

/**
 * 戦略の表示色をサイト全体で1か所(strategy-colors.js)にまとめていることの静的チェック。
 *  - 正の色: 的中特化=青 / バランス=緑 / 一撃重視=赤 / 絞り込み=紫
 *  - 戦略色を使うページは strategy-colors.js を読み込み、独自の色定義を持たない
 *  - WAF 制約: テンプレートリテラル不可
 */
final class StrategyColorsTest extends TestCase
{
    private const PAGES = ['ai-predict.php', 'strategy.php', 'performance.php', 'index.php'];

    private function src(string $f): string
    {
        return file_get_contents(dirname(__DIR__) . '/' . $f);
    }

    public function test_palette_defines_the_four_strategy_colors(): void
    {
        $js = $this->src('strategy-colors.js');
        $expect = ['的中特化' => '#2563eb', 'バランス' => '#16a34a', '一撃重視' => '#dc2626', '絞り込み' => '#7c3aed'];
        foreach ($expect as $type => $main) {
            $this->assertMatchesRegularExpression("/'" . $type . "':\s*\{\s*main:\s*'" . $main . "'/u", $js, $type);
        }
        $this->assertStringContainsString('function strategyColor(', $js);
        $this->assertStringContainsString('function strategyBgColor(', $js);
        $this->assertStringNotContainsString('`', $js, 'WAF: テンプレートリテラル不可');
    }

    public function test_pages_load_palette_and_have_no_own_strategy_colors(): void
    {
        foreach (self::PAGES as $f) {
            $s = $this->src($f);
            $this->assertStringContainsString('src="strategy-colors.js', $s, "$f が strategy-colors.js を読み込む");
            // 戦略名の直後に色コードを書く独自定義(例 '的中特化': '#0055a4' / color: '#2563eb')が無い
            $this->assertDoesNotMatchRegularExpression(
                "/(的中特化|バランス|一撃重視|絞り込み)'[^\\n]{0,40}#[0-9a-fA-F]{6}/u", $s, "$f に戦略色の独自定義");
        }
        $home = $this->src('home-races.js');
        $this->assertDoesNotMatchRegularExpression("/(的中特化|バランス|一撃重視|絞り込み)'[^\\n]{0,40}#[0-9a-fA-F]{6}/u", $home);
        $this->assertStringContainsString('strategyColor(type)', $home);
    }

    public function test_palette_is_loaded_before_inline_use(): void
    {
        foreach (['ai-predict.php', 'strategy.php', 'performance.php'] as $f) {
            $s = $this->src($f);
            $this->assertLessThan(strpos($s, 'strategyColor('), strpos($s, 'src="strategy-colors.js'), "$f: 定義の読み込みが使用より前");
        }
        $idx = $this->src('index.php');
        $this->assertLessThan(strpos($idx, 'src="home-races.js'), strpos($idx, 'src="strategy-colors.js'));
    }

    public function test_hits_heading_shows_date_and_fallback(): void
    {
        $this->assertStringContainsString('id="hitsHeading"', $this->src('index.php'));
        $js = $this->src('home-races.js');
        $this->assertStringContainsString("'今日の的中 ' + formatMD(", $js);
        $this->assertStringContainsString("'の的中(本日分は集計前)'", $js);
        $this->assertStringNotContainsString('`', $js, 'WAF: テンプレートリテラル不可');
    }
}
