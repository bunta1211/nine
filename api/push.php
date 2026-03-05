<?php
/**
 * Web Push通知API
 * プッシュ通知の購読管理と送信
 */

define('IS_API', true);
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/push.php';
require_once __DIR__ . '/../config/app.php';
// push_helper は test など送信時にのみ必要（subscribe/unsubscribe では不要・読み込み失敗時の500回避）
$push_helper_loaded = false;
function ensurePushHelperLoaded() {
    global $push_helper_loaded;
    if (!$push_helper_loaded) {
        require_once __DIR__ . '/../includes/push_helper.php';
        $push_helper_loaded = true;
    }
}

header('Content-Type: application/json');

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

// ログイン確認（一部のアクションは除く）
$public_actions = ['vapid_public_key'];
if (!in_array($action, $public_actions) && !isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$user_id = $_SESSION['user_id'] ?? null;

/**
 * プッシュ通知用テーブルが存在しない場合は作成
 */
function ensurePushTablesExist(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(255) NOT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_used_at DATETIME DEFAULT NULL,
            INDEX idx_user_active (user_id, is_active),
            INDEX idx_endpoint (endpoint(255))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS push_notification_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subscription_id INT DEFAULT NULL,
            user_id INT NOT NULL,
            notification_type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT DEFAULT NULL,
            data JSON DEFAULT NULL,
            status ENUM('pending', 'sent', 'failed', 'expired') DEFAULT 'pending',
            error_message TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME DEFAULT NULL,
            INDEX idx_user (user_id),
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

switch ($action) {
    case 'vapid_public_key':
        // VAPID公開鍵を返す
        successResponse(['publicKey' => VAPID_PUBLIC_KEY]);
        break;
    
    case 'debug':
        // デバッグ用：基本情報を返す
        $debugInfo = [
            'user_id' => $user_id,
            'php_version' => PHP_VERSION,
            'web_push_library' => class_exists('Minishlink\WebPush\WebPush') ? 'installed' : 'NOT INSTALLED',
            'vapid_public_key_set' => defined('VAPID_PUBLIC_KEY') && !empty(VAPID_PUBLIC_KEY) ? 'yes' : 'no',
            'vapid_private_key_set' => defined('VAPID_PRIVATE_KEY') && !empty(VAPID_PRIVATE_KEY) ? 'yes' : 'no'
        ];
        successResponse(['debug' => $debugInfo]);
        break;
        
    case 'subscribe':
        // プッシュ通知を購読
        try {
            $subscription = $input['subscription'] ?? null;
            
            if (!$subscription || !isset($subscription['endpoint'])) {
                errorResponse('購読情報が不正です');
            }
            
            $endpoint = $subscription['endpoint'];
            $p256dh = isset($subscription['keys']['p256dh']) ? $subscription['keys']['p256dh'] : '';
            $auth = isset($subscription['keys']['auth']) ? $subscription['keys']['auth'] : '';
            $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
            
            // テーブルがなければ自動作成
            ensurePushTablesExist($pdo);
            
            // 既存の購読を確認
            $stmt = $pdo->prepare("SELECT id FROM push_subscriptions WHERE endpoint = ?");
            $stmt->execute([$endpoint]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing && isset($existing['id'])) {
                $stmt = $pdo->prepare("
                    UPDATE push_subscriptions 
                    SET user_id = ?, p256dh = ?, auth = ?, user_agent = ?, is_active = 1, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$user_id, $p256dh, $auth, $userAgent, (int)$existing['id']]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$user_id, $endpoint, $p256dh, $auth, $userAgent]);
            }
            
            // 保存確認用に購読数を取得
            $stmt = $pdo->prepare("SELECT COUNT(*) as n FROM push_subscriptions WHERE user_id = ? AND is_active = 1");
            $stmt->execute([$user_id]);
            $count = (int)$stmt->fetch(PDO::FETCH_ASSOC)['n'];
            successResponse(['subscription_count' => $count], 'プッシュ通知を有効にしました');
        } catch (PDOException $e) {
            error_log('Push subscribe PDO error: ' . $e->getMessage());
            errorResponse('購読の保存に失敗しました: ' . $e->getMessage(), 500);
        } catch (Throwable $e) {
            error_log('Push subscribe error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            errorResponse('サーバーエラー: ' . $e->getMessage(), 500);
        }
        break;
        
    case 'unsubscribe':
        // プッシュ通知の購読を解除
        $endpoint = $input['endpoint'] ?? '';
        
        if (!$endpoint) {
            errorResponse('エンドポイントが必要です');
        }
        
        $stmt = $pdo->prepare("
            UPDATE push_subscriptions SET is_active = 0, updated_at = NOW()
            WHERE endpoint = ? AND user_id = ?
        ");
        $stmt->execute([$endpoint, $user_id]);
        
        successResponse([], 'プッシュ通知を無効にしました');
        break;
        
    case 'update_subscription':
        // 購読情報の更新（Service Workerからの呼び出し用）
        $oldEndpoint = $input['old_endpoint'] ?? '';
        $newSubscription = $input['new_subscription'] ?? null;
        
        ensurePushTablesExist($pdo);
        if ($oldEndpoint && $newSubscription) {
            // 古い購読を無効化
            $stmt = $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE endpoint = ?");
            $stmt->execute([$oldEndpoint]);
            
            // 新しい購読を登録
            $stmt = $pdo->prepare("
                INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), is_active = 1
            ");
            $stmt->execute([
                $user_id,
                $newSubscription['endpoint'],
                $newSubscription['keys']['p256dh'] ?? '',
                $newSubscription['keys']['auth'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        }
        
        successResponse([]);
        break;
        
    case 'test':
        ensurePushTablesExist($pdo);
        ensurePushHelperLoaded();
        // デバッグ: 購読情報を確認
        $subscriptions = [];
        $sentCount = 0;
        try {
            $stmt = $pdo->prepare("SELECT id, endpoint, p256dh, auth, is_active, created_at FROM push_subscriptions WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // テーブルがない場合
        }
        
        $debugInfo = [
            'user_id' => $user_id,
            'subscription_count' => count($subscriptions),
            'active_count' => count(array_filter($subscriptions, function($s) { return $s['is_active']; })),
            'web_push_library' => class_exists('Minishlink\WebPush\WebPush') ? 'installed' : 'NOT INSTALLED',
            'vapid_public_key_set' => defined('VAPID_PUBLIC_KEY') && !empty(VAPID_PUBLIC_KEY) ? 'yes' : 'no',
            'vapid_private_key_set' => defined('VAPID_PRIVATE_KEY') && !empty(VAPID_PRIVATE_KEY) ? 'yes' : 'no'
        ];
        
        $baseUrl = function_exists('getPushBaseUrl') ? getPushBaseUrl() : (defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://social9.jp');
        
        try {
            $sentCount = sendPushToUser($pdo, $user_id, [
                'title' => defined('APP_NAME') ? APP_NAME : 'Social100',
                'body' => 'プッシュ通知が正常に動作しています！🎉',
                'icon' => $baseUrl . '/assets/icons/icon-192x192.png',
                'badge' => $baseUrl . '/assets/icons/icon-72x72.png',
                'tag' => 'test-' . time(),
                'data' => ['type' => 'test', 'timestamp' => time()]
            ]);
        } catch (Exception $e) {
            errorResponse('プッシュ送信エラー: ' . $e->getMessage(), 500, ['debug' => $debugInfo]);
        }
        
        if ($sentCount > 0) {
            successResponse(['sent_count' => $sentCount, 'debug' => $debugInfo], 'テスト通知を送信しました');
        } else {
            // 最新のログを取得
            $recentLogs = [];
            try {
                $stmt = $pdo->prepare("SELECT status, error_message, created_at FROM push_notification_logs WHERE user_id = ? ORDER BY id DESC LIMIT 5");
                $stmt->execute([$user_id]);
                $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // ログテーブルがない場合は無視
            }
            
            $activeCount = (int)($debugInfo['active_count'] ?? 0);
            $lastError = !empty($recentLogs[0]['error_message']) ? $recentLogs[0]['error_message'] : '';
            if ($activeCount > 0) {
                $msg = '購読は' . $activeCount . '件ありますが、プッシュ送信に失敗しました。';
                if ($lastError) {
                    $msg .= ' 原因: ' . $lastError;
                } elseif (($debugInfo['web_push_library'] ?? '') === 'NOT INSTALLED') {
                    $msg .= ' Web Pushライブラリ(minishlink/web-push)がインストールされていません。composer require minishlink/web-push を実行してください。';
                } elseif (!defined('VAPID_PUBLIC_KEY') || !defined('VAPID_PRIVATE_KEY') || empty(VAPID_PUBLIC_KEY) || empty(VAPID_PRIVATE_KEY)) {
                    $msg .= ' config/push.php にVAPIDキーが設定されていません。';
                }
            } else {
                $msg = '有効な購読がありません。通知を有効にしてから再度お試しください。';
            }
            
            errorResponse($msg, 400, [
                'debug' => $debugInfo,
                'recent_logs' => $recentLogs
            ]);
        }
        break;
        
    case 'status':
        // 購読状態を確認（テーブルがなければ自動作成）
        ensurePushTablesExist($pdo);
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM push_subscriptions 
            WHERE user_id = ? AND is_active = 1
        ");
        $stmt->execute([$user_id]);
        $count = (int)$stmt->fetch()['count'];
        
        successResponse([
            'subscribed' => $count > 0,
            'subscription_count' => $count
        ]);
        break;
        
    default:
        errorResponse('不明なアクションです');
}

/**
 * プッシュ通知を送信
 * 
 * @param int $userId 送信先ユーザーID
 * @param array $payload 通知データ
 * @return array 結果
 */
function sendPushNotification($userId, $payload) {
    global $pdo;
    
    // ユーザーの有効な購読を取得
    $stmt = $pdo->prepare("
        SELECT * FROM push_subscriptions 
        WHERE user_id = ? AND is_active = 1
    ");
    $stmt->execute([$userId]);
    $subscriptions = $stmt->fetchAll();
    
    if (empty($subscriptions)) {
        return ['success' => false, 'message' => '有効な購読がありません', 'sent_count' => 0];
    }
    
    $sentCount = 0;
    $failedEndpoints = [];
    
    foreach ($subscriptions as $sub) {
        $result = sendWebPush($sub, $payload);
        
        if ($result['success']) {
            $sentCount++;
            // 最終使用日時を更新
            $pdo->prepare("UPDATE push_subscriptions SET last_used_at = NOW() WHERE id = ?")->execute([$sub['id']]);
        } else {
            $failedEndpoints[] = $sub['endpoint'];
            
            // 410 Gone または 404 の場合は購読を無効化
            if (in_array($result['status'], [404, 410])) {
                $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE id = ?")->execute([$sub['id']]);
            }
        }
        
        // ログを記録
        logPushNotification($sub['id'], $userId, $payload, $result);
    }
    
    return [
        'success' => $sentCount > 0,
        'message' => $sentCount > 0 ? '送信完了' : '送信失敗',
        'sent_count' => $sentCount,
        'failed_endpoints' => $failedEndpoints
    ];
}

/**
 * 複数ユーザーにプッシュ通知を送信
 * 
 * @param array $userIds 送信先ユーザーID配列
 * @param array $payload 通知データ
 * @return array 結果
 */
function sendPushNotificationToUsers($userIds, $payload) {
    $totalSent = 0;
    $results = [];
    
    foreach ($userIds as $userId) {
        $result = sendPushNotification($userId, $payload);
        $totalSent += $result['sent_count'];
        $results[$userId] = $result;
    }
    
    return [
        'success' => $totalSent > 0,
        'total_sent' => $totalSent,
        'results' => $results
    ];
}

/**
 * Web Pushを送信（cURL使用）
 * 
 * @param array $subscription 購読情報
 * @param array $payload 通知データ
 * @return array 結果
 */
function sendWebPush($subscription, $payload) {
    $endpoint = $subscription['endpoint'];
    $p256dh = $subscription['p256dh'];
    $auth = $subscription['auth'];
    
    // ペイロードをJSON化
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    
    // JWTヘッダーを生成
    $jwt = generateVapidJwt($endpoint);
    
    // 暗号化（簡易版 - 本番環境ではライブラリ使用推奨）
    // ここでは暗号化なしでテスト（一部ブラウザでは動作しない可能性あり）
    $encryptedPayload = encryptPayload($payloadJson, $p256dh, $auth);
    
    if ($encryptedPayload === false) {
        // 暗号化に失敗した場合、暗号化なしで送信を試みる
        return sendUnencryptedPush($endpoint, $payloadJson, $jwt);
    }
    
    // cURLでプッシュサービスにリクエスト
    $ch = curl_init($endpoint);
    
    $headers = [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'TTL: ' . PUSH_DEFAULT_TTL,
        'Urgency: ' . PUSH_DEFAULT_URGENCY,
        'Authorization: vapid t=' . $jwt . ', k=' . VAPID_PUBLIC_KEY
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $encryptedPayload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'response' => $response,
        'error' => $error
    ];
}

/**
 * 暗号化なしでプッシュ送信（フォールバック）
 */
function sendUnencryptedPush($endpoint, $payload, $jwt) {
    $ch = curl_init($endpoint);
    
    $headers = [
        'Content-Type: application/json',
        'TTL: ' . PUSH_DEFAULT_TTL,
        'Urgency: ' . PUSH_DEFAULT_URGENCY,
        'Authorization: vapid t=' . $jwt . ', k=' . VAPID_PUBLIC_KEY
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'response' => $response,
        'error' => $error
    ];
}

/**
 * VAPID JWT を生成
 */
function generateVapidJwt($endpoint) {
    // エンドポイントからオリジンを抽出
    $urlParts = parse_url($endpoint);
    $audience = $urlParts['scheme'] . '://' . $urlParts['host'];
    
    // JWTヘッダー
    $header = base64UrlEncode(json_encode([
        'typ' => 'JWT',
        'alg' => 'ES256'
    ]));
    
    // JWTペイロード
    $payload = base64UrlEncode(json_encode([
        'aud' => $audience,
        'exp' => time() + 86400, // 24時間有効
        'sub' => VAPID_SUBJECT
    ]));
    
    $unsignedToken = $header . '.' . $payload;
    
    // 署名を生成（OpenSSL使用）
    $signature = signWithECDSA($unsignedToken, VAPID_PRIVATE_KEY);
    
    return $unsignedToken . '.' . $signature;
}

/**
 * ECDSA署名を生成
 */
function signWithECDSA($data, $privateKeyBase64) {
    // 秘密鍵をデコード
    $privateKeyRaw = base64UrlDecode($privateKeyBase64);
    
    // OpenSSLで署名（簡易実装）
    // 注: 本番環境では適切なECDSA署名ライブラリを使用すること
    $signature = hash_hmac('sha256', $data, $privateKeyRaw, true);
    
    return base64UrlEncode($signature);
}

/**
 * ペイロードを暗号化（簡易版）
 * 注: 本番環境では web-push ライブラリなどを使用すること
 */
function encryptPayload($payload, $p256dh, $auth) {
    // 暗号化は複雑なため、ここでは false を返してフォールバックに任せる
    // 本番環境では minishlink/web-push などのライブラリを使用
    return false;
}

/**
 * プッシュ通知ログを記録
 */
function logPushNotification($subscriptionId, $userId, $payload, $result) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO push_notification_logs 
            (subscription_id, user_id, notification_type, title, body, data, status, error_message, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $subscriptionId,
            $userId,
            $payload['data']['type'] ?? 'general',
            $payload['title'] ?? '',
            $payload['body'] ?? '',
            json_encode($payload['data'] ?? []),
            $result['success'] ? 'sent' : 'failed',
            $result['error'] ?? null,
            $result['success'] ? date('Y-m-d H:i:s') : null
        ]);
    } catch (Exception $e) {
        error_log('Push notification log error: ' . $e->getMessage());
    }
}

// ヘルパー関数（push.php設定ファイルにも定義されているが、念のため）
if (!function_exists('base64UrlEncode')) {
    function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('base64UrlDecode')) {
    function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }
}
