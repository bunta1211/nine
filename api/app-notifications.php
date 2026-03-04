<?php
/**
 * アプリ間通知API
 * 外部アプリ（Guild等）からの通知を取得・管理
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';

$action = $_GET['action'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    jsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
}

switch ($action) {
    case 'count':
        // 未読通知数を取得
        getUnreadCount($userId);
        break;
        
    case 'list':
        // 通知一覧を取得
        getNotificationList($userId);
        break;
        
    case 'mark_read':
        // 通知を既読にする
        markAsRead($userId);
        break;
        
    case 'mark_all_read':
        // すべて既読にする
        markAllAsRead($userId);
        break;
        
    default:
        jsonResponse(['success' => false, 'error' => 'Invalid action']);
}

/**
 * 未読通知数を取得
 */
function getUnreadCount($userId) {
    global $pdo;
    
    try {
        // テーブルが存在するか確認
        $stmt = $pdo->query("SHOW TABLES LIKE 'app_notifications'");
        if ($stmt->rowCount() === 0) {
            jsonResponse(['success' => true, 'count' => 0]);
            return;
        }
        
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM app_notifications 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$userId]);
        $count = (int)$stmt->fetchColumn();
        
        jsonResponse(['success' => true, 'count' => $count]);
    } catch (PDOException $e) {
        jsonResponse(['success' => true, 'count' => 0]);
    }
}

/**
 * 通知一覧を取得
 */
function getNotificationList($userId) {
    global $pdo;
    
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $sourceApp = $_GET['source'] ?? null;
    
    try {
        // テーブルが存在するか確認
        $stmt = $pdo->query("SHOW TABLES LIKE 'app_notifications'");
        if ($stmt->rowCount() === 0) {
            jsonResponse(['success' => true, 'notifications' => [], 'total' => 0]);
            return;
        }
        
        $sql = "SELECT * FROM app_notifications WHERE user_id = ?";
        $params = [$userId];
        
        if ($sourceApp) {
            $sql .= " AND source_app = ?";
            $params[] = $sourceApp;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // データ型を整形
        foreach ($notifications as &$n) {
            $n['id'] = (int)$n['id'];
            $n['user_id'] = (int)$n['user_id'];
            $n['is_read'] = (int)$n['is_read'];
            $n['data'] = $n['data'] ? json_decode($n['data'], true) : null;
        }
        
        // 総数を取得
        $countSql = "SELECT COUNT(*) FROM app_notifications WHERE user_id = ?";
        $countParams = [$userId];
        if ($sourceApp) {
            $countSql .= " AND source_app = ?";
            $countParams[] = $sourceApp;
        }
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($countParams);
        $total = (int)$stmt->fetchColumn();
        
        jsonResponse([
            'success' => true,
            'notifications' => $notifications,
            'total' => $total
        ]);
    } catch (PDOException $e) {
        jsonResponse(['success' => true, 'notifications' => [], 'total' => 0]);
    }
}

/**
 * 通知を既読にする
 */
function markAsRead($userId) {
    global $pdo;
    
    $input = json_decode(file_get_contents('php://input'), true);
    $notificationId = $input['id'] ?? null;
    
    if (!$notificationId) {
        jsonResponse(['success' => false, 'error' => 'Notification ID required']);
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE app_notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$notificationId, $userId]);
        
        jsonResponse(['success' => true]);
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'error' => 'Database error']);
    }
}

/**
 * すべて既読にする
 */
function markAllAsRead($userId) {
    global $pdo;
    
    $input = json_decode(file_get_contents('php://input'), true);
    $sourceApp = $input['source'] ?? null;
    
    try {
        $sql = "UPDATE app_notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0";
        $params = [$userId];
        
        if ($sourceApp) {
            $sql .= " AND source_app = ?";
            $params[] = $sourceApp;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        jsonResponse(['success' => true, 'updated' => $stmt->rowCount()]);
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'error' => 'Database error']);
    }
}
