<?php
/**
 * Googleスプレッドシート連携設定
 *
 * 設定手順:
 * 1. Google Cloud Console でプロジェクトの Google Sheets API を有効化
 * 2. OAuth同意画面でスコープに spreadsheets を追加
 * 3. 認証情報でOAuth 2.0 クライアントID（ウェブ）のリダイレクトURIに
 *    https://あなたのドメイン/api/google-sheets-callback.php を追加
 * 4. 同一プロジェクトならカレンダー用の Client ID/Secret を流用可能（スコープのみ追加）
 */

$localConfig = __DIR__ . '/google_sheets.local.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
}

if (!defined('GOOGLE_SHEETS_CLIENT_ID')) {
    define('GOOGLE_SHEETS_CLIENT_ID', defined('GOOGLE_CALENDAR_CLIENT_ID') ? GOOGLE_CALENDAR_CLIENT_ID : '');
}
if (!defined('GOOGLE_SHEETS_CLIENT_SECRET')) {
    define('GOOGLE_SHEETS_CLIENT_SECRET', defined('GOOGLE_CALENDAR_CLIENT_SECRET') ? GOOGLE_CALENDAR_CLIENT_SECRET : '');
}

function isGoogleSheetsEnabled() {
    return !empty(GOOGLE_SHEETS_CLIENT_ID) && !empty(GOOGLE_SHEETS_CLIENT_SECRET);
}

function getGoogleSheetsRedirectUri() {
    $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    if (empty($base) && !empty($_SERVER['HTTP_HOST'] ?? '')) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . $_SERVER['HTTP_HOST'];
        $path = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($path !== '/' && $path !== '\\' && $path !== '.') {
            $base .= rtrim(str_replace('\\', '/', $path), '/');
        }
    }
    return $base . '/api/google-sheets-callback.php';
}
