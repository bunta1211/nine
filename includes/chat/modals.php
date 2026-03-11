    <!-- バーチャル背景選択モーダル -->
    <div class="virtual-bg-modal" id="virtualBgModal" style="display: none;">
        <div class="virtual-bg-header">
            <span>バーチャル背景</span>
            <button onclick="closeVirtualBgModal()" aria-label="閉じる" style="background: none; border: none; color: #fff; font-size: 20px; cursor: pointer;">×</button>
        </div>
        <div class="virtual-bg-options">
            <div class="virtual-bg-item" onclick="setVirtualBackground('none')" data-bg="none">
                <div class="virtual-bg-preview" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); display: flex; align-items: center; justify-content: center;">
                    <span style="font-size: 24px;">🚫</span>
                </div>
                <span>なし</span>
            </div>
            <div class="virtual-bg-item" onclick="setVirtualBackground('blur')" data-bg="blur">
                <div class="virtual-bg-preview" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center;">
                    <span style="font-size: 24px;">🌫️</span>
                </div>
                <span>ぼかし</span>
            </div>
            <div class="virtual-bg-item" onclick="setVirtualBackground('office')" data-bg="office">
                <div class="virtual-bg-preview" style="background: url('/assets/images/virtual-bg/office.jpg') center/cover, linear-gradient(135deg, #4a5568 0%, #2d3748 100%);"></div>
                <span>オフィス</span>
            </div>
            <div class="virtual-bg-item" onclick="setVirtualBackground('nature')" data-bg="nature">
                <div class="virtual-bg-preview" style="background: url('/assets/images/virtual-bg/nature.jpg') center/cover, linear-gradient(135deg, #48bb78 0%, #38a169 100%);"></div>
                <span>自然</span>
            </div>
            <div class="virtual-bg-item" onclick="setVirtualBackground('space')" data-bg="space">
                <div class="virtual-bg-preview" style="background: url('/assets/images/virtual-bg/space.jpg') center/cover, linear-gradient(135deg, #1a1a2e 0%, #0f0f23 100%);"></div>
                <span>宇宙</span>
            </div>
            <div class="virtual-bg-item" onclick="setVirtualBackground('beach')" data-bg="beach">
                <div class="virtual-bg-preview" style="background: url('/assets/images/virtual-bg/beach.jpg') center/cover, linear-gradient(135deg, #4299e1 0%, #3182ce 100%);"></div>
                <span>ビーチ</span>
            </div>
        </div>
        <div class="virtual-bg-upload">
            <label for="customBgUpload" class="upload-btn">
                📁 カスタム背景をアップロード
            </label>
            <input type="file" id="customBgUpload" accept="image/*" onchange="uploadCustomBackground(event)" style="display: none;" aria-label="カスタム背景画像を選択">
        </div>
    </div>

    
    <!-- グループ名変更モーダル -->
    <div class="modal-overlay" id="renameGroupModal">
        <div class="modal">
            <div class="modal-header">
                <h3><?= $currentLang === 'en' ? 'Rename Group' : ($currentLang === 'zh' ? '重命名群组' : 'グループ名変更') ?></h3>
                <button class="modal-close" onclick="closeModal('renameGroupModal')" aria-label="閉じる">×</button>
            </div>
            <div class="modal-body">
                <div class="i18n-input-group">
                    <div class="form-group i18n-field">
                        <label>🇯🇵 <?= $currentLang === 'en' ? 'Group Name' : ($currentLang === 'zh' ? '群组名称' : 'グループ名') ?> <span class="required">*</span></label>
                        <input type="text" id="renameGroupName" placeholder="<?= $currentLang === 'en' ? 'Group name (Japanese)' : ($currentLang === 'zh' ? '群组名称（日语）' : 'グループ名') ?>">
                    </div>
                    <div class="form-group i18n-field">
                        <label>🇺🇸 Group Name (English)</label>
                        <input type="text" id="renameGroupNameEn" placeholder="Optional">
                    </div>
                    <div class="form-group i18n-field">
                        <label>🇨🇳 群组名称（中文）</label>
                        <input type="text" id="renameGroupNameZh" placeholder="可选">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('renameGroupModal')"><?= __('cancel') ?></button>
                <button class="btn btn-primary" onclick="saveGroupRename()"><?= __('save') ?></button>
            </div>
        </div>
    </div>
    
    <!-- アイコン変更モーダル（グループ・ユーザー共通） -->
    <div class="modal-overlay" id="iconChangeModal">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h3 id="iconChangeModalTitle"><?= $currentLang === 'en' ? 'Change Icon' : ($currentLang === 'zh' ? '更换图标' : 'アイコン変更') ?></h3>
                <button class="modal-close" onclick="closeIconChangeModal()" aria-label="閉じる">×</button>
            </div>
            <div class="modal-body">
                <!-- プレビューと調整エリア（横並び） -->
                <div class="icon-editor-row">
                    <!-- 左: プレビュー -->
                    <div class="icon-preview-area">
                        <div class="icon-change-preview" id="iconChangePreview">
                            <?= mb_substr($display_name ?? 'U', 0, 1) ?>
                        </div>
                    </div>
                    <!-- 右: 位置・大きさ調整 -->
                    <div class="icon-adjust-area">
                        <div class="icon-adjust-section">
                            <label><?= $currentLang === 'en' ? 'Position' : ($currentLang === 'zh' ? '位置' : '位置') ?></label>
                            <div class="icon-position-controls">
                                <div class="icon-pos-row">
                                    <button type="button" class="icon-pos-btn" id="iconChangePosUp" title="上">↑</button>
                                </div>
                                <div class="icon-pos-row">
                                    <button type="button" class="icon-pos-btn" id="iconChangePosLeft" title="左">←</button>
                                    <button type="button" class="icon-pos-btn icon-pos-reset" id="iconChangePosReset" title="リセット">●</button>
                                    <button type="button" class="icon-pos-btn" id="iconChangePosRight" title="右">→</button>
                                </div>
                                <div class="icon-pos-row">
                                    <button type="button" class="icon-pos-btn" id="iconChangePosDown" title="下">↓</button>
                                </div>
                            </div>
                            <div class="icon-pos-value">X: <span id="iconChangePosX">0</span>% / Y: <span id="iconChangePosY">0</span>%</div>
                        </div>
                        <div class="icon-adjust-section">
                            <label><?= $currentLang === 'en' ? 'Size' : ($currentLang === 'zh' ? '大小' : '大きさ') ?></label>
                            <div class="icon-size-controls">
                                <button type="button" class="icon-size-btn" id="iconChangeSizeDown">−</button>
                                <span id="iconChangeSizeValue">100</span>%
                                <button type="button" class="icon-size-btn" id="iconChangeSizeUp">+</button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- サンプルアイコン選択 -->
                <div class="form-group">
                    <label><?= $currentLang === 'en' ? 'Choose from samples' : ($currentLang === 'zh' ? '从示例中选择' : 'サンプルから選択') ?></label>
                    <div class="sample-icons-container">
                        <div class="sample-icons-row" id="iconChangeSamplesGrid">
                            <!-- JavaScriptで動的に生成 -->
                        </div>
                        <div class="sample-icons-nav">
                            <button type="button" class="sample-nav-btn" id="iconSamplePrev" onclick="navigateIconSamples(-1)">◀</button>
                            <span class="sample-nav-info"><span id="iconSamplePage">1</span> / <span id="iconSampleTotalPages">1</span></span>
                            <button type="button" class="sample-nav-btn" id="iconSampleNext" onclick="navigateIconSamples(1)">▶</button>
                        </div>
                    </div>
                </div>
                
                <!-- アイコンスタイル選択 -->
                <div class="form-group">
                    <label><?= $currentLang === 'en' ? 'Icon Style' : ($currentLang === 'zh' ? '图标样式' : 'アイコンスタイル') ?></label>
                    <div class="icon-style-grid" id="iconChangeStyleGrid">
                        <!-- JavaScriptで動的に生成 -->
                    </div>
                </div>
                
                <div class="icon-divider">
                    <span><?= $currentLang === 'en' ? 'or' : ($currentLang === 'zh' ? '或者' : 'または') ?></span>
                </div>
                
                <div class="form-group">
                    <label for="iconChangeFileInput"><?= $currentLang === 'en' ? 'Upload Image' : ($currentLang === 'zh' ? '上传图片' : '画像をアップロード') ?></label>
                    <input type="file" id="iconChangeFileInput" accept="image/*" onchange="previewIconChange(this);" style="width: 100%;" aria-label="アイコン画像を選択">
                </div>
                <input type="hidden" id="iconChangeType" value="">
                <input type="hidden" id="iconChangeTargetId" value="">
                <input type="hidden" id="iconChangeSelectedSample" value="">
                <input type="hidden" id="iconChangeSelectedStyle" value="default">
                <input type="hidden" id="iconChangeSelectedPosX" value="0">
                <input type="hidden" id="iconChangeSelectedPosY" value="0">
                <input type="hidden" id="iconChangeSelectedSize" value="100">
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeIconChangeModal()"><?= __('cancel') ?></button>
                <button class="btn btn-primary" onclick="saveIconChange()"><?= __('save') ?></button>
            </div>
        </div>
    </div>
    
    <!-- 招待リンクモーダル -->
    <div class="modal-overlay" id="inviteLinkModal">
        <div class="modal" style="max-width: 450px;">
            <div class="modal-header">
                <h3><?= $currentLang === 'en' ? 'Group Invite Link' : ($currentLang === 'zh' ? '群组邀请链接' : 'グループ招待リンク') ?></h3>
                <button class="modal-close" onclick="closeModal('inviteLinkModal')" aria-label="閉じる">×</button>
            </div>
            <div class="modal-body">
                <p class="invite-description">
                    <?= $currentLang === 'en' ? 'Share this link to invite people to this group.' : ($currentLang === 'zh' ? '分享此链接以邀请他人加入群组。' : 'このリンクを共有してグループに招待できます。') ?>
                </p>
                <div class="invite-link-box">
                    <input type="text" id="inviteLinkInput" class="invite-link-input" readonly>
                    <button class="btn btn-primary copy-btn" onclick="copyInviteLink()">📋 <?= $currentLang === 'en' ? 'Copy' : ($currentLang === 'zh' ? '复制' : 'コピー') ?></button>
                </div>
                <button class="btn btn-secondary reset-link-btn" onclick="resetInviteLink()">
                    🔄 <?= $currentLang === 'en' ? 'Generate New Link' : ($currentLang === 'zh' ? '生成新链接' : '新しいリンクを生成') ?>
                </button>
            </div>
        </div>
    </div>
    
    <!-- メンバー管理モーダル -->
    <div class="modal-overlay" id="addMemberModal">
        <div class="modal member-modal-redesign">
            <div class="member-modal-header">
                <div class="member-modal-title">
                    <span class="member-modal-icon">👥</span>
                    <h3><?= $currentLang === 'en' ? 'Member Management' : ($currentLang === 'zh' ? '成员管理' : 'メンバー管理') ?></h3>
                </div>
                <button class="member-modal-close" onclick="closeModal('addMemberModal')">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div class="member-modal-body">
                <!-- 現在のメンバー一覧 -->
                <div class="member-section">
                    <div class="member-section-header">
                        <span class="member-section-label"><?= $currentLang === 'en' ? 'Current Members' : ($currentLang === 'zh' ? '当前成员' : '現在のメンバー') ?></span>
                        <span class="member-count-badge" id="memberCountBadge">0</span>
                    </div>
                    <div id="currentMembersList" class="member-list-container">
                        <div class="member-loading">
                            <div class="member-loading-spinner"></div>
                            <span><?= $currentLang === 'en' ? 'Loading...' : ($currentLang === 'zh' ? '加载中...' : '読み込み中...') ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- 新メンバー追加 -->
                <div class="member-section member-add-section">
                    <div class="member-section-header">
                        <span class="member-section-label"><?= $currentLang === 'en' ? 'Add New Member' : ($currentLang === 'zh' ? '添加新成员' : '新規メンバー追加') ?></span>
                    </div>
                    <div class="member-search-wrapper">
                        <svg class="member-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="M21 21l-4.35-4.35"/>
                        </svg>
                        <input type="text" id="addMemberSearch" class="member-search-input" placeholder="<?= $currentLang === 'en' ? 'Search by name or email...' : ($currentLang === 'zh' ? '输入名称或邮箱搜索...' : '名前またはメールアドレスで検索...') ?>" aria-label="<?= $currentLang === 'en' ? 'Search members' : ($currentLang === 'zh' ? '搜索成员' : 'メンバーを検索') ?>" oninput="searchMembersToAdd(this.value)">
                    </div>
                    <div class="member-search-results" id="addMemberResults">
                        <!-- 検索結果がここに表示 -->
                    </div>
                    
                    <!-- メール/電話で招待 -->
                    <div class="member-invite-section" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(0,0,0,0.1);">
                        <div class="member-section-header">
                            <span class="member-section-label" style="font-size: 12px; color: #666;"><?= $currentLang === 'en' ? 'Invite by Email/Phone' : ($currentLang === 'zh' ? '通过邮件/电话邀请' : '外部ユーザーを招待') ?></span>
                        </div>
                        <div style="display: flex; gap: 8px; margin-top: 8px;">
                            <input type="text" id="inviteContactInput" class="member-search-input" placeholder="<?= $currentLang === 'en' ? 'Email or phone number...' : ($currentLang === 'zh' ? '邮箱或电话号码...' : 'メールアドレスまたは電話番号...') ?>" style="flex: 1;">
                            <button type="button" onclick="sendGroupInvite()" class="btn-invite-send" style="padding: 8px 16px; background: var(--primary, #667eea); color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 13px; white-space: nowrap;">
                                <?= $currentLang === 'en' ? 'Invite' : ($currentLang === 'zh' ? '邀请' : '招待') ?>
                            </button>
                        </div>
                        <div id="inviteResult" style="margin-top: 8px; font-size: 12px;"></div>
                    </div>
                    
                    <!-- 招待リンク・QRコード -->
                    <div class="member-invite-link-section" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(0,0,0,0.1);">
                        <div class="member-section-header">
                            <span class="member-section-label" style="font-size: 12px; color: #666;"><?= $currentLang === 'en' ? 'Invitation Link & QR Code' : ($currentLang === 'zh' ? '邀请链接和二维码' : '招待アドレス・QRコード') ?></span>
                        </div>
                        
                        <!-- 招待アドレス -->
                        <div style="margin-top: 12px;">
                            <label style="font-size: 11px; color: #888; display: block; margin-bottom: 4px;"><?= $currentLang === 'en' ? 'Invitation URL' : ($currentLang === 'zh' ? '邀请链接' : '招待アドレス') ?></label>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <input type="text" id="groupInviteUrl" readonly class="member-search-input" style="flex: 1; font-size: 12px; background: #f5f5f5; cursor: text;">
                                <button type="button" onclick="copyGroupInviteUrl()" class="btn-copy-url" style="padding: 8px 12px; background: #6c757d; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 12px; white-space: nowrap;" title="<?= $currentLang === 'en' ? 'Copy' : ($currentLang === 'zh' ? '复制' : 'コピー') ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle;">
                                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                                    </svg>
                                </button>
                            </div>
                            <div id="copyUrlResult" style="margin-top: 4px; font-size: 11px; color: #27ae60;"></div>
                        </div>
                        
                        <!-- QRコード -->
                        <div style="margin-top: 16px; text-align: center;">
                            <label style="font-size: 11px; color: #888; display: block; margin-bottom: 8px;"><?= $currentLang === 'en' ? 'QR Code' : ($currentLang === 'zh' ? '二维码' : 'QRコード') ?></label>
                            <div id="groupInviteQrCode" style="display: inline-block; padding: 12px; background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                                <!-- QRコードがここに表示 -->
                            </div>
                            <div style="margin-top: 8px;">
                                <button type="button" onclick="downloadGroupQrCode()" class="btn-download-qr" style="padding: 6px 12px; background: #17a2b8; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 11px;">
                                    <?= $currentLang === 'en' ? 'Download QR' : ($currentLang === 'zh' ? '下载二维码' : 'QRをダウンロード') ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- メンバー管理メニュー -->
    <div class="member-context-menu" id="memberContextMenu" style="display: none;">
        <div class="member-menu-item" onclick="toggleMemberAdmin()" id="menuToggleAdmin">
            <span>👑</span>
            <span id="menuToggleAdminText"><?= $currentLang === 'en' ? 'Make Admin' : ($currentLang === 'zh' ? '设为管理员' : '管理者に任命') ?></span>
        </div>
        <div class="member-menu-item" onclick="toggleMemberSilence()" id="menuToggleSilence">
            <span>🔇</span>
            <span id="menuToggleSilenceText"><?= $currentLang === 'en' ? 'Silence' : ($currentLang === 'zh' ? '禁言' : '発言制限') ?></span>
        </div>
        <div class="member-menu-item" id="menuAddContactFromGroup" style="display: none;" onclick="addContactFromMemberMenu()">
            <span>👋</span>
            <span><?= $currentLang === 'en' ? 'Address request' : ($currentLang === 'zh' ? '地址申请' : 'アドレス追加申請') ?></span>
        </div>
        <div class="menu-divider"></div>
        <div class="member-menu-item danger" onclick="removeMemberFromGroup()">
            <span>🚫</span>
            <span><?= $currentLang === 'en' ? 'Remove' : ($currentLang === 'zh' ? '移除' : '削除') ?></span>
        </div>
    </div>
    
    <!-- 手動タスク追加モーダル -->
    <div class="modal-overlay" id="manualWishModal">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h3>📋 <?= $currentLang === 'en' ? 'Add to Task' : ($currentLang === 'zh' ? '添加到任务' : 'タスクに追加') ?></h3>
                <button class="modal-close" onclick="closeModal('manualWishModal')" aria-label="閉じる">×</button>
            </div>
            <div class="modal-body">
                <p style="color: #666; font-size: 13px; margin-bottom: 12px;">
                    <?= $currentLang === 'en' ? 'Enter the task details below.' : ($currentLang === 'zh' ? '请在下方输入任务详情。' : 'タスクの詳細を入力してください。') ?>
                </p>
                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 6px;">
                        <?= $currentLang === 'en' ? 'Original Message' : ($currentLang === 'zh' ? '原始消息' : '元のメッセージ') ?>
                    </label>
                    <textarea id="wishOriginalText" rows="4" placeholder="<?= $currentLang === 'en' ? 'Edit the message content if needed' : ($currentLang === 'zh' ? '如需可编辑消息内容' : '必要に応じてメッセージを編集できます') ?>"
                              style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 13px; resize: vertical; min-height: 80px; background: #fff; color: #333;"></textarea>
                    <input type="hidden" id="wishMessageId" value="">
                </div>
                <!-- 担当者セレクター -->
                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 6px;">
                        <?= $currentLang === 'en' ? 'Assign To' : ($currentLang === 'zh' ? '分配给' : '担当者') ?>
                    </label>
                    <select id="wishAssignTo" aria-label="<?= $currentLang === 'en' ? 'Assign To' : ($currentLang === 'zh' ? '分配给' : '担当者を選択') ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; background: #fff !important; color: #333 !important;">
                        <option value=""><?= $currentLang === 'en' ? 'Myself' : ($currentLang === 'zh' ? '自己' : '自分') ?></option>
                        <!-- グループメンバーは動的に追加 -->
                    </select>
                    <p style="color: #888; font-size: 12px; margin-top: 4px;">
                        <?= $currentLang === 'en' ? 'Select a person to assign this task to.' : ($currentLang === 'zh' ? '选择要将此任务分配给谁。' : '他のメンバーに依頼する場合は選択してください。') ?>
                    </p>
                </div>
                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 6px;">
                        <?= $currentLang === 'en' ? 'Task Title' : ($currentLang === 'zh' ? '任务标题' : 'タスクタイトル') ?> <span style="color: #e74c3c;">*</span>
                    </label>
                    <input type="text" id="wishTextInput" placeholder="<?= $currentLang === 'en' ? 'e.g., Submit report by Friday' : ($currentLang === 'zh' ? '例：周五前提交报告' : '例：金曜までに報告書を提出') ?>" 
                           style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; background: #fff; color: #333;">
                    <p style="color: #888; font-size: 12px; margin-top: 4px;">
                        <?= $currentLang === 'en' ? 'Enter a concise task title.' : ($currentLang === 'zh' ? '请输入简洁的任务标题。' : '簡潔なタスクタイトルを入力してください。') ?>
                    </p>
                </div>
                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 6px;">
                        <?= $currentLang === 'en' ? 'Due Date' : ($currentLang === 'zh' ? '截止日期' : '期限') ?>
                    </label>
                    <input type="date" id="taskDueDateModal" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; background: #fff; color: #333;">
                    <p style="color: #888; font-size: 12px; margin-top: 4px;">
                        <?= $currentLang === 'en' ? 'Optional. Set a deadline for this task.' : ($currentLang === 'zh' ? '可选。设置此任务的截止日期。' : '任意。このタスクの期限を設定できます。') ?>
                    </p>
                </div>
                <div class="form-group">
                    <label style="font-weight: 600; display: block; margin-bottom: 6px;">
                        <?= $currentLang === 'en' ? 'Priority' : ($currentLang === 'zh' ? '优先级' : '優先度') ?>
                    </label>
                    <select id="taskPriorityModal" aria-label="<?= $currentLang === 'en' ? 'Priority' : ($currentLang === 'zh' ? '优先级' : '優先度を選択') ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; background: #fff !important; color: #333 !important;">
                        <option value="0"><?= $currentLang === 'en' ? 'Low' : ($currentLang === 'zh' ? '低' : '低') ?></option>
                        <option value="1" selected><?= $currentLang === 'en' ? 'Medium' : ($currentLang === 'zh' ? '中' : '中') ?></option>
                        <option value="2"><?= $currentLang === 'en' ? 'High' : ($currentLang === 'zh' ? '高' : '高') ?></option>
                        <option value="3"><?= $currentLang === 'en' ? 'Urgent' : ($currentLang === 'zh' ? '紧急' : '緊急') ?></option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('manualWishModal')"><?= __('cancel') ?></button>
                <button class="btn btn-primary" onclick="saveTaskFromMessage()"><?= __('save') ?></button>
            </div>
        </div>
    </div>
    
    <!-- 新規会話モーダル -->
    <div class="modal-overlay" id="newConversationModal">
        <div class="modal">
            <div class="modal-header">
                <h3>新しい会話</h3>
                <button class="modal-close" onclick="closeModal('newConversationModal')" aria-label="閉じる">×</button>
            </div>
            <div class="modal-body">
                <div class="conv-type-tabs" role="tablist" aria-label="<?= $currentLang === 'en' ? 'Conversation type' : ($currentLang === 'zh' ? '会话类型' : '会話の種類') ?>">
                    <button type="button" role="tab" id="convTypeTabDm" aria-selected="true" aria-controls="dmForm" class="conv-type-tabs__btn" onclick="switchConversationType('dm')">DM</button>
                    <button type="button" role="tab" id="convTypeTabGroup" aria-selected="false" aria-controls="groupForm" class="conv-type-tabs__btn" onclick="switchConversationType('group')"><?= __('group') ?></button>
                </div>
                
                <div id="dmForm">
                    <div class="form-group">
                        <label><?= __('users') ?></label>
                        <input type="text" id="userSearch" placeholder="<?= $currentLang === 'en' ? 'Search by name...' : ($currentLang === 'zh' ? '按名称搜索...' : '名前で検索...') ?>" aria-label="<?= $currentLang === 'en' ? 'Search users' : ($currentLang === 'zh' ? '搜索用户' : 'ユーザーを検索') ?>" oninput="filterUsers()">
                    </div>
                    <div class="user-list" id="userList">
                        <?php foreach ($all_users as $u): ?>
                        <div class="user-item" data-user-id="<?= $u['id'] ?>" data-name="<?= htmlspecialchars($u['display_name']) ?>" onclick="selectUser(this)">
                            <div class="conv-avatar avatar-grey"><?= mb_substr($u['display_name'], 0, 1) ?></div>
                            <div style="flex:1;"><?= htmlspecialchars($u['display_name']) ?></div>
                            <div class="check">✓</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div id="groupForm" style="display:none;">
                    <!-- 組織選択（どの組織に新規チャットを作るか） -->
                    <div class="form-group chat-add-group-org-select">
                        <label><?= $currentLang === 'en' ? 'Organization' : ($currentLang === 'zh' ? '组织' : '組織') ?></label>
                        <select id="newConversationOrganizationId" aria-label="<?= $currentLang === 'en' ? 'Select organization' : ($currentLang === 'zh' ? '选择组织' : '組織を選択') ?>">
                            <option value=""><?= $currentLang === 'en' ? 'None (personal)' : ($currentLang === 'zh' ? '无（个人）' : 'なし（個人）') ?></option>
                            <?php foreach (!empty($userOrganizations) ? $userOrganizations : [] as $org): ?>
                            <option value="<?= (int)$org['id'] ?>"><?= htmlspecialchars($org['name'] ?? '', ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- 本人確認未済時: 既存のグループをこの組織に追加 -->
                    <div class="form-group chat-add-group-clone-from" id="chatAddGroupCloneFromWrap" style="display:none;">
                        <label><?= $currentLang === 'en' ? 'Add existing group to this organization' : ($currentLang === 'zh' ? '将现有群组添加到此组织' : '既存のグループをこの組織に追加') ?></label>
                        <p class="form-hint"><?= $currentLang === 'en' ? 'If you are an admin of a group in another organization, you can add it here without identity verification.' : ($currentLang === 'zh' ? '如果您是其他组织中某群组的管理员，可在此添加而无需身份验证。' : '他の組織で管理者のグループがあれば、本人確認なしでこの組織に同じ名前のグループを作成できます。') ?></p>
                        <select id="newConversationCloneFromId" aria-label="<?= $currentLang === 'en' ? 'Select existing group' : ($currentLang === 'zh' ? '选择现有群组' : '既存のグループを選択') ?>">
                            <option value="">— <?= $currentLang === 'en' ? 'Select' : ($currentLang === 'zh' ? '选择' : '選択') ?> —</option>
                        </select>
                    </div>
                    <!-- 多言語グループ名入力 -->
                    <div class="i18n-input-group">
                        <div class="form-group i18n-field">
                            <label>🇯🇵 <?= __('group') ?><?= $currentLang === 'en' ? ' Name' : ($currentLang === 'zh' ? '名称' : '名') ?> <span class="required">*</span></label>
                            <input type="text" id="groupName" placeholder="<?= $currentLang === 'en' ? 'Group name (Japanese)' : ($currentLang === 'zh' ? '群组名称（日语）' : 'グループ名') ?>">
                        </div>
                        <div class="form-group i18n-field">
                            <label>🇺🇸 Group Name (English)</label>
                            <input type="text" id="groupNameEn" placeholder="Optional">
                        </div>
                        <div class="form-group i18n-field">
                            <label>🇨🇳 群组名称（中文）</label>
                            <input type="text" id="groupNameZh" placeholder="可选">
                        </div>
                    </div>
                    <div class="form-group chat-add-group-member-search">
                        <label><?= $currentLang === 'en' ? 'Search members' : ($currentLang === 'zh' ? '搜索成员' : 'メンバーを検索') ?></label>
                        <input type="text" id="groupMemberSearch" placeholder="<?= $currentLang === 'en' ? 'Search by name...' : ($currentLang === 'zh' ? '按名称搜索...' : '名前で検索...') ?>" aria-label="<?= $currentLang === 'en' ? 'Search members by name' : ($currentLang === 'zh' ? '按名称搜索成员' : '名前でメンバーを検索') ?>" oninput="filterGroupMembers()">
                    </div>
                    <div class="form-group">
                        <label><?= $currentLang === 'en' ? 'Select Members' : ($currentLang === 'zh' ? '选择成员' : 'メンバーを選択') ?></label>
                    </div>
                    <div class="user-list" id="groupUserList">
                        <?php foreach ($all_users as $u): ?>
                        <div class="user-item" data-user-id="<?= $u['id'] ?>" data-name="<?= htmlspecialchars($u['display_name'], ENT_QUOTES) ?>" onclick="toggleGroupMember(this)">
                            <div class="conv-avatar avatar-grey"><?= mb_substr($u['display_name'], 0, 1) ?></div>
                            <div style="flex:1;"><?= htmlspecialchars($u['display_name']) ?></div>
                            <div class="check">✓</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('newConversationModal')">キャンセル</button>
                <button class="btn btn-primary" onclick="createConversation()">作成</button>
            </div>
        </div>
    </div>
    
    <!-- 検索結果から友達申請モーダル（メッセージ付き） -->
    <div class="modal-overlay" id="friendRequestFromSearchModal">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #3b82f6, #2563eb); color: white;">
                <h3>👤 友達申請</h3>
                <button class="modal-close" onclick="closeFriendRequestFromSearchModal()" style="color: white;">×</button>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <p id="friendRequestTargetName" style="margin-bottom: 16px; font-size: 14px; color: var(--text, #333);"></p>
                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 6px;">メッセージ（任意・最大500文字）</label>
                    <textarea id="friendRequestMessage" rows="3" placeholder="自己紹介や申請理由を入力..." maxlength="500" style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; font-family: inherit; resize: vertical;"></textarea>
                </div>
                <p style="font-size: 12px; color: var(--text-muted, #6b7280);">送信後、相手が承諾するとDMが可能になります。</p>
            </div>
            <div class="modal-footer" style="padding: 16px 20px; border-top: 1px solid #e2e8f0; display: flex; gap: 12px; justify-content: flex-end;">
                <button class="btn btn-secondary" onclick="closeFriendRequestFromSearchModal()" style="padding: 10px 20px; border: 1px solid #e2e8f0; background: white; border-radius: 8px; cursor: pointer;">キャンセル</button>
                <button class="btn btn-primary" id="friendRequestSubmitBtn" onclick="submitFriendRequestFromSearch()" style="padding: 10px 20px; background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">申請する</button>
            </div>
        </div>
    </div>
    
    <!-- 検索モーダル（入力欄は上パネル検索バーと同じデザイン） -->
    <div class="modal-overlay" id="searchModal">
        <div class="search-modal">
            <div class="search-input-row">
                <div class="search-box search-modal-search-box" style="flex:1;min-width:0;">
                    <img src="assets/icons/line/search.svg" alt="" class="search-box-icon icon-line" aria-hidden="true" width="20" height="20">
                    <input type="text" id="searchInput" class="search-box-input" placeholder="<?= __('search_placeholder') ?>" aria-label="<?= __('search_placeholder') ?>" oninput="performSearch()" autofocus>
                </div>
                <button class="search-close" onclick="closeModal('searchModal')" aria-label="閉じる">×</button>
            </div>
            <div class="search-filter-tabs">
                <button class="active" data-filter="all" onclick="setSearchFilter('all', this)"><?= __('all') ?></button>
                <button data-filter="messages" onclick="setSearchFilter('messages', this)"><?= __('messages') ?></button>
                <button data-filter="users" onclick="setSearchFilter('users', this)"><?= __('users') ?></button>
                <button data-filter="groups" onclick="setSearchFilter('groups', this)"><?= __('groups') ?></button>
            </div>
            <div class="search-refine-bar" id="searchRefineBar" style="display:none;">
                <label class="search-refine-label"><?= $currentLang === 'en' ? 'Filter by group' : ($currentLang === 'zh' ? '按群组筛选' : 'グループで絞り込み') ?></label>
                <select id="searchRefineGroup" class="search-refine-select" onchange="applySearchRefine()" aria-label="<?= $currentLang === 'en' ? 'Group' : ($currentLang === 'zh' ? '群组' : 'グループ') ?>">
                    <option value=""><?= $currentLang === 'en' ? 'All' : ($currentLang === 'zh' ? '全部' : 'すべて') ?></option>
                </select>
                <label class="search-refine-label"><?= $currentLang === 'en' ? 'Word' : ($currentLang === 'zh' ? '关键词' : 'ワード') ?></label>
                <input type="text" id="searchRefineWord" class="search-refine-input" placeholder="<?= $currentLang === 'en' ? 'Further narrow...' : ($currentLang === 'zh' ? '进一步筛选...' : 'さらに絞り込み...') ?>" oninput="applySearchRefine()" aria-label="<?= $currentLang === 'en' ? 'Keyword' : ($currentLang === 'zh' ? '关键词' : 'ワード') ?>">
            </div>
            <div class="search-content">
                <div class="search-section-title" id="searchSectionTitle"><?= __('recent_search') ?></div>
                <div class="search-results" id="searchResults">
                    <!-- 検索履歴または結果が表示される -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- 通知モーダル -->
    <div class="modal-overlay" id="notificationModal">
        <div class="modal" style="max-width:400px;">
            <div class="modal-header">
                <h3>🔔 通知</h3>
                <button class="modal-close" onclick="closeModal('notificationModal')" aria-label="閉じる">×</button>
            </div>
            <div class="modal-body" id="notificationList" style="padding:0;max-height:400px;overflow-y:auto;">
                <div style="text-align:center;color:var(--text-muted);padding:40px;">通知はありません</div>
            </div>
        </div>
    </div>
    
    <!-- 個人アドレス帳・DMモーダル（旧: 友達追加モーダル） -->
    <div class="modal-overlay" id="addFriendModal">
        <div class="modal" style="max-width:480px;">
            <div class="modal-header">
                <h3>👥 個人アドレス帳・DM</h3>
                <button class="modal-close" onclick="closeModal('addFriendModal')" aria-label="閉じる">×</button>
            </div>
            <div class="modal-body" style="padding:0;">
                <!-- タブ -->
                <div class="add-friend-tabs">
                    <button class="active" onclick="switchAddFriendTab('members')">
                        <span class="tab-icon">👥</span> メンバー
                    </button>
                    <button onclick="switchAddFriendTab('invite')">
                        <span class="tab-icon">🔗</span> 招待
                    </button>
                    <button onclick="switchAddFriendTab('qr')">
                        <span class="tab-icon">📱</span> QR
                    </button>
                    <button onclick="switchAddFriendTab('search')">
                        <span class="tab-icon">✉️</span> Mail
                    </button>
                    <button onclick="switchAddFriendTab('contacts')">
                        <span class="tab-icon">📇</span> <?= $currentLang === 'en' ? 'Contacts' : ($currentLang === 'zh' ? '通讯录' : '連絡先') ?>
                    </button>
                </div>
                
                <!-- グループメンバータブ（デフォルト表示） -->
                <div class="add-friend-content" id="addFriendMembers">
                    <p class="add-friend-desc">所属グループのメンバーを検索してDMを開始できます</p>
                    <div class="search-user-box">
                        <input type="text" id="searchMemberInput" placeholder="名前で検索..." aria-label="メンバーを名前で検索" oninput="debounceSearchGroupMembers()">
                    </div>
                    <div class="search-user-results" id="searchMemberResults" style="max-height:300px;">
                        <div class="search-user-empty">
                            <span style="font-size:40px;">👥</span>
                            <p>読み込み中...</p>
                        </div>
                    </div>
                </div>
                
                <!-- 招待リンクタブ -->
                <div class="add-friend-content" id="addFriendInvite" style="display:none;">
                    <p class="add-friend-desc"><?= $currentLang === 'en' ? 'Share this link so others can add you to their address book.' : ($currentLang === 'zh' ? '分享此链接，他人可将您加入通讯录。' : 'このリンクを共有すると、あなたの個人アドレス帳に追加できます') ?></p>
                    <div class="invite-link-box">
                        <input type="text" id="friendInviteLinkInput" readonly value="<?= 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/invite.php?u=' . $user_id ?>">
                        <button class="copy-btn" onclick="copyFriendInviteLink()">📋 コピー</button>
                    </div>
                    <p class="invite-link-note">※ リンクの有効期限はありません</p>
                </div>
                
                <!-- QRコードタブ -->
                <div class="add-friend-content" id="addFriendQR" style="display:none;">
                    <p class="add-friend-desc">このQRコードを友達に見せて読み取ってもらいましょう</p>
                    <div class="qr-code-container" style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap; justify-content:center;">
                        <!-- 自分のQRコード -->
                        <div class="qr-code-box" id="qrCodeDisplay" style="text-align:center; padding: 20px; flex:1; min-width:200px;">
                            <img id="qrCodeImage" src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/invite.php?u=' . $user_id) ?>" alt="QRコード" style="border-radius:8px; background:#fff; padding:8px;">
                            <p style="margin-top:8px;font-size:11px;color:var(--text-muted);">マイQRコード</p>
                        </div>
                        <!-- QRスキャナー -->
                        <div class="qr-scanner-box" style="text-align:center; padding: 20px; flex:1; min-width:200px;">
                            <div id="qrScannerPreview" style="display:none; width:180px; height:180px; margin:0 auto; border-radius:8px; overflow:hidden; position:relative;">
                                <video id="qrVideo" style="width:100%; height:100%; object-fit:cover;"></video>
                                <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:120px; height:120px; border:2px solid #10b981; border-radius:8px;"></div>
                            </div>
                            <div id="qrScannerButton" style="padding:20px;">
                                <div style="width:80px; height:80px; margin:0 auto 12px; background:linear-gradient(135deg,#667eea,#764ba2); border-radius:16px; display:flex; align-items:center; justify-content:center;">
                                    <span style="font-size:36px;">📷</span>
                                </div>
                                <button class="btn btn-secondary" onclick="startQRScanner()" style="font-size:13px; padding:8px 16px;">
                                    カメラで読み取る
                                </button>
                            </div>
                            <p id="qrScannerStatus" style="margin-top:8px;font-size:11px;color:var(--text-muted);"><?= $currentLang === 'en' ? 'Scan QR to add to address book' : ($currentLang === 'zh' ? '扫描二维码添加到通讯录' : '個人アドレス帳に追加するQRを読み取る') ?></p>
                            <div id="qrScannedUserResult" class="qr-scanned-user-result" style="display:none; margin-top:16px; padding:12px; border-radius:12px; background:var(--bg-hover, #f0f4f8);">
                                <p class="qr-scanned-label" style="margin:0 0 8px 0; font-size:12px; color:var(--text-muted);"><?= $currentLang === 'en' ? 'Scanned user' : ($currentLang === 'zh' ? '扫描到的用户' : '読み取った相手') ?></p>
                                <div id="qrScannedUserCard"></div>
                            </div>
                        </div>
                    </div>
                    <p class="add-friend-note"><?= $currentLang === 'en' ? 'After scanning, the user will appear. Tap Add to add to your address book and start a chat.' : ($currentLang === 'zh' ? '扫描后将显示用户，点击添加即可加入通讯录并开始聊天。' : '読み取ると相手が表示されます。追加を押してアドレス帳に登録し、会話を始められます。') ?></p>
                </div>
                
                <!-- メール・携帯で検索タブ（Email/携帯番号のみ） -->
                <div class="add-friend-content" id="addFriendSearch" style="display:none;">
                    <p class="add-friend-desc"><?= $currentLang === 'en' ? 'Search by email or phone number. You can send an invite to unregistered email addresses.' : ($currentLang === 'zh' ? '通过邮箱或手机号搜索。可向未注册邮箱发送邀请。' : 'メールアドレスまたは携帯番号で検索。登録済みの方はアドレス追加申請、未登録のメールアドレスには招待を送れます。') ?></p>
                    <div class="search-user-box">
                        <input type="text" id="searchUserInput" placeholder="<?= $currentLang === 'en' ? 'Email or phone number...' : ($currentLang === 'zh' ? '邮箱或手机号...' : 'Email/携帯番号で検索') ?>" oninput="debounceSearchUser()" autocomplete="off">
                    </div>
                    <div class="search-user-results" id="searchUserResults">
                        <div class="search-user-empty">
                            <span style="font-size:40px;">🔍</span>
                            <p><?= $currentLang === 'en' ? 'Search by email or phone' : ($currentLang === 'zh' ? '输入邮箱或手机号' : 'メールアドレスまたは携帯番号で検索') ?></p>
                        </div>
                    </div>
                    <div id="addFriendSearchInviteRow" class="add-friend-search-invite-row" style="display: none;">
                        <button type="button" class="btn btn-primary" id="addFriendSearchInviteBtn"><?= htmlspecialchars(__('search_invite_mail_btn'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                </div>
                
                <!-- 連絡先タブ（端末連絡先から友達候補を表示） -->
                <div class="add-friend-content" id="addFriendContacts" style="display:none;">
                    <p class="add-friend-desc"><?= $currentLang === 'en' ? 'Load your device contacts to find friends on Social9' : ($currentLang === 'zh' ? '加载设备通讯录以查找Social9上的好友' : '端末の連絡先を読み込んで、Social9にいる友達を見つけます') ?></p>
                    <div id="addFriendContactsInitial" class="add-friend-contacts-initial">
                        <p class="add-friend-contacts-privacy"><?= $currentLang === 'en' ? 'Contact data is only used for matching and is not stored on the server.' : ($currentLang === 'zh' ? '通讯录数据仅用于匹配，不会保存在服务器上。' : '連絡先データは照合のみに使用し、サーバーに保存しません。') ?></p>
                        <button type="button" class="btn btn-primary" id="addFriendContactsLoadBtn" onclick="loadAddFriendContacts()"><?= $currentLang === 'en' ? 'Load contacts' : ($currentLang === 'zh' ? '加载通讯录' : '連絡先を読み込む') ?></button>
                        <div id="addFriendContactsUnsupported" class="add-friend-contacts-unsupported" style="display:none;">
                            <p><?= $currentLang === 'en' ? 'Your browser does not support loading contacts here. Use CSV or vCard import in Settings.' : ($currentLang === 'zh' ? '您的浏览器不支持在此加载通讯录。请在设置中使用CSV或vCard导入。' : 'お使いのブラウザではここから連絡先を読み込めません。設定の連絡先インポート（CSV/vCard）をご利用ください。') ?></p>
                            <a href="settings.php#friends" class="add-friend-contacts-settings-link"><?= $currentLang === 'en' ? 'Open Settings' : ($currentLang === 'zh' ? '打开设置' : '設定を開く') ?></a>
                        </div>
                    </div>
                    <div id="addFriendContactsLoading" class="add-friend-contacts-loading" style="display:none;">
                        <p><?= $currentLang === 'en' ? 'Loading contacts...' : ($currentLang === 'zh' ? '正在加载通讯录...' : '連絡先を読み込んでいます...') ?></p>
                    </div>
                    <div id="addFriendContactsResults" class="add-friend-contacts-results" style="display:none;">
                        <div id="addFriendContactsList" class="add-friend-contacts-list" style="max-height:300px;overflow-y:auto;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- リアクションピッカー（プレミアム版） -->
    <div class="reaction-picker-v2" id="reactionPicker" onclick="event.stopPropagation()">
        <div class="reaction-row">
            <button onclick="addReaction(null, '👍')" title="いいね">👍</button>
            <button onclick="addReaction(null, '❤️')" title="ハート">❤️</button>
            <button onclick="addReaction(null, '😊')" title="嬉しい">😊</button>
            <button onclick="addReaction(null, '🎉')" title="お祝い">🎉</button>
            <button onclick="addReaction(null, '😂')" title="笑い">😂</button>
            <button onclick="addReaction(null, '🔥')" title="ファイヤー">🔥</button>
        </div>
        <div class="reaction-row">
            <button onclick="addReaction(null, '👏')" title="拍手">👏</button>
            <button onclick="addReaction(null, '🙏')" title="感謝">🙏</button>
            <button onclick="addReaction(null, '🙇')" title="ありがとう">🙇</button>
            <button onclick="addReaction(null, '💪')" title="頑張る">💪</button>
            <button onclick="addReaction(null, '✨')" title="キラキラ">✨</button>
            <button onclick="addReaction(null, '😢')" title="悲しい">😢</button>
            <button onclick="addReaction(null, '🤔')" title="考え中">🤔</button>
        </div>
        <div class="reaction-row">
            <button onclick="addReaction(null, '👀')" title="見てる">👀</button>
            <button onclick="addReaction(null, '💯')" title="100点">💯</button>
            <button onclick="addReaction(null, '🥰')" title="大好き">🥰</button>
            <button onclick="addReaction(null, '😮')" title="驚き">😮</button>
        </div>
    </div>
    
    <!-- メッセージ編集モーダル -->
    <div class="edit-message-modal" id="editMessageModal">
        <div class="edit-message-content">
            <h3>✏️ メッセージを編集</h3>
            
            <!-- TO選択は削除済み（editToSelector は closeEditModal 用に id のみ残す） -->
            <div class="edit-to-section" id="editToSection" style="display:none;">
                <label>宛先:</label>
                <div class="edit-to-display" id="editToDisplay"><span class="edit-to-text">指定なし</span></div>
                <div class="edit-to-selector" id="editToSelector" style="display:none;">
                    <div class="edit-to-list" id="editToList"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ファイル表示名編集モーダル（editMessageModal の外に独立配置） -->
    <div class="modal-overlay" id="editFileDisplayNameModal" onclick="if(event.target===this)closeEditFileDisplayNameModal()">
        <div class="modal" style="max-width: 400px;" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3>📝 ファイル名を変更</h3>
                <button class="modal-close" onclick="closeEditFileDisplayNameModal()" aria-label="閉じる">×</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="editFileDisplayNameInput">表示名</label>
                    <input type="text" id="editFileDisplayNameInput" placeholder="ファイルの表示名を入力" maxlength="200" style="width:100%;padding:10px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;box-sizing:border-box;" onkeydown="if(event.key==='Escape')closeEditFileDisplayNameModal();if(event.key==='Enter')saveFileDisplayNameEdit()">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeEditFileDisplayNameModal()">キャンセル</button>
                <button class="btn btn-primary" onclick="saveFileDisplayNameEdit()">保存</button>
            </div>
        </div>
    </div>
    
    <!-- チャット内タスク依頼モーダル -->
    <div class="modal-overlay" id="chatTaskModal">
        <div class="modal" style="max-width: 450px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #3b82f6, #2563eb); color: white;">
                <h3>📋 タスクを依頼</h3>
                <button class="modal-close" onclick="closeChatTaskModal()" style="color: white;">×</button>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 6px;">タスク内容 *</label>
                    <textarea id="chatTaskContent" rows="3" placeholder="依頼するタスクの内容" style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; font-family: inherit; resize: vertical;"></textarea>
                </div>
                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px;">作業者（担当者） * 複数選択可</label>
                    <div id="chatTaskAssigneeList" class="task-assignee-checkbox-list chat-task-assignee-list" style="max-height: 200px;">
                        <!-- このグループのメンバーのみ表示（動的に読み込み） -->
                        <div class="assignee-loading" style="color: #6b7280; font-size: 13px;">読み込み中...</div>
                    </div>
                    <div class="assignee-selected-hint" id="chatTaskAssigneeHint" style="margin-top: 6px; font-size: 12px; color: #0369a1; display: none;"></div>
                </div>
                <div class="form-row" style="display: flex; gap: 12px;">
                    <div class="form-group" style="flex: 1;">
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 6px;">期限</label>
                        <input type="date" id="chatTaskDueDate" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 6px;">優先度</label>
                        <select id="chatTaskPriority" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                            <option value="0">低</option>
                            <option value="1" selected>中</option>
                            <option value="2">高</option>
                            <option value="3">緊急</option>
                        </select>
                    </div>
                </div>
                <div class="task-info-hint" style="margin-top: 12px; padding: 10px; background: #f0f9ff; border-radius: 8px; font-size: 12px; color: #0369a1;">
                    💡 依頼者: あなた（自動設定）<br>
                    選択した担当者にタスクが割り当てられ、チャットに依頼内容が表示されます。完了時もチャットに通知されます。
                </div>
            </div>
            <div class="modal-footer" style="padding: 16px 20px; border-top: 1px solid #e2e8f0; display: flex; gap: 12px; justify-content: flex-end;">
                <button class="btn btn-secondary" onclick="closeChatTaskModal()" style="padding: 10px 20px; border: 1px solid #e2e8f0; background: white; border-radius: 8px; cursor: pointer;">キャンセル</button>
                <button class="btn btn-primary" onclick="submitChatTask()" style="padding: 10px 20px; background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">依頼する</button>
            </div>
        </div>
    </div>

    <!-- 右パネル用：タスク編集モーダル -->
    <div class="modal-overlay" id="editTaskPanelModal">
        <div class="modal" style="max-width: 450px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #3b82f6, #2563eb); color: white;">
                <h3>✏️ <?= $currentLang === 'en' ? 'Edit Task' : ($currentLang === 'zh' ? '编辑任务' : 'タスクを編集') ?></h3>
                <button class="modal-close" onclick="closeEditTaskPanelModal()" style="color: white;" type="button" aria-label="<?= $currentLang === 'en' ? 'Close' : ($currentLang === 'zh' ? '关闭' : '閉じる') ?>">×</button>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <input type="hidden" id="editTaskPanelId" value="">
                <div class="form-group" style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?= $currentLang === 'en' ? 'Title' : ($currentLang === 'zh' ? '标题' : 'タイトル') ?></label>
                    <input type="text" id="editTaskPanelTitle" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;" maxlength="200">
                </div>
                <div class="form-group" style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?= $currentLang === 'en' ? 'Description' : ($currentLang === 'zh' ? '描述' : '説明') ?></label>
                    <textarea id="editTaskPanelDescription" rows="3" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; resize: vertical;"></textarea>
                </div>
                <div class="form-group" style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?= $currentLang === 'en' ? 'Assignee' : ($currentLang === 'zh' ? '负责人' : '担当者') ?></label>
                    <select id="editTaskPanelAssignee" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                        <option value=""><?= $currentLang === 'en' ? 'Unassigned' : ($currentLang === 'zh' ? '未分配' : '未割当') ?></option>
                    </select>
                </div>
                <div style="display: flex; gap: 12px;">
                    <div class="form-group" style="flex: 1;">
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?= $currentLang === 'en' ? 'Due date' : ($currentLang === 'zh' ? '截止日期' : '期限') ?></label>
                        <input type="date" id="editTaskPanelDueDate" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?= $currentLang === 'en' ? 'Priority' : ($currentLang === 'zh' ? '优先级' : '優先度') ?></label>
                        <select id="editTaskPanelPriority" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                            <option value="0"><?= $currentLang === 'en' ? 'Low' : ($currentLang === 'zh' ? '低' : '低') ?></option>
                            <option value="1" selected><?= $currentLang === 'en' ? 'Medium' : ($currentLang === 'zh' ? '中' : '中') ?></option>
                            <option value="2"><?= $currentLang === 'en' ? 'High' : ($currentLang === 'zh' ? '高' : '高') ?></option>
                            <option value="3"><?= $currentLang === 'en' ? 'Urgent' : ($currentLang === 'zh' ? '紧急' : '緊急') ?></option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 16px 20px; border-top: 1px solid #e2e8f0; display: flex; gap: 12px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" onclick="closeEditTaskPanelModal()" style="padding: 10px 20px; border: 1px solid #e2e8f0; background: white; border-radius: 8px; cursor: pointer;"><?= $currentLang === 'en' ? 'Cancel' : ($currentLang === 'zh' ? '取消' : 'キャンセル') ?></button>
                <button type="button" class="btn btn-primary" onclick="submitEditTaskPanel()" style="padding: 10px 20px; background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;"><?= $currentLang === 'en' ? 'Save' : ($currentLang === 'zh' ? '保存' : '保存') ?></button>
            </div>
        </div>
    </div>

    <!-- 友達申請モーダル（検索結果から呼び出し） -->
    <div class="modal-overlay" id="friendRequestModal">
        <div class="modal" style="max-width: 420px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #10b981, #059669); color: white;">
                <h3>👋 <?= $currentLang === 'en' ? 'Send Friend Request' : ($currentLang === 'zh' ? '发送好友请求' : '友達申請を送る') ?></h3>
                <button class="modal-close" onclick="closeFriendRequestModal()" style="color: white;">×</button>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <div class="fr-modal-user-info" id="frModalUserInfo" style="display: flex; align-items: center; gap: 14px; padding: 14px; background: #f8fafc; border-radius: 10px; margin-bottom: 16px;">
                    <div class="fr-modal-avatar" id="frModalAvatar" style="width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg, #94a3b8, #64748b); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 600; flex-shrink: 0;"></div>
                    <div>
                        <div id="frModalUserName" style="font-size: 16px; font-weight: 600; color: #1e293b;"></div>
                        <div id="frModalUserSub" style="font-size: 12px; color: #64748b; margin-top: 2px;"><?= $currentLang === 'en' ? 'Send a message with your request' : ($currentLang === 'zh' ? '附上消息发送请求' : 'メッセージを添えて申請できます') ?></div>
                    </div>
                </div>
                <input type="hidden" id="frModalTargetUserId" value="">
                <div class="form-group" style="margin-bottom: 0;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 6px;"><?= $currentLang === 'en' ? 'Message (optional)' : ($currentLang === 'zh' ? '附言（可选）' : 'メッセージ（任意）') ?></label>
                    <textarea id="frModalMessage" rows="3" maxlength="500" placeholder="<?= $currentLang === 'en' ? 'Hello! I would like to connect.' : ($currentLang === 'zh' ? '你好！想加你为好友。' : 'よろしくお願いします！') ?>" style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; font-family: inherit; resize: vertical;"></textarea>
                    <div style="text-align: right; font-size: 11px; color: #94a3b8; margin-top: 4px;"><span id="frModalCharCount">0</span>/500</div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 16px 20px; border-top: 1px solid #e2e8f0; display: flex; gap: 12px; justify-content: flex-end;">
                <button class="btn btn-secondary" onclick="closeFriendRequestModal()" style="padding: 10px 20px; border: 1px solid #e2e8f0; background: white; border-radius: 8px; cursor: pointer;"><?= $currentLang === 'en' ? 'Cancel' : ($currentLang === 'zh' ? '取消' : 'キャンセル') ?></button>
                <button class="btn btn-primary" id="frModalSendBtn" onclick="submitFriendRequest()" style="padding: 10px 20px; background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;"><?= $currentLang === 'en' ? 'Send Request' : ($currentLang === 'zh' ? '发送请求' : '申請する') ?></button>
            </div>
        </div>
    </div>