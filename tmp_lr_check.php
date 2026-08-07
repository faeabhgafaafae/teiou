<?php
/**
 * v2 LR訓練データのjcdバグ影響確認 (読み取り専用)
 * GET ?api_key=xxx
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$BUG_VENUES = ['丸亀','児島','宮島','徳山','下関','若松','芦屋','福岡','唐津','大村'];
$ph = implode(',', array_fill(0, count($BUG_VENUES), '?'));

// ─── ベース条件 (export_lr_data.phpと同じJOIN + NULLフィルタ) ─────────
// A. 全訓練データ件数
$a = $pdo->query("
    SELECT COUNT(*) AS rows, COUNT(DISTINCT e.race_id) AS races,
           MIN(r.date) AS min_date, MAX(r.date) AS max_date
    FROM entries e
    JOIN races r ON e.race_id = r.id
    JOIN results res ON res.race_id = e.race_id AND res.player_id = e.player_id
    WHERE r.date BETWEEN '2026-06-29' AND '2026-07-19'
      AND e.exhibit_time IS NOT NULL AND e.start_timing IS NOT NULL
      AND EXISTS(SELECT 1 FROM results r2 WHERE r2.race_id=r.id AND r2.actual_rank=1)
")->fetch();

// B. バグ期間・影響会場のみ: JOINが成立するか
$b_stmt = $pdo->prepare("
    SELECT COUNT(*) AS rows, COUNT(DISTINCT e.race_id) AS races
    FROM entries e
    JOIN races r ON e.race_id = r.id
    JOIN results res ON res.race_id = e.race_id AND res.player_id = e.player_id
    WHERE r.date BETWEEN '2026-06-29' AND '2026-07-05'
      AND r.venue IN ($ph)
      AND e.exhibit_time IS NOT NULL AND e.start_timing IS NOT NULL
      AND EXISTS(SELECT 1 FROM results r2 WHERE r2.race_id=r.id AND r2.actual_rank=1)
");
$b_stmt->execute($BUG_VENUES);
$b = $b_stmt->fetch();

// C. バグ期間・影響会場: entriesは存在するがJOINが成立しないレース
// (player_id不一致 = ミスラベル確認)
$c_stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT r.id) AS races_with_entries,
           COUNT(DISTINCT e.id) AS entries_count
    FROM entries e
    JOIN races r ON e.race_id = r.id
    WHERE r.date BETWEEN '2026-06-29' AND '2026-07-05'
      AND r.venue IN ($ph)
      AND e.exhibit_time IS NOT NULL AND e.start_timing IS NOT NULL
      AND EXISTS(SELECT 1 FROM results r2 WHERE r2.race_id=r.id AND r2.actual_rank=1)
");
$c_stmt->execute($BUG_VENUES);
$c = $c_stmt->fetch();

// D. player_id不一致サンプル (最初の3レース)
$d_stmt = $pdo->prepare("
    SELECT r.date, r.venue, r.race_no,
           GROUP_CONCAT(DISTINCT e.player_id ORDER BY e.lane) AS entry_pids,
           (SELECT GROUP_CONCAT(DISTINCT res3.player_id ORDER BY res3.actual_rank)
            FROM results res3 WHERE res3.race_id = r.id) AS result_pids
    FROM races r
    JOIN entries e ON e.race_id = r.id
    WHERE r.date BETWEEN '2026-06-29' AND '2026-07-01'
      AND r.venue IN ($ph)
      AND EXISTS(SELECT 1 FROM results r2 WHERE r2.race_id=r.id AND r2.actual_rank=1)
    GROUP BY r.id, r.date, r.venue, r.race_no
    ORDER BY r.date, r.venue, r.race_no
    LIMIT 5
");
$d_stmt->execute($BUG_VENUES);
$d = $d_stmt->fetchAll();

// E. 期間・venue種別クロス集計
$e_stmt = $pdo->prepare("
    SELECT
        CASE WHEN r.date <= '2026-07-05' THEN 'bug_6/29-7/5' ELSE 'clean_7/6-7/19' END AS period,
        CASE WHEN r.venue IN ($ph) THEN 'affected' ELSE 'normal' END AS venue_type,
        COUNT(*) AS rows,
        COUNT(DISTINCT e.race_id) AS races
    FROM entries e
    JOIN races r ON e.race_id = r.id
    JOIN results res ON res.race_id = e.race_id AND res.player_id = e.player_id
    WHERE r.date BETWEEN '2026-06-29' AND '2026-07-19'
      AND e.exhibit_time IS NOT NULL AND e.start_timing IS NOT NULL
      AND EXISTS(SELECT 1 FROM results r2 WHERE r2.race_id=r.id AND r2.actual_rank=1)
    GROUP BY period, venue_type
    ORDER BY period, venue_type
");
$e_stmt->execute($BUG_VENUES);
$e = $e_stmt->fetchAll();

// F. 日別レース数(訓練データ内)
$f = $pdo->query("
    SELECT r.date, COUNT(DISTINCT e.race_id) AS races
    FROM entries e
    JOIN races r ON e.race_id = r.id
    JOIN results res ON res.race_id = e.race_id AND res.player_id = e.player_id
    WHERE r.date BETWEEN '2026-06-29' AND '2026-07-19'
      AND e.exhibit_time IS NOT NULL AND e.start_timing IS NOT NULL
      AND EXISTS(SELECT 1 FROM results r2 WHERE r2.race_id=r.id AND r2.actual_rank=1)
    GROUP BY r.date ORDER BY r.date
")->fetchAll();

echo json_encode([
    'A_total_training'          => $a,
    'B_bug_period_joined'       => $b,
    'C_bug_period_entries_exist' => $c,
    'D_player_mismatch_samples' => $d,
    'E_period_venue_cross'      => $e,
    'F_daily_races'             => $f,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
