<?php
/**
 * ログイン診断API
 * 本番環境でのログイン問題を診断
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$diagnostics = [
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'checks' => []
];

// 1. 設定ファイルチェック
try {
    require_once __DIR__ . '/../config/database.php';
    $diagnostics['checks']['config_database'] = 'OK';
} catch (Exception $e) {
    $diagnostics['checks']['config_database'] = 'ERROR: ' . $e->getMessage();
}

try {
    require_once __DIR__ . '/../config/session.php';
    $diagnostics['checks']['config_session'] = 'OK';
} catch (Exception $e) {
    $diagnostics['checks']['config_session'] = 'ERROR: ' . $e->getMessage();
}

// 2. データベース接続チェック
try {
    $pdo = getDB();
    $diagnostics['checks']['db_connection'] = 'OK';
} catch (Exception $e) {
    $diagnostics['checks']['db_connection'] = 'ERROR: ' . $e->getMessage();
    echo json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 3. 必要なテーブルチェック
$requiredTables = [
    'users',
    'email_verification_codes',
    'user_settings',
    'conversations',
    'messages'
];

foreach ($requiredTables as $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        if ($stmt->rowCount() > 0) {
            $diagnostics['checks']['table_' . $table] = 'OK';
        } else {
            $diagnostics['checks']['table_' . $table] = 'MISSING';
        }
    } catch (Exception $e) {
        $diagnostics['checks']['table_' . $table] = 'ERROR: ' . $e->getMessage();
    }
}

// 4. email_verification_codes テーブルの構造チェック
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM email_verification_codes");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $diagnostics['checks']['email_verification_codes_columns'] = $columns;
    
    $required = ['id', 'email', 'code', 'expires_at', 'is_new_user', 'attempts', 'verified_at', 'created_at'];
    $missing = array_diff($required, $columns);
    if (empty($missing)) {
        $diagnostics['checks']['email_verification_codes_structure'] = 'OK';
    } else {
        $diagnostics['checks']['email_verification_codes_structure'] = 'MISSING COLUMNS: ' . implode(', ', $missing);
    }
} catch (Exception $e) {
    $diagnostics['checks']['email_verification_codes_structure'] = 'ERROR: ' . $e->getMessage();
}

// 5. Mailerクラスチェック
try {
    require_once __DIR__ . '/../includes/Mailer.php';
    $diagnostics['checks']['mailer_class'] = 'OK';
} catch (Exception $e) {
    $diagnostics['checks']['mailer_class'] = 'ERROR: ' . $e->getMessage();
}

// 6. セッション開始チェック
try {
    start_session_once();
    $diagnostics['checks']['session_start'] = 'OK';
    $diagnostics['checks']['session_id'] = session_id();
} catch (Exception $e) {
    $diagnostics['checks']['session_start'] = 'ERROR: ' . $e->getMessage();
}

// 結果出力
echo json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);


