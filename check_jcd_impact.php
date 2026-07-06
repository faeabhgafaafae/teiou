<?php
/**
 * 一時確認用API: jcdマッピングバグの影響範囲調査
 * 確認が終わったら削除すること
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db();

// 「高松」という架空の会場名でracesレコードが存在するか
$stmt = $pdo->query("SELECT COUNT(*) AS cnt, MIN(date) AS min_date, MAX(date) AS max_date FROM races WHERE venue = '高松'");
$takamatsu = $stmt->fetch();

// 影響対象10会場(丸亀/児島/宮島/徳山/下関/若松/芦屋/福岡/唐津/大村)について
// races単位でentries件数とresults件数を比較(乖離があれば連携が崩れている可能性)
$affected = ['丸亀', '児島', '宮島', '徳山', '下関', '若松', '芦屋', '福岡', '唐津', '大村'];
$placeholders = implode(',', array_fill(0, count($affected), '?'));

$stmt2 = $pdo->prepare("
    SELECT r.venue,
        COUNT(DISTINCT r.id) AS race_count,
        COUNT(DISTINCT CASE WHEN e.id IS NOT NULL THEN r.id END) AS races_with_entries,
        COUNT(DISTINCT CASE WHEN res.id IS NOT NULL THEN r.id END) AS races_with_results
    FROM races r
    LEFT JOIN entries e ON e.race_id = r.id
    LEFT JOIN results res ON res.race_id = r.id
    WHERE r.venue IN ($placeholders)
    GROUP BY r.venue
    ORDER BY r.venue
");
$stmt2->execute($affected);
$byVenue = $stmt2->fetchAll();

// 直近日付でentriesはあるがresultsが無い(または逆)races を具体的にサンプル
$stmt3 = $pdo->prepare("
    SELECT r.id, r.date, r.venue, r.race_no,
        (SELECT COUNT(*) FROM entries e WHERE e.race_id = r.id) AS entry_count,
        (SELECT COUNT(*) FROM results res WHERE res.race_id = r.id) AS result_count
    FROM races r
    WHERE r.venue IN ($placeholders)
      AND r.date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    HAVING entry_count > 0 XOR result_count > 0
    ORDER BY r.date DESC
    LIMIT 20
");
$stmt3->execute($affected);
$mismatches = $stmt3->fetchAll();

json_response([
    'takamatsu_races' => $takamatsu,
    'by_venue'         => $byVenue,
    'mismatch_sample'  => $mismatches,
]);
