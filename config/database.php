<?php
/**
 * データベース接続設定
 * 環境を自動判定してローカル/本番を切り替え
 */

$dbHost = null;
$dbName = 'social9';
$dbUser = '';
$dbPass = '';

// 1. database.aws.php があれば優先（PHP-FPM で SetEnv が渡らない場合用）
$awsConfig = __DIR__ . '/database.aws.php';
if (file_exists($awsConfig)) {
    require $awsConfig;
    $dbHost = defined('DB_HOST') ? DB_HOST : null;
    $dbName = defined('DB_NAME') ? DB_NAME : 'social9';
    $dbUser = defined('DB_USER') ? DB_USER : '';
    $dbPass = defined('DB_PASS') ? DB_PASS : '';
}

// 2. 環境変数（getenv / $_SERVER）が設定されていればそちらを優先
if (!$dbHost) {
    $dbHost = getenv('DB_HOST') ?: ($_SERVER['DB_HOST'] ?? null);
    if ($dbHost) {
        $dbName = getenv('DB_NAME') ?: ($_SERVER['DB_NAME'] ?? 'social9');
        $dbUser = getenv('DB_USER') ?: ($_SERVER['DB_USER'] ?? '');
        $dbPass = getenv('DB_PASS') ?: ($_SERVER['DB_PASS'] ?? '');
    }
}

if ($dbHost) {
    if (!defined('DB_HOST')) define('DB_HOST', $dbHost);
    if (!defined('DB_NAME')) define('DB_NAME', $dbName);
    if (!defined('DB_USER')) define('DB_USER', $dbUser);
    if (!defined('DB_PASS')) define('DB_PASS', $dbPass);
} else {
    // 環境判定：本番環境かどうか
    // ※ AWS 等に移転した場合は database.aws.php を配置すること。
    //    未配置だと下記 heteml の fallback が使われ接続に失敗する。
    $isProduction = (
        isset($_SERVER['HTTP_HOST']) && 
        strpos($_SERVER['HTTP_HOST'], 'social9.jp') !== false
    ) || (
        isset($_SERVER['SERVER_NAME']) && 
        strpos($_SERVER['SERVER_NAME'], 'social9.jp') !== false
    );

    if ($isProduction) {
        // ========== 本番環境（heteml 旧サーバー用 fallback） ==========
        // AWS 利用時は database.aws.php を配置してここを通らないようにすること
        define('DB_HOST', 'mysql322.phy.heteml.lan');
        define('DB_NAME', '_social9');
        define('DB_USER', '_social9');
        define('DB_PASS', 'nine2024db');
    } else {
        // ========== ローカル開発環境（XAMPP） ==========
        define('DB_HOST', 'localhost');
        define('DB_NAME', 'social9');
        define('DB_USER', 'root');
        define('DB_PASS', '');
    }
}

define('DB_CHARSET', 'utf8mb4');

/**
 * PDO接続を取得
 */
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            if (defined('PDO::ATTR_AUTOCOMMIT')) {
                try { $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, true); } catch (Exception $e) {}
            }
        } catch (PDOException $e) {
            error_log('Database connection error: ' . $e->getMessage());
            
            // 開発環境ではエラー詳細を表示
            if (defined('IS_API') && IS_API) {
                header('Content-Type: application/json');
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'データベース接続エラー']);
            } else {
                die('データベース接続エラーが発生しました。管理者に連絡してください。');
            }
            exit;
        }
    }
    
    return $pdo;
}

/**
 * JSON成功レスポンス
 */
function successResponse($data = [], $message = '') {
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
function errorResponse($message, $status_code = 400, $extra = []) {
    http_response_code($status_code);
    $response = [
        'success' => false,
        'message' => $message
    ];
    if (!empty($extra)) {
        $response = array_merge($response, $extra);
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// AUTH_LEVEL定数は config/app.php で定義
