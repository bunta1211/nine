<?php
/**
 * AWS環境用設定サンプル
 * 
 * 使用方法:
 * 1. このファイルを app.local.php にコピー（またはEC2上で環境変数を使用）
 * 2. 環境変数でDB接続情報を設定する場合は、database.php が自動で読み取る
 * 3. 以下の設定をEC2のユーザーデータまたは Systems Manager で設定推奨
 * 
 * 必要な環境変数:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS
 *   APP_ENV=production
 *   APP_URL=https://social9.jp
 */

// ============================================
// 環境識別
// ============================================
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_DEBUG', false);

// ============================================
// サイト設定
// ============================================
define('APP_URL', getenv('APP_URL') ?: 'https://social9.jp');
define('SITE_URL', getenv('APP_URL') ?: 'https://social9.jp');
define('SITE_NAME', 'Social9');

// ============================================
// セキュリティ設定
// ============================================
define('FORCE_HTTPS', true);
define('SECURE_COOKIES', true);

// ============================================
// アップロード先（初期はローカル。S3移行時は要変更）
// ============================================
// define('UPLOAD_DIR', __DIR__ . '/../uploads/');  // デフォルト
// S3使用時: AWS SDK 導入後、UPLOAD_URL 等を設定
