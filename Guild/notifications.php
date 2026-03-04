<?php
/**
 * Guild 通知ページ
 */

require_once __DIR__ . '/includes/common.php';

$pageTitle = __('notifications');

require_once __DIR__ . '/templates/header.php';

$pdo = getDB();
$userId = getGuildUserId();

// 通知を取得
$stmt = $pdo->prepare("
    SELECT * FROM guild_notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 100
");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

// 未読を既読に更新
$stmt = $pdo->prepare("UPDATE guild_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
$stmt->execute([$userId]);
?>

<div class="page-header">
    <h1 class="page-title"><?= __('notifications') ?></h1>
    <?php if (!empty($notifications)): ?>
    <button class="btn btn-secondary" onclick="markAllRead()">すべて既読にする</button>
    <?php endif; ?>
</div>

<?php if (empty($notifications)): ?>
<div class="empty-state">
    <div class="empty-icon">🔔</div>
    <p>通知はありません</p>
</div>
<?php else: ?>
<div class="notification-list">
    <?php foreach ($notifications as $notification): ?>
    <a href="<?= $notification['link'] ? h($notification['link']) : '#' ?>" 
       class="notification-item <?= $notification['is_read'] ? '' : 'unread' ?>">
        <div class="notification-icon">
            <?php
            $icons = [
                'new_request' => '📋',
                'assigned' => '✅',
                'approved' => '👍',
                'earth_received' => '🌍',
                'thanks' => '💝',
                'advance_payment' => '💰',
                'request_update' => '📝',
                'system' => '⚙️'
            ];
            echo $icons[$notification['type']] ?? '🔔';
            ?>
        </div>
        <div class="notification-content">
            <div class="notification-title"><?= h($notification['title']) ?></div>
            <?php if ($notification['message']): ?>
            <div class="notification-message"><?= h($notification['message']) ?></div>
            <?php endif; ?>
            <div class="notification-time"><?= date('n/j H:i', strtotime($notification['created_at'])) ?></div>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.notification-list {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
}

.notification-item {
    display: flex;
    gap: var(--spacing-md);
    padding: var(--spacing-md);
    background: var(--color-bg-card);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: inherit;
    transition: background var(--transition-fast);
}

.notification-item:hover {
    background: var(--color-bg-hover);
}

.notification-item.unread {
    background: #eff6ff;
    border-left: 3px solid var(--color-primary);
}

.dark .notification-item.unread {
    background: rgba(99, 102, 241, 0.1);
}

.notification-icon {
    font-size: 1.5rem;
    width: 40px;
    text-align: center;
}

.notification-content {
    flex: 1;
}

.notification-title {
    font-weight: 500;
    margin-bottom: var(--spacing-xs);
}

.notification-message {
    font-size: var(--font-size-sm);
    color: var(--color-text-secondary);
    margin-bottom: var(--spacing-xs);
}

.notification-time {
    font-size: var(--font-size-xs);
    color: var(--color-text-muted);
}
</style>

<script>
async function markAllRead() {
    try {
        await Guild.api('notifications.php?action=mark_all_read', { method: 'POST' });
        location.reload();
    } catch (error) {
        Guild.toast('エラーが発生しました', 'error');
    }
}
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
