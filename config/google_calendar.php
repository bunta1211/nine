<?php
/**
 * Googleカレンダー連携設定
 *
 * 設定手順:
 * 1. https://console.cloud.google.com/ でプロジェクト作成
 * 2. Google Calendar API を有効化
 * 3. OAuth同意画面を設定（スコープ: calendar）
 * 4. 認証情報でOAuth 2.0 クライアントID作成（ウェブアプリケーション）
 * 5. リダイレクトURI: https://あなたのドメイン/api/google-calendar-callback.php
 */

$localConfig = __DIR__ . '/google_calendar.local.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
}

// OAuth Client ID / Secret（localで上書き推奨）
if (!defined('GOOGLE_CALENDAR_CLIENT_ID')) {
    define('GOOGLE_CALENDAR_CLIENT_ID', '');
}
if (!defined('GOOGLE_CALENDAR_CLIENT_SECRET')) {
    define('GOOGLE_CALENDAR_CLIENT_SECRET', '');
}

// カレンダーAPIが有効か
function isGoogleCalendarEnabled() {
    return !empty(GOOGLE_CALENDAR_CLIENT_ID) && !empty(GOOGLE_CALENDAR_CLIENT_SECRET);
}

/**
 * リダイレクトURIを取得（設定画面の表示・Google Console登録用）
 */
function getGoogleCalendarRedirectUriForDisplay() {
    $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    if (empty($base) && !empty($_SERVER['HTTP_HOST'] ?? '')) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . $_SERVER['HTTP_HOST'];
        $path = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($path !== '/' && $path !== '\\' && $path !== '.') {
            $base .= rtrim(str_replace('\\', '/', $path), '/');
        }
    }
    return $base . '/api/google-calendar-callback.php';
}
