<?php
/**
 * Guild API ブートストラップ
 */

define('GUILD_IS_API', true);

// エラーレポート設定（デバッグ用：問題解決後に display_errors を 0 に戻す）
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/api_error.log');

// CORS設定
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// OPTIONSリクエストへの応答
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 設定ファイル読み込み
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/lang.php';

/**
 * JSON成功レスポンス
 */
function jsonSuccess($data = [], $message = '') {
    $response = ['success' => true];
    
    if ($message) {
        $response['message'] = $message;
    }
    
    echo json_encode(array_merge($response, $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * JSONエラーレスポンス
 */
function jsonError($message, $statusCode = 400, $data = []) {
    http_response_code($statusCode);
    $response = [
        'success' => false,
        'message' => $message
    ];
    echo json_encode(array_merge($response, $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * リクエストボディをJSONとしてパース
 */
function getJsonInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?? [];
}

/**
 * 必須パラメータチェック
 */
function requireParams($params, $required) {
    $missing = [];
    foreach ($required as $key) {
        if (!isset($params[$key]) || $params[$key] === '') {
            $missing[] = $key;
        }
    }
    
    if (!empty($missing)) {
        jsonError('必須パラメータが不足しています: ' . implode(', ', $missing));
    }
}

/**
 * APIログインチェック
 */
function requireApiLogin() {
    if (!isGuildLoggedIn()) {
        jsonError('ログインが必要です', 401);
    }
}

/**
 * API システム管理者チェック
 */
function requireApiSystemAdmin() {
    requireApiLogin();
    if (!isGuildSystemAdmin()) {
        jsonError('システム管理者権限が必要です', 403);
    }
}

/**
 * API 給与担当者チェック
 */
function requireApiPayrollAdmin() {
    requireApiLogin();
    if (!isGuildSystemAdmin() && !isGuildPayrollAdmin()) {
        jsonError('給与担当者権限が必要です', 403);
    }
}

/**
 * CSRFトークン検証（API用）
 */
function verifyApiCsrf() {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['guild_csrf_token']) || 
        !hash_equals($_SESSION['guild_csrf_token'], $token)) {
        jsonError('セキュリティトークンが無効です', 403);
    }
}
