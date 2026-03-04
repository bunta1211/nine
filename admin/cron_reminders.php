<?php
/**
 * リマインダー通知cronジョブ
 * 
 * 1分ごとに実行することを推奨
 * crontab例: * * * * * php /path/to/admin/cron_reminders.php
 * 
 * Windowsの場合はタスクスケジューラで設定
 */

// CLIまたはWebからのテスト実行を許可
if (php_sapi_name() !== 'cli' && !defined('CRON_ALLOWED')) {
    // Webからのテスト実行用
    if (isset($_GET['test'])) {
        // セッション開始してログイン確認
        require_once __DIR__ . '/../config/session.php';
        if (!isLoggedIn()) {
            http_response_code(403);
            die('Login required');
        }
        // 管理者のみ許可
        $role = $_SESSION['role'] ?? 'user';
        if (!in_array($role, ['developer', 'admin', 'system_admin', 'super_admin'])) {
            http_response_code(403);
            die('Admin only');
        }
    } else {
        http_response_code(403);
        die('CLI only or add ?test=1');
    }
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/push_helper.php';

$pdo = getDB();

// 実行ログ
$logFile = __DIR__ . '/../logs/cron_reminders.log';
function cronLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message\n", 3, $logFile);
    if (php_sapi_name() === 'cli') {
        echo "[$timestamp] $message\n";
    }
}

cronLog("リマインダーチェック開始");

try {
    // 通知すべきリマインダーを取得（現在時刻以前で未通知のもの）
    $stmt = $pdo->prepare("
        SELECT r.*, u.display_name, u.email
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
    
    cronLog("対象リマインダー数: " . count($reminders));
    
    foreach ($reminders as $reminder) {
        $userId = $reminder['user_id'];
        $title = $reminder['title'];
        $description = $reminder['description'] ?: '';
        
        cronLog("処理中: ID={$reminder['id']}, User={$userId}, Title={$title}");
        
        // プッシュ通知を送信
        // 10秒間のバイブレーションパターン（500ms振動、200ms休止を繰り返し）
        $vibratePattern = [];
        for ($i = 0; $i < 14; $i++) {
            $vibratePattern[] = 500;  // 振動
            $vibratePattern[] = 200;  // 休止
        }
        $vibratePattern[] = 500;  // 最後の振動（合計約10秒）
        
        $pushPayload = [
            'title' => '⏰ ' . $title,
            'body' => $description ?: 'リマインダーの時間です',
            'icon' => '/assets/icons/icon-192x192.png',
            'badge' => '/assets/icons/icon-72x72.png',
            'tag' => 'reminder-' . $reminder['id'],
            'renotify' => true,
            'requireInteraction' => true,  // ユーザーが操作するまで消えない
            'vibrate' => $vibratePattern,  // 10秒間バイブレーション
            'data' => [
                'type' => 'reminder',
                'reminder_id' => (int)$reminder['id'],
                'title' => $title,
                'url' => '/chat.php'  // クリック時の遷移先
            ],
            'actions' => [
                ['action' => 'view', 'title' => '確認'],
                ['action' => 'snooze', 'title' => '5分後に再通知']
            ]
        ];
        
        $pushResult = sendPushToUser($pdo, $userId, $pushPayload);
        
        // 通知ログを記録
        try {
            $logStmt = $pdo->prepare("
                INSERT INTO ai_reminder_logs (reminder_id, user_id, notification_type, status)
                VALUES (?, ?, 'push', ?)
            ");
            $logStmt->execute([
                $reminder['id'],
                $userId,
                $pushResult ? 'sent' : 'failed'
            ]);
        } catch (Exception $e) {
            cronLog("ログ記録エラー: " . $e->getMessage());
        }
        
        // リマインダーの状態を更新
        if ($reminder['remind_type'] === 'once') {
            // 1回限りの場合は通知済みにして非アクティブ化
            $updateStmt = $pdo->prepare("
                UPDATE ai_reminders 
                SET is_notified = 1, is_active = 0, updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$reminder['id']]);
            cronLog("  → 完了（1回限り）");
        } else {
            // 繰り返しの場合は次回の日時を計算
            $nextRemindAt = calculateNextRemindAt($reminder['remind_at'], $reminder['remind_type']);
            $updateStmt = $pdo->prepare("
                UPDATE ai_reminders 
                SET remind_at = ?, is_notified = 0, updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$nextRemindAt, $reminder['id']]);
            cronLog("  → 次回: $nextRemindAt ({$reminder['remind_type']})");
        }
    }
    
    cronLog("リマインダーチェック完了");
    
} catch (Exception $e) {
    cronLog("エラー: " . $e->getMessage());
}

/**
 * 次回のリマインド日時を計算
 */
function calculateNextRemindAt($currentRemindAt, $type) {
    $datetime = new DateTime($currentRemindAt);
    
    switch ($type) {
        case 'daily':
            $datetime->modify('+1 day');
            break;
        case 'weekly':
            $datetime->modify('+1 week');
            break;
        case 'monthly':
            $datetime->modify('+1 month');
            break;
        case 'yearly':
            $datetime->modify('+1 year');
            break;
        default:
            return $currentRemindAt;
    }
    
    return $datetime->format('Y-m-d H:i:s');
}

// Webアクセス時はJSONで結果を返す
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'processed' => count($reminders ?? []),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
