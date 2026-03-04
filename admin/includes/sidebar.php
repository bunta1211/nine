<?php
/**
 * 管理パネル 共通サイドバー
 * 使用前に $admin_current_page を設定すること（例: 'monitor', 'security', 'improvement', 'attackers', 'backup'）
 */
$current = isset($admin_current_page) ? $admin_current_page : '';
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <h1>⚙️ <span>管理パネル</span></h1>
    </div>
    <nav class="sidebar-nav" id="sidebarNav">
        <a href="index.php"<?= $current === 'dashboard' ? ' class="active"' : '' ?> data-id="dashboard" draggable="true">
            <span class="icon">📊</span>
            <span>ダッシュボード</span>
        </a>
        <a href="users.php"<?= $current === 'users' ? ' class="active"' : '' ?> data-id="users" draggable="true">
            <span class="icon">👥</span>
            <span>ユーザー管理</span>
        </a>
        <a href="ai_usage.php"<?= $current === 'ai_usage' ? ' class="active"' : '' ?> data-id="ai_usage" draggable="true">
            <span class="icon">🤖</span>
            <span>AI使用量</span>
        </a>
        <a href="ai_memories.php"<?= $current === 'ai_memories' ? ' class="active"' : '' ?> data-id="ai_memories" draggable="true">
            <span class="icon">🧠</span>
            <span>AI記憶管理</span>
        </a>
        <a href="ai_specialists.php"<?= $current === 'ai_specialists' ? ' class="active"' : '' ?> data-id="ai_specialists" draggable="true">
            <span class="icon">🎯</span>
            <span>専門AI管理</span>
        </a>
        <a href="ai_safety.php"<?= $current === 'ai_safety' ? ' class="active"' : '' ?> data-id="ai_safety" draggable="true">
            <span class="icon">🛡️</span>
            <span>AI安全通報</span>
        </a>
        <a href="reports.php"<?= $current === 'reports' ? ' class="active"' : '' ?> data-id="reports" draggable="true">
            <span class="icon">🚨</span>
            <span>通報管理</span>
        </a>
        <a href="specs.php"<?= $current === 'specs' ? ' class="active"' : '' ?> data-id="specs" draggable="true">
            <span class="icon">📋</span>
            <span>仕様書ビューア</span>
        </a>
        <a href="improvement_reports.php"<?= $current === 'improvement' ? ' class="active"' : '' ?> data-id="improvement" draggable="true">
            <span class="icon">📌</span>
            <span>改善・デバッグログ</span>
        </a>
        <a href="backup.php"<?= $current === 'backup' ? ' class="active"' : '' ?> data-id="backup" draggable="true">
            <span class="icon">🗄️</span>
            <span>バックアップ</span>
        </a>
        <a href="settings.php"<?= $current === 'settings' ? ' class="active"' : '' ?> data-id="settings" draggable="true">
            <span class="icon">⚙️</span>
            <span>システム設定</span>
        </a>
        <a href="security.php"<?= $current === 'security' ? ' class="active"' : '' ?> data-id="security" draggable="true">
            <span class="icon">🔒</span>
            <span>セキュリティ</span>
        </a>
        <a href="monitor.php"<?= $current === 'monitor' ? ' class="active"' : '' ?> data-id="monitor" draggable="true">
            <span class="icon">📊</span>
            <span>エラーチェック</span>
        </a>
        <a href="attackers.php"<?= $current === 'attackers' ? ' class="active"' : '' ?> data-id="attackers" draggable="true">
            <span class="icon">🎯</span>
            <span>攻撃者情報</span>
        </a>
        <a href="../chat.php" data-id="back" draggable="false">
            <span class="icon">←</span>
            <span>チャットに戻る</span>
        </a>
    </nav>
</aside>
