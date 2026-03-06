<?php
/**
 * ローカル環境設定サンプル
 * 
 * 使用方法:
 * 1. このファイルを app.local.php にコピー
 * 2. 環境に合わせて設定を変更
 * 3. app.local.php は .gitignore に追加済み
 */

// ============================================
// 環境識別
// ============================================
define('APP_ENV', 'development');  // 'development', 'staging', 'production'
define('APP_DEBUG', true);         // デバッグモード

// ============================================
// サイト設定
// ============================================
define('SITE_URL', 'http://localhost/nine');
define('SITE_NAME', 'Social9 (開発)');
// 本番環境では必須: define('APP_URL', 'https://social9.jp');

// ============================================
// セキュリティ設定
// ============================================
define('FORCE_HTTPS', false);      // 開発環境ではfalse
define('SECURE_COOKIES', false);   // 開発環境ではfalse

// ============================================
// ログ設定
// ============================================
define('LOG_LEVEL', 'debug');      // 'debug', 'info', 'warning', 'error'
define('LOG_TO_FILE', true);
define('LOG_PATH', __DIR__ . '/../logs/');

// ============================================
// キャッシュ設定
// ============================================
define('CACHE_ENABLED', false);    // 開発環境では無効
define('CACHE_TTL', 0);            // キャッシュ時間（秒）

// ============================================
// 金庫（メモページの「金庫」でパスワード・メモを暗号保管）
// ============================================
// 本番で金庫を使う場合は必ず設定。未設定だと「VAULT_MASTER_KEY が設定されていません」で保存できない。
// 生成例: openssl rand -hex 32
// define('VAULT_MASTER_KEY', 'ここに32文字以上のランダム文字列');

// ============================================
// 機能フラグ
// ============================================
define('FEATURE_TRANSLATION', true);
define('FEATURE_CALLS', true);
define('FEATURE_TASKS', true);
define('FEATURE_AI_CHAT', true);

// ============================================
// Jitsi Meet（自前サーバー利用時）
// ============================================
// 自前で Jitsi を構築した場合は以下でドメインとベースURLを上書き。
// 例: define('JITSI_DOMAIN', 'meet.social9.jp');
// 例: define('JITSI_BASE_URL', 'https://meet.social9.jp/');
// 未定義の場合は app.php の既定値（meet.jit.si）が使われます。
