<?php
/**
 * health_lib.php — データ取得・生成・清算のヘルスチェック(2026-11-07〜)
 *
 * 1日分のレースについて、出走表・オッズ・買い目(締切前に生成されたか)・成績が揃っているかを集計し、
 * 締切前に買い目を作れなかったレースの理由を分類する。health_check.php(API)・healthcheck.py
 * (毎時の自動チェックと再実行)・admin.php(警告表示)で共用する。
 * 集計は PHP 側で行い、DB の日時関数に依存しない(テストは SQLite)。DB の時刻は JST。
 */

// 締切前に買い目を作れなかった理由
const HC_REASON_LABELS = [
    'no_racelist'             => '出走表なし(取込み失敗)',
    'racelist_after_deadline' => '出走表の取込みが締切後',
    'racelist_too_close'      => '出走表の取込みが締切35分前以降(締切前生成の対象時間に間に合わず)',
    'pregen_missed'           => '出走表はあったが締切前生成が実行されなかった(ジョブの取りこぼし)',
    'unknown_deadline'        => '締切時刻が不明',
];
const HC_PREGEN_WINDOW_MIN = 35;   // pregenerate_upcoming.py の対象(締切まで0〜35分)

/** 'YYYY-MM-DD' + 'HH:MM' → 'YYYY-MM-DD HH:MM:00'(不正なら null) */
function hc_deadline(string $date, ?string $hhmm): ?string {
    if ($hhmm === null || !preg_match('/^(\d{1,2}):(\d{2})/', $hhmm, $m)) return null;
    return sprintf('%s %02d:%02d:00', $date, (int)$m[1], (int)$m[2]);
}

function hc_minus_minutes(string $dt, int $min): string {
    return date('Y-m-d H:i:s', strtotime($dt) - $min * 60);
}

/**
 * 1日分のレースごとの状態。
 * @return array race_id => [venue, race_no, deadline, n_entries, first_entry, n_odds, first_strategy,
 *                           settled, balance_without_odds]
 */
function hc_race_rows(PDO $pdo, string $date, float $balance_max_odds = 100.0): array {
    $stmt = $pdo->prepare("
        SELECT r.id, r.venue, r.race_no, r.scheduled_time,
               (SELECT COUNT(*) FROM entries e WHERE e.race_id = r.id)            AS n_entries,
               (SELECT MIN(e.created_at) FROM entries e WHERE e.race_id = r.id)   AS first_entry,
               (SELECT COUNT(*) FROM odds_3t o WHERE o.race_id = r.id)            AS n_odds,
               (SELECT MIN(s.created_at) FROM strategies s WHERE s.race_id = r.id) AS first_strategy,
               (SELECT COUNT(*) FROM results x WHERE x.race_id = r.id AND x.actual_rank = 1) AS settled
        FROM races r
        WHERE r.date = ?
        ORDER BY r.venue, r.race_no
    ");
    $stmt->execute([$date]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[(int)$r['id']] = [
            'venue'          => $r['venue'],
            'race_no'        => (int)$r['race_no'],
            'deadline'       => hc_deadline($date, $r['scheduled_time']),
            'n_entries'      => (int)$r['n_entries'],
            'first_entry'    => $r['first_entry'],
            'n_odds'         => (int)$r['n_odds'],
            'first_strategy' => $r['first_strategy'],
            'settled'        => (int)$r['settled'] > 0,
            'balance_without_odds' => false,
        ];
    }
    if (!$rows) return [];

    // バランスの買い目に、現在のオッズで上限超え(本来は除外される)の組が入っている
    // = 生成時点でオッズが無かった(オッズ条件が効いていない)
    $over = [];
    $st = $pdo->prepare("SELECT o.race_id, o.combo FROM odds_3t o JOIN races r ON r.id = o.race_id
                         WHERE r.date = ? AND o.odds > ?");
    $st->execute([$date, $balance_max_odds]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $over[(int)$o['race_id']][$o['combo']] = true;
    }
    $st = $pdo->prepare("SELECT s.race_id, s.combinations FROM strategies s JOIN races r ON r.id = s.race_id
                         WHERE r.date = ? AND s.strategy_type = 'バランス'");
    $st->execute([$date]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $rid = (int)$s['race_id'];
        if (!isset($rows[$rid], $over[$rid])) continue;
        foreach (json_decode((string)$s['combinations'], true) ?: [] as $c) {
            if (isset($over[$rid][$c])) { $rows[$rid]['balance_without_odds'] = true; break; }
        }
    }
    return $rows;
}

/** 締切前に買い目が作られなかったレースの理由(作られていれば null) */
function hc_late_reason(array $r): ?string {
    if ($r['deadline'] === null) return 'unknown_deadline';
    if ($r['first_strategy'] !== null && $r['first_strategy'] <= $r['deadline']) return null;
    if ($r['n_entries'] === 0 || $r['first_entry'] === null) return 'no_racelist';
    if ($r['first_entry'] > $r['deadline']) return 'racelist_after_deadline';
    if ($r['first_entry'] > hc_minus_minutes($r['deadline'], HC_PREGEN_WINDOW_MIN)) return 'racelist_too_close';
    return 'pregen_missed';
}

/**
 * 1日分の集計。$now(JST 'Y-m-d H:i:s')時点で締切を過ぎたレースを対象に、締切前生成の割合と理由の内訳、
 * 出走表・オッズ・買い目・成績の欠けを数える。
 */
function hc_day_summary(array $rows, string $now): array {
    $s = [
        'races' => count($rows), 'venues_with_entries' => [], 'races_with_entries' => 0,
        'past_deadline' => 0, 'pregenerated' => 0, 'late' => 0, 'missing_strategy' => 0,
        'reasons' => array_fill_keys(array_keys(HC_REASON_LABELS), 0),
        'odds_missing_upcoming' => 0, 'upcoming' => 0, 'balance_without_odds' => 0,
        'unsettled_past_deadline' => 0, 'settled' => 0,
    ];
    foreach ($rows as $r) {
        if ($r['n_entries'] > 0) {
            $s['races_with_entries']++;
            $s['venues_with_entries'][$r['venue']] = true;
        }
        if ($r['settled']) $s['settled']++;
        if ($r['balance_without_odds']) $s['balance_without_odds']++;
        $dl = $r['deadline'];
        if ($dl !== null && $dl > $now && $dl <= date('Y-m-d H:i:s', strtotime($now) + 3600) && $r['n_entries'] > 0) {
            $s['upcoming']++;                       // 締切まで60分以内
            if ($r['n_odds'] === 0) $s['odds_missing_upcoming']++;
        }
        if ($dl === null || $dl > $now) continue;   // 以降は締切を過ぎたレースのみ
        $s['past_deadline']++;
        $reason = hc_late_reason($r);
        if ($reason === null) {
            $s['pregenerated']++;
        } else {
            $s['late']++;
            $s['reasons'][$reason]++;
            if ($r['first_strategy'] === null) $s['missing_strategy']++;
        }
        if (!$r['settled'] && $r['n_entries'] > 0) $s['unsettled_past_deadline']++;
    }
    $s['venues_with_entries'] = array_keys($s['venues_with_entries']);
    sort($s['venues_with_entries']);
    $s['pregenerated_rate'] = $s['past_deadline'] > 0 ? round($s['pregenerated'] / $s['past_deadline'] * 100, 1) : null;
    return $s;
}

// ── ヘルスチェックの記録(health_checks テーブル) ─────────────────────────

function hc_ensure_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS health_checks (
        check_date     DATE        NOT NULL,
        check_name     VARCHAR(20) NOT NULL,
        status         VARCHAR(10) NOT NULL,
        attempts       INT         NOT NULL DEFAULT 0,
        detail         TEXT,
        last_action_at DATETIME    NULL,
        updated_at     DATETIME    NOT NULL,
        PRIMARY KEY (check_date, check_name)
    )");
}

/**
 * 1件記録する。status: ok / retrying(再実行した) / alert(再実行しても直らない) / pending(時刻前で未判定)
 * $action=true のときは attempts を1増やし last_action_at を更新。ok なら attempts をそのまま残す。
 */
function hc_record(PDO $pdo, string $date, string $check, string $status, string $detail, bool $action, string $now): void {
    hc_ensure_table($pdo);
    $st = $pdo->prepare('SELECT attempts, last_action_at FROM health_checks WHERE check_date = ? AND check_name = ?');
    $st->execute([$date, $check]);
    $cur = $st->fetch(PDO::FETCH_ASSOC) ?: ['attempts' => 0, 'last_action_at' => null];
    $attempts = (int)$cur['attempts'] + ($action ? 1 : 0);
    $last     = $action ? $now : $cur['last_action_at'];
    $pdo->prepare('DELETE FROM health_checks WHERE check_date = ? AND check_name = ?')->execute([$date, $check]);
    $pdo->prepare('INSERT INTO health_checks (check_date, check_name, status, attempts, detail, last_action_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$date, $check, $status, $attempts, $detail, $last, $now]);
}

/** $from 以降の記録(新しい日付順) */
function hc_list(PDO $pdo, string $from): array {
    hc_ensure_table($pdo);
    $st = $pdo->prepare('SELECT * FROM health_checks WHERE check_date >= ? ORDER BY check_date DESC, check_name');
    $st->execute([$from]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
