<?php
/**
 * Guild アプリ間通知ヘルパー
 * Social9との通知連携を行う
 */

/**
 * Social9に通知を送信
 * 
 * @param int $userId 通知対象ユーザーID
 * @param string $type 通知タイプ
 * @param string $title 通知タイトル
 * @param string $message 通知メッセージ
 * @param string $link リンク先URL
 * @param array $data 追加データ
 * @return bool 成功/失敗
 */
function sendAppNotification($userId, $type, $title, $message = '', $link = '', $data = []) {
    try {
        $pdo = getDB();
        
        // app_notificationsテーブルに直接挿入
        $stmt = $pdo->prepare("
            INSERT INTO app_notifications 
            (user_id, source_app, notification_type, title, message, link, data)
            VALUES (?, 'guild', ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $type,
            $title,
            $message,
            $link,
            !empty($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : null
        ]);
        
        return true;
    } catch (PDOException $e) {
        // テーブルが存在しない場合等はエラーを無視
        error_log('App notification error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Earth受け取り通知を送信
 */
function notifyEarthReceived($userId, $amount, $fromName, $requestTitle = '') {
    $title = "🌍 {$amount} Earthを受け取りました";
    $message = $fromName ? "{$fromName}から" : '';
    if ($requestTitle) {
        $message .= "「{$requestTitle}」の報酬";
    }
    
    return sendAppNotification($userId, 'guild_earth_received', $title, $message, '/nine/Guild/payments.php', [
        'amount' => $amount,
        'from' => $fromName,
        'request' => $requestTitle
    ]);
}

/**
 * 依頼採用通知を送信
 */
function notifyRequestAssigned($userId, $requestTitle, $requestId, $earthAmount) {
    $title = "✅ 依頼に採用されました";
    $message = "「{$requestTitle}」（{$earthAmount} Earth）";
    
    return sendAppNotification($userId, 'guild_request_assigned', $title, $message, "/nine/Guild/request.php?id={$requestId}", [
        'request_id' => $requestId,
        'earth_amount' => $earthAmount
    ]);
}

/**
 * 依頼完了通知を送信
 */
function notifyRequestCompleted($userId, $requestTitle, $requestId) {
    $title = "🎉 依頼が完了しました";
    $message = "「{$requestTitle}」";
    
    return sendAppNotification($userId, 'guild_request_completed', $title, $message, "/nine/Guild/request.php?id={$requestId}", [
        'request_id' => $requestId
    ]);
}

/**
 * 感謝受け取り通知を送信
 */
function notifyThanksReceived($userId, $amount, $fromName, $isAnonymous = false) {
    $title = "💝 感謝の気持ちを受け取りました";
    $message = $isAnonymous ? "匿名から {$amount} Earth" : "{$fromName}から {$amount} Earth";
    
    return sendAppNotification($userId, 'guild_thanks_received', $title, $message, '/nine/Guild/payments.php', [
        'amount' => $amount,
        'is_anonymous' => $isAnonymous
    ]);
}

/**
 * 前借り承認通知を送信
 */
function notifyAdvanceApproved($userId, $amount) {
    $title = "💰 前借り申請が承認されました";
    $message = "{$amount} Earth（" . number_format($amount * EARTH_TO_YEN) . "円）";
    
    return sendAppNotification($userId, 'guild_advance_approved', $title, $message, '/nine/Guild/payments.php', [
        'amount' => $amount,
        'yen' => $amount * EARTH_TO_YEN
    ]);
}

/**
 * 新規依頼通知を送信（ギルドメンバー全員へ）
 */
function notifyNewRequest($guildId, $requestTitle, $requestId, $earthAmount, $excludeUserId = null) {
    try {
        $pdo = getDB();
        
        // ギルドメンバーを取得
        $stmt = $pdo->prepare("SELECT user_id FROM guild_members WHERE guild_id = ?");
        $stmt->execute([$guildId]);
        $members = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $title = "📋 新しい依頼が投稿されました";
        $message = "「{$requestTitle}」（{$earthAmount} Earth）";
        
        foreach ($members as $memberId) {
            if ($excludeUserId && $memberId == $excludeUserId) continue;
            
            sendAppNotification($memberId, 'guild_new_request', $title, $message, "/nine/Guild/request.php?id={$requestId}", [
                'request_id' => $requestId,
                'earth_amount' => $earthAmount,
                'guild_id' => $guildId
            ]);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log('New request notification error: ' . $e->getMessage());
        return false;
    }
}
