<?php
/**
 * AIリマインダー未読通知取得API
 * ai.php が 500 になる対策として独立エンドポイント化
 */
header('Content-Type: application/json; charset=utf-8');
define('IS_API', true);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/../includes/api-helpers.php';

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$pdo = getDB();
$user_id = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("
        SELECT r.*, l.notified_at
        FROM ai_reminders r
        LEFT JOIN ai_reminder_logs l ON r.id = l.reminder_id AND l.status = 'sent'
        WHERE r.user_id = ?
        AND r.is_active = 1
        AND r.remind_at <= NOW()
        AND (r.is_notified = 0 OR l.status = 'sent')
        ORDER BY r.remind_at DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    successResponse(['notifications' => $notifications]);
} catch (Throwable $e) {
    error_log("get_pending_notifications: " . $e->getMessage());
    successResponse(['notifications' => []]);
}
