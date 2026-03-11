<?php
/**
 * チャット画面 - 上パネル（トップバー）
 * 
 * 必要な変数:
 * - $display_name: 表示名
 * - $user: ユーザー情報
 * - $userOrganizations: 所属組織リスト
 * - $currentLang: 現在の言語
 * - $topbar_mobile_title: (任意) 携帯版でロゴ位置に表示するタイトル（チャット画面で会話選択時）
 * - $topbar_back_url: (任意) 指定時は左に「戻る」リンクを表示（例: memos.php では 'chat.php'）
 * - $topbar_header_id: (任意) ヘッダー要素に付ける id（例: design.php では 'topPanel'）
 */
if (!isset($topbar_mobile_title)) {
    $topbar_mobile_title = '';
}
$topbar_header_id_attr = isset($topbar_header_id) && $topbar_header_id !== '' ? ' id="' . htmlspecialchars($topbar_header_id) . '"' : '';
?>
<!-- ========== 上パネル ========== -->
<header class="top-panel"<?= $topbar_header_id_attr ?>>
    <div class="top-panel-inner">
    <div class="top-left">
        <?php if (!empty($topbar_back_url)): ?>
        <a href="<?= htmlspecialchars($topbar_back_url) ?>" class="toggle-left-btn topbar-back-link" title="<?= htmlspecialchars(__('back'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(__('back'), ENT_QUOTES, 'UTF-8') ?>">←</a>
        <?php else: ?>
        <button class="toggle-left-btn" onclick="toggleLeftMenu()" title="左パネル表示/非表示" aria-label="左パネルを開く" id="toggleLeftBtn">⇐</button>
        <?php endif; ?>
        <div class="logo">
            <span class="logo-pc"><?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'Social9') ?></span>
            <span class="logo-mobile">9</span>
            <?php if (!empty($topbar_mobile_title)): ?>
            <span class="logo-mobile-chat-title"><?= htmlspecialchars($topbar_mobile_title) ?></span>
            <?php endif; ?>
        </div>
        <div class="search-box" onclick="focusTopBarSearch()">
            <img src="assets/icons/line/search.svg" alt="" class="search-box-icon icon-line" aria-hidden="true" width="20" height="20">
            <input type="text" id="topBarSearchInput" class="search-box-input" name="q" placeholder="<?= htmlspecialchars(__('search') . '... (Ctrl+K)', ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" aria-label="<?= htmlspecialchars(__('search'), ENT_QUOTES, 'UTF-8') ?>">
        </div>
    </div>
    
    <div class="top-right">
        <!-- 5つ表示: デザイン・アプリ・タスク・メモ・通知・言語 -->
        <div class="task-memo-buttons" id="taskMemoButtons">
            <button class="top-btn" onclick="location.href='design.php'"><img src="assets/icons/line/palette.svg" alt="" class="icon-line" width="20" height="20"> <span class="btn-label"><?= __('design') ?></span></button>
            <div class="app-menu-container" style="position:relative; display:inline-block;">
                <button class="top-btn" type="button" onclick="toggleAppMenu(event)" id="appMenuBtn" title="<?= $currentLang === 'en' ? 'Apps' : ($currentLang === 'zh' ? '应用' : 'アプリ') ?>" aria-label="<?= $currentLang === 'en' ? 'Apps' : ($currentLang === 'zh' ? '应用' : 'アプリ') ?>">
                    <img src="assets/icons/line/app-grid.svg" alt="" class="icon-line" width="20" height="20"> <span class="btn-label"><?= $currentLang === 'en' ? 'Apps' : ($currentLang === 'zh' ? '应用' : 'アプリ') ?></span>
                </button>
                <div id="appDropdown" class="app-dropdown-menu" style="display:none;">
                    <div class="app-dropdown-header">
                        <span><?= $currentLang === 'en' ? 'Apps' : ($currentLang === 'zh' ? '应用' : 'アプリ') ?></span>
                    </div>
                    <a href="<?= (function_exists('getBaseUrl') && getBaseUrl() !== '') ? htmlspecialchars(rtrim(getBaseUrl(), '/') . '/Guild/', ENT_QUOTES, 'UTF-8') : 'Guild/'; ?>" class="app-dropdown-item">
                        <span class="app-dropdown-icon">🍀</span>
                        <span><?= $currentLang === 'en' ? 'Guild' : ($currentLang === 'zh' ? '公会' : 'ギルド') ?></span>
                    </a>
                    <div class="app-dropdown-item" style="cursor:default;opacity:0.55;">
                        <span class="app-dropdown-icon">✨</span>
                        <span><?= $currentLang === 'en' ? 'Personality' : ($currentLang === 'zh' ? '性格诊断' : 'パーソナリティ診断') ?></span>
                        <span style="margin-left:auto;font-size:11px;color:#999;"><?= $currentLang === 'en' ? 'Coming soon' : ($currentLang === 'zh' ? '建设中' : '工事中') ?></span>
                    </div>
                </div>
            </div>
            <div class="task-menu-container" style="position:relative; display:inline-block;">
                <a href="tasks.php" class="top-btn" id="taskMenuLink"><img src="assets/icons/line/clipboard.svg" alt="" class="icon-line" width="20" height="20"> <span class="btn-label"><?= $currentLang === 'en' ? 'Task/Memo' : ($currentLang === 'zh' ? '任务/备忘' : 'タスク/メモ') ?></span> <span class="badge" id="taskMemoBadge" style="display:none;">0</span></a>
                <div id="taskDropdown" class="task-dropdown-menu" aria-hidden="true" style="display:none;">
                    <div class="task-dropdown-header">
                        <img src="assets/icons/line/clipboard.svg" alt="" class="icon-line icon-line--sm" width="16" height="16"> <span><?= $currentLang === 'en' ? 'My Tasks' : ($currentLang === 'zh' ? '我的任务' : '自分のタスク') ?></span>
                        <a href="tasks.php" class="task-view-all"><?= $currentLang === 'en' ? 'View All' : ($currentLang === 'zh' ? '查看全部' : 'すべて表示') ?> →</a>
                    </div>
                    <div class="task-dropdown-list" id="taskDropdownList">
                        <div class="task-dropdown-loading"><?= $currentLang === 'en' ? 'Loading...' : ($currentLang === 'zh' ? '加载中...' : '読み込み中...') ?></div>
                    </div>
                    <div class="task-dropdown-divider"></div>
                    <div class="task-dropdown-header">
                        <img src="assets/icons/line/memo.svg" alt="" class="icon-line icon-line--sm" width="16" height="16"> <span><?= $currentLang === 'en' ? 'Recent Memos' : ($currentLang === 'zh' ? '最近备忘' : '最近のメモ') ?></span>
                        <a href="tasks.php?tab=memos" class="task-view-all"><?= $currentLang === 'en' ? 'All Memos' : ($currentLang === 'zh' ? '全部备忘' : 'メモ一覧') ?> →</a>
                    </div>
                    <div class="task-dropdown-list" id="memoDropdownList">
                        <div class="task-dropdown-loading"><?= $currentLang === 'en' ? 'Loading...' : ($currentLang === 'zh' ? '加载中...' : '読み込み中...') ?></div>
                    </div>
                </div>
            </div>
            <div class="notification-menu-container" style="position:relative;">
                <a href="notifications.php" class="top-btn" id="notificationBtn"><img src="assets/icons/line/bell.svg" alt="" class="icon-line" width="20" height="20"> <span class="btn-label"><?= __('notifications') ?></span> <span class="badge notification-badge" id="notificationBadge" style="display:none;">0</span></a>
                <div id="notificationDropdown" class="notification-dropdown" aria-hidden="true" style="display:none;">
                    <div class="notification-dropdown-item" onclick="location.href='notifications.php'">
                        <img src="assets/icons/line/clipboard.svg" alt="" class="icon-line icon-line--sm" width="16" height="16"> <?= $currentLang === 'en' ? 'View all notifications' : ($currentLang === 'zh' ? '查看全部通知' : '通知一覧を見る') ?>
                    </div>
                    <div class="notification-dropdown-divider"></div>
                    <div class="notification-dropdown-item" id="pushNotificationToggle" onclick="togglePushNotification()">
                        <img src="assets/icons/line/bell.svg" alt="" class="icon-line icon-line--sm" id="pushStatusIcon" width="16" height="16">
                        <span id="pushStatusText"><?= $currentLang === 'en' ? 'Enable push notifications' : ($currentLang === 'zh' ? '启用推送通知' : 'プッシュ通知を有効にする') ?></span>
                    </div>
                    <div class="notification-dropdown-hint" id="pushHint">
                        <?= $currentLang === 'en' ? 'Receive notifications when browser is closed' : ($currentLang === 'zh' ? '关闭浏览器也可接收通知' : 'ブラウザを閉じていても通知を受け取れます') ?>
                    </div>
                </div>
            </div>
            <!-- 受信したアドレス追加申請があるときのみ表示（JSで件数取得して表示） -->
            <a href="settings.php?section=friends#requests" id="topbarFriendRequestBadge" class="topbar-friend-request-badge" style="display:none;" title="<?= $currentLang === 'en' ? 'Friend requests' : ($currentLang === 'zh' ? '好友请求' : 'アドレス追加申請') ?>">
                <span class="topbar-friend-request-icon">👋</span>
                <span class="topbar-friend-request-label"><?= $currentLang === 'en' ? 'Requests' : ($currentLang === 'zh' ? '申请' : '申請') ?></span>
                <span class="topbar-friend-request-count" id="topbarFriendRequestCount">0</span>
            </a>
            <div class="language-selector" style="position: relative;">
                <button class="top-btn" onclick="toggleLanguageMenu(event)" id="languageBtn">
                    <img src="assets/icons/line/globe.svg" alt="" class="icon-line" width="20" height="20"> <span class="btn-label"><?= $currentLang === 'en' ? 'EN' : ($currentLang === 'zh' ? '中' : 'JP') ?></span>
                </button>
                <div class="language-dropdown" id="languageDropdown">
                    <div class="language-option <?= $currentLang === 'ja' ? 'active' : '' ?>" onclick="changeLanguage('ja')">🇯🇵 日本語</div>
                    <div class="language-option <?= $currentLang === 'en' ? 'active' : '' ?>" onclick="changeLanguage('en')">🇺🇸 English</div>
                    <div class="language-option <?= $currentLang === 'zh' ? 'active' : '' ?>" onclick="changeLanguage('zh')">🇨🇳 中文</div>
                </div>
            </div>
        </div>

        <!-- 収納ボタン：クリックでデザイン・タスク・メモ・通知・JP の表示/非表示をトグル -->
        <button type="button" class="top-btn top-panel-toggle-btn" id="toggleTaskMemoBtn" onclick="toggleTaskMemoButtons()" title="<?= $currentLang === 'en' ? 'Toggle menu' : ($currentLang === 'zh' ? '切换菜单' : 'メニュー表示切替') ?>" aria-label="<?= $currentLang === 'en' ? 'Toggle menu' : ($currentLang === 'zh' ? '切换菜单' : 'メニュー表示切替') ?>">≡</button>
        
        <?php
        // ユーザーアイコンスタイル計算
        $avatarStyle = $user['avatar_style'] ?? 'default';
        $avatarPosX = (float)($user['avatar_pos_x'] ?? 0);
        $avatarPosY = (float)($user['avatar_pos_y'] ?? 0);
        $avatarSize = (int)($user['avatar_size'] ?? 100);
        
        // スタイルに応じた背景色
        $avatarStyleBg = [
            'default' => 'linear-gradient(135deg, #667eea, #764ba2)',
            'white' => '#ffffff',
            'black' => '#333333',
            'blue' => 'linear-gradient(135deg, #4facfe, #00f2fe)',
            'green' => 'linear-gradient(135deg, #43e97b, #38f9d7)',
            'orange' => 'linear-gradient(135deg, #fa709a, #fee140)',
            'pink' => 'linear-gradient(135deg, #f093fb, #f5576c)',
            'purple' => 'linear-gradient(135deg, #667eea, #764ba2)',
            'red' => 'linear-gradient(135deg, #ff512f, #f09819)',
            'yellow' => 'linear-gradient(135deg, #f7971e, #ffd200)',
            'gray' => 'linear-gradient(135deg, #bdc3c7, #2c3e50)'
        ];
        $avatarBg = $avatarStyleBg[$avatarStyle] ?? $avatarStyleBg['default'];
        ?>
        <div class="user-menu-container" style="position: relative;">
            <div class="user-info" onclick="toggleUserMenu(event)" title="<?= $currentLang === 'en' ? 'Account menu' : ($currentLang === 'zh' ? '账户菜单' : 'アカウントメニュー') ?>">
                <!-- 携帯: 歯車アイコンのみ表示。PC: 名前＋▼を表示 -->
                <img src="assets/icons/line/gear.svg" alt="" class="icon-line user-info-mobile-gear" width="20" height="20" aria-hidden="true">
                <span class="user-info-pc-only">
                    <span class="user-info-name"><?= htmlspecialchars($display_name) ?></span>
                    <span class="user-info-arrow" style="font-size: 10px; opacity: 0.7;">▼</span>
                </span>
            </div>
            
            <!-- ユーザーメニュードロップダウン -->
            <div class="user-dropdown" id="userDropdown">
                <div id="userDropdownFriendRequestAlert" style="display:none;"></div>
                <div class="user-dropdown-header">
                    <div class="user-icon" style="width:40px;height:40px;font-size:18px;cursor:pointer;background:<?= $avatarBg ?>;overflow:hidden;position:relative;" onclick="openUserAvatarModal(); closeUserMenu();" title="<?= $currentLang === 'en' ? 'Change Icon' : ($currentLang === 'zh' ? '更换图标' : 'アイコン変更') ?>">
                        <?php if (!empty($user['avatar_path'])): ?>
                            <img src="<?= htmlspecialchars($user['avatar_path']) ?>" alt="" style="width:<?= $avatarSize ?>%;height:<?= $avatarSize ?>%;position:absolute;top:50%;left:50%;transform:translate(calc(-50% + <?= $avatarPosX ?>%), calc(-50% + <?= $avatarPosY ?>%));object-fit:contain;">
                        <?php else: ?>
                            <?= mb_substr($display_name, 0, 1) ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div style="font-weight:600;"><?= htmlspecialchars($display_name) ?></div>
                        <div style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($user['email']) ?></div>
                    </div>
                    <button class="avatar-change-btn" onclick="openUserAvatarModal(); closeUserMenu();" title="<?= $currentLang === 'en' ? 'Change Icon' : ($currentLang === 'zh' ? '更换图标' : 'アイコン変更') ?>" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:14px;color:var(--text-muted);">✏️</button>
                </div>
                
                <div class="user-dropdown-orgs">
                    <div style="font-size:11px;color:var(--text-muted);padding:8px 15px 4px;font-weight:600;">所属組織</div>
                    <?php if (!empty($userOrganizations)): ?>
                    <?php foreach ($userOrganizations as $org): ?>
                    <div class="user-org-item">
                        <?php 
                        $orgIcon = $org['type'] === 'corporation' ? '🏢' : ($org['type'] === 'family' ? '👨‍👩‍👧' : '👥');
                        $ownerBadge = $org['relationship'] === 'owner' ? '<span style="color:#f1c40f;margin-left:4px;">★</span>' : '';
                        ?>
                        <span><?= $orgIcon ?> <?= htmlspecialchars($org['name']) ?><?= $ownerBadge ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <a href="admin/create_organization.php" class="user-dropdown-create-org">➕ 所属組織を作成する</a>
                </div>
                <div class="user-dropdown-divider"></div>
                
                <a href="#" onclick="switchAccount(); return false;">🔄 アカウント切り替え</a>
                <?php if (isOrgAdminUser()): ?>
                <div class="user-dropdown-divider"></div>
                <a href="admin/members.php">🏢 組織管理</a>
                <?php endif; ?>
                <?php 
                // システム管理者権限チェック（developer, system_admin, admin ロール）
                $userRole = $_SESSION['role'] ?? 'user';
                $isSystemAdmin = in_array($userRole, ['developer', 'system_admin', 'admin']);
                if ($isSystemAdmin): 
                ?>
                <div class="user-dropdown-divider"></div>
                <a href="admin/index.php">🛡️ システム管理</a>
                <?php endif; ?>
                <div class="user-dropdown-divider"></div>
                <a href="#" onclick="logout(); return false;" class="logout-link">🚪 ログアウト</a>
            </div>
        </div>
        
        <button class="settings-btn" onclick="location.href='settings.php'" title="<?= $currentLang === 'en' ? 'Settings' : ($currentLang === 'zh' ? '设置' : '設定') ?>" aria-label="<?= $currentLang === 'en' ? 'Settings' : ($currentLang === 'zh' ? '设置' : '設定') ?>"><img src="assets/icons/line/gear.svg" alt="" class="icon-line" width="20" height="20"></button>
        <button class="toggle-right-btn" onclick="toggleRightPanel()" title="詳細パネル表示/非表示" aria-label="右パネルを開く" id="toggleRightBtn">⇒</button>
    </div>
    </div>
</header>
<script>
(function() {
    function updateFriendRequestBadge() {
        var base = (typeof window.__CHAT_API_BASE !== 'undefined' && window.__CHAT_API_BASE) ? window.__CHAT_API_BASE.replace(/\/?$/, '') + '/' : '';
        fetch(base + 'api/friends.php?action=pending_count', { credentials: 'include' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success || data.count === undefined) return;
                var count = parseInt(data.count, 10) || 0;
                var badgeEl = document.getElementById('topbarFriendRequestBadge');
                var countEl = document.getElementById('topbarFriendRequestCount');
                var alertEl = document.getElementById('userDropdownFriendRequestAlert');
                if (!badgeEl || !countEl) return;
                if (count > 0) {
                    badgeEl.style.display = '';
                    countEl.textContent = count;
                    badgeEl.setAttribute('aria-label', '\u30a2\u30c9\u30ec\u30b9\u8ffd\u52a0\u7533\u8acb\u304c' + count + '\u4ef6');
                    if (alertEl) {
                        alertEl.style.display = 'block';
                        alertEl.innerHTML = '<a href="settings.php?section=friends#requests" class="user-dropdown-friend-request-alert" style="display:block;padding:12px 15px;background:linear-gradient(135deg,#fef3c7,#fde68a);color:#92400e;font-weight:600;text-decoration:none;border-radius:8px;margin:8px;text-align:center;">\ud83d\udc4b \u30a2\u30c9\u30ec\u30b9\u8ffd\u52a0\u7533\u8acb\u304c' + count + '\u4ef6\u5c4a\u3044\u3066\u3044\u307e\u3059 \u2192</a>';
                    }
                } else {
                    badgeEl.style.display = 'none';
                    if (alertEl) alertEl.style.display = 'none';
                }
            })
            .catch(function() {});
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', updateFriendRequestBadge);
    } else {
        updateFriendRequestBadge();
    }
    window.updateFriendRequestBadge = updateFriendRequestBadge;
})();
</script>
