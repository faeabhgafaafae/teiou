<?php
/**
 * verify_admin_render.php (一時スクリプト・確認後削除)
 * admin.php の認証以降の実コードをそのまま評価し、実際の描画HTMLを検証する。
 * admin.phpはセッション認証必須でWebFetch検証できないため、api_key認証に差し替える。
 * 重複を避けるためadmin.php本体を読み込んで評価する(コピーではなく実ファイル)。
 * GET: ?api_key=xxxx
 */
require_once __DIR__ . '/auth.php';
require_admin_or_api_key($_GET['api_key'] ?? '');

$src = file_get_contents(__DIR__ . '/admin.php');
$pos = strpos($src, "\$today = date('Y-m-d');");
if ($pos === false) { http_response_code(500); echo 'marker not found'; exit; }
eval(substr($src, $pos));
