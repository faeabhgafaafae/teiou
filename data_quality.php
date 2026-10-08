<?php
/**
 * 成績データの品質に関する既知の問題(リーク込み期間・欠損日)と、集計期間への該当判定。
 * performance.php(利用者向けの注記)と admin.php(データ品質の注意欄)で共用する。
 *
 * type:
 *   'leak' = 予測・買い目・清算が結果取込み後の再計算で書き換わり、成績が水増しされている期間
 *            (design_leak_fix_20260930.md。復元は未実施)
 *   'gap'  = 予測・買い目が無く成績に含まれない日。結果確定後の後追い生成は
 *            結果を知った状態での選定になり得るため行わない(design_v3w_switch_20261001.md §2.2)
 * 新しい問題が見つかったらここに1行足せば、両画面に同じ判定で表示される。
 */

const DATA_QUALITY_ISSUES = [
    [
        'type' => 'leak', 'from' => '2026-08-19', 'to' => '2026-09-28',
        'text' => '2026-08-19〜09-28 の予測・買い目・成績は、結果取込み後の再計算で当該レースの結果を含んだ値に書き換わっており、的中率・回収率が実力より高く出ています(リーク込み。修正済みだが過去分は未復元)。',
    ],
    [
        'type' => 'gap', 'from' => '2026-10-05', 'to' => '2026-10-05',
        'text' => '2026-10-05 は集計バッチの障害で予測・買い目が生成されなかったため、この日のレース(144R)は成績に含まれていません。結果確定後に買い目を後から作ると結果を知った状態での選定になり得るため、補完は行っていません。',
    ],
];

/**
 * 集計期間 [$from, $to] と重なる問題を返す($types を指定するとその type のみ)。
 */
function data_quality_issues(string $from, string $to, array $types = []): array {
    return array_values(array_filter(DATA_QUALITY_ISSUES, function ($i) use ($from, $to, $types) {
        return ($types === [] || in_array($i['type'], $types, true))
            && $i['from'] <= $to && $i['to'] >= $from;
    }));
}

/** 指定日が問題の期間に含まれるか(日別表の行に印を付ける用途) */
function data_quality_issue_on(string $date, array $types = []): ?array {
    $hits = data_quality_issues($date, $date, $types);
    return $hits[0] ?? null;
}
