<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$player_id = $_GET['player_id'] ?? '';
$lane      = $_GET['lane']      ?? '';
$venue     = $_GET['venue']     ?? '';
$tab       = $_GET['tab']       ?? 'recent10';

if (!$player_id) {
    json_response(['error' => 'player_id は必須です'], 400);
}

$pdo = get_db();
$rank1 = 0; $rank2 = 0; $rank3 = 0; $total = 0;

switch ($tab) {
    case 'recent10':
        $stmt = $pdo->prepare('
            SELECT actual_rank
            FROM results res
            JOIN races r ON res.race_id = r.id
            WHERE res.player_id = ?
            ORDER BY r.date DESC, r.race_no DESC
            LIMIT 10
        ');
        $stmt->execute([(int)$player_id]);
        $rows = $stmt->fetchAll();
        $total = count($rows);
        foreach ($rows as $row) {
            $ar = (int)$row['actual_rank'];
            if ($ar === 1) $rank1++;
            elseif ($ar === 2) $rank2++;
            elseif ($ar === 3) $rank3++;
        }
        break;

    case 'recent6m':
        $stmt = $pdo->prepare('
            SELECT actual_rank, COUNT(*) as cnt
            FROM results res
            JOIN races r ON res.race_id = r.id
            WHERE res.player_id = ?
            AND r.date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY actual_rank
        ');
        $stmt->execute([(int)$player_id]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $ar = (int)$row['actual_rank'];
            $cnt = (int)$row['cnt'];
            $total += $cnt;
            if ($ar === 1) $rank1 = $cnt;
            elseif ($ar === 2) $rank2 = $cnt;
            elseif ($ar === 3) $rank3 = $cnt;
        }
        break;

    case 'local':
        if (!$venue) {
            json_response(['error' => 'venue は必須です'], 400);
        }
        $stmt = $pdo->prepare('
            SELECT actual_rank, COUNT(*) as cnt
            FROM results res
            JOIN races r ON res.race_id = r.id
            WHERE res.player_id = ?
            AND r.venue = ?
            AND r.date >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
            GROUP BY actual_rank
        ');
        $stmt->execute([(int)$player_id, $venue]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $ar = (int)$row['actual_rank'];
            $cnt = (int)$row['cnt'];
            $total += $cnt;
            if ($ar === 1) $rank1 = $cnt;
            elseif ($ar === 2) $rank2 = $cnt;
            elseif ($ar === 3) $rank3 = $cnt;
        }
        break;

    case 'current_period':
        $month = (int)date('n');
        if ($month >= 5 && $month <= 10) {
            $periodStart = date('Y') . '-05-01';
        } elseif ($month >= 11) {
            $periodStart = date('Y') . '-11-01';
        } else {
            $periodStart = ((int)date('Y') - 1) . '-11-01';
        }
        $stmt = $pdo->prepare('
            SELECT actual_rank, COUNT(*) as cnt
            FROM results res
            JOIN races r ON res.race_id = r.id
            WHERE res.player_id = ?
            AND r.date >= ?
            GROUP BY actual_rank
        ');
        $stmt->execute([(int)$player_id, $periodStart]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $ar = (int)$row['actual_rank'];
            $cnt = (int)$row['cnt'];
            $total += $cnt;
            if ($ar === 1) $rank1 = $cnt;
            elseif ($ar === 2) $rank2 = $cnt;
            elseif ($ar === 3) $rank3 = $cnt;
        }
        break;

    default:
        json_response(['error' => '不正なtab値です'], 400);
}

json_response([
    'total'   => $total,
    'rank1'   => $rank1,
    'rank2'   => $rank2,
    'rank3'   => $rank3,
    'rate1'   => $total > 0 ? round($rank1 / $total * 100, 1) : 0,
    'rate2'   => $total > 0 ? round($rank2 / $total * 100, 1) : 0,
    'rate3'   => $total > 0 ? round($rank3 / $total * 100, 1) : 0,
    'rate123' => $total > 0 ? round(($rank1 + $rank2 + $rank3) / $total * 100, 1) : 0,
]);
