<?php
/**
 * 本番サーバー用設定
 * 
 * 使用方法: このファイルを app.local.php にリネームしてサーバーにアップロード
 * WinSCP: アップロード後、config/app.local.production.php を app.local.php にリネーム
 */

define('APP_ENV', 'production');
define('APP_DEBUG', false);  // 本番: エラー表示を無効化（app.php で display_errors=0 が適用される）
define('APP_URL', 'https://social9.jp');
