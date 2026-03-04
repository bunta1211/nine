<?php
/**
 * 設定リンク＋個人アカウント（ドロップダウン）の共通バー
 * Phase 4: 左パネル・右パネルのヘッダーで利用
 *
 * 必要な変数: $user, $display_name, $currentLang, $userOrganizations（省略時は []）, $account_bar_suffix（例: 'LeftPanel', 'RightPanel'）
 * 左パネル用: $account_bar_variant = 'left_panel' のときは個人表示・歯車・グループ管理ボタンなし（空バー）
 */
if (!isset($account_bar_suffix) || $account_bar_suffix === '') {
    $account_bar_suffix = 'Panel';
}
$account_bar_variant = $account_bar_variant ?? 'default';
$userOrgs = $userOrganizations ?? [];
if ($account_bar_variant === 'left_panel') {
    // 左パネル用: 個人表示・歯車・グループ管理ボタンはすべて出さない（空のコンテナでレイアウト維持）
    ?>
    <div class="user-menu-container panel-account-bar panel-account-bar--left" style="position: relative;"></div>
    <?php
    return;
}

$avatarStyle = $user['avatar_style'] ?? 'default';
$avatarPosX = (float)($user['avatar_pos_x'] ?? 0);
$avatarPosY = (float)($user['avatar_pos_y'] ?? 0);
$avatarSize = (int)($user['avatar_size'] ?? 100);
$avatarStyleBg = [
    'default' => 'linear-gradient(135deg, #667eea, #764ba2)',
    'white' => '#ffffff', 'black' => '#333333', 'blue' => 'linear-gradient(135deg, #4facfe, #00f2fe)',
    'green' => 'linear-gradient(135deg, #43e97b, #38f9d7)', 'orange' => 'linear-gradient(135deg, #fa709a, #fee140)',
    'pink' => 'linear-gradient(135deg, #f093fb, #f5576c)', 'purple' => 'linear-gradient(135deg, #667eea, #764ba2)',
    'red' => 'linear-gradient(135deg, #ff512f, #f09819)', 'yellow' => 'linear-gradient(135deg, #f7971e, #ffd200)',
    'gray' => 'linear-gradient(135deg, #bdc3c7, #2c3e50)'
];
$avatarBg = $avatarStyleBg[$avatarStyle] ?? $avatarStyleBg['default'];
$dropdownId = 'userDropdown' . $account_bar_suffix;
?>
<div class="user-menu-container panel-account-bar" style="position: relative;">
    <a href="settings.php" class="panel-settings-link" title="<?= $currentLang === 'en' ? 'Settings' : ($currentLang === 'zh' ? '设置' : '設定') ?>" aria-label="<?= $currentLang === 'en' ? 'Settings' : ($currentLang === 'zh' ? '设置' : '設定') ?>"><img src="assets/icons/line/gear.svg" alt="" class="icon-line" width="20" height="20"></a>
    <div class="user-info panel-user-info" onclick="toggleUserMenu(event)" title="<?= $currentLang === 'en' ? 'Account menu' : ($currentLang === 'zh' ? '账户菜单' : 'アカウントメニュー') ?>">
        <span class="user-info-name"><?= htmlspecialchars($display_name) ?></span>
        <span class="user-info-arrow" style="font-size: 10px; opacity: 0.7;">▼</span>
    </div>
    <div class="user-dropdown" id="<?= htmlspecialchars($dropdownId) ?>">
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
                <div style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($user['email'] ?? '') ?></div>
            </div>
            <button class="avatar-change-btn" onclick="openUserAvatarModal(); closeUserMenu();" title="<?= $currentLang === 'en' ? 'Change Icon' : ($currentLang === 'zh' ? '更换图标' : 'アイコン変更') ?>" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:14px;color:var(--text-muted);">✏️</button>
        </div>
        <div class="user-dropdown-orgs">
            <div style="font-size:11px;color:var(--text-muted);padding:8px 15px 4px;font-weight:600;">所属組織</div>
            <?php if (!empty($userOrgs)): ?>
                <?php foreach ($userOrgs as $org): ?>
                <div class="user-org-item">
                    <?php
                    $orgIcon = ($org['type'] ?? '') === 'corporation' ? '🏢' : (($org['type'] ?? '') === 'family' ? '👨‍👩‍👧' : '👥');
                    $ownerBadge = ($org['relationship'] ?? '') === 'owner' ? '<span style="color:#f1c40f;margin-left:4px;">★</span>' : '';
                    ?>
                    <span><?= $orgIcon ?> <?= htmlspecialchars($org['name'] ?? '') ?><?= $ownerBadge ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <a href="admin/create_organization.php" class="user-dropdown-create-org">➕ 所属組織を作成する</a>
        </div>
        <div class="user-dropdown-divider"></div>
        <a href="#" onclick="switchAccount(); return false;">🔄 アカウント切り替え</a>
        <?php if (function_exists('isOrgAdminUser') && isOrgAdminUser()): ?>
        <div class="user-dropdown-divider"></div>
        <a href="admin/members.php">🏢 組織管理</a>
        <?php endif; ?>
        <?php
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
