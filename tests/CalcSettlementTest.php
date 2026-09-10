<?php
use PHPUnit\Framework\TestCase;

/**
 * settlement_lib.php の calc_settlement() テスト(import_results.phpの清算ロジック)。
 * 傾斜配分(stakes)あり/なし(後方互換)双方のケースを検証する。
 */
final class CalcSettlementTest extends TestCase
{
    public function test_hit_with_stakes(): void
    {
        $combos = ['1-2-3', '1-3-2', '2-1-3'];
        $stakes = [800, 600, 400];
        $r = calc_settlement($combos, $stakes, '1-3-2', 25.5);

        $this->assertSame(1, $r['is_hit']);
        $this->assertSame(1800, $r['cost']); // stakes合計
        $this->assertSame((int)floor(25.5 * 600), $r['payout']); // 的中買い目(index1)のstake=600
    }

    public function test_miss_with_stakes(): void
    {
        $combos = ['1-2-3', '1-3-2', '2-1-3'];
        $stakes = [800, 600, 400];
        $r = calc_settlement($combos, $stakes, '3-2-1', 12.0);

        $this->assertSame(0, $r['is_hit']);
        $this->assertSame(0, $r['payout']);
        $this->assertSame(1800, $r['cost']); // 外れてもcostはstakes合計のまま
    }

    public function test_stakes_null_falls_back_to_flat_100_yen(): void
    {
        // stakes=null(旧データ・フォールバック時)は1点100円均等で清算する後方互換
        $combos = ['1-2-3', '1-3-2', '2-1-3'];
        $r = calc_settlement($combos, null, '1-2-3', 10.0);

        $this->assertSame(1, $r['is_hit']);
        $this->assertSame(300, $r['cost']); // count(combos)*100
        $this->assertSame(1000, $r['payout']); // floor(10.0*100)
    }

    public function test_stakes_null_and_miss(): void
    {
        $combos = ['1-2-3', '1-3-2'];
        $r = calc_settlement($combos, null, '9-9-9', 10.0);
        $this->assertSame(0, $r['is_hit']);
        $this->assertSame(200, $r['cost']);
        $this->assertSame(0, $r['payout']);
    }

    public function test_winning_odds_null_means_no_payout_even_if_hit(): void
    {
        // オッズ未取得時は的中していてもpayout計算不能なので0
        $combos = ['1-2-3'];
        $r = calc_settlement($combos, [500], '1-2-3', null);
        $this->assertSame(1, $r['is_hit']);
        $this->assertSame(0, $r['payout']);
    }

    public function test_winning_combo_not_in_combos_is_miss(): void
    {
        $combos = ['1-2-3', '1-3-2'];
        $stakes = [500, 400];
        $r = calc_settlement($combos, $stakes, '4-5-6', 30.0);
        $this->assertSame(0, $r['is_hit']);
        $this->assertSame(0, $r['payout']);
        $this->assertSame(900, $r['cost']);
    }

    public function test_stake_of_zero_on_winning_combo_is_not_counted_as_hit(): void
    {
        // stake=0円の買い目が当たった場合、現行仕様ではis_hit=0扱い(stake_win>0が条件)
        $combos = ['1-2-3', '1-3-2'];
        $stakes = [0, 1000];
        $r = calc_settlement($combos, $stakes, '1-2-3', 30.0);
        $this->assertSame(0, $r['is_hit']);
        $this->assertSame(0, $r['payout']);
    }
}
