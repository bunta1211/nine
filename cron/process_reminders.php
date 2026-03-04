<?php
/**
 * リマインダー処理 cronジョブ
 * 
 * 設定方法:
 * crontab -e
 * * * * * * php /path/to/nine/cron/process_reminders.php >> /path/to/nine/logs/cron.log 2>&1
 * 
 * 毎分実行して、期限が来たリマインダーのプッシュ通知を送信
 */

// CLI実行チェック
if (php_sapi_name() !== 'cli' && !defined('CRON_MODE')) {
    die('This script must be run from command line');
}

define('CRON_MODE', true);

// パス設定
$basePath = dirname(__DIR__);

// 必要なファイルを読み込み
require_once $basePath . '/config/database.php';
require_once $basePath . '/config/app.php';
require_once $basePath . '/includes/push_helper.php';

// ログ出力関数
function cronLog($message) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

try {
    $pdo = getDB();
    cronLog('Reminder processor started');
    
    // 期限が来た未通知のリマインダーを取得
    $stmt = $pdo->prepare("
        SELECT r.*, u.display_name as user_name
        FROM ai_reminders r
        JOIN users u ON r.user_id = u.id
        WHERE r.is_active = 1 
        AND r.is_notified = 0 
        AND r.remind_at <= NOW()
        ORDER BY r.remind_at ASC
        LIMIT 100
    ");
    $stmt->execute();
    $reminders = $stmt->fetchAll();
    
    if (empty($reminders)) {
        cronLog('No pending reminders');
        exit(0);
    }
    
    cronLog('Found ' . count($reminders) . ' pending reminders');
    
    $successCount = 0;
    $failCount = 0;
    
    foreach ($reminders as $reminder) {
        try {
            // プッシュ通知を送信
            $notificationSent = sendReminderPushNotification($pdo, $reminder);
            
            // チャット用の通知メッセージを作成（秘書からのメッセージとして保存）
            saveReminderNotificationMessage($pdo, $reminder);
            
            // リマインダーのステータスを更新
            updateReminderStatus($pdo, $reminder);
            
            // ログを記録
            $pdo->prepare("
                INSERT INTO ai_reminder_logs (reminder_id, user_id, notification_type, status)
                VALUES (?, ?, 'both', 'sent')
            ")->execute([$reminder['id'], $reminder['user_id']]);
            
            cronLog("Sent reminder #{$reminder['id']}: {$reminder['title']} to user #{$reminder['user_id']}");
            $successCount++;
            
        } catch (Exception $e) {
            cronLog("Error processing reminder #{$reminder['id']}: " . $e->getMessage());
            $failCount++;
        }
    }
    
    cronLog("Completed: {$successCount} sent, {$failCount} failed");
    
} catch (Exception $e) {
    cronLog('Fatal error: ' . $e->getMessage());
    exit(1);
}

/**
 * リマインダーのプッシュ通知を送信
 */
function sendReminderPushNotification($pdo, $reminder) {
    $payload = [
        'title' => '⏰ ' . $reminder['title'],
        'body' => $reminder['description'] ?: 'リマインダーの時間です',
        'icon' => '/assets/icons/icon-192x192.png',
        'badge' => '/assets/icons/icon-72x72.png',
        'tag' => 'reminder-' . $reminder['id'],
        'renotify' => true,
        'requireInteraction' => true,
        'data' => [
            'type' => 'reminder',
            'reminder_id' => (int)$reminder['id'],
            'title' => $reminder['title']
        ],
        'actions' => [
            ['action' => 'complete', 'title' => '完了'],
            ['action' => 'snooze', 'title' => '10分後']
        ]
    ];
    
    return sendPushToUser($pdo, $reminder['user_id'], $payload);
}

/**
 * リマインダー通知をチャットメッセージとして保存
 */
function saveReminderNotificationMessage($pdo, $reminder) {
    try {
        $message = "⏰ リマインダー\n\n【{$reminder['title']}】\n";
        if (!empty($reminder['description'])) {
            $message .= $reminder['description'] . "\n";
        }
        $message .= "\n設定した時間になりました！";
        
        // ai_conversationsテーブルに保存（秘書からの通知として）
        $stmt = $pdo->prepare("
            INSERT INTO ai_conversations (user_id, question, answer, answered_by, language)
            VALUES (?, '[リマインダー通知]', ?, 'ai', 'ja')
        ");
        $stmt->execute([$reminder['user_id'], $message]);
        
    } catch (Exception $e) {
        error_log('Reminder message save error: ' . $e->getMessage());
    }
}

/**
 * リマインダーのステータスを更新
 */
function updateReminderStatus($pdo, $reminder) {
    if ($reminder['remind_type'] === 'once') {
        // 1回のみの場合は通知済み＆非アクティブに
        $pdo->prepare("
            UPDATE ai_reminders SET is_notified = 1, is_active = 0 WHERE id = ?
        ")->execute([$reminder['id']]);
    } else {
        // 繰り返しの場合は次回の日時を計算
        $nextRemind = calculateNextRemindDate($reminder['remind_at'], $reminder['remind_type']);
        $pdo->prepare("
            UPDATE ai_reminders SET remind_at = ?, is_notified = 0 WHERE id = ?
        ")->execute([$nextRemind, $reminder['id']]);
    }
}

/**
 * 次回のリマインド日時を計算
 */
function calculateNextRemindDate($currentDate, $type) {
    $date = new DateTime($currentDate);
    
    switch ($type) {
        case 'daily':
            $date->modify('+1 day');
            break;
        case 'weekly':
            $date->modify('+1 week');
            break;
        case 'monthly':
            $date->modify('+1 month');
            break;
        case 'yearly':
            $date->modify('+1 year');
            break;
    }
    
    return $date->format('Y-m-d H:i:s');
}
