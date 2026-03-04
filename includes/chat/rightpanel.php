<?php
/**
 * チャット画面 - 右パネル（詳細パネル）
 * 
 * 必要な変数:
 * - $selected_conversation: 選択中の会話
 * - $currentLang: 現在の言語
 */
?>
<!-- 右パネル -->
<aside class="right-panel" id="rightPanel">
    <!-- モバイル用閉じるボタン -->
    <button class="mobile-close-panel" onclick="closeMobileRightPanel()" aria-label="パネルを閉じる">×</button>
    <div class="right-header">
        <h3><?= __('details') ?></h3>
        <?php $account_bar_suffix = 'RightPanel'; include __DIR__ . '/settings-account-bar.php'; ?>
    </div>
    
    <div class="right-panel-scroll">
    <!-- 概要セクション（デフォルト閉じ） -->
    <div class="right-section">
        <div class="section-header collapsed" onclick="toggleSection(this)">
            <h3><img src="assets/icons/line/memo.svg" alt="" class="icon-line icon-line--sm" width="16" height="16"> <?= __('overview') ?></h3>
            <span class="toggle-icon">▽</span>
        </div>
        <div class="section-content">
            <!-- 概要リスト（複数エントリ対応） -->
            <div class="overview-list" id="overviewList">
                <!-- JavaScriptで動的に生成 -->
            </div>
            <!-- 新規追加ボタン -->
            <button class="overview-add-btn" id="overviewAddBtn" onclick="addNewOverviewEntry()">
                <span class="plus-icon">＋</span>
                <span><?= $currentLang === 'en' ? 'Add note' : ($currentLang === 'zh' ? '添加备注' : '概要を追加') ?></span>
            </button>
        </div>
    </div>
    
    <!-- 共有フォルダセクション（ワンクリックで中央パネルに表示） -->
    <div class="right-section" id="storageVaultSection">
        <div class="section-header sv-direct-open" onclick="openStorageVault()">
            <h3><img src="assets/icons/line/folder.svg" alt="" class="icon-line icon-line--sm" width="16" height="16"> <?= $currentLang === 'en' ? 'Shared Folder' : ($currentLang === 'zh' ? '共享文件夹' : '共有フォルダ') ?></h3>
            <span class="toggle-icon">▷</span>
        </div>
        <div class="section-content" style="display:none;">
            <div class="sv-usage-compact" id="svUsageCompact">
                <div class="sv-usage-bar-wrap">
                    <div class="sv-usage-bar-fill" id="svUsageBarFill" style="width:0%"></div>
                </div>
                <div class="sv-usage-text" id="svUsageText">-- / --</div>
            </div>
        </div>
    </div>
    
    <!-- メディアセクション（デフォルト閉じ、メディアがあれば開く） -->
    <div class="right-section" id="mediaSectionWrapper">
        <div class="section-header collapsed" id="mediaSectionHeader" onclick="toggleSection(this)">
            <h3><img src="assets/icons/line/image.svg" alt="" class="icon-line icon-line--sm" width="16" height="16"> <?= __('media') ?></h3>
            <span class="toggle-icon">▽</span>
        </div>
        <div class="section-content">
            <!-- メディア追加フォーム -->
            <div class="media-add-compact">
                <div class="media-add-row">
                    <input type="text" id="mediaTitleInput" placeholder="<?= __('input_title') ?>" aria-label="<?= __('input_title') ?>">
                </div>
                <div class="media-add-row">
                    <input type="text" id="mediaUrlInput" placeholder="<?= __('input_url') ?>" aria-label="<?= __('input_url') ?>">
                    <button class="media-file-btn theme-action-btn" onclick="document.getElementById('mediaInput').click()" title="<?= $currentLang === 'en' ? 'Select file' : ($currentLang === 'zh' ? '选择文件' : 'ファイルを選択') ?>" aria-label="<?= $currentLang === 'en' ? 'Select file' : ($currentLang === 'zh' ? '选择文件' : 'ファイルを選択') ?>">📁</button>
                    <button class="media-add-btn theme-action-btn" onclick="addMediaFromUrl()" aria-label="メディアを追加">＋<?= __('add') ?></button>
                </div>
            </div>
            <input type="file" id="mediaInput" style="display:none;" accept="image/*,video/*,audio/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.json,.xml,.zip,.rar,.7z" multiple onchange="handleMediaUpload(this)" aria-label="ファイルを選択">
            <!-- メディアリスト -->
            <div class="media-card-list" id="mediaCardList">
                <!-- メディアカードがここに表示 -->
            </div>
            <p class="no-media-text" id="noMediaText"><?= __('no_media') ?></p>
        </div>
    </div>
    
    <!-- グループ設定セクション（グループ管理者のみ表示、デフォルト閉じ） -->
    <div class="right-section group-admin-section" id="groupSettingsSection" style="display:none;">
        <div class="section-header collapsed" onclick="toggleSection(this)">
            <h3 id="settingsSectionTitle"><img src="assets/icons/line/gear.svg" alt="" class="icon-line icon-line--sm" width="16" height="16"> <?= $currentLang === 'en' ? 'Settings' : ($currentLang === 'zh' ? '设置' : '設定') ?></h3>
            <span class="toggle-icon">▽</span>
        </div>
        <div class="section-content">
            <!-- DM許可設定（グループのみ） -->
            <div class="group-setting-toggle dm-hide">
                <div class="setting-label">
                    <img src="assets/icons/line/envelope.svg" alt="" class="icon-line icon-line--sm" width="16" height="16">
                    <span><?= $currentLang === 'en' ? 'Allow Member DM' : ($currentLang === 'zh' ? '允许成员私信' : 'メンバー間DMを許可') ?></span>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" id="allowMemberDmToggle" 
                           <?= ($selected_conversation['allow_member_dm'] ?? 1) ? 'checked' : '' ?>
                           onchange="toggleAllowMemberDm(this.checked)">
                    <span class="toggle-slider"></span>
                </label>
            </div>
            
            <div class="group-settings-list">
                <button class="group-setting-item" onclick="openAddMemberModal()">
                    <span>👥</span>
                    <span><?= $currentLang === 'en' ? 'Member Management' : ($currentLang === 'zh' ? '成员管理' : 'メンバー管理') ?></span>
                </button>
                <button class="group-setting-item" onclick="openRenameGroupModal()">
                    <span>✏️</span>
                    <span id="renameButtonText"><?= $currentLang === 'en' ? 'Rename' : ($currentLang === 'zh' ? '重命名' : '名前変更') ?></span>
                </button>
                <button class="group-setting-item" onclick="openGroupIconModal()">
                    <span>🖼️</span>
                    <span><?= $currentLang === 'en' ? 'Change Icon' : ($currentLang === 'zh' ? '更换图标' : 'アイコン変更') ?></span>
                </button>
                <button class="group-setting-item dm-hide" onclick="openInviteLinkModal()">
                    <span>🔗</span>
                    <span><?= $currentLang === 'en' ? 'Invite Link' : ($currentLang === 'zh' ? '邀请链接' : '招待リンク') ?></span>
                </button>
                <button class="group-setting-item danger" onclick="confirmDeleteGroup()">
                    <span>🗑️</span>
                    <span id="deleteButtonText"><?= $currentLang === 'en' ? 'Delete Chat' : ($currentLang === 'zh' ? '删除聊天' : 'チャット削除') ?></span>
                </button>
            </div>
        </div>
    </div>
    
    </div><!-- /.right-panel-scroll -->
</aside>
