<?php
/**
 * 一時エンドポイント: サーバー上に残存するレガシーファイルの中身・更新日時を確認
 * 確認後に削除すること
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$target = $_GET['file'] ?? 'cron_get_data.py';
// パストラバーサル対策: ファイル名のみ許可(ディレクトリ区切り不可)
if (strpos($target, '/') !== false || strpos($target, '\\') !== false || strpos($target, '..') !== false) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid file name']);
    exit;
}

$path = __DIR__ . '/' . $target;

$result = [
    'target' => $target,
    'exists' => file_exists($path),
];

if ($result['exists']) {
    $result['size']       = filesize($path);
    $result['mtime']      = date('Y-m-d H:i:s', filemtime($path));
    $result['is_dir']     = is_dir($path);
    if (!$result['is_dir']) {
        $result['content'] = file_get_contents($path);
    }
}

// ついでにドキュメントルート直下で cron / get_data / 類似の古いファイルがないか一覧化
$dirFiles = scandir(__DIR__);
$related = array_values(array_filter($dirFiles, function ($f) {
    return preg_match('/cron|get_data|old_scrape|legacy/i', $f);
}));
$result['related_files_in_docroot'] = array_map(function ($f) {
    $p = __DIR__ . '/' . $f;
    return [
        'name'  => $f,
        'mtime' => date('Y-m-d H:i:s', filemtime($p)),
        'size'  => is_dir($p) ? null : filesize($p),
        'is_dir'=> is_dir($p),
    ];
}, $related);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
