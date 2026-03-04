<?php
/**
 * チャット画面 - 左パネル（サイドバー）
 * 
 * 必要な変数:
 * - $conversations: 会話リスト
 * - $selected_conversation_id: 選択中の会話ID
 * - $totalConversations: 会話総数
 * - $currentLang: 現在の言語
 */
?>
<!-- 左パネル -->
<aside class="left-panel" id="leftPanel">
    <!-- モバイル用閉じるボタン -->
    <button class="mobile-close-left" onclick="closeMobileLeftPanel()" aria-label="パネルを閉じる">×</button>
    
    <!-- ヘッダー（PC・モバイル共通） -->
    <div class="left-header">
        <div class="left-header-tabs">
            <!-- PC版: モーダルを開く / モバイル版: インラインフォームを表示 -->
            <button title="<?= $currentLang === 'en' ? 'Add a group and choose organization' : ($currentLang === 'zh' ? '创建群组并选择组织' : 'グループを追加し、組織を選択') ?>" onclick="handleCreateGroupClick()"><?= __('add_group') ?></button>
            <button class="secondary" onclick="handleAddFriendClick()"><?= __('add_friend') ?></button>
        </div>
        <div class="left-header-actions">
            <?php $account_bar_suffix = 'LeftPanel'; $account_bar_variant = 'left_panel'; include __DIR__ . '/settings-account-bar.php'; ?>
        </div>
    </div>
    
    <!-- モバイル用インラインフォーム -->
    <div class="mobile-inline-form mobile-only" id="mobileInlineForm" style="display: none;">
        <!-- グループ作成フォーム -->
        <div class="mobile-form-section" id="mobileGroupForm" style="display: none;">
            <div class="mobile-form-header">
                <span>グループ作成</span>
                <button class="mobile-form-close" onclick="closeMobileInlineForm()" aria-label="フォームを閉じる">×</button>
            </div>
            <div class="mobile-form-body">
                <input type="text" id="mobileGroupName" placeholder="グループ名を入力" maxlength="50" aria-label="グループ名">
            </div>
            <div class="mobile-member-section">
                <div class="mobile-member-label">メンバーを選択（あなたが管理者になります）</div>
                <div class="mobile-member-list" id="mobileGroupMemberList">
                    <div class="mobile-member-loading">読み込み中...</div>
                </div>
                <div class="mobile-selected-count" id="mobileSelectedCount">選択中: 0人</div>
            </div>
            <div class="mobile-form-actions">
                <button class="mobile-form-submit" onclick="createMobileGroup()" aria-label="グループを作成">グループを作成</button>
            </div>
        </div>
        
        <!-- 友達追加フォーム -->
        <div class="mobile-form-section" id="mobileFriendForm" style="display: none;">
            <div class="mobile-form-header">
                <span>友達追加</span>
                <button type="button" class="mobile-friend-qr-scan-btn" onclick="openAddFriendModalForQR()">QRコード</button>
                <button class="mobile-form-close" onclick="closeMobileInlineForm()" aria-label="フォームを閉じる">×</button>
            </div>
            <div class="mobile-form-body">
                <input type="text" id="mobileFriendSearchInput" placeholder="Email/携帯番号で検索" autocomplete="off" aria-label="Emailまたは携帯番号で友達を検索">
                <p class="mobile-friend-search-desc">メールアドレスまたは携帯番号で検索。登録済みの方は友達申請、未登録のメールアドレスには招待を送れます</p>
                <button class="mobile-form-submit" onclick="searchMobileFriend()" aria-label="友達を検索">検索</button>
            </div>
            <div class="mobile-search-results" id="mobileFriendResults"></div>
            <div id="mobileFriendInviteRow" class="mobile-friend-invite-row" style="display: none;">
                <button type="button" class="mobile-friend-invite-btn" id="mobileFriendInviteBtn" onclick="sendMobileInvite()">このメールアドレスに友達申請を送る</button>
            </div>
            <button type="button" class="mobile-show-my-qr-btn" onclick="showMyQRCodeMobile()">QRコードを表示</button>
            <div id="mobileMyQRContainer" class="mobile-my-qr-container" style="display: none;">
                <div class="mobile-my-qr-inner">
                    <p class="mobile-my-qr-title">あなたの招待用QRコード</p>
                    <img id="mobileMyQRImage" src="" alt="招待用QRコード" class="mobile-my-qr-img">
                    <button type="button" class="mobile-my-qr-close" onclick="closeMyQRCodeMobile()">閉じる</button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="left-panel-filter" id="leftPanelFilter">
        <button type="button" class="left-panel-filter-trigger" id="leftPanelFilterTrigger" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= htmlspecialchars(__('all')) ?>">
            <span class="left-panel-filter-label" id="leftPanelFilterLabel"><?= __('all') ?></span>
            <span class="left-panel-filter-chevron" aria-hidden="true">▼</span>
        </button>
        <div class="left-panel-filter-dropdown" id="leftPanelFilterDropdown" role="listbox" aria-hidden="true" style="display: none;">
            <div class="left-panel-filter-options">
                <button type="button" class="left-panel-filter-option" role="option" data-filter="all" aria-selected="true"><?= __('all') ?></button>
                <button type="button" class="left-panel-filter-option" role="option" data-filter="unread"><?= __('unread') ?></button>
                <button type="button" class="left-panel-filter-option" role="option" data-filter="group"><?= __('group') ?></button>
                <button type="button" class="left-panel-filter-option" role="option" data-filter="dm"><?= __('filter_friends') ?></button>
                <?php 
                $userOrgs = $userOrganizations ?? [];
                foreach ($userOrgs as $org): 
                    $orgId = (int)($org['id'] ?? 0);
                    $orgName = htmlspecialchars($org['name'] ?? '', ENT_QUOTES);
                ?>
                <button type="button" class="left-panel-filter-option" role="option" data-filter="org-<?= $orgId ?>"><?= $orgName ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- 会話リスト（すべて・グループタブ用） -->
    <div class="conversation-list" id="conversationList">
        <!-- あなたの秘書（AIアシスタント） -->
        <div class="conv-item ai-secretary <?= $selected_conversation_id === 'ai' ? 'active' : '' ?>" 
             data-type="ai"
             data-id="ai"
             onclick="event.stopPropagation(); event.preventDefault(); selectAISecretary();">
            <div class="conv-avatar ai-avatar ai-secretary-avatar-clickable" title="クリックでキャラクターを変更">🤖</div>
            <div class="conv-info">
                <div class="conv-name">あなたの秘書</div>
            </div>
        </div>
        
        <?php 
        /* 標準デザイン：会話リストのアバターはグレーで統一 */
        foreach ($conversations as $conv): 
            // 多言語対応の名前を取得
            $conv_name = getLocalizedName($conv, 'name') ?: 'DM';
            $initial = mb_substr($conv_name, 0, 1);
            $member_count = (int)($conv['member_count'] ?? 0);
            $time = '';
            if ($conv['last_message_at']) {
                $tz = defined('DB_STORAGE_TIMEZONE') ? DB_STORAGE_TIMEZONE : 'UTC';
                try {
                    $dt = new DateTime($conv['last_message_at'], new DateTimeZone($tz));
                    $ts = $dt->getTimestamp();
                } catch (Exception $e) {
                    $ts = strtotime($conv['last_message_at']);
                }
                $diff = time() - $ts;
                if ($diff < 3600) $time = floor($diff / 60) . '分前';
                elseif ($diff < 86400) $time = floor($diff / 3600) . '時間前';
                elseif ($diff < 172800) $time = '昨日';
                elseif ($diff < 604800) $time = floor($diff / 86400) . '日前';
                elseif ($diff < 2592000) $time = floor($diff / 604800) . '週間前';
                else $time = date('n/j', $ts);
            }
            $is_active = $selected_conversation_id == $conv['id'];
            // フィルター用: 2人＝DM、3人以上＝グループ
            $filter_type = ($conv['type'] === 'dm' || $member_count == 2) ? 'dm' : 'group';
            // 表示用: 3人以上のグループのみグループ扱い（2人はDMなので単一アイコン）
            $is_group = $conv['type'] === 'group' && $member_count >= 3;
            $color = 'avatar-grey';
        ?>
        <?php 
        $unread_count = (int)($conv['unread_count'] ?? 0);
        $has_unread = ($unread_count > 0 && !$is_active);
        ?>
        <div class="conv-item <?= $is_active ? 'active' : '' ?> <?= $has_unread ? 'has-unread' : '' ?> <?= !empty($conv['is_pinned']) ? 'is-pinned' : '' ?>" 
             data-type="<?= $filter_type ?>"
             data-filter-type="<?= $filter_type ?>"
             data-organization-id="<?= isset($conv['organization_id']) && $conv['organization_id'] !== null && $conv['organization_id'] !== '' ? (int)$conv['organization_id'] : '' ?>"
             data-conv-id="<?= $conv['id'] ?>"
             data-conv-type="<?= $conv['type'] ?>"
             data-my-role="<?= $conv['my_role'] ?? 'member' ?>"
             data-conv-name="<?= htmlspecialchars($conv['name'] ?? '', ENT_QUOTES) ?>"
             data-conv-name-en="<?= htmlspecialchars($conv['name_en'] ?? '', ENT_QUOTES) ?>"
             data-conv-name-zh="<?= htmlspecialchars($conv['name_zh'] ?? '', ENT_QUOTES) ?>"
             data-unread="<?= $unread_count ?>"
             data-is-pinned="<?= !empty($conv['is_pinned']) ? '1' : '0' ?>"
             data-conv-icon-path="<?= htmlspecialchars($conv['icon_path'] ?? '', ENT_QUOTES) ?>"
             data-conv-icon-style="<?= htmlspecialchars($conv['icon_style'] ?? 'default', ENT_QUOTES) ?>"
             data-conv-icon-pos-x="<?= (float)($conv['icon_pos_x'] ?? 0) ?>"
             data-conv-icon-pos-y="<?= (float)($conv['icon_pos_y'] ?? 0) ?>"
             data-conv-icon-size="<?= (int)($conv['icon_size'] ?? 100) ?>"
             onclick="switchToConversation(<?= (int)$conv['id'] ?>)">
            <?php 
            // アイコンスタイルの背景色マッピング
            $iconStyleBg = [
                'default' => '#6b7280',
                'white' => '#FFFFFF',
                'black' => '#1a1a1a',
                'gray' => '#6b7280',
                'red' => 'linear-gradient(135deg, #FF6B6B, #ee5a5a)',
                'orange' => 'linear-gradient(135deg, #FFA500, #FF8C00)',
                'yellow' => 'linear-gradient(135deg, #FFD700, #FFC107)',
                'green' => 'linear-gradient(135deg, #4CAF50, #43A047)',
                'blue' => 'linear-gradient(135deg, #2196F3, #1976D2)',
                'purple' => 'linear-gradient(135deg, #9C27B0, #7B1FA2)',
                'pink' => 'linear-gradient(135deg, #FF69B4, #FF1493)'
            ];
            $iconStyleBorder = [
                'white' => '1px solid #e0e0e0'
            ];
            $currentStyle = $conv['icon_style'] ?? 'default';
            $currentPosX = (float)($conv['icon_pos_x'] ?? 0);
            $currentPosY = (float)($conv['icon_pos_y'] ?? 0);
            $currentSize = (int)($conv['icon_size'] ?? 100);
            $hasCustomStyle = !empty($conv['icon_style']) && $conv['icon_style'] !== 'default';
            $bgStyle = $iconStyleBg[$currentStyle] ?? $iconStyleBg['default'];
            $borderStyle = $iconStyleBorder[$currentStyle] ?? 'none';
            $posTransform = "translate({$currentPosX}%, {$currentPosY}%)";
            $useCustomStyle = !empty($conv['icon_path']) || ($is_group && $hasCustomStyle);
            ?>
            <div class="conv-avatar <?= $useCustomStyle ? '' : $color ?>" <?php if ($useCustomStyle): ?>style="background: <?= $bgStyle ?>; border: <?= $borderStyle ?>;"<?php endif; ?>>
                <?php if (!empty($conv['icon_path'])): ?>
                <img src="<?= htmlspecialchars($conv['icon_path']) ?>" alt="<?= htmlspecialchars($conv_name ?? '', ENT_QUOTES) ?>" class="conv-icon-img" style="width: <?= $currentSize ?>%; height: <?= $currentSize ?>%; transform: <?= $posTransform ?>;">
                <?php elseif ($is_group): ?>
                <span class="conv-icon-group" <?php if ($hasCustomStyle && $currentStyle !== 'white' && $currentStyle !== 'yellow'): ?>style="color: white;"<?php endif; ?>>👥</span>
                <?php else: ?>
                <?= htmlspecialchars($initial) ?>
                <?php endif; ?>
            </div>
            <div class="conv-info">
                <div class="conv-name" title="<?= htmlspecialchars($conv_name, ENT_QUOTES) ?>">
                    <span class="conv-name-text"><?= htmlspecialchars($conv_name) ?></span>
                    <?php if ($filter_type === 'group' && $member_count > 0): ?>
                    <span class="conv-member-count">(<?= $member_count ?>)</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="conv-meta">
                <div class="conv-time"><?= $time ?></div>
                <button type="button" class="conv-pin-btn" data-conv-id="<?= (int)$conv['id'] ?>" onclick="event.stopPropagation();toggleConvPin(<?= (int)$conv['id'] ?>)" title="ピン留め" aria-label="ピン留め">📌</button>
                <?php if ($has_unread): ?>
                <div class="conv-unread"><?= $unread_count > 99 ? '99+' : $unread_count ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- 友達リスト（友達タブ用：グループメンバー、APIから動的取得） -->
    <div class="friends-list" id="friendsList" style="display: none;">
        <div class="friends-list-label" style="padding:8px 6px 4px;font-size:11px;color:var(--text-muted);border-top:1px solid var(--border);">
            <?= $currentLang === 'en' ? 'Group members (start DM)' : ($currentLang === 'zh' ? '群组成员（发起私信）' : 'グループメンバー（DM開始可）') ?>
        </div>
        <div id="friendsListContent" style="flex:1;overflow-y:auto;">
            <div class="friends-loading" style="padding:24px;text-align:center;color:var(--text-muted);font-size:14px;">読み込み中...</div>
        </div>
    </div>
    
    <?php if ($totalConversations > 10): ?>
    <div class="conv-list-footer" id="convListFooter" onclick="toggleConversationList()">
        <?= str_replace('%d', '<span id="hiddenConvCount">' . ($totalConversations - 10) . '</span>', __('show_more')) ?>
    </div>
    <?php endif; ?>
</aside>
