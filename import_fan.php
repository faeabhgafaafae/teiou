<?php
/**
 * 艇王 - 期別成績インポートAPI
 * ロリポップのサーバーに設置して使う
 *
 * 使い方: upload.html からファイルをアップロードする
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// 認証チェック
if (($_POST['api_key'] ?? '') !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => '認証エラー']);
    exit;
}

// ─── DB接続 ────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続失敗: ' . $e->getMessage()]);
    exit;
}

// ─── ファイル受け取り ───────────────────────────────
if (empty($_FILES['fanfile'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ファイルがありません']);
    exit;
}

$file     = $_FILES['fanfile'];
$filename = basename($file['name']);
$tmppath  = $file['tmp_name'];

// ファイル名から年・期を判定
// fan2510.txt → 2025年前期(1)
// fan2504.txt → 2025年後期(2)
preg_match('/fan(\d{2})(\d{2})/', $filename, $m);
if (!$m) {
    http_response_code(400);
    echo json_encode(['error' => 'ファイル名が不正: ' . $filename]);
    exit;
}
$year   = 2000 + (int)$m[1];
$period = ($m[2] === '04') ? 2 : 1;  // 04=後期, 10=前期

// ─── パース＆DB登録 ────────────────────────────────
$lines   = file($tmppath, FILE_IGNORE_NEW_LINES);
$ok      = 0;
$skip    = 0;

// prepared statement
$stmt_player = $pdo->prepare("
    INSERT INTO players (id, name, name_kana, branch, grade)
    VALUES (:id, :name, :name_kana, :branch, :grade)
    ON DUPLICATE KEY UPDATE
        name=VALUES(name), name_kana=VALUES(name_kana),
        branch=VALUES(branch), grade=VALUES(grade)
");

$stmt_period = $pdo->prepare("
    INSERT INTO player_periods
        (player_id, year, period, grade,
         win_rate, fukusho_rate, race_count, avg_st,
         c1_count, c1_rank1)
    VALUES
        (:player_id, :year, :period, :grade,
         :win_rate, :fukusho_rate, :race_count, :avg_st,
         :c1_count, :c1_rank1)
    ON DUPLICATE KEY UPDATE
        grade=VALUES(grade),
        win_rate=VALUES(win_rate),
        fukusho_rate=VALUES(fukusho_rate),
        race_count=VALUES(race_count),
        avg_st=VALUES(avg_st),
        c1_count=VALUES(c1_count),
        c1_rank1=VALUES(c1_rank1)
");

$pdo->beginTransaction();

foreach ($lines as $raw) {
    // Shift-JIS → UTF-8変換
    $line = mb_convert_encoding($raw, 'UTF-8', 'SJIS-win');

    // バイト単位で処理するため元のバイト列を使う
    $bytes = $raw;  // 生バイト列

    if (strlen($bytes) < 106) {
        $skip++;
        continue;
    }

    // 登番（数値チェック）
    $player_id_str = trim(substr($bytes, 0, 4));
    if (!ctype_digit($player_id_str)) {
        $skip++;
        continue;
    }
    $player_id = (int)$player_id_str;

    // 各フィールド取得
    $name      = trim(mb_convert_encoding(substr($bytes, 4, 16), 'UTF-8', 'SJIS-win'));
    $name_kana = trim(mb_convert_encoding(substr($bytes, 20, 15), 'UTF-8', 'SJIS-win'));
    $branch    = trim(mb_convert_encoding(substr($bytes, 35, 4), 'UTF-8', 'SJIS-win'));
    $grade     = trim(substr($bytes, 39, 2));

    // 勝率 [58:62] ÷100
    $win_raw = trim(substr($bytes, 58, 4));
    $win_rate = ctype_digit($win_raw) ? (int)$win_raw / 100 : null;

    // 複勝率 [62:66] ÷10
    $fuku_raw = trim(substr($bytes, 62, 4));
    $fukusho_rate = ctype_digit($fuku_raw) ? (int)$fuku_raw / 10 : null;

    // 出走回数 [72:75]
    $rc_raw = trim(substr($bytes, 72, 3));
    $race_count = ctype_digit($rc_raw) ? (int)$rc_raw : null;

    // 平均ST [102:105] ÷100
    $st_raw = trim(substr($bytes, 102, 3));
    $avg_st = ctype_digit($st_raw) ? (int)$st_raw / 100 : null;

    // 1コース出走 [66:69]
    $c1c_raw = trim(substr($bytes, 66, 3));
    $c1_count = ctype_digit($c1c_raw) ? (int)$c1c_raw : null;

    // 1コース1着 [69:72]
    $c1r_raw = trim(substr($bytes, 69, 3));
    $c1_rank1 = ctype_digit($c1r_raw) ? (int)$c1r_raw : null;

    try {
        $stmt_player->execute([
            ':id'        => $player_id,
            ':name'      => $name,
            ':name_kana' => $name_kana,
            ':branch'    => $branch,
            ':grade'     => $grade,
        ]);

        $stmt_period->execute([
            ':player_id'    => $player_id,
            ':year'         => $year,
            ':period'       => $period,
            ':grade'        => $grade,
            ':win_rate'     => $win_rate,
            ':fukusho_rate' => $fukusho_rate,
            ':race_count'   => $race_count,
            ':avg_st'       => $avg_st,
            ':c1_count'     => $c1_count,
            ':c1_rank1'     => $c1_rank1,
        ]);

        $ok++;
    } catch (PDOException $e) {
        // 1行エラーでも続行
        $skip++;
    }
}

$pdo->commit();

echo json_encode([
    'file'    => $filename,
    'year'    => $year,
    'period'  => $period === 2 ? '後期' : '前期',
    'ok'      => $ok,
    'skip'    => $skip,
    'message' => "{$ok}件登録完了",
]);
