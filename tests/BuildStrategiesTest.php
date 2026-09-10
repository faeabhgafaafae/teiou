<?php
use PHPUnit\Framework\TestCase;

/**
 * generate_strategies.php の純粋ロジック部分のテスト:
 *  - build_strategies(): 戦略選定 + オッズフィルタ + 傾斜配分の統合動作
 *  - _strat_allocate(): 傾斜配分(largest remainder法・最低100円保証)
 *  - _strat_stake_weights(): 配分方式ごとの重み計算
 *  - _strat_harville(): 3連単Harville近似確率
 */
final class BuildStrategiesTest extends TestCase
{
    // lane => 1着確率(合計1.0)。降順=predicted_rank順に対応させている。
    private function probMap6(): array
    {
        return [1 => 0.35, 2 => 0.20, 3 => 0.15, 4 => 0.13, 5 => 0.10, 6 => 0.07];
    }

    // ── build_strategies() ──────────────────────────────

    public function test_strategy_keys_and_order(): void
    {
        $built = build_strategies([1, 2, 3, 4, 5, 6], $this->probMap6(), true, []);
        $this->assertSame(['的中特化', 'バランス', '一撃重視', '絞り込み'], array_keys($built));
    }

    public function test_tekichu_uses_harville_top6_when_prob_ok(): void
    {
        $prob = $this->probMap6();
        $built = build_strategies([1, 2, 3, 4, 5, 6], $prob, true, []);

        $expected = _strat_top_harville($prob, [1, 2, 3, 4], 6);
        $this->assertSame($expected, $built['的中特化']['combinations']);
        $this->assertCount(6, $built['的中特化']['combinations']);
        // 上位4艇(1-4)のみから構成される
        foreach ($built['的中特化']['combinations'] as $combo) {
            foreach (explode('-', $combo) as $lane) {
                $this->assertContains((int)$lane, [1, 2, 3, 4]);
            }
        }
    }

    public function test_tekichu_falls_back_to_permutations_when_prob_not_ok(): void
    {
        $built = build_strategies([1, 2, 3, 4, 5, 6], [], false, []);
        $expected = _strat_permutations([1, 2, 3]);
        sort($expected);
        $actual = $built['的中特化']['combinations'];
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->assertCount(6, $built['的中特化']['combinations']);
    }

    public function test_shiborikomi_uses_harville_top3_when_prob_ok(): void
    {
        $prob = $this->probMap6();
        $built = build_strategies([1, 2, 3, 4, 5, 6], $prob, true, []);
        $expected = _strat_top_harville($prob, [1, 2, 3], 3);
        $this->assertSame($expected, $built['絞り込み']['combinations']);
        $this->assertCount(3, $built['絞り込み']['combinations']);
    }

    public function test_shiborikomi_falls_back_to_single_lane_sorted_combo(): void
    {
        // predicted_rank順の上位3艇が枠番降順でも、枠番昇順1点に整列される
        $built = build_strategies([5, 2, 6, 1, 3, 4], [], false, []);
        $this->assertSame(['2-5-6'], $built['絞り込み']['combinations']);
    }

    public function test_ichigeki_empty_when_fewer_than_four_lanes(): void
    {
        $built = build_strategies([1, 2, 3], [], false, []);
        $this->assertSame([], $built['一撃重視']['combinations']);
    }

    public function test_balance_odds_upper_boundary_is_inclusive(): void
    {
        // BALANCE_MAX_ODDS=100.0: ちょうど100.0は除外されない、100.01は除外される
        $lanes = [1, 2, 3, 4, 5, 6];
        $builtAt100    = build_strategies($lanes, [], false, ['1-2-3' => 100.0]);
        $builtOver100  = build_strategies($lanes, [], false, ['1-2-3' => 100.01]);

        $this->assertContains('1-2-3', $builtAt100['バランス']['combinations']);
        $this->assertNotContains('1-2-3', $builtOver100['バランス']['combinations']);
        $this->assertCount(12, $builtAt100['バランス']['combinations']);
        $this->assertCount(11, $builtOver100['バランス']['combinations']);
    }

    public function test_ichigeki_odds_lower_boundary_is_inclusive(): void
    {
        // ICHIGEKI_MIN_ODDS=15.0: ちょうど15.0は除外されない、14.99は除外される
        $lanes = [1, 2, 3, 4, 5, 6];
        $builtAt15   = build_strategies($lanes, [], false, ['1-2-3' => 15.0]);
        $builtUnder15 = build_strategies($lanes, [], false, ['1-2-3' => 14.99]);

        $this->assertContains('1-2-3', $builtAt15['一撃重視']['combinations']);
        $this->assertNotContains('1-2-3', $builtUnder15['一撃重視']['combinations']);
        $this->assertCount(6, $builtAt15['一撃重視']['combinations']);
        $this->assertCount(5, $builtUnder15['一撃重視']['combinations']);
    }

    public function test_empty_odds_map_skips_filtering(): void
    {
        $lanes = [1, 2, 3, 4, 5, 6];
        $built = build_strategies($lanes, [], false, []);
        $this->assertCount(12, $built['バランス']['combinations']);
        $this->assertCount(6, $built['一撃重視']['combinations']);
    }

    // ── 傾斜配分(stakes)の統合テスト ─────────────────────

    public function test_stakes_are_null_and_scheme_flat_when_prob_not_ok(): void
    {
        $built = build_strategies([1, 2, 3, 4, 5, 6], [], false, []);
        foreach ($built as $type => $b) {
            $this->assertNull($b['stakes'], "{$type}: prob_ok=falseならstakesはnull");
            $this->assertSame('flat', $b['stake_scheme']);
            $this->assertSame(count($b['combinations']) * 100, $b['total_cost']);
        }
    }

    public function test_stakes_sum_and_bounds_when_prob_ok(): void
    {
        $prob  = $this->probMap6();
        $built = build_strategies([1, 2, 3, 4, 5, 6], $prob, true, []);

        foreach ($built as $type => $b) {
            $combos = $b['combinations'];
            $stakes = $b['stakes'];
            if (count($combos) === 0) {
                $this->assertNull($stakes);
                continue;
            }
            $this->assertSame('prob', $b['stake_scheme']);
            $this->assertCount(count($combos), $stakes, "{$type}: stakes件数がcombinationsと一致");
            $this->assertSame(count($combos) * STAKE_UNIT, array_sum($stakes), "{$type}: stakes合計が予算総額と一致");
            foreach ($stakes as $s) {
                $this->assertGreaterThanOrEqual(STAKE_MIN, $s, "{$type}: 最低保証を下回らない");
                $this->assertSame(0, $s % 100, "{$type}: 100円単位");
            }
            $this->assertSame($b['total_cost'], array_sum($stakes));
        }
    }

    // ── _strat_allocate() 直接テスト ─────────────────────

    public function test_allocate_largest_remainder_no_ties(): void
    {
        // wsum=1.0, remain_units=15。端数の大きい順に+1円単位を配る。
        $stakes = _strat_allocate(['a', 'b', 'c'], [0.50, 0.31, 0.19], 1800);
        $this->assertSame([800, 600, 400], $stakes);
        $this->assertSame(1800, array_sum($stakes));
    }

    public function test_allocate_minimum_guarantee_when_budget_equals_minimum(): void
    {
        // budget = n * STAKE_MIN ちょうど → 傾斜の余地なし、全てSTAKE_MIN
        $stakes = _strat_allocate(['a', 'b', 'c'], [10, 1, 1], 300);
        $this->assertSame([100, 100, 100], $stakes);
    }

    public function test_allocate_falls_back_to_equal_weight_when_all_weights_zero(): void
    {
        $stakes = _strat_allocate(['a', 'b', 'c'], [0, 0, 0], 1800);
        $this->assertSame([600, 600, 600], $stakes);
    }

    public function test_allocate_empty_combos_returns_empty(): void
    {
        $this->assertSame([], _strat_allocate([], [], 1800));
    }

    // ── _strat_stake_weights() 直接テスト ─────────────────

    public function test_stake_weights_flat_scheme_is_uniform(): void
    {
        $ws = _strat_stake_weights('flat', ['1-2-3', '1-2-4'], $this->probMap6(), []);
        $this->assertSame([1.0, 1.0], $ws);
    }

    public function test_stake_weights_prob_scheme_uses_harville(): void
    {
        $prob = $this->probMap6();
        $ws = _strat_stake_weights('prob', ['1-2-3'], $prob, []);
        $this->assertEqualsWithDelta(_strat_harville($prob, 1, 2, 3), $ws[0], 1e-9);
    }

    public function test_stake_weights_inv_odds_scheme(): void
    {
        $ws = _strat_stake_weights('inv_odds', ['1-2-3', '1-2-4'], [], ['1-2-3' => 10.0]);
        $this->assertEqualsWithDelta(0.1, $ws[0], 1e-9);
        $this->assertSame(0.0, $ws[1]); // オッズ未取得は0
    }

    public function test_stake_weights_kelly_scheme(): void
    {
        $prob = $this->probMap6();
        $p = _strat_harville($prob, 1, 2, 3);
        $ws = _strat_stake_weights('kelly', ['1-2-3'], $prob, ['1-2-3' => 20.0]);
        $b = 20.0 - 1.0;
        $expected = max(0.0, ($p * $b - (1.0 - $p)) / $b);
        $this->assertEqualsWithDelta($expected, $ws[0], 1e-9);
    }

    // ── _strat_harville() 直接テスト ─────────────────────

    public function test_harville_formula(): void
    {
        $prob = [1 => 0.3, 2 => 0.2, 3 => 0.1];
        $result = _strat_harville($prob, 1, 2, 3);
        $expected = 0.3 * (0.2 / 0.7) * (0.1 / 0.5);
        $this->assertEqualsWithDelta($expected, $result, 1e-9);
    }

    public function test_harville_returns_zero_when_denominator_collapses(): void
    {
        $prob = [1 => 1.0, 2 => 0.0, 3 => 0.0];
        $this->assertSame(0.0, _strat_harville($prob, 1, 2, 3));
    }
}
