<?php
/**
 * Social9 お知らせ画面
 * 運営からのお知らせ、システム通知、メンション通知などを表示
 */

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/design_loader.php';
require_once __DIR__ . '/includes/asset_helper.php';
require_once __DIR__ . '/includes/lang.php';

requireLogin();

$pdo = getDB();
$user_id = $_SESSION['user_id'];
$display_name = $_SESSION['display_name'] ?? 'ユーザー';

// トップバー用：ユーザー情報・所属組織
$user = [];
$userOrganizations = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT o.id, o.name, o.type, om.role as relationship
        FROM organizations o
        INNER JOIN organization_members om ON o.id = om.organization_id
        WHERE om.user_id = ? AND om.left_at IS NULL
        ORDER BY CASE om.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END, o.name
    ");
    $stmt->execute([$user_id]);
    $userOrganizations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// デザイン設定を取得
$designSettings = getDesignSettings($pdo, $user_id);

// 言語設定を初期化
$currentLang = getCurrentLanguage();

// タブ切り替え
$active_tab = $_GET['tab'] ?? 'all';

// 通知一覧取得
$notifications = [];
try {
    $sql = "
        SELECT * FROM notifications 
        WHERE user_id = ?
    ";
    $params = [$user_id];
    
    if ($active_tab === 'unread') {
        $sql .= " AND is_read = 0";
    } elseif ($active_tab === 'system') {
        $sql .= " AND type IN ('system', 'announcement')";
    } elseif ($active_tab === 'mention') {
        $sql .= " AND type = 'mention'";
    }
    
    $sql .= " ORDER BY created_at DESC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // テーブルがない場合はスキップ
}

// 未読数取得
$unread_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    $unread_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// 運営からのお知らせ（全ユーザー向け）
$announcements = [];
try {
    $stmt = $pdo->query("
        SELECT * FROM announcements 
        WHERE is_active = 1 
        AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY priority DESC, created_at DESC
        LIMIT 10
    ");
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // テーブルがない場合はスキップ
}

// 携帯ではタブ（すべて・未読・運営・メンション）を表示しない
$show_notification_tabs = (preg_match('/Mobile|Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i', $_SERVER['HTTP_USER_AGENT'] ?? '') !== 1);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('notifications') ?> | <?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social9' ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <?= generateFontLinks() ?>
    <link rel="stylesheet" href="assets/css/common.css?v=<?= assetVersion('assets/css/common.css', __DIR__) ?>">
    <link rel="stylesheet" href="assets/css/layout/header.css?v=<?= assetVersion('assets/css/layout/header.css', __DIR__) ?>">
    <link rel="stylesheet" href="assets/css/panel-panels-unified.css?v=<?= assetVersion('assets/css/panel-panels-unified.css', __DIR__) ?>">
    <?= generateDesignCSS($designSettings) ?>
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/pages-mobile.css?v=<?= assetVersion('assets/css/pages-mobile.css', __DIR__) ?>">
    <style>
        :root {
            --header-height: 70px;
            --left-panel-width: 260px;
            --right-panel-width: 280px;
        }
        
        .topbar-back-link { text-decoration: none; color: inherit; display: inline-flex; align-items: center; justify-content: center; }
        .notifications-page .top-panel .user-info .user-info-mobile-gear { display: none !important; }
        
        /* 右パネル（フィルター） */
        .notifications-page .right-panel {
            width: var(--right-panel-width);
            background: var(--dt-right-bg, rgba(255,255,255,0.95));
            display: flex;
            flex-direction: column;
            overflow: hidden;
            flex-shrink: 0;
            border-radius: 16px;
            transition: width 0.2s, margin 0.2s;
        }
        .notifications-page .right-panel.collapsed {
            width: 0;
            min-width: 0;
            margin-right: 0;
            overflow: hidden;
        }
        .notifications-page .right-panel .right-header {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            font-size: 14px;
            font-weight: 600;
            color: var(--dt-text-primary, #333);
        }
        .notifications-page .right-panel-scroll { flex: 1; overflow-y: auto; padding: 12px 0; }
        .notifications-page .right-panel .filter-tabs {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .notifications-page .right-panel .filter-tabs a {
            display: block;
            padding: 12px 16px;
            color: var(--dt-text-primary, #333);
            text-decoration: none;
            font-size: 14px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .notifications-page .right-panel .filter-tabs a:hover { background: rgba(0,0,0,0.05); }
        .notifications-page .right-panel .filter-tabs a.active {
            background: var(--dt-accent-bg, rgba(34, 197, 94, 0.15));
            color: var(--dt-accent, #059669);
            font-weight: 600;
        }

        .main-container { display: flex; }
        
        /* 左パネル（空白） */
        .left-spacer {
            width: var(--left-panel-width);
            background: rgba(255,255,255,0.95);
            flex-shrink: 0;
            border-radius: 16px;
        }
        
        /* 中央パネル - デザイントークン対応 */
        .center-panel {
            flex: 1;
            background: var(--dt-center-bg, rgba(255,255,255,0.98));
            min-width: 0;
            padding: 24px;
            border-radius: 16px;
            overflow: hidden;
            backdrop-filter: blur(12px);
        }
        
        /* スクロールバー - デザイントークン対応 */
        .center-panel::-webkit-scrollbar,
        .notification-list::-webkit-scrollbar {
            width: 6px;
        }
        .center-panel::-webkit-scrollbar-track,
        .notification-list::-webkit-scrollbar-track {
            background: transparent;
        }
        .center-panel::-webkit-scrollbar-thumb,
        .notification-list::-webkit-scrollbar-thumb {
            background: var(--dt-scroll-thumb, rgba(100,116,139,0.15));
            border-radius: 10px;
        }
        .center-panel::-webkit-scrollbar-thumb:hover,
        .notification-list::-webkit-scrollbar-thumb:hover {
            background: var(--dt-scroll-thumb-hover, rgba(100,116,139,0.25));
        }
        
        /* 右パネル（空白） */
        .right-spacer {
            width: var(--right-panel-width);
            background: rgba(255,255,255,0.95);
            flex-shrink: 0;
            border-radius: 16px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        @media (max-width: 1200px) {
            .notifications-page .right-panel { display: none; }
        }
        @media (max-width: 900px) {
            .left-spacer { display: none; }
        }
        
        /* セクションヘッダー */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .section-header h2 {
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .unread-badge {
            background: #dc2626;
            color: white;
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 10px;
        }
        
        .mark-all-read {
            padding: 8px 16px;
            background: var(--bg-secondary);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
        }
        .mark-all-read:hover { background: var(--border-light); }
        
        /* 通知リスト */
        .notification-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .notification-item {
            background: var(--dt-card-bg, #ffffff);
            border: 1px solid var(--dt-card-border, #e2e8f0);
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            gap: 16px;
            transition: all 0.2s;
            cursor: pointer;
            color: var(--dt-text-primary, #1e293b);
        }
        .notification-item:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
        }
        .notification-item.unread {
            background: var(--dt-card-bg, #ffffff);
            border-left: 3px solid var(--dt-accent, #22c55e);
        }
        
        .notification-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: rgba(100, 116, 139, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .notification-icon.system { background: rgba(59, 130, 246, 0.15); }
        .notification-icon.mention { background: rgba(234, 179, 8, 0.15); }
        .notification-icon.message { background: rgba(14, 165, 233, 0.15); }
        
        .notification-content {
            flex: 1;
            min-width: 0;
        }
        .notification-title {
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--dt-text-primary, #1e293b);
        }
        .notification-body {
            font-size: 14px;
            color: var(--dt-text-muted, #64748b);
            line-height: 1.5;
        }
        .notification-time {
            font-size: 12px;
            color: var(--dt-text-muted, #64748b);
            margin-top: 8px;
        }
        
        /* 運営お知らせ（特別枠） */
        .announcement-card {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
        }
        .announcement-card .badge {
            background: #92400e;
            color: white;
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 4px;
            margin-bottom: 8px;
            display: inline-block;
        }
        .announcement-card h3 {
            font-size: 16px;
            margin-bottom: 8px;
        }
        .announcement-card p {
            font-size: 14px;
            color: #78350f;
            line-height: 1.6;
        }
        .announcement-card .time {
            font-size: 12px;
            color: #92400e;
            margin-top: 12px;
        }
        
        /* 空状態 */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        .empty-state .icon {
            font-size: 64px;
            margin-bottom: 16px;
        }
        .empty-state h3 {
            color: var(--text-primary);
            margin-bottom: 8px;
        }
    </style>
</head>
<body class="notifications-page style-<?= htmlspecialchars($designSettings['ui_style'] ?? DESIGN_DEFAULT_STYLE) ?>" data-theme="<?= htmlspecialchars($designSettings['theme'] ?? DESIGN_DEFAULT_THEME) ?>">
    <?php
    $topbar_back_url = 'chat.php';
    include __DIR__ . '/includes/chat/topbar.php';
    ?>
    
    <!-- メインコンテナ -->
    <div class="main-container">
        <aside class="left-spacer"></aside>
        
        <main class="center-panel">
            <div class="container">
                
                <?php if (!empty($announcements)): ?>
                <!-- 運営からのお知らせ -->
                <?php foreach ($announcements as $ann): ?>
                <div class="announcement-card">
                    <span class="badge">📢 運営からのお知らせ</span>
                    <h3><?= htmlspecialchars($ann['title']) ?></h3>
                    <p><?= nl2br(htmlspecialchars($ann['content'])) ?></p>
                    <div class="time"><?= date('Y年n月j日', strtotime($ann['created_at'])) ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- セクションヘッダー -->
                <div class="section-header">
                    <h2>
                        <?= __('notification_list') ?>
                        <?php if ($unread_count > 0): ?>
                        <span class="unread-badge"><?= $unread_count ?><?= $currentLang === 'en' ? ' unread' : ($currentLang === 'zh' ? '条未读' : '件未読') ?></span>
                        <?php endif; ?>
                    </h2>
                    <?php if ($unread_count > 0): ?>
                    <button class="mark-all-read" onclick="markAllAsRead()"><?= __('mark_all_read') ?></button>
                    <?php endif; ?>
                </div>
                
                <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <div class="icon">🔔</div>
                    <h3><?= __('no_notifications') ?></h3>
                    <p><?= __('no_notifications_desc') ?></p>
                </div>
                <?php else: ?>
                <div class="notification-list">
                    <?php foreach ($notifications as $notif): ?>
                    <?php
                        $is_unread = empty($notif['is_read']) || $notif['is_read'] == 0;
                        $icon_class = '';
                        $icon = '🔔';
                        switch ($notif['type']) {
                            case 'system':
                            case 'announcement':
                                $icon_class = 'system';
                                $icon = '📢';
                                break;
                            case 'mention':
                                $icon_class = 'mention';
                                $icon = '@';
                                break;
                            case 'message':
                                $icon_class = 'message';
                                $icon = '💬';
                                break;
                        }
                    ?>
                    <div class="notification-item <?= $is_unread ? 'unread' : '' ?>" 
                         data-id="<?= $notif['id'] ?>"
                         onclick="openNotification(<?= $notif['id'] ?>, '<?= $notif['related_type'] ?? '' ?>', <?= $notif['related_id'] ?? 'null' ?>)">
                        <div class="notification-icon <?= $icon_class ?>"><?= $icon ?></div>
                        <div class="notification-content">
                            <div class="notification-title"><?= htmlspecialchars($notif['title']) ?></div>
                            <div class="notification-body"><?= htmlspecialchars($notif['content']) ?></div>
                            <div class="notification-time"><?= date('n月j日 H:i', strtotime($notif['created_at'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
            </div>
        </main>
        
        <!-- 右パネル（フィルター: すべて・未読・運営・メンション） -->
        <aside class="right-panel" id="rightPanel">
            <div class="right-header"><?= $currentLang === 'en' ? 'Details' : ($currentLang === 'zh' ? '详情' : '詳細') ?></div>
            <div class="right-panel-scroll">
                <nav class="filter-tabs" aria-label="<?= htmlspecialchars(__('notifications'), ENT_QUOTES, 'UTF-8') ?>">
                    <a href="?tab=all" class="<?= $active_tab === 'all' ? 'active' : '' ?>"><?= __('all') ?></a>
                    <a href="?tab=unread" class="<?= $active_tab === 'unread' ? 'active' : '' ?>"><?= __('unread') ?></a>
                    <a href="?tab=system" class="<?= $active_tab === 'system' ? 'active' : '' ?>"><?= __('admin_notifications') ?></a>
                    <a href="?tab=mention" class="<?= $active_tab === 'mention' ? 'active' : '' ?>"><?= __('mentions') ?></a>
                </nav>
            </div>
        </aside>
    </div>
    
    <script src="assets/js/topbar-standalone.js"></script>
    <script>
        async function markAllAsRead() {
            try {
                const response = await fetch('api/notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'mark_all_read' })
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                }
            } catch (e) {
                console.error('Error marking notifications as read:', e);
            }
        }
        
        async function openNotification(id, relatedType, relatedId) {
            // 既読にする
            try {
                await fetch('api/notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'mark_read', notification_id: id })
                });
            } catch (e) {}
            
            // 関連先に移動
            if (relatedType === 'message' && relatedId) {
                // メッセージの場合はチャット画面へ（将来的には該当メッセージにスクロール）
                location.href = 'chat.php';
            } else if (relatedType === 'task' && relatedId) {
                location.href = 'tasks.php';
            } else {
                // 既読だけ反映
                document.querySelector(`[data-id="${id}"]`)?.classList.remove('unread');
            }
        }
    </script>
</body>
</html>






