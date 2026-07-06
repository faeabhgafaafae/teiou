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

json_response(['remap_check' => $results]);
