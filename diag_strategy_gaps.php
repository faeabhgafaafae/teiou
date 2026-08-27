<?php
/**
 * 診断: 12Rバグによりstrategy未生成のレースを集計 (読み取り専用)
 * GET /diag_strategy_gaps.php
 * 確認後に削除予定
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(60);

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗']);
    exit;
}

// ① 旧バグ影響候補: max(race_no) > COUNT(*) の会場/日付
// (先頭に欠番があると最後のレースがループ対象外になる)
$stmt = $pdo->query('
    SELECT date, venue,
           COUNT(*)      AS race_count,
           MAX(race_no)  AS max_race_no,
           GROUP_CONCAT(race_no ORDER BY race_no) AS actual_race_nos
    FROM races
    GROUP BY date, venue
    HAVING MAX(race_no) > COUNT(*)
    ORDER BY date DESC, venue
');
$gap_venues = $stmt->fetchAll();

// ② gap_venuesのうち実際にstrategy未生成の12R(最大race_no)を確認
$missing_details = [];
foreach ($gap_venues as $row) {
    $max_rno = (int)$row['max_race_no'];
    $count   = (int)$row['race_count'];
    // count+1 以上の race_no が "漏れ候補"
    for ($rno = $count + 1; $rno <= $max_rno; $rno++) {
        $s = $pdo->prepare('
            SELECT r.id AS race_id, COUNT(st.id) AS strategy_count
            FROM races r
            LEFT JOIN strategies st ON st.race_id = r.id
            WHERE r.date = ? AND r.venue = ? AND r.race_no = ?
        ');
        $s->execute([$row['date'], $row['venue'], $rno]);
        $res = $s->fetch();
        if ($res && (int)$res['strategy_count'] === 0) {
            $missing_details[] = [
                'date'     => $row['date'],
                'venue'    => $row['venue'],
                'race_no'  => $rno,
                'race_id'  => $res['race_id'],
                'actual_race_nos' => $row['actual_race_nos'],
            ];
        }
    }
}

// ③ strategy自体が0件の全レース (欠番に関わらず)
$stmt2 = $pdo->query('
    SELECT COUNT(*) AS cnt
    FROM races r
    LEFT JOIN strategies s ON s.race_id = r.id
    WHERE s.id IS NULL
');
$total_no_strategy = (int)$stmt2->fetch()['cnt'];

// ④ 内訳: race_no別
$stmt3 = $pdo->query('
    SELECT r.race_no, COUNT(*) AS cnt
    FROM races r
    LEFT JOIN strategies s ON s.race_id = r.id
    WHERE s.id IS NULL
    GROUP BY r.race_no
    ORDER BY r.race_no
');
$by_race_no = $stmt3->fetchAll();

echo json_encode([
    'bug_affected_venue_dates'   => count($gap_venues),
    'strategy_missing_due_to_bug'=> count($missing_details),
    'missing_details'            => $missing_details,
    'total_races_no_strategy'    => $total_no_strategy,
    'no_strategy_by_race_no'     => $by_race_no,
    'gap_venues'                 => $gap_venues,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
