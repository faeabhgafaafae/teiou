<?php
use PHPUnit\Framework\TestCase;

/**
 * 本日発生した「同じパターンのバグの繰り返し」を防ぐための静的チェック。
 * ロジックの正しさではなく「書き忘れ」系の regression なので、
 * PHPUnitではなくgrepベースの軽量チェックとして実装している。
 *
 *  1. header.phpのinclude漏れ (2026-09-10 修正: 3ページで発生)
 *  2. 自ドメイン想定の絶対URL fetch()のハードコード (2026-09-10 修正: 10ファイル)
 *     外部APIへの正当なfetchは ALLOWED_ABSOLUTE_FETCH_HOSTS に追加すること。
 */
final class StaticChecksTest extends TestCase
{
    private const ALLOWED_ABSOLUTE_FETCH_HOSTS = [
        '2410049.moo.jp', // 外部の会場情報API(自ドメインではない正当な外部呼び出し)
    ];

    // <html> / <!DOCTYPE> を出力する既存ページ。新規ページを追加したら忘れずにここにも足すこと。
    private const EXPECTED_HEADER_PAGES = [
        'admin.php', 'admin_v2.php', 'ai-predict.php', 'analysis.php', 'index.php',
        'my-picks.php', 'mypage.php', 'odds.php', 'performance.php', 'predict.php',
        'predictions.php', 'racelist.php', 'races.php', 'result.php', 'strategy.php',
    ];

    private function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    public function test_known_pages_include_header_php(): void
    {
        foreach (self::EXPECTED_HEADER_PAGES as $file) {
            $path = $this->projectRoot() . '/' . $file;
            $this->assertFileExists($path);
            $content = file_get_contents($path);
            $hasHeaderInclude = (bool)preg_match(
                '/\b(include|include_once|require|require_once)\b[^;]*header\.php/',
                $content
            );
            $this->assertTrue($hasHeaderInclude, "{$file} は header.php をincludeしていません");
        }
    }

    public function test_no_undeclared_absolute_url_fetch(): void
    {
        $files = array_merge(
            glob($this->projectRoot() . '/*.php'),
            glob($this->projectRoot() . '/*.js')
        );

        $violations = [];
        foreach ($files as $path) {
            $content = file_get_contents($path);
            if (!preg_match_all('/fetch\(\s*[\'"]https:\/\/([^\'"\/]+)/', $content, $matches)) {
                continue;
            }
            foreach ($matches[1] as $host) {
                if (!in_array($host, self::ALLOWED_ABSOLUTE_FETCH_HOSTS, true)) {
                    $violations[] = basename($path) . ' -> https://' . $host;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "許可されていない絶対URL fetch()が見つかりました。相対パスに直すか、".
            "正当な外部APIならALLOWED_ABSOLUTE_FETCH_HOSTSに追加してください:\n" .
            implode("\n", $violations)
        );
    }
}
