<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../hits_lib.php';

/**
 * 的中速報(get_hits.php / home-races.js の「今日の的中」)のテスト:
 *  1. 同じレースの複数戦略の的中を1件にまとめる(並び順・戦略順)
 *  2. オッズ(確定払戻÷100)と人気(race_payouts の人気順 → 無ければ odds_3t の順位)
 *  3. 静的: home-races.js のカードが WAF 制約(テンプレートリテラル不可)に従い、4戦略のアイコンに名前が付く
 */
final class HitsLibTest extends TestCase
{
    private function row(int $rid, int $no, string $type, string $combo = '3-1-4', string $venue = '桐生'): array
    {
        return ['race_id' => $rid, 'venue' => $venue, 'date' => '2026-11-04', 'race_no' => $no,
                'strategy_type' => $type, 'combination' => $combo];
    }

    public function test_groups_same_race_into_one_card(): void
    {
        // get_hits.php の並び(新しいレース順)。桐生12R はバランス・的中特化・絞り込みの3行
        $rows = [
            $this->row(10, 12, 'バランス'),
            $this->row(10, 12, '絞り込み'),
            $this->row(10, 12, '的中特化'),
            $this->row(11, 11, '一撃重視', '1-2-3', '下関'),
            $this->row(12, 10, '的中特化', '2-1-3', '若松'),
        ];
        $g = hits_group_by_race($rows);
        $this->assertCount(3, $g);
        $this->assertSame([10, 11, 12], array_column($g, 'race_id'), '並び順は入力(新しいレース順)を保つ');
        $this->assertSame(['的中特化', 'バランス', '絞り込み'], $g[0]['strategies'], '戦略は表示順・重複なし');
        $this->assertSame('3-1-4', $g[0]['combination']);
        $this->assertSame(12, $g[0]['race_no']);
        $this->assertSame(['一撃重視'], $g[1]['strategies']);
    }

    public function test_duplicate_strategy_rows_are_merged(): void
    {
        $g = hits_group_by_race([$this->row(1, 1, 'バランス'), $this->row(1, 1, 'バランス')]);
        $this->assertSame(['バランス'], $g[0]['strategies']);
    }

    public function test_popularity_from_odds(): void
    {
        $odds = ['1-2-3' => 5.2, '1-3-2' => 8.0, '2-1-3' => 8.0, '3-1-4' => 15.5, '6-5-4' => 900.0];
        $this->assertSame(1, hits_popularity_from_odds($odds, '1-2-3'));
        $this->assertSame(2, hits_popularity_from_odds($odds, '2-1-3'), '同オッズは同順位');
        $this->assertSame(4, hits_popularity_from_odds($odds, '3-1-4'));
        $this->assertNull(hits_popularity_from_odds($odds, '4-5-6'), '的中組のオッズが無ければ不明');
    }

    public function test_attach_odds_prefers_payouts_then_odds_3t(): void
    {
        $groups = hits_group_by_race([
            $this->row(1, 12, 'バランス', '3-1-4'),   // 払戻・人気あり
            $this->row(2, 11, 'バランス', '1-2-3'),   // 払戻あり・人気なし → odds_3t の順位
            $this->row(3, 10, 'バランス', '2-1-3'),   // 払戻なし → odds_3t のオッズと順位
            $this->row(4, 9,  'バランス', '1-3-2'),   // どちらも無し
        ]);
        $payouts = [
            1 => ['amount' => 1550, 'popularity' => 5],
            2 => ['amount' => 520,  'popularity' => null],
        ];
        $odds = [
            2 => ['1-2-3' => 5.4, '1-3-2' => 3.1],
            3 => ['2-1-3' => 22.0, '1-2-3' => 4.0, '1-3-2' => 6.0],
        ];
        $h = hits_attach_odds($groups, $payouts, $odds);
        $this->assertSame([15.5, 5], [$h[0]['odds'], $h[0]['popularity']], '15.5倍(5番人気)');
        $this->assertSame([5.2, 2], [$h[1]['odds'], $h[1]['popularity']], 'オッズは確定払戻÷100、人気は odds_3t の順位');
        $this->assertSame([22.0, 3], [$h[2]['odds'], $h[2]['popularity']]);
        $this->assertSame([null, null], [$h[3]['odds'], $h[3]['popularity']]);
    }

    public function test_home_hit_card_static(): void
    {
        $js = file_get_contents(dirname(__DIR__) . '/home-races.js');
        $this->assertStringNotContainsString('`', $js, 'WAF: テンプレートリテラル不可');
        foreach (['的中特化', 'バランス', '一撃重視', '絞り込み'] as $t) {
            $this->assertMatchesRegularExpression("/'" . $t . "':\s*'<(path|circle)/u", $js, "$t のSVGアイコン");
        }
        $this->assertStringContainsString("setAttribute('title', type)", $js, 'アイコンに戦略名(title)');
        $this->assertStringContainsString("setAttribute('aria-label', type)", $js, 'アイコンに戦略名(aria-label)');

        $api = file_get_contents(dirname(__DIR__) . '/get_hits.php');
        $this->assertStringContainsString('hits_group_by_race(', $api);
        $this->assertStringContainsString('hits_attach_odds(', $api);
    }
}
