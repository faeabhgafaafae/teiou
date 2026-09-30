<?php
use PHPUnit\Framework\TestCase;

/**
 * ハイブリッド構成(戦略ごとの参照モデル切り替え)のテスト。
 *  - STRATEGY_MODEL_MAP(model_switch.php)は4戦略を既知モデルに割り当てる(2026-10-01〜全v3w)
 *  - build_strategies_hybrid() が map に従って参照モデルを切り替える
 *  - v2 の予測しか無いレースでは build_strategies() 単独呼び出しと combinations/stakes が一致
 *  - 参照モデル欠損時はフォールバックする
 * DB非依存の純粋ロジックのみを検証する(保存/清算はDBが要るため対象外)。
 */
final class HybridStrategyMapTest extends TestCase
{
    /** v2: 1号艇本命の確率分布・予測順位 [1,2,3,4,5,6] */
    private function v2Model(): array
    {
        return [
            'lanes'    => [1, 2, 3, 4, 5, 6],
            'prob_map' => [1 => 0.35, 2 => 0.20, 3 => 0.15, 4 => 0.13, 5 => 0.10, 6 => 0.07],
            'prob_ok'  => true,
        ];
    }

    /** v3w: 2号艇本命・rank1が入れ替わった別分布 [2,1,3,4,5,6](v2と買い目が変わる) */
    private function v3wModel(): array
    {
        return [
            'lanes'    => [2, 1, 3, 4, 5, 6],
            'prob_map' => [2 => 0.35, 1 => 0.20, 3 => 0.15, 4 => 0.13, 5 => 0.10, 6 => 0.07],
            'prob_ok'  => true,
        ];
    }

    // ── 既定マップ ──────────────────────────────────────

    /**
     * 既定マップ(model_switch.php)は4戦略すべてを既知モデルに割り当てていること。
     * 2026-10-01 に全戦略 v3w へ切り替えたが、'v2' へ戻す(ロールバック)だけでも
     * CI(=デプロイの前提)が通るよう、値そのものは固定しない。
     */
    public function test_default_map_assigns_known_models(): void
    {
        $this->assertSame(['的中特化', 'バランス', '一撃重視', '絞り込み'], array_keys(STRATEGY_MODEL_MAP));
        foreach (STRATEGY_MODEL_MAP as $type => $model) {
            $this->assertContains($model, ['v2', 'v3w'], "{$type}: 未知のモデル");
        }
        $this->assertSame('v2', STRATEGY_MODEL_FALLBACK, 'フォールバックは常に保存される v2');
    }

    // ── v2 の予測しか無いとき build_strategies と一致 ──────

    public function test_all_v2_matches_single_build_strategies(): void
    {
        $v2  = $this->v2Model();
        $ref = build_strategies($v2['lanes'], $v2['prob_map'], $v2['prob_ok'], []);
        // 既定マップのまま v3w を渡さない = v3w 未生成レース。全戦略が v2 で生成される
        $hyb = build_strategies_hybrid(['v2' => $v2], []);

        $this->assertSame(array_keys($ref), array_keys($hyb), '戦略の並び順が一致');
        foreach ($ref as $type => $r) {
            $this->assertSame($r['combinations'], $hyb[$type]['combinations'], "{$type}: combinations一致");
            $this->assertSame($r['stakes'],       $hyb[$type]['stakes'],       "{$type}: stakes一致");
            $this->assertSame($r['stake_scheme'], $hyb[$type]['stake_scheme'], "{$type}: scheme一致");
            $this->assertSame($r['total_cost'],   $hyb[$type]['total_cost'],   "{$type}: total_cost一致");
            $this->assertSame('v2', $hyb[$type]['model_ref'], "{$type}: model_refはv2");
        }
    }

    // ── 的中特化だけ v3w へ切り替え ─────────────────────

    public function test_switching_tekichu_to_v3w_changes_only_that_strategy(): void
    {
        $v2  = $this->v2Model();
        $v3w = $this->v3wModel();
        $models = ['v2' => $v2, 'v3w' => $v3w];

        $refV2  = build_strategies($v2['lanes'],  $v2['prob_map'],  true, []);
        $refV3w = build_strategies($v3w['lanes'], $v3w['prob_map'], true, []);

        $map = ['的中特化' => 'v3w', 'バランス' => 'v2', '一撃重視' => 'v2', '絞り込み' => 'v2'];
        $hyb = build_strategies_hybrid($models, [], $map);

        // 的中特化は v3w 由来(v2 とは異なる買い目になっている)
        $this->assertSame($refV3w['的中特化']['combinations'], $hyb['的中特化']['combinations']);
        $this->assertSame('v3w', $hyb['的中特化']['model_ref']);
        $this->assertNotSame($refV2['的中特化']['combinations'], $hyb['的中特化']['combinations'],
            'v2 と v3w で的中特化の買い目が実際に変わること');

        // 他3戦略は v2 由来のまま(byte一致)
        foreach (['バランス', '一撃重視', '絞り込み'] as $type) {
            $this->assertSame($refV2[$type]['combinations'], $hyb[$type]['combinations'], "{$type}: v2のまま");
            $this->assertSame('v2', $hyb[$type]['model_ref'], "{$type}: model_refはv2");
        }
    }

    public function test_switching_uses_correct_model_ranking(): void
    {
        // v3w の rank1 は 2号艇なので、v3wの的中特化の最有力手は 2-1-3 から始まる
        $hyb = build_strategies_hybrid(
            ['v2' => $this->v2Model(), 'v3w' => $this->v3wModel()],
            [],
            ['的中特化' => 'v3w', 'バランス' => 'v3w', '一撃重視' => 'v3w', '絞り込み' => 'v3w']
        );
        $this->assertSame('2-1-3', $hyb['的中特化']['combinations'][0]);
        // 一撃重視は1着固定=v3wのrank1(2号艇)
        foreach ($hyb['一撃重視']['combinations'] as $combo) {
            $this->assertSame('2', explode('-', $combo)[0], '一撃重視の1着は常にv3wのrank1(2号艇)');
        }
    }

    // ── フォールバック ─────────────────────────────────

    public function test_falls_back_to_v2_when_assigned_model_missing(): void
    {
        // v3w データが無い(未生成レース)。的中特化を v3w に割り当てても v2 にフォールバック。
        $v2 = $this->v2Model();
        $refV2 = build_strategies($v2['lanes'], $v2['prob_map'], true, []);
        $map = ['的中特化' => 'v3w', 'バランス' => 'v2', '一撃重視' => 'v2', '絞り込み' => 'v2'];

        $hyb = build_strategies_hybrid(['v2' => $v2, 'v3w' => null], [], $map);

        $this->assertSame($refV2['的中特化']['combinations'], $hyb['的中特化']['combinations']);
        $this->assertSame('v2', $hyb['的中特化']['model_ref'], 'フォールバック時のmodel_refはv2');
    }

    public function test_all_four_strategies_present_in_canonical_order(): void
    {
        $hyb = build_strategies_hybrid(['v2' => $this->v2Model()], []);
        $this->assertSame(['的中特化', 'バランス', '一撃重視', '絞り込み'], array_keys($hyb));
    }
}
