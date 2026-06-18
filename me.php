<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method Not Allowed'], 405);
}

$user = require_login();
json_response(['user' => $user]);
