<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/hits_lib.php';

/**
 * 的中速報API
 * strategy_results の is_hit=1 を、レース単位にまとめて返す(2026-11-05〜。同じレースで複数戦略が
 * 的中した場合は1件にまとめ、strategies に戦略名を並べる)。
 *
 * GET パラメータ:
 *   date   : 対象日 (YYYY-MM-DD, 省略時は全期間)
 *   limit  : 取得件数 (デフォルト=20, 最大=500)
 *   offset : 取得開始位置 (デフォルト=0)
 *
 * レスポンス: { total: N(まとめた後のレース数), hits: [
 *   { venue, date, race_no, combination: '3-1-4', strategies: ['的中特化', ...],
 *     odds: 15.5(3連単確定払戻÷100), popularity: 5(人気順。不明なら null) }, ...] }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$date   = isset($_GET['date'])   ? trim($_GET['date'])         : null;
$limit  = isset($_GET['limit'])  ? (int)$_GET['limit']         : 20;
$offset = isset($_GET['offset']) ? (int)$_GET['offset']        : 0;

if ($limit < 1 || $limit > 500) $limit = 20;
if ($offset < 0) $offset = 0;

$where  = 'sr.is_hit = 1 AND sr.payout > 0';
$params = [];

if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $where   .= ' AND r.date = ?';
    $params[] = $date;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    echo json_encode(['total' => 0, 'hits' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 的中行(1レースで複数戦略が的中すると複数行)を取得し、hits_lib.php でレース単位にまとめる。
    // 並び順は従来どおり新しいレース順(日付・レース番号の降順)。
    // 日付指定時は当日分すべて(最大でも 1日 約170R × 4戦略)、未指定時は必要件数の4倍までを読む。
    $row_cap = $params ? 5000 : ($limit + $offset) * 4;
    $stmtHits = $pdo->prepare("
        SELECT
            r.id AS race_id,
            r.venue,
            r.date,
            r.race_no,
            s.strategy_type,
            (
                SELECT GROUP_CONCAT(res2.lane ORDER BY res2.actual_rank SEPARATOR '-')
                FROM results res2
                WHERE res2.race_id = sr.race_id
                  AND res2.actual_rank IN (1, 2, 3)
            ) AS combination
        FROM strategy_results sr
        JOIN strategies s ON s.id = sr.strategy_id
        JOIN races r      ON r.id = sr.race_id
        WHERE $where
        ORDER BY r.date DESC, r.race_no DESC, r.id DESC, sr.id DESC
        LIMIT $row_cap
    ");
    $stmtHits->execute($params ?: []);
    $groups = hits_group_by_race($stmtHits->fetchAll());
    $total  = count($groups);
    $groups = array_slice($groups, $offset, $limit);

    // オッズ(3連単の確定払戻÷100)と人気(race_payouts の人気順、無ければ odds_3t のオッズ順位)
    $payouts = [];
    $odds_3t = [];
    $ids = array_column($groups, 'race_id');
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $combo_of = array_column($groups, 'combination', 'race_id');
        try {
            $st = $pdo->prepare("SELECT race_id, combo, amount, popularity FROM race_payouts
                                 WHERE race_id IN ($ph) AND bet_type = '3連単'");
            $st->execute($ids);
            foreach ($st->fetchAll() as $p) {
                if ($p['combo'] === ($combo_of[(int)$p['race_id']] ?? null)) {
                    $payouts[(int)$p['race_id']] = ['amount' => (int)$p['amount'],
                        'popularity' => $p['popularity'] !== null ? (int)$p['popularity'] : null];
                }
            }
        } catch (PDOException $e) { /* race_payouts 未作成時は odds_3t のみで算出 */ }
        $need = array_values(array_filter($ids, fn($id) => !isset($payouts[$id]) || $payouts[$id]['popularity'] === null));
        if ($need) {
            $ph2 = implode(',', array_fill(0, count($need), '?'));
            $st = $pdo->prepare("SELECT race_id, combo, odds FROM odds_3t WHERE race_id IN ($ph2) AND odds IS NOT NULL");
            $st->execute($need);
            foreach ($st->fetchAll() as $o) {
                $odds_3t[(int)$o['race_id']][$o['combo']] = (float)$o['odds'];
            }
        }
    }
    $hits = array_map(function ($g) {
        unset($g['race_id']);
        return $g;
    }, hits_attach_odds($groups, $payouts, $odds_3t));
} catch (PDOException $e) {
    $total = 0;
    $hits  = [];
}

echo json_encode(['total' => $total, 'hits' => $hits], JSON_UNESCAPED_UNICODE);
