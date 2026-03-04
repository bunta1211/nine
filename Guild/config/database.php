<?php
/**
 * Guild データベース接続設定
 * Social9と同じデータベースを使用
 */

// Social9の設定を読み込み
require_once __DIR__ . '/../../config/database.php';

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
