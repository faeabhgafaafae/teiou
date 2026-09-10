<?php
/**
 * PHPUnit bootstrap。DBやconfig.php(gitignore対象)に依存しない
 * 純粋ロジックのファイルだけを読み込む。
 */
require_once __DIR__ . '/../predict_v3_core.php';
require_once __DIR__ . '/../generate_strategies.php';
require_once __DIR__ . '/../settlement_lib.php';
