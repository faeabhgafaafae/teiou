<?php
/**
 * race_days_lib.php — 開催日の判定(2026-11-07〜)
 * トップページ(index.php)で、当日の出走表がまだ無い時間帯(夜〜朝)に、直近の開催日を表示するために使う。
 */
function race_days_pdo(): ?PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (!defined('DB_HOST')) {
            if (!is_file(__DIR__ . '/config.php')) return null;
            require_once __DIR__ . '/config.php';
        }
        try {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Exception $e) {
            return null;
        }
    }
    return $pdo;
}

/** その日に出走表のあるレースがあるか */
function has_races_on(string $date, ?PDO $pdo = null): bool {
    $pdo = $pdo ?? race_days_pdo();
    if (!$pdo) return true;   // DB 不通時は従来どおり当日を表示する
    $st = $pdo->prepare('SELECT 1 FROM races r WHERE r.date = ? AND EXISTS (SELECT 1 FROM entries e WHERE e.race_id = r.id) LIMIT 1');
    $st->execute([$date]);
    return $st->fetchColumn() !== false;
}

/** $date より前で、出走表のあるレースがある最後の日(無ければ null) */
function last_race_day_before(string $date, ?PDO $pdo = null): ?string {
    $pdo = $pdo ?? race_days_pdo();
    if (!$pdo) return null;
    $st = $pdo->prepare('SELECT MAX(r.date) FROM races r WHERE r.date < ? AND r.date >= ?
                         AND EXISTS (SELECT 1 FROM entries e WHERE e.race_id = r.id)');
    $st->execute([$date, date('Y-m-d', strtotime($date . ' -14 days'))]);
    $d = $st->fetchColumn();
    return $d ?: null;
}
