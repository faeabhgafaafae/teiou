<?php
use PHPUnit\Framework\TestCase;

/**
 * 「同じパターンのバグの繰り返し」を防ぐための静的チェック。
 * ロジックの正しさではなく「書き忘れ」系の regression なので、
 * PHPUnitではなくgrepベースの軽量チェックとして実装している。
 *
 *  1. header.phpのinclude漏れ (2026-09-10 修正: 3ページで発生)
 *     → <!DOCTYPE/<html を出力するルート直下ページを自動検出して検査する
 *       (ハードコードのページ一覧は「新規ページの追加忘れ」を取りこぼすため廃止)。
 *  2. 自ドメイン想定の絶対URL fetch()のハードコード (2026-09-10 修正: 10ファイル)
 *     外部APIへの正当なfetchは ALLOWED_ABSOLUTE_FETCH_HOSTS に追加すること。
 *  3. 自ドメイン(本番ホスト)への絶対URL参照 (2026-09-18 追加)
 *     → app.js:189 の fetch('https://2410049.moo.jp/venues.php') を #2 が見逃していた。
 *       原因は #2 の許可リストに本番ドメイン 2410049.moo.jp が入っていたこと(同一
 *       オリジンなのに「外部API」と誤ラベル)、および fetch() の直後に文字列リテラルが
 *       来る形しか検出できず、ベース変数(API_HOST/API_BASE)や文字列分割
 *       ('https://' + '2410049.moo.jp')経由の組み立てを取りこぼしていたこと。
 *       → 許可リストから本番ドメインを除去し、クライアントJS(.js と .php 内<script>)に
 *         本番ホスト文字列が現れること自体を禁止する検査を追加した。
 *       ※ サーバー側PHPの curl_init 等は絶対URLが必要なため <script> 外は対象外。
 */
final class StaticChecksTest extends TestCase
{
    // 正当な「外部」ホストのみを列挙する。自ドメイン(本番ホスト)は同一オリジン
    // なので相対パスで書くこと。ここに本番ホストを追加してはならない。
    private const ALLOWED_ABSOLUTE_FETCH_HOSTS = [
        'api.github.com',  // admin.phpのジョブ実行状況取得(GitHub Actions API・公開リポジトリ)
    ];

    // アプリ自身の本番ホスト。クライアント側コードに絶対URLで現れてはならない。
    private const OWN_PRODUCTION_HOSTS = [
        '2410049.moo.jp',
    ];

    // header.phpをincludeせず <html> を出力してよい例外(partial等)。現状なし。
    private const HEADER_EXEMPT_PAGES = [
        'header.php', 'footer.php',
    ];

    private function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    /** ルート直下の *.php / *.js のパス一覧 */
    private function rootSources(): array
    {
        return array_merge(
            glob($this->projectRoot() . '/*.php'),
            glob($this->projectRoot() . '/*.js')
        );
    }

    /** ファイル中の <script>...</script> ブロックを連結して返す(クライアントJS部分) */
    private function inlineScripts(string $content): string
    {
        if (!preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $content, $m)) {
            return '';
        }
        return implode("\n", $m[1]);
    }

    public function test_pages_include_header_php(): void
    {
        $missing = [];
        foreach (glob($this->projectRoot() . '/*.php') as $path) {
            $name = basename($path);
            if (in_array($name, self::HEADER_EXEMPT_PAGES, true)) {
                continue;
            }
            $content = file_get_contents($path);
            // <!DOCTYPE または <html を出力する = 完全なHTMLページ
            if (!preg_match('/<!DOCTYPE|<html\b/i', $content)) {
                continue;
            }
            $hasHeaderInclude = (bool)preg_match(
                '/\b(include|include_once|require|require_once)\b[^;]*header\.php/',
                $content
            );
            if (!$hasHeaderInclude) {
                $missing[] = $name;
            }
        }
        $this->assertSame(
            [],
            $missing,
            "header.php をincludeしていない完全HTMLページがあります(意図的な例外なら ".
            "HEADER_EXEMPT_PAGES に追加):\n" . implode("\n", $missing)
        );
    }

    public function test_no_undeclared_absolute_url_fetch(): void
    {
        $violations = [];
        foreach ($this->rootSources() as $path) {
            $content = file_get_contents($path);
            // fetch( の直後にクォート付き絶対URL(バッククォート含む)が来る形を検出
            if (!preg_match_all('/fetch\(\s*[\'"`]https:\/\/([^\'"`\/]+)/', $content, $matches)) {
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

    /**
     * ベース変数(API_HOST/API_BASE)や文字列分割による自ドメイン絶対URLの組み立ては
     * test_no_undeclared_absolute_url_fetch の正規表現を回避してしまう。クライアントJS
     * (.js ファイルと .php 内の<script>ブロック)に本番ホスト文字列が出現すること自体を禁止する。
     */
    public function test_no_own_production_host_in_client_js(): void
    {
        $violations = [];
        foreach ($this->rootSources() as $path) {
            $content = file_get_contents($path);
            $js = str_ends_with($path, '.js') ? $content : $this->inlineScripts($content);
            if ($js === '') {
                continue;
            }
            foreach (self::OWN_PRODUCTION_HOSTS as $host) {
                if (strpos($js, $host) !== false) {
                    $violations[] = basename($path) . ' -> ' . $host;
                }
            }
        }
        $this->assertSame(
            [],
            $violations,
            "クライアントJSに本番ホストの絶対URLが含まれています。同一オリジンなので相対パスに".
            "直してください(fetch/リンク/ベース変数いずれも):\n" . implode("\n", $violations)
        );
    }
}
