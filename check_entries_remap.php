<?php
/**
 * 一時確認用API: entries誤ラベルの再マッピング可能性を確認する
 * 確認が終わったら削除すること
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db();

// ラベル(誤) => 実際の会場 の対応(jcdズレのパターン)
$SHIFT_MAP = [
    '高松' => '丸亀',
    '丸亀' => '児島',
    '児島' => '宮島',
    '宮島' => '徳山',
    '徳山' => '下関',
    '下関' => '若松',
    '若松' => '芦屋',
    '芦屋' => '福岡',
    '福岡' => '唐津',
    '唐津' => '大村',
];

$results = [];

foreach ($SHIFT_MAP as $wrongLabel => $realVenue) {
    // 誤ラベル側のraces(entriesを持つもの)を取得
    $stmt = $pdo->prepare("
        SELECT r.id, r.date, r.race_no
        FROM races r
        WHERE r.venue = ?
          AND EXISTS (SELECT 1 FROM entries e WHERE e.race_id = r.id)
    ");
    $stmt->execute([$wrongLabel]);
    $wrongRows = $stmt->fetchAll();

    $matched = 0;
    $unmatched = 0;
    $unmatchedSample = [];

    foreach ($wrongRows as $row) {
        // 同じ date+race_no で「実際の会場名」のracesが存在するか
        $stmt2 = $pdo->prepare("SELECT id, (SELECT COUNT(*) FROM results WHERE race_id = races.id) AS result_count FROM races WHERE date = ? AND venue = ? AND race_no = ?");
        $stmt2->execute([$row['date'], $realVenue, $row['race_no']]);
        $target = $stmt2->fetch();
        if ($target) {
            $matched++;
        } else {
            $unmatched++;
            if (count($unmatchedSample) < 3) {
                $unmatchedSample[] = ['date' => $row['date'], 'race_no' => $row['race_no']];
            }
        }
    }

    $results[] = [
        'wrong_label'      => $wrongLabel,
        'real_venue'       => $realVenue,
        'wrong_races_with_entries' => count($wrongRows),
        'matched_to_real_venue'    => $matched,
        'unmatched'                => $unmatched,
        'unmatched_sample'         => $unmatchedSample,
    ];
}

// 児島(jcd=16の実会場)が特定日に本当にレースをしていたか(公式ページの
// 「データがありません」がページ保持期限切れなのか、開催なしなのかを切り分ける)
$stmt3 = $pdo->prepare("SELECT date, COUNT(*) AS cnt FROM races r JOIN results res ON res.race_id = r.id WHERE r.venue = '児島' AND r.date IN ('2026-06-29','2026-06-25','2026-06-22','2026-06-20') GROUP BY date");
$stmt3->execute();
$kojimaCheck = $stmt3->fetchAll();

json_response(['remap_check' => $results, 'kojima_race_check' => $kojimaCheck]);
