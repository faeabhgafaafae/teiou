<?php
/**
 * plan_access.php — 予想の公開範囲(2026-11-07〜。design_site_audit_20261105.md #3)
 *
 *   予測順位                                   … 未ログインを含む全員
 *   予測確率(スコア)・買い目・AI解説・予想の根拠 … Standard 以上
 *   戦略比較ビュー・マイ的中トラッカー・会場横断比較・過去レース高度検索 … Premium
 *
 * 画面で隠すだけでなく、各 API(api_predict.php / get_prediction.php / strategy_detail.php /
 * gemini_explain.php / get_performance_races.php)がこの関数で応答から除く・拒否する。
 */

/** Standard 以上か(予測確率・買い目・AI解説・予想の根拠を見られる) */
function plan_can_view_details(?array $user): bool {
    return $user !== null && in_array($user['plan'] ?? 'free', ['standard', 'premium'], true);
}

/** 予測確率・旧スコア内訳・予想の根拠を除き、予測順位だけを残す(Free・未ログイン向け) */
function strip_prediction_details(array $preds): array {
    foreach ($preds as &$p) {
        $p['score_total'] = null;
        unset($p['score_ability'], $p['score_course'], $p['score_today'], $p['score_weather'],
              $p['win_probability'], $p['factors'], $p['breakdown']);
    }
    unset($p);
    return $preds;
}
