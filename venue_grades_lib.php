<?php
/**
 * venue_grades_lib.php — その日の会場ごとの開催グレード(2026-11-07〜)
 *
 * 以前は各ページの VENUE_GRADES に会場ごとの固定値(下関=SG 等)を書いていたため、開催ごとの実際の
 * グレードと違っていた(design_site_audit_20261105.md #5)。グレードは出走表取込み時に boatrace.jp の
 * 開催一覧(scrape_racelist.py → import_grades.php)から races.grade に保存しており、ここで日付ごとに引く。
 * グレード未取込み(過去分・取込み前)の会場は返さない(画面ではバッジを出さない)。
 */
require_once __DIR__ . '/config.php';

const GRADE_VALUES = ['SG', 'G1', 'G2', 'G3', '一般'];

/** 'YYYY-MM-DD' の会場 => グレード */
function venue_grades(string $date): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [];
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        return venue_grades_from($pdo, $date);
    } catch (Exception $e) {
        return [];   // DB 不通・grade 列が未作成のときはバッジなし
    }
}

function venue_grades_from(PDO $pdo, string $date): array {
    $st = $pdo->prepare("SELECT venue, MAX(grade) AS grade FROM races WHERE date = ? AND grade IS NOT NULL AND grade <> '' GROUP BY venue");
    $st->execute([$date]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (in_array($r['grade'], GRADE_VALUES, true)) $out[$r['venue']] = $r['grade'];
    }
    return $out;
}

/** ページに埋め込む JS オブジェクト(日付は ?date=、無ければ当日 JST) */
function venue_grades_json(?string $date = null): string {
    $date = $date ?? ($_GET['date'] ?? (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d'));
    return json_encode((object)venue_grades((string)$date), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}
