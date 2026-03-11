<?php
/**
 * Guild データベース接続設定
 * Social9と同じデータベースを使用
 */

$guildParentConfig = __DIR__ . '/../../config/database.php';
if (!is_file($guildParentConfig)) {
    if (php_sapi_name() !== 'cli' && !headers_sent()) {
        header('Location: setup.php');
        exit;
    }
    throw new RuntimeException('Guild: config/database.php not found. Run from document root.');
}
require_once $guildParentConfig;

/**
 * Guild専用のテーブルプレフィックス
 */
define('GUILD_TABLE_PREFIX', 'guild_');

/**
 * Guildテーブル名を取得
 */
function guildTable($name) {
    return GUILD_TABLE_PREFIX . $name;
}
