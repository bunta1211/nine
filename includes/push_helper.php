<?php
/**
 * プッシュ通知ヘルパー
 * メッセージ送信時などにプッシュ通知を送信するためのヘルパー関数
 * 
 * 必要なライブラリ: minishlink/web-push
 * インストール: composer require minishlink/web-push
 */

require_once __DIR__ . '/../config/push.php';

// Web Pushライブラリ（Composer経由）
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

/**
 * プッシュ通知用のベースURLを取得
 */
function getPushBaseUrl() {
    if (defined('APP_URL')) {
        return rtrim(APP_URL, '/');
    }
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $_SERVER['HTTP_HOST'];
    }
    return 'https://social9.jp';
}

/**
 * メッセージ送信時のプッシュ通知を送信
 * 
 * @param PDO $pdo
 * @param int $conversationId 会話ID
 * @param int $senderId 送信者ID
 * @param string $senderName 送信者名
 * @param string $content メッセージ内容
 * @param string $conversationName 会話名（グループ名など）
 * @param array $mentionedUserIds メンションされたユーザーID（空の場合は全メンバーに送信しない）
 * @param bool $isDM DMかどうか
 */
function triggerMessagePushNotification($pdo, $conversationId, $senderId, $senderName, $content, $conversationName = '', $mentionedUserIds = [], $isDM = false) {
    // プッシュ通知を送信するユーザーを決定
    $targetUserIds = [];
    
    if (!empty($mentionedUserIds)) {
        $targetUserIds = $mentionedUserIds;
    } elseif ($isDM) {
        $stmt = $pdo->prepare("
            SELECT user_id FROM conversation_members 
            WHERE conversation_id = ? AND user_id != ? AND left_at IS NULL
        ");
        $stmt->execute([$conversationId, $senderId]);
        $targetUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        // グループでメンションなし：全メンバーに送信（各ユーザーのpush_new_message設定で制御）
        $stmt = $pdo->prepare("
            SELECT user_id FROM conversation_members 
            WHERE conversation_id = ? AND user_id != ? AND left_at IS NULL
        ");
        $stmt->execute([$conversationId, $senderId]);
        $targetUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    if (empty($targetUserIds)) {
        return;
    }
    
    // 送信者を除外
    $targetUserIds = array_filter($targetUserIds, fn($id) => (int)$id !== (int)$senderId);
    
    if (empty($targetUserIds)) {
        return;
    }
    
    // 各ユーザーのプッシュ通知設定を確認
    foreach ($targetUserIds as $userId) {
        if (!shouldSendPushNotification($pdo, $userId, $mentionedUserIds, $isDM)) {
            continue;
        }
        
        // 通知内容を作成
        $title = $isDM ? $senderName : ($conversationName ?: 'グループ');
        $body = $isDM ? $content : ($senderName . ': ' . $content);
        
        // 本文を40文字に制限
        if (mb_strlen($body) > 40) {
            $body = mb_substr($body, 0, 40) . '...';
        }
        
        // アイコンは絶対URL（モバイル通知で必須）
        $baseUrl = getPushBaseUrl();
        $iconUrl = $baseUrl . '/assets/icons/icon-192x192.png';
        $badgeUrl = $baseUrl . '/assets/icons/icon-72x72.png';
        
        sendPushToUser($pdo, $userId, [
            'title' => $title,
            'body' => $body,
            'icon' => $iconUrl,
            'badge' => $badgeUrl,
            'tag' => 'conv-' . $conversationId,
            'renotify' => true,
            'data' => [
                'type' => $isDM ? 'dm' : 'message',
                'conversation_id' => $conversationId,
                'sender_id' => $senderId,
                'sender_name' => $senderName
            ],
            'actions' => [
                ['action' => 'reply', 'title' => '返信'],
                ['action' => 'dismiss', 'title' => '閉じる']
            ]
        ]);
    }
}

/**
 * ユーザーのプッシュ通知設定を確認
 */
function shouldSendPushNotification($pdo, $userId, $mentionedUserIds, $isDM) {
    try {
        $stmt = $pdo->prepare("
            SELECT push_enabled, push_new_message, push_mention, push_dm 
            FROM notification_settings 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $settings = $stmt->fetch();
        
        if (!$settings) {
            // 設定がない場合はデフォルト（DM・グループ・メンションすべて通知）
            $settings = [
                'push_enabled' => 1,
                'push_new_message' => 1,
                'push_mention' => 1,
                'push_dm' => 1
            ];
        }
        
        // プッシュ通知が無効
        if (!$settings['push_enabled']) {
            return false;
        }
        
        // DMの場合
        if ($isDM && $settings['push_dm']) {
            return true;
        }
        
        // メンションされている場合
        if (!empty($mentionedUserIds) && in_array($userId, $mentionedUserIds) && $settings['push_mention']) {
            return true;
        }
        
        // 新着メッセージ通知が有効
        if ($settings['push_new_message']) {
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        // エラーの場合はデフォルトでメンションとDMのみ許可
        if ($isDM || !empty($mentionedUserIds)) {
            return true;
        }
        return false;
    }
}

/**
 * 特定ユーザーにプッシュ通知を送信
 * @return int 送信成功数（0の場合は失敗）
 */
function sendPushToUser($pdo, $userId, $payload) {
    $stmt = $pdo->prepare("
        SELECT * FROM push_subscriptions 
        WHERE user_id = ? AND is_active = 1
    ");
    $stmt->execute([$userId]);
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($subscriptions)) {
        return 0;
    }
    
    $sentCount = 0;
    foreach ($subscriptions as $sub) {
        if (sendWebPushNotification($pdo, $sub, $payload, $userId)) {
            $sentCount++;
        }
    }
    
    return $sentCount;
}

/**
 * Web Pushを送信（AES128GCM暗号化対応）
 * minishlink/web-push ライブラリが必須
 */
function sendWebPushNotification($pdo, $subscription, $payload, $userId) {
    if (!class_exists('Minishlink\WebPush\WebPush')) {
        error_log('Push: minishlink/web-push がインストールされていません。composer require minishlink/web-push を実行してください。');
        logPushResult($pdo, $subscription['id'], $userId, $payload, false, 'Web Pushライブラリ未インストール');
        return false;
    }
    
    $endpoint = $subscription['endpoint'];
    $p256dh = $subscription['p256dh'] ?? '';
    $auth = $subscription['auth'] ?? '';
    
    if (empty($p256dh) || empty($auth)) {
        error_log('Push: 購読情報にp256dhまたはauthがありません');
        logPushResult($pdo, $subscription['id'], $userId, $payload, false, '購読情報が不正');
        return false;
    }
    
    // Service Worker用のJSONペイロード（title, body, icon等）
    $payloadForSw = [
        'title' => $payload['title'] ?? (defined('APP_NAME') ? APP_NAME : 'Social100'),
        'body' => $payload['body'] ?? '新しいメッセージがあります',
        'icon' => $payload['icon'] ?? null,
        'badge' => $payload['badge'] ?? null,
        'tag' => $payload['tag'] ?? 'social9-' . time(),
        'renotify' => $payload['renotify'] ?? true,
        'data' => $payload['data'] ?? [],
        'actions' => $payload['actions'] ?? []
    ];
    $payloadJson = json_encode($payloadForSw, JSON_UNESCAPED_UNICODE);
    
    try {
        $webPush = new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject' => defined('VAPID_SUBJECT') ? VAPID_SUBJECT : 'mailto:admin@social9.example.com',
                'publicKey' => VAPID_PUBLIC_KEY,
                'privateKey' => VAPID_PRIVATE_KEY
            ]
        ]);
        
        $sub = \Minishlink\WebPush\Subscription::create([
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => $p256dh,
                'auth' => $auth
            ]
        ]);
        
        $report = $webPush->sendOneNotification($sub, $payloadJson);
        
        $success = $report->isSuccess();
        $reason = $success ? null : $report->getReason();
        
        if (!$success && $report->isSubscriptionExpired()) {
            $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE id = ?")->execute([$subscription['id']]);
        }
        
        if ($success) {
            $pdo->prepare("UPDATE push_subscriptions SET last_used_at = NOW() WHERE id = ?")->execute([$subscription['id']]);
        }
        
        logPushResult($pdo, $subscription['id'], $userId, $payload, $success, $reason);
        return $success;
        
    } catch (Exception $e) {
        error_log('Push send error: ' . $e->getMessage());
        logPushResult($pdo, $subscription['id'], $userId, $payload, false, $e->getMessage());
        return false;
    }
}

/**
 * 通話着信時のプッシュ通知を送信（相手の携帯でバイブ・音声通知を出すため）
 *
 * @param PDO $pdo
 * @param array $targetUserIds 着信先ユーザーIDの配列
 * @param int $callId 通話ID
 * @param string $roomId JitsiルームID
 * @param int $conversationId 会話ID
 * @param string $callType 'audio' | 'video'
 * @param string $initiatorName 発信者表示名
 */
function triggerCallPushNotification($pdo, array $targetUserIds, $callId, $roomId, $conversationId, $callType, $initiatorName) {
    if (empty($targetUserIds)) {
        return;
    }
    $baseUrl = getPushBaseUrl();
    $iconUrl = $baseUrl . '/assets/icons/icon-192x192.png';
    $badgeUrl = $baseUrl . '/assets/icons/icon-72x72.png';
    $label = $callType === 'video' ? 'ビデオ通話' : '音声通話';
    $title = '着信';
    $body = $initiatorName . 'さんから' . $label;

    foreach ($targetUserIds as $userId) {
        try {
            $stmt = $pdo->prepare("SELECT notify_call FROM user_notification_settings WHERE user_id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if ($row && (int)($row['notify_call'] ?? 1) === 0) {
                continue; // 通話着信通知オフならスキップ
            }
        } catch (Throwable $e) {
            // テーブルが無い等は送信する
        }
        sendPushToUser($pdo, (int)$userId, [
            'title' => $title,
            'body' => $body,
            'icon' => $iconUrl,
            'badge' => $badgeUrl,
            'tag' => 'call-' . $callId,
            'renotify' => true,
            'data' => [
                'type' => 'call_incoming',
                'call_id' => (int)$callId,
                'room_id' => $roomId,
                'conversation_id' => (int)$conversationId,
                'call_type' => $callType,
                'initiator_name' => $initiatorName
            ],
            'actions' => [
                ['action' => 'answer', 'title' => '出る'],
                ['action' => 'decline', 'title' => '拒否']
            ]
        ]);
    }
}

/**
 * プッシュ送信結果をログに記録
 */
function logPushResult($pdo, $subscriptionId, $userId, $payload, $success, $errorMessage = null) {
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
            $success ? 'sent' : 'failed',
            $errorMessage,
            $success ? date('Y-m-d H:i:s') : null
        ]);
    } catch (Exception $e) {
        error_log('Push log error: ' . $e->getMessage());
    }
}
