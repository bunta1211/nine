<?php
/**
 * OTP認証診断API
 * 本番環境でのOTP問題を診断
 * 
 * 使用後は必ず削除してください
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

$diagnostics = [
    'timestamp' => date('Y-m-d H:i:s'),
    'timezone' => date_default_timezone_get(),
    'server_time' => [
        'now' => date('Y-m-d H:i:s'),
        'utc' => gmdate('Y-m-d H:i:s'),
        'timestamp' => time()
    ],
    'checks' => []
];

try {
    $pdo = getDB();
    $diagnostics['checks']['db_connection'] = 'OK';
} catch (Exception $e) {
    $diagnostics['checks']['db_connection'] = 'ERROR: ' . $e->getMessage();
    echo json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 1. テーブル存在チェック
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'email_verification_codes'");
    $diagnostics['checks']['table_exists'] = $stmt->rowCount() > 0 ? 'OK' : 'MISSING';
} catch (Exception $e) {
    $diagnostics['checks']['table_exists'] = 'ERROR: ' . $e->getMessage();
}

// 2. テーブル構造チェック
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM email_verification_codes");
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[$row['Field']] = [
            'type' => $row['Type'],
            'null' => $row['Null'],
            'default' => $row['Default']
        ];
    }
    $diagnostics['checks']['table_structure'] = $columns;
    
    $required = ['id', 'email', 'code', 'expires_at', 'is_new_user', 'attempts', 'verified_at', 'created_at'];
    $missing = array_diff($required, array_keys($columns));
    $diagnostics['checks']['missing_columns'] = empty($missing) ? 'NONE' : implode(', ', $missing);
} catch (Exception $e) {
    $diagnostics['checks']['table_structure'] = 'ERROR: ' . $e->getMessage();
}

// 3. 最新のレコードを確認（メールアドレスは部分マスク）
try {
    $stmt = $pdo->query("
        SELECT 
            id,
            CONCAT(LEFT(email, 3), '***@', SUBSTRING_INDEX(email, '@', -1)) as masked_email,
            LENGTH(code) as code_length,
            expires_at,
            NOW() as db_now,
            CASE WHEN expires_at > NOW() THEN 'VALID' ELSE 'EXPIRED' END as status,
            TIMESTAMPDIFF(SECOND, NOW(), expires_at) as seconds_remaining,
            is_new_user,
            attempts,
            verified_at,
            created_at
        FROM email_verification_codes
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $diagnostics['recent_codes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $diagnostics['recent_codes'] = 'ERROR: ' . $e->getMessage();
}

// 4. password_hash/verify テスト
try {
    $testCode = '123456';
    $hashed = password_hash($testCode, PASSWORD_DEFAULT);
    $verifyResult = password_verify($testCode, $hashed);
    $diagnostics['checks']['password_hash_test'] = [
        'test_code' => $testCode,
        'hash_length' => strlen($hashed),
        'hash_prefix' => substr($hashed, 0, 10) . '...',
        'verify_result' => $verifyResult ? 'OK' : 'FAILED'
    ];
} catch (Exception $e) {
    $diagnostics['checks']['password_hash_test'] = 'ERROR: ' . $e->getMessage();
}

// 5. DBのタイムゾーン確認
try {
    $stmt = $pdo->query("SELECT @@session.time_zone as session_tz, @@global.time_zone as global_tz, NOW() as db_now");
    $tz = $stmt->fetch(PDO::FETCH_ASSOC);
    $diagnostics['checks']['db_timezone'] = $tz;
} catch (Exception $e) {
    $diagnostics['checks']['db_timezone'] = 'ERROR: ' . $e->getMessage();
}

// 6. 特定のメールアドレスのコードを検証（GETパラメータで指定）
$testEmail = $_GET['email'] ?? null;
$testCode = $_GET['code'] ?? null;

if ($testEmail && $testCode) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                code,
                expires_at,
                NOW() as db_now,
                CASE WHEN expires_at > NOW() THEN 'VALID' ELSE 'EXPIRED' END as expiry_status,
                verified_at,
                attempts
            FROM email_verification_codes
            WHERE email = ? AND verified_at IS NULL
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$testEmail]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($record) {
            $verifyResult = password_verify($testCode, $record['code']);
            $diagnostics['test_verification'] = [
                'record_found' => true,
                'expiry_status' => $record['expiry_status'],
                'expires_at' => $record['expires_at'],
                'db_now' => $record['db_now'],
                'verified_at' => $record['verified_at'],
                'attempts' => $record['attempts'],
                'input_code' => $testCode,
                'input_code_length' => strlen($testCode),
                'stored_hash_length' => strlen($record['code']),
                'password_verify_result' => $verifyResult ? 'MATCH' : 'NO_MATCH'
            ];
        } else {
            // レコードがない場合、最新のレコードを確認
            $stmt2 = $pdo->prepare("
                SELECT expires_at, verified_at, attempts, created_at
                FROM email_verification_codes
                WHERE email = ?
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt2->execute([$testEmail]);
            $lastRecord = $stmt2->fetch(PDO::FETCH_ASSOC);
            
            $diagnostics['test_verification'] = [
                'record_found' => false,
                'reason' => $lastRecord ? 
                    ($lastRecord['verified_at'] ? 'Already verified' : 'Expired or not found') : 
                    'No records for this email',
                'last_record' => $lastRecord
            ];
        }
    } catch (Exception $e) {
        $diagnostics['test_verification'] = 'ERROR: ' . $e->getMessage();
    }
}

// 7. PHPバージョンとパスワードハッシュ情報
$diagnostics['php_info'] = [
    'version' => PHP_VERSION,
    'password_algos' => password_algos(),
    'default_algo' => PASSWORD_DEFAULT,
    'bcrypt_cost' => PASSWORD_BCRYPT
];

echo json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
