<?php
/**
 * マイ的中トラッカー: 買い目CSVエクスポートAPI(Premium限定)
 * GET /export_user_picks.php
 * get_user_picks.php と同じデータをCSV形式でダウンロードさせる。
 * DB書き込みは発生しない読み取り専用エンドポイント。
 */
require_once __DIR__ . '/auth.php';

$user = require_login();
if ($user['plan'] !== 'premium') {
    json_response(['error' => 'premium_required', 'message' => 'CSVエクスポートはPremium会員限定です'], 403);
}

$pdo = get_db();

$stmt = $pdo->prepare('
    SELECT
        up.id,
        up.bet_type,
        up.combo,
        up.cost,
        up.created_at,
        r.venue,
        r.date,
        r.race_no,
        rp_match.amount AS matched_payout,
        (SELECT COUNT(*) FROM race_payouts rp2 WHERE rp2.race_id = up.race_id) AS result_count
    FROM user_picks up
    JOIN races r ON r.id = up.race_id
    LEFT JOIN race_payouts rp_match
        ON  rp_match.race_id  = up.race_id
        AND rp_match.bet_type = up.bet_type
        AND rp_match.combo    = up.combo
    WHERE up.user_id = ?
    ORDER BY up.created_at DESC
');
$stmt->execute([$user['id']]);
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="teiou_my_picks_' . date('Ymd') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // Excel向けBOM

fputcsv($out, ['日付', '会場', 'レース', '賭式', '組番', '購入額', '結果', '払戻額', '記録日時']);

foreach ($rows as $row) {
    if ($row['matched_payout'] !== null) {
        $resultLabel = '的中';
        $payout      = (int)$row['matched_payout'];
    } elseif ((int)$row['result_count'] > 0) {
        $resultLabel = '不的中';
        $payout      = 0;
    } else {
        $resultLabel = '未確定';
        $payout      = '';
    }

    fputcsv($out, [
        $row['date'],
        $row['venue'],
        $row['race_no'] . 'R',
        $row['bet_type'],
        $row['combo'],
        (int)$row['cost'],
        $resultLabel,
        $payout,
        $row['created_at'],
    ]);
}

fclose($out);
