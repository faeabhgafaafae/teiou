<?php
/**
 * import_grades.php — 会場ごとの開催グレード・開催名を races に保存する(2026-11-07〜)
 *
 * POST JSON: {"api_key": "...", "date": "YYYY-MM-DD", "grades": {"桐生": {"grade": "G3", "title": "..."}, ...}}
 * scrape_racelist.py(出走表取込みの最後)と backfill_grades.py(過去分)から呼ぶ。races の行が無い会場は何もしない。
 * grade / series_title 列が無ければ追加する(他の取込みAPIと同じ方式。1060=既存は正常)。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/venue_grades_lib.php';

header('Content-Type: application/json; charset=utf-8');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (($in['api_key'] ?? '') !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key'], JSON_UNESCAPED_UNICODE);
    exit;
}
$date   = (string)($in['date'] ?? '');
$grades = $in['grades'] ?? null;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_array($grades)) {
    http_response_code(400);
    echo json_encode(['error' => 'date / grades が不正'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (["ALTER TABLE races ADD COLUMN grade VARCHAR(4) DEFAULT NULL COMMENT '開催グレード SG/G1/G2/G3/一般'",
          "ALTER TABLE races ADD COLUMN series_title VARCHAR(100) DEFAULT NULL COMMENT '開催名'"] as $ddl) {
    try { $pdo->exec($ddl); } catch (PDOException $e) { if ((int)$e->errorInfo[1] !== 1060) throw $e; }
}

$st = $pdo->prepare('UPDATE races SET grade = ?, series_title = ? WHERE date = ? AND venue = ?');
$updated = 0;
foreach ($grades as $venue => $g) {
    $grade = $g['grade'] ?? null;
    if (!in_array($grade, GRADE_VALUES, true)) continue;
    $title = mb_substr(trim((string)($g['title'] ?? '')), 0, 100);
    $st->execute([$grade, $title !== '' ? $title : null, $date, (string)$venue]);
    $updated += $st->rowCount();
}
echo json_encode(['ok' => true, 'races_updated' => $updated], JSON_UNESCAPED_UNICODE);
