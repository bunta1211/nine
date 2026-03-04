<?php
/**
 * Guild 通知API
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';
require_once __DIR__ . '/../includes/common.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        requireApiLogin();
        listNotifications();
        break;
    case 'mark_read':
        requireApiLogin();
        markRead();
        break;
    case 'mark_all_read':
        requireApiLogin();
        markAllRead();
        break;
    case 'count':
        requireApiLogin();
        getUnreadCount();
        break;
    default:
        jsonError('Invalid action', 400);
}

/**
 * 通知一覧
 */
function listNotifications() {
    $userId = getGuildUserId();
    $limit = (int)($_GET['limit'] ?? 50);
    $offset = (int)($_GET['offset'] ?? 0);
    
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT * FROM guild_notifications 
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$userId, $limit, $offset]);
    $notifications = $stmt->fetchAll();
    
    jsonSuccess(['notifications' => $notifications]);
}

/**
 * 既読にする
 */
function markRead() {
    $input = getJsonInput();
    $notificationId = (int)($input['notification_id'] ?? 0);
    $userId = getGuildUserId();
    
    $pdo = getDB();
    $stmt = $pdo->prepare("
        UPDATE guild_notifications 
        SET is_read = 1, read_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$notificationId, $userId]);
    
    jsonSuccess([]);
}

/**
 * すべて既読にする
 */
function markAllRead() {
    $userId = getGuildUserId();
    
    $pdo = getDB();
    $stmt = $pdo->prepare("
        UPDATE guild_notifications 
        SET is_read = 1, read_at = NOW()
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$userId]);
    
    jsonSuccess([], 'すべて既読にしました');
}

/**
 * 未読数取得（テーブル未作成時は 0 を返す）
 */
function getUnreadCount() {
    try {
        $count = getUnreadNotificationCount();
        jsonSuccess(['count' => $count]);
    } catch (PDOException $e) {
        // guild_notifications テーブルが未作成の場合は 0 を返す
        jsonSuccess(['count' => 0]);
    }
}
