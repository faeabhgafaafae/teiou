<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method Not Allowed'], 405);
}

// 未ログインは 200 + user:null(各ページがログイン状態の確認に使うため、401 でコンソールにエラーを出さない)
json_response(['user' => current_user()]);
