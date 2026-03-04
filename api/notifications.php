<?php
/**
 * 通知API
 * 仕様書: 07_通知機能.md
 * api-bootstrap により IP ブロック・迎撃・共通エラーハンドリングを適用
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$pdo = getDB();
$user_id = $_SESSION['user_id'];

$method = $_SERVER['REQUEST_METHOD'];
$input = getJsonInput() ?: $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        // 通知一覧を取得
        $unread_only = isset($_GET['unread_only']) && $_GET['unread_only'] === '1';
        $limit = min((int)($_GET['limit'] ?? 20), 50);
        $offset = (int)($_GET['offset'] ?? 0);
        
        $sql = "
            SELECT * FROM notifications
            WHERE user_id = ?
        ";
        $params = [$user_id];
        
        if ($unread_only) {
            $sql .= " AND is_read = 0";
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $notifications = $stmt->fetchAll();
        
        // 未読数を取得
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        $unread_count = (int)$stmt->fetch()['count'];
        
        successResponse([
            'notifications' => $notifications,
            'unread_count' => $unread_count
        ]);
        break;
        
    case 'unread_count':
    case 'count':
        // 通知の未読数（一覧用）・未読メッセージ数。例外時も500にせず200で安全な値を返す
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$user_id]);
            $unread_count = (int)$stmt->fetch()['count'];
        } catch (Throwable $e) {
            error_log("notifications.php count (notifications): " . $e->getMessage());
            $unread_count = 0;
        }
        
        $unread_messages = 0;
        try {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(unread), 0) as total FROM (
                    SELECT 
                        (SELECT COUNT(*) FROM messages m 
                         WHERE m.conversation_id = c.id 
                         AND m.deleted_at IS NULL 
                         AND m.sender_id != ?
                         AND (
                             (cm.last_read_message_id IS NOT NULL AND m.id > cm.last_read_message_id)
                             OR (cm.last_read_message_id IS NULL AND (cm.last_read_at IS NULL OR m.created_at > cm.last_read_at))
                         )
                        ) as unread
                    FROM conversations c
                    INNER JOIN conversation_members cm ON c.id = cm.conversation_id
                    WHERE cm.user_id = ? AND cm.left_at IS NULL
                ) as counts
            ");
            $stmt->execute([$user_id, $user_id]);
            $unread_messages = (int)$stmt->fetch()['total'];
        } catch (Throwable $e) {
            error_log("notifications.php count (unread_messages): " . $e->getMessage());
        }
        
        successResponse([
            'unread_count' => $unread_count,
            'unread_messages' => $unread_messages,
            'total' => $unread_messages
        ]);
        break;
        
    case 'mark_read':
        // 特定の通知を既読にする
        $notification_id = (int)($input['notification_id'] ?? 0);
        
        if ($notification_id) {
            $pdo->prepare("
                UPDATE notifications SET is_read = 1, read_at = NOW()
                WHERE id = ? AND user_id = ?
            ")->execute([$notification_id, $user_id]);
        }
        
        successResponse([], '既読にしました');
        break;
        
    case 'mark_all_read':
        // すべての通知を既読にする
        $pdo->prepare("
            UPDATE notifications SET is_read = 1, read_at = NOW()
            WHERE user_id = ? AND is_read = 0
        ")->execute([$user_id]);
        
        successResponse([], 'すべて既読にしました');
        break;
        
    case 'delete':
        // 通知を削除
        $notification_id = (int)($input['notification_id'] ?? 0);
        
        if ($notification_id) {
            $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?")
                ->execute([$notification_id, $user_id]);
        }
        
        successResponse([]);
        break;
        
    case 'settings':
        // 通知設定を取得/更新
        if ($method === 'GET') {
            $stmt = $pdo->prepare("SELECT * FROM notification_settings WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $settings = $stmt->fetch();
            
            if (!$settings) {
                // デフォルト設定
                $settings = [
                    'notify_new_message' => 1,
                    'notify_mention' => 1,
                    'notify_call' => 1,
                    'notify_permission_request' => 1,
                    'sound_enabled' => 1
                ];
            }
            
            successResponse(['settings' => $settings]);
        } else {
            // 設定を更新
            $settings = [
                'notify_new_message' => $input['notify_new_message'] ?? 1,
                'notify_mention' => $input['notify_mention'] ?? 1,
                'notify_call' => $input['notify_call'] ?? 1,
                'notify_permission_request' => $input['notify_permission_request'] ?? 1,
                'sound_enabled' => $input['sound_enabled'] ?? 1
            ];
            
            $pdo->prepare("
                INSERT INTO notification_settings (user_id, notify_new_message, notify_mention, notify_call, notify_permission_request, sound_enabled)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    notify_new_message = VALUES(notify_new_message),
                    notify_mention = VALUES(notify_mention),
                    notify_call = VALUES(notify_call),
                    notify_permission_request = VALUES(notify_permission_request),
                    sound_enabled = VALUES(sound_enabled)
            ")->execute([
                $user_id,
                $settings['notify_new_message'],
                $settings['notify_mention'],
                $settings['notify_call'],
                $settings['notify_permission_request'],
                $settings['sound_enabled']
            ]);
            
            successResponse([], '設定を保存しました');
        }
        break;
        
    default:
        errorResponse('不明なアクションです');
}
