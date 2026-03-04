<?php
/**
 * Googleログイン設定
 *
 * 設定手順:
 * 1. https://console.cloud.google.com/ でプロジェクト作成（または既存を使用）
 * 2. OAuth同意画面を設定（スコープ: email, profile, openid）
 * 3. 認証情報でOAuth 2.0 クライアントID作成（ウェブアプリケーション）
 * 4. リダイレクトURIに以下を追加:
 *    - https://あなたのドメイン/api/google-login-callback.php
 *
 * 注: Googleカレンダー連携と同じクライアントID/Secretを使用できます。
 *     その場合は、認証情報のリダイレクトURIに上記URLを追加してください。
 */

$localConfig = __DIR__ . '/google_login.local.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
}

// Googleカレンダー設定を読み込み（Client ID/Secret の流用のため）
require_once __DIR__ . '/google_calendar.php';

// Googleカレンダー設定を流用（未設定の場合は空）
if (!defined('GOOGLE_LOGIN_CLIENT_ID')) {
    define('GOOGLE_LOGIN_CLIENT_ID', defined('GOOGLE_CALENDAR_CLIENT_ID') ? GOOGLE_CALENDAR_CLIENT_ID : '');
}
if (!defined('GOOGLE_LOGIN_CLIENT_SECRET')) {
    define('GOOGLE_LOGIN_CLIENT_SECRET', defined('GOOGLE_CALENDAR_CLIENT_SECRET') ? GOOGLE_CALENDAR_CLIENT_SECRET : '');
}

/**
 * Googleログインが有効か
 */
function isGoogleLoginEnabled() {
    return !empty(GOOGLE_LOGIN_CLIENT_ID) && !empty(GOOGLE_LOGIN_CLIENT_SECRET);
}

/**
 * リダイレクトURIを取得
 */
function getGoogleLoginRedirectUri() {
    $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    if (empty($base) && !empty($_SERVER['HTTP_HOST'] ?? '')) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . $_SERVER['HTTP_HOST'];
        $path = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($path !== '/' && $path !== '\\' && $path !== '.') {
            $base .= rtrim(str_replace('\\', '/', $path), '/');
        }
    }
    return $base . '/api/google-login-callback.php';
}
