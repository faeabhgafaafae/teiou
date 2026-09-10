<?php
use PHPUnit\Framework\TestCase;

/**
 * PredictV3::score_race() の単体テスト。
 * 係数(COEFS/MEANS/SCALES)は学習済み定数のスナップショットとして扱い、
 * 「入力→出力」の不変条件(確率の正規化・順位付け・欠損補完・空/1件時の挙動)を検証する。
 * 再学習で係数が変わった場合、内枠優位性のテストのみ意図的に落ちうる(コメント参照)。
 */
final class PredictV3ScoreRaceTest extends TestCase
{
    private function baseEntry(int $lane, int $playerId): array
    {
        return [
            'lane'              => $lane,
            'player_id'         => $playerId,
            'exhibit_time'      => 6.80 + $lane * 0.01,
            'start_timing'      => 0.15,
            'motor_2rate'       => 35.0,
            'global_win_rate'   => 5.5,
            'local_win_rate'    => 0.16,
            'avg_st'            => 0.16,
            'grade'             => 'B1',
            'course_rank1'      => 3,
            'course_count'      => 20,
            'course_rank2'      => 8,
            'recent10_avg_rank' => 3.2,
            'recent10_win_rate' => 0.15,
            'recent10_st_mean'  => 0.16,
        ];
    }

    private function sixEntries(): array
    {
        $entries = [];
        for ($lane = 1; $lane <= 6; $lane++) {
            $entries[] = $this->baseEntry($lane, 1000 + $lane);
        }
        return $entries;
    }

    public function test_probabilities_sum_to_one_and_rank_matches_descending_probability(): void
    {
        $results = PredictV3::score_race($this->sixEntries(), ['wind_speed' => 3.0, 'wave_height' => 2.0]);

        $this->assertCount(6, $results);

        $sum = array_sum(array_column($results, 'probability'));
        $this->assertEqualsWithDelta(1.0, $sum, 0.01);

        $prevProb  = 2.0;
        $prevRank  = 0;
        foreach ($results as $r) {
            $this->assertGreaterThan(0.0, $r['probability']);
            $this->assertLessThan(1.0, $r['probability']);
            $this->assertLessThanOrEqual($prevProb, $r['probability']);
            $this->assertEquals($prevRank + 1, $r['predicted_rank']);
            $prevProb = $r['probability'];
            $prevRank = $r['predicted_rank'];
        }
    }

    public function test_result_keys_are_player_id(): void
    {
        $results = PredictV3::score_race($this->sixEntries(), ['wind_speed' => 3.0, 'wave_height' => 2.0]);
        foreach ($results as $playerId => $r) {
            $this->assertSame($playerId, $r['player_id']);
        }
    }

    public function test_missing_fields_fall_back_to_medians_without_error(): void
    {
        $entries = [];
        for ($lane = 1; $lane <= 6; $lane++) {
            $e = $this->baseEntry($lane, 2000 + $lane);
            // 欠損だらけの艇を混ぜる(スクレイピング失敗を想定)
            if ($lane === 3) {
                $e['exhibit_time']      = null;
                $e['start_timing']      = null;
                $e['motor_2rate']       = null;
                $e['global_win_rate']   = null;
                $e['local_win_rate']    = null;
                $e['avg_st']            = null;
                $e['grade']             = null;
                $e['recent10_avg_rank'] = null;
                $e['recent10_win_rate'] = null;
                $e['recent10_st_mean']  = null;
            }
            $entries[] = $e;
        }

        $results = PredictV3::score_race($entries, []); // weatherも空

        $this->assertCount(6, $results);
        $sum = array_sum(array_column($results, 'probability'));
        $this->assertEqualsWithDelta(1.0, $sum, 0.01);
        foreach ($results as $r) {
            $this->assertIsFloat($r['probability']);
            $this->assertGreaterThan(0.0, $r['probability']);
        }
    }

    public function test_single_entry_gets_probability_one(): void
    {
        $results = PredictV3::score_race([$this->baseEntry(1, 999)], ['wind_speed' => 2.0, 'wave_height' => 1.0]);
        $this->assertCount(1, $results);
        $r = $results[999];
        $this->assertEqualsWithDelta(1.0, $r['probability'], 1e-9);
        $this->assertSame(1, $r['predicted_rank']);
    }

    public function test_empty_entries_returns_empty_array(): void
    {
        $this->assertSame([], PredictV3::score_race([], ['wind_speed' => 1.0, 'wave_height' => 1.0]));
    }

    public function test_identical_profiles_are_symmetric_and_inner_lane_favored(): void
    {
        // 枠番以外すべて同一プロファイル。
        // 現行モデルはlane_2..lane_6のダミー変数の係数が全て負(=1号艇が基準で優位)なので、
        // 他条件が全く同じなら確率は lane1 > lane2 > ... > lane6 の単調減少になるはず。
        // 再学習でこの符号が変わった場合はモデル自体の大きな挙動変化を意味するので、
        // このテストの失敗は「デグレ」ではなく要調査のシグナルとして扱う。
        $entries = [];
        for ($lane = 1; $lane <= 6; $lane++) {
            $e = $this->baseEntry($lane, 3000 + $lane);
            // レース内相対値が同じになるよう、展示タイム・モーターも全艇同値に
            $e['exhibit_time'] = 6.80;
            $e['motor_2rate']  = 35.0;
            $entries[] = $e;
        }

        $results = PredictV3::score_race($entries, ['wind_speed' => 3.0, 'wave_height' => 2.0]);

        $probByLane = [];
        foreach ($results as $r) {
            $probByLane[$r['lane']] = $r['probability'];
        }
        for ($lane = 1; $lane <= 5; $lane++) {
            $this->assertGreaterThan(
                $probByLane[$lane + 1],
                $probByLane[$lane],
                "lane{$lane}はlane" . ($lane + 1) . "より確率が高いはず(内枠優位)"
            );
        }
    }

    public function test_tied_avg_st_is_handled_and_result_is_order_independent(): void
    {
        // 複数艇のavg_stが同値(タイ)のケースでも例外なく計算でき、
        // avg_st_rankの算出がentries配列の並び順に依存しない(値ベースである)ことを検証する。
        $entries = $this->sixEntries();
        $entries[0]['avg_st'] = 0.16; // lane1
        $entries[2]['avg_st'] = 0.16; // lane3 と同値でタイを作る
        $weather = ['wind_speed' => 3.0, 'wave_height' => 2.0];

        $forward  = PredictV3::score_race($entries, $weather);
        $reversed = PredictV3::score_race(array_reverse($entries), $weather);

        $this->assertCount(6, $forward);
        ksort($forward);
        ksort($reversed);
        foreach ($forward as $playerId => $r) {
            $this->assertEqualsWithDelta($r['probability'], $reversed[$playerId]['probability'], 1e-9);
        }
    }
}
