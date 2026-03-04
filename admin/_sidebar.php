<?php
/**
 * 管理画面共通サイドバー
 * 
 * 使い方:
 *   $currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
 *   require_once __DIR__ . '/_sidebar.php';
 *   // <style> 内で: <?php adminSidebarCSS(); ?>
 *   // <body> 内で: <div class="admin-container"><?php adminSidebarHTML($currentPage); ?><main class="main-content">...</main></div>
 */

function adminSidebarCSS() {
?>
        .admin-container { display: flex; min-height: 100vh; }
        .sidebar { width: 250px; background: #1e293b; color: white; position: fixed; top: 0; left: 0; height: 100vh; overflow-y: auto; z-index: 100; }
        .sidebar-header { padding: 0 20px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 10px; padding-top: 20px; }
        .sidebar-header h1 { font-size: 20px; color: white; margin: 0; display: flex; align-items: center; gap: 8px; }
        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 12px 20px; color: rgba(255,255,255,0.7); text-decoration: none; transition: background 0.2s; font-size: 14px; }
        .sidebar-nav a:hover, .sidebar-nav a.active { background: rgba(255,255,255,0.1); color: white; }
        .sidebar-nav a .icon { font-size: 18px; width: 24px; text-align: center; }
        .sidebar-nav a.dragging { opacity: 0.4; background: rgba(255,255,255,0.15); }
        .sidebar-nav a.drag-over { border-top: 2px solid #60a5fa; margin-top: -2px; }
        .sidebar-nav a[draggable="true"] { cursor: grab; }
        .sidebar-nav a[draggable="true"]:active { cursor: grabbing; }
        .main-content { flex: 1; margin-left: 250px; padding: 30px; background: #f3f4f6; min-height: 100vh; }
        @media (max-width: 1024px) {
            .sidebar { width: 60px; }
            .sidebar-header h1 span, .sidebar-nav a span:not(.icon) { display: none; }
            .main-content { margin-left: 60px; }
        }
<?php
}

function adminSidebarHTML($currentPage = '') {
    $menuItems = [
        ['id' => 'index',                'href' => 'index.php',                'icon' => '📊', 'label' => 'ダッシュボード'],
        ['id' => 'users',                'href' => 'users.php',                'icon' => '👥', 'label' => 'ユーザー管理'],
        ['id' => 'ai_usage',             'href' => 'ai_usage.php',             'icon' => '🤖', 'label' => 'AI使用量'],
        ['id' => 'reports',              'href' => 'reports.php',              'icon' => '🚨', 'label' => '通報管理'],
        ['id' => 'specs',                'href' => 'specs.php',                'icon' => '📋', 'label' => '仕様書ビューア'],
        ['id' => 'improvement_reports',  'href' => 'improvement_reports.php',  'icon' => '📌', 'label' => '改善・デバッグログ'],
        ['id' => 'backup',               'href' => 'backup.php',               'icon' => '🗄️', 'label' => 'バックアップ'],
        ['id' => 'settings',             'href' => 'settings.php',             'icon' => '⚙️', 'label' => 'システム設定'],
        ['id' => 'security',             'href' => 'security.php',             'icon' => '🔒', 'label' => 'セキュリティ'],
        ['id' => 'monitor',              'href' => 'monitor.php',              'icon' => '📊', 'label' => 'エラーチェック'],
        ['id' => 'attackers',            'href' => 'attackers.php',            'icon' => '🎯', 'label' => '攻撃者情報'],
        ['id' => 'storage_billing',     'href' => 'storage_billing.php',     'icon' => '💰', 'label' => '利用料請求'],
        ['id' => 'ai_memories',          'href' => 'ai_memories.php',          'icon' => '🧠', 'label' => 'AI記憶管理'],
        ['id' => 'ai_safety_reports',    'href' => 'ai_safety_reports.php',    'icon' => '🛡️', 'label' => 'AI安全通報'],
    ];
?>
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1>⚙️ <span>管理パネル</span></h1>
            </div>
            <nav class="sidebar-nav" id="sidebarNav">
<?php foreach ($menuItems as $item): ?>
                <a href="<?= $item['href'] ?>" data-id="<?= $item['id'] ?>" draggable="true"<?= ($currentPage === $item['id']) ? ' class="active"' : '' ?>>
                    <span class="icon"><?= $item['icon'] ?></span>
                    <span><?= $item['label'] ?></span>
                </a>
<?php endforeach; ?>
                <a href="../chat.php" data-id="back" draggable="false">
                    <span class="icon">←</span>
                    <span>チャットに戻る</span>
                </a>
            </nav>
        </aside>
<?php
}
