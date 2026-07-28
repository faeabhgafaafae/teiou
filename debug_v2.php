<?php
// 一時デバッグスクリプト
if (($_GET['k'] ?? '') !== 'teiou2026') { http_response_code(403); exit; }
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

echo "Step 1: config.php\n";
require_once __DIR__ . '/config.php';
echo "Step 2: DB_HOST=" . DB_HOST . "\n";

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "Step 3: DB接続OK\n";
} catch (PDOException $e) {
    echo "Step 3 FAIL: " . $e->getMessage() . "\n"; exit;
}

echo "Step 4: predict_v2_core.php require\n";
require_once __DIR__ . '/predict_v2_core.php';
echo "Step 5: PredictV2クラスOK\n";

echo "Step 6: score_race テスト\n";
$test_entries = [
    ['lane'=>1,'player_id'=>1,'exhibit_time'=>6.70,'start_timing'=>0.08,'motor_2rate'=>30.5,'global_win_rate'=>4.5,'global_2rate'=>30.0,'local_win_rate'=>0.20,'local_2rate'=>0.35],
    ['lane'=>2,'player_id'=>2,'exhibit_time'=>6.75,'start_timing'=>0.10,'motor_2rate'=>25.0,'global_win_rate'=>3.0,'global_2rate'=>25.0,'local_win_rate'=>null,'local_2rate'=>null],
];
$result = PredictV2::score_race($test_entries, ['wind_speed'=>2,'wave_height'=>1,'temperature'=>25]);
echo "Result: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";

echo "Step 7: predictions_v2テーブル作成テスト\n";
PredictV2::save_predictions($pdo, 999999, $result);
echo "Step 8: 保存OK\n";
$pdo->exec("DELETE FROM predictions_v2 WHERE race_id=999999");
echo "Step 9: クリーンアップOK\n";

echo "全ステップ完了\n";
