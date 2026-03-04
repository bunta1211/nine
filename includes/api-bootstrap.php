<?php
/**
 * APIブートストラップ
 * 
 * 全てのAPIファイルはこのファイルをインクルードすることで、
 * 以下の初期化処理が統一される：
 * - IS_API定数の定義
 * - Content-Typeヘッダーの設定
 * - セッション開始
 * - データベース接続
 * - 共通関数のロード
 * - グローバルエラーハンドラーの設定
 * 
 * 使用方法:
 * <?php
 * require_once __DIR__ . '/../includes/api-bootstrap.php';
 * 
 * // ログインが必要な場合
 * requireLogin();
 * 
 * $pdo = getDB();
 * $input = getJsonInput();
 * $action = getAction();
 * 
 * switch ($action) {
 *     case 'list':
 *         // 処理
 *         break;
 *     default:
 *         errorResponse('不正なアクションです');
 * }
 */

// API識別フラグ
if (!defined('IS_API')) {
    define('IS_API', true);
}

// 出力バッファ: PHP警告等のHTML混入を防止し、常にJSONを返す
ob_start();

// 致命的エラーハンドラーを最優先で登録（require前のエラーも捕捉）
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log('API Fatal Error [' . ($_SERVER['REQUEST_URI'] ?? '') . ']: ' . $error['message']);
        if (ob_get_level()) {
            ob_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'message' => 'サーバーエラーが発生しました',
            'error_type' => 'fatal'
        ], JSON_UNESCAPED_UNICODE);
    }
});

// レスポンスヘッダー
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// php://input は1回しか読めないため、最初に読み込みしてキャッシュ（interceptor と getJsonInput で共有）
// multipart/form-data の場合は php://input を読まない（携帯などで $_FILES が空になる問題を防ぐ）
if (!isset($GLOBALS['_API_RAW_INPUT'])) {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    $GLOBALS['_API_RAW_INPUT'] = (stripos($ct, 'multipart/form-data') !== false)
        ? ''
        : (file_get_contents('php://input') ?: '');
}

// 設定ファイルのロード
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

// 共通ヘルパーのロード
require_once __DIR__ . '/api-helpers.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/interceptor.php';
require_once __DIR__ . '/ipinfo.php';

// 内部ページチェックリクエストの場合はセキュリティチェックをスキップ
$isPageCheck = isset($_SERVER['HTTP_X_PAGE_CHECK']) && $_SERVER['HTTP_X_PAGE_CHECK'] === '1';
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);

// ファイルアップロード時は迎撃をスキップ（ファイル名・メッセージが誤検知される場合がある）
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
$uri = $_SERVER['REQUEST_URI'] ?? '';
$isMultipartUpload = (stripos($ct, 'multipart/form-data') !== false)
    && (
        in_array($_POST['action'] ?? '', ['upload_file', 'upload_image'])
        || (isset($_FILES['file']) && (!isset($_FILES['file']['error']) || $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE))
        || (strpos($uri, 'messages.php') !== false && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
    );

// デザイン・設定保存APIは正当なパラメータで迎撃誤検知しやすいためスキップ
$isSettingsDesignSave = (strpos($uri, 'settings.php') !== false && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
if ($isSettingsDesignSave && $GLOBALS['_API_RAW_INPUT'] !== '') {
    $decoded = @json_decode($GLOBALS['_API_RAW_INPUT'], true);
    $safeActions = ['update_design', 'get', 'update_notification', 'update_sound', 'update_detail', 'update_call', 'update_ringtone_preview'];
    if (is_array($decoded) && isset($decoded['action']) && in_array($decoded['action'], $safeActions, true)) {
        $isSettingsDesignSave = true;
    } else {
        $isSettingsDesignSave = false;
    }
} else {
    $isSettingsDesignSave = false;
}

if (!$isPageCheck || !$isLocalhost) {
    // セキュリティチェック（IPブロック、攻撃検出、自動迎撃）
    try {
        $security = getSecurity();
        
        // IPブロックチェック
        $blockedInfo = $security->isIPBlocked();
        if ($blockedInfo) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'アクセスが拒否されました',
                'error_type' => 'blocked'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 自動迎撃システム（ファイルアップロード・設定保存時はスキップ）
        if (!$isMultipartUpload && !$isSettingsDesignSave) {
            $interceptor = getInterceptor();
            if ($interceptor->intercept()) {
                // 攻撃が検出されてブロックされた
                $interceptor->sendBlockedResponse();
            }
        }
        
    } catch (Exception $e) {
        // セキュリティモジュールのエラーは静かに無視（テーブル未作成時など）
    }
}

// グローバルエラーハンドラー
set_exception_handler(function($e) {
    error_log('API Exception [' . $_SERVER['REQUEST_URI'] . ']: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => APP_DEBUG ? $e->getMessage() : 'サーバーエラーが発生しました',
        'error_type' => 'exception'
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// 致命的エラーは上で既に登録済み（二重登録は問題なし、両方実行される）

/**
 * JSONリクエストボディを取得
 * php://input は1回しか読めないため、$GLOBALS['_API_RAW_INPUT'] のキャッシュを使用
 * @return array
 */
if (!function_exists('getJsonInput')) {
    function getJsonInput() {
        static $input = null;
        
        if ($input === null) {
            $raw = $GLOBALS['_API_RAW_INPUT'] ?? '';
            $input = $raw ? json_decode($raw, true) : [];
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $input = [];
            }
        }
        
        return is_array($input) ? $input : [];
    }
}

/**
 * アクションパラメータを取得
 * @return string
 */
function getAction() {
    $input = getJsonInput();
    return $input['action'] ?? $_GET['action'] ?? $_POST['action'] ?? '';
}

/**
 * 現在のユーザーIDを取得（未ログイン時はnull）
 * @return int|null
 */
function getAuthUserId() {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * 現在の組織IDを取得（未設定時はnull）
 * @return int|null
 */
function getAuthOrgId() {
    return isset($_SESSION['current_org_id']) ? (int)$_SESSION['current_org_id'] : null;
}

/**
 * 認証レベルを取得
 * @return int
 */
function getAuthLevel() {
    return (int)($_SESSION['auth_level'] ?? 0);
}

/**
 * APIリクエストのログ記録
 * @param string $action
 * @param array $context
 */
function logApiRequest($action, $context = []) {
    if (!defined('APP_DEBUG') || !APP_DEBUG) return;
    
    $log = [
        'time' => date('Y-m-d H:i:s'),
        'user_id' => getAuthUserId(),
        'action' => $action,
        'uri' => $_SERVER['REQUEST_URI'],
        'method' => $_SERVER['REQUEST_METHOD'],
        'context' => $context
    ];
    
    error_log(json_encode($log, JSON_UNESCAPED_UNICODE) . PHP_EOL, 3, LOG_DIR . 'api.log');
}


