<script>
// ========================================
// scripts.php v2026.02.27.6 - ポーリング: JSON parse安全化+リトライ制御（指数バックオフ+上限停止）
// ========================================
console.log('[scripts.php] Loaded v2026.02.27.6 - ポーリング安全化+リトライ制御');

// 他スクリプト（ai-reply-suggest.js 等）の DOMContentLoaded で参照されるため、最初に設定
window._currentUserId = <?= (int)($_SESSION['user_id'] ?? 0) ?>;

// ========================================
// グローバル escapeHtml 関数（全体で使用）
// ========================================
window.escapeHtml = function(str) {
    if (!str) return '';
    return str.toString()
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};

// ========================================
// グローバル関数プレースホルダ（後で上書きされる）
// ========================================
window.selectAISecretary = window.selectAISecretary || function() {
    console.log('[AI Secretary] Loading...');
};
window.dismissReminder = window.dismissReminder || function() {};
window.markReminderComplete = window.markReminderComplete || function() {};
window.dismissReminderNotification = window.dismissReminderNotification || function() {};

// ========================================
// ファイル表示名編集（鉛筆ボタン用・最優先で定義）
// PDF/Officeファイルカードの✏️クリックで呼ばれる
// ========================================
window.openEditFileDisplayNameModal = function(messageId) {
    console.log('[openEditFileDisplayNameModal] called with messageId:', messageId);
    var card = document.querySelector('[data-message-id="' + messageId + '"]');
    if (!card) { console.warn('[openEditFileDisplayNameModal] card not found:', messageId); alert('メッセージが見つかりません (ID:' + messageId + ')'); return; }
    var fileCard = card.querySelector('.file-attachment-card');
    if (!fileCard) { console.warn('[openEditFileDisplayNameModal] file-attachment-card not found'); alert('ファイルカードが見つかりません'); return; }
    var displayName = fileCard.dataset.fileDisplayName || fileCard.getAttribute('data-file-display-name') || '';
    if (!displayName) {
        var nameEl = fileCard.querySelector('div[style*="font-weight"]');
        if (nameEl && nameEl.textContent) displayName = nameEl.textContent.trim();
    }
    if (!displayName) {
        var link = fileCard.querySelector('a[href]');
        if (link) {
            var href = (link.getAttribute('href') || '');
            displayName = href.split('/').pop() || href.split(/[\\/]/).pop() || 'ファイル';
        }
    }
    if (!displayName) displayName = 'ファイル';
    window._editingFileMessageId = messageId;
    var input = document.getElementById('editFileDisplayNameInput');
    var modal = document.getElementById('editFileDisplayNameModal');
    if (!input || !modal) { console.warn('[openEditFileDisplayNameModal] modal elements not found, input:', !!input, 'modal:', !!modal); alert('モーダル要素が見つかりません'); return; }
    input.value = displayName;
    // CSSクラスとインラインスタイル両方で確実に表示
    modal.classList.add('active');
    modal.style.display = 'flex';
    modal.style.opacity = '1';
    modal.style.visibility = 'visible';
    modal.style.zIndex = '10000';
    console.log('[openEditFileDisplayNameModal] modal activated, display:', modal.style.display);
    setTimeout(function() { input.focus(); input.select(); }, 100);
};

// イベント委譲: 鉛筆・名前を変更ボタンクリックを確実に拾う（onclickが効かない環境用）
document.addEventListener('click', function(e) {
    var btn = e.target.closest ? e.target.closest('[data-edit-file-message-id]') : null;
    if (!btn) return;
    var msgId = parseInt(btn.getAttribute('data-edit-file-message-id'), 10);
    if (!msgId) return;
    e.preventDefault();
    e.stopPropagation();
    if (typeof openEditFileDisplayNameModal === 'function') {
        openEditFileDisplayNameModal(msgId);
    } else {
        console.error('[edit] openEditFileDisplayNameModal not defined');
    }
}, true);

// 保存・閉じる（モーダルのonclickから呼ばれるため必ず先頭で定義）
window.closeEditFileDisplayNameModal = function() {
    var modal = document.getElementById('editFileDisplayNameModal');
    if (modal) {
        modal.classList.remove('active');
        modal.style.display = '';
        modal.style.opacity = '';
        modal.style.visibility = '';
        modal.style.zIndex = '';
    }
    window._editingFileMessageId = null;
};
window.saveFileDisplayNameEdit = async function() {
    var messageId = window._editingFileMessageId;
    if (!messageId) { alert('メッセージが特定できません'); return; }
    var input = document.getElementById('editFileDisplayNameInput');
    var displayName = input ? input.value.trim() : '';
    if (!displayName) { alert('表示名を入力してください'); return; }
    try {
        var res = await fetch('api/messages.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'edit_display_name', message_id: messageId, display_name: displayName })
        });
        var data = await res.json();
        if (data && data.success) {
            window.closeEditFileDisplayNameModal();
            location.reload();
        } else {
            alert(data && data.message ? data.message : '更新に失敗しました');
        }
    } catch (e) {
        console.error('saveFileDisplayNameEdit:', e);
        alert('エラー: ' + (e.message || '通信に失敗しました'));
    }
};

// ========================================
// 自分宛メッセージ通知（着信音・バイブ）設定で種類選択可能
// ========================================
(function() {
    var _messageNotificationCtx = null;
    var _notificationSoundCache = 'default';
    var _callRingtoneCache = 'default';
    var _notificationTriggerPc = 'to_me';
    var _notificationTriggerMobile = 'to_me';
    var _settingsFetched = false;
    
    function isMobileView() {
        return window.innerWidth < 768 || /Mobile|Android|iPhone|iPad|iPod|webOS|BlackBerry|Opera Mini|IEMobile/i.test(navigator.userAgent || '');
    }
    
    function getAudioContext() {
        if (_messageNotificationCtx) return _messageNotificationCtx;
        var Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return null;
        _messageNotificationCtx = new Ctx();
        return _messageNotificationCtx;
    }
    
    function playTone(ctx, freq, startTime, duration, gainVal, type) {
        type = type || 'sine';
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.value = freq;
        osc.type = type;
        gain.gain.setValueAtTime(gainVal, startTime);
        gain.gain.exponentialRampToValueAtTime(0.01, startTime + duration);
        osc.start(startTime);
        osc.stop(startTime + duration);
    }
    
    function playSoundPreset(ctx, preset) {
        var t = ctx.currentTime;
        if (preset === 'default') {
            playTone(ctx, 880, t, 0.12, 0.25, 'sine');
        } else if (preset === 'gentle') {
            playTone(ctx, 523, t, 0.15, 0.2, 'sine');
            playTone(ctx, 659, t + 0.08, 0.12, 0.18, 'sine');
        } else if (preset === 'bright') {
            playTone(ctx, 1047, t, 0.08, 0.22, 'sine');
            playTone(ctx, 1319, t + 0.06, 0.1, 0.2, 'sine');
        } else if (preset === 'classic') {
            playTone(ctx, 660, t, 0.1, 0.24, 'sine');
            playTone(ctx, 880, t + 0.12, 0.14, 0.22, 'sine');
        } else if (preset === 'chime') {
            playTone(ctx, 784, t, 0.06, 0.2, 'sine');
            playTone(ctx, 1047, t + 0.07, 0.06, 0.18, 'sine');
            playTone(ctx, 1319, t + 0.14, 0.08, 0.16, 'sine');
        }
        /* silent: 何も鳴らさない */
    }
    
    function playSoundFileOnce(soundId) {
        if (soundId === 'silent' || !soundId) return;
        var paths = window.__RINGTONE_PATHS;
        if (!paths || !paths[soundId]) return;
        var path = paths[soundId];
        if (!path) return;
        try {
            if (_messageNotificationAudio && !_messageNotificationAudio.ended) {
                _messageNotificationAudio.pause();
                _messageNotificationAudio.currentTime = 0;
            }
            _messageNotificationAudio = null;
            var a = new Audio(path);
            a.loop = false; // メッセージ着信音は1回のみ。繰り返しは通話着信だけ。
            a.addEventListener('ended', function() {
                _messageNotificationAudio = null;
            });
            _messageNotificationAudio = a;
            a.play().catch(function() { _messageNotificationAudio = null; });
        } catch (e) { /* 着信音スキップ */ _messageNotificationAudio = null; }
    }
    
    function invalidateNotificationCache() {
        _settingsFetched = false;
    }
    try {
        var _bc = new BroadcastChannel('social9-settings');
        _bc.onmessage = function(e) {
            if (e.data && e.data.type === 'ringtone_saved') invalidateNotificationCache();
        };
    } catch (ignore) {}
    function getNotificationSettings(callback) {
        if (_settingsFetched) {
            callback({
                notification_sound: _notificationSoundCache,
                call_ringtone: _callRingtoneCache,
                notification_trigger_pc: _notificationTriggerPc,
                notification_trigger_mobile: _notificationTriggerMobile
            });
            return;
        }
        fetch('api/settings.php?action=get')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                _settingsFetched = true;
                var s = data.settings || {};
                _notificationSoundCache = s.notification_sound || 'default';
                _callRingtoneCache = (s.call_ringtone !== undefined && s.call_ringtone !== '') ? s.call_ringtone : 'default';
                _notificationTriggerPc = (s.notification_trigger_pc === 'all' || s.notification_trigger_pc === 'to_me') ? s.notification_trigger_pc : 'to_me';
                _notificationTriggerMobile = (s.notification_trigger_mobile === 'all' || s.notification_trigger_mobile === 'to_me') ? s.notification_trigger_mobile : 'to_me';
                callback({
                    notification_sound: _notificationSoundCache,
                    call_ringtone: _callRingtoneCache,
                    notification_trigger_pc: _notificationTriggerPc,
                    notification_trigger_mobile: _notificationTriggerMobile
                });
            })
            .catch(function() {
                _settingsFetched = true;
                _notificationSoundCache = 'default';
                _callRingtoneCache = 'default';
                _notificationTriggerPc = 'to_me';
                _notificationTriggerMobile = 'to_me';
                callback({
                    notification_sound: 'default',
                    call_ringtone: 'default',
                    notification_trigger_pc: 'to_me',
                    notification_trigger_mobile: 'to_me'
                });
            });
    }
    
    function getNotificationSound(callback) {
        getNotificationSettings(function(s) { callback(s.notification_sound); });
    }
    
    var _lastMessageNotificationAt = 0;
    var _messageNotificationCooldownMs = 2500;
    var _messageNotificationAudio = null; // 再生中のメッセージ着信音（1回だけ鳴らして止める用）
    
    window.playMessageNotification = function() {
        var now = Date.now();
        if (now - _lastMessageNotificationAt < _messageNotificationCooldownMs) return;
        // 既にメッセージ着信音が再生中なら重ねて鳴らさない（1回で止める）
        if (_messageNotificationAudio && !_messageNotificationAudio.ended) return;
        _lastMessageNotificationAt = now;
        getNotificationSound(function(preset) {
            if (preset === 'silent') return;
            var path = window.__RINGTONE_PATHS && window.__RINGTONE_PATHS[preset];
            if (path) {
                playSoundFileOnce(preset);
            } else {
            try {
                var ctx = getAudioContext();
                if (ctx) {
                    var play = function() { playSoundPreset(ctx, preset); };
                    if (ctx.state === 'suspended') {
                        ctx.resume().then(play).catch(function() {});
                    } else if (ctx.state === 'running') {
                        play();
                    }
                }
            } catch (e) { /* 着信音スキップ */ }
            }
            // navigator.vibrate はユーザー操作後にのみ許可される（Chrome Intervention対策）
            var canVibrate = window._userHasInteracted || (navigator.userActivation && navigator.userActivation.hasBeenActive);
            if (navigator.vibrate && canVibrate) {
                try { navigator.vibrate([100, 80, 100]); } catch (v) { }
            }
        });
    };
    
    window.getNotificationSoundSetting = function(callback) {
        getNotificationSound(callback);
    };
    
    window.setNotificationSoundCache = function(value) {
        _notificationSoundCache = value;
        _settingsFetched = true;
    };
    
    window.getNotificationSettings = getNotificationSettings;
    window.checkAndPlayMessageNotification = function(isToMe) {
        getNotificationSettings(function(s) {
            if (s.notification_sound === 'silent') return;
            var mobile = isMobileView();
            var trigger = mobile ? (s.notification_trigger_mobile || 'to_me') : (s.notification_trigger_pc || 'to_me');
            if (trigger === 'none') return;
            if (trigger === 'all' || (trigger === 'to_me' && isToMe)) {
                if (typeof window.playMessageNotification === 'function') {
                    window.playMessageNotification();
                }
            }
        });
        // 携帯: メッセージが届く都度、PC: 自分宛のときのみ、通知許可がオフならホップを表示
        var mobile = isMobileView();
        if ((isToMe || mobile) && typeof PushNotifications !== 'undefined' && typeof PushNotifications.showNotificationPermissionHop === 'function') {
            PushNotifications.showNotificationPermissionHop();
        }
    };
    
    function markUserInteracted() {
        window._userHasInteracted = true;
    }
    function resumeAudioOnInteraction() {
        markUserInteracted();
        var ctx = getAudioContext();
        if (ctx && ctx.state === 'suspended') ctx.resume().catch(function(){});
    }
    document.addEventListener('click', resumeAudioOnInteraction, { once: true });
    document.addEventListener('touchstart', resumeAudioOnInteraction, { once: true, passive: true });
})();

// ========================================
// ファイルパス→メディア変換ヘルパー（翻訳等で使用）
// ========================================
window.processFilePathsInContent = function(text) {
    if (!text) return text;
    
    // 画像パターン
    const imagePatterns = [
        /(uploads\/messages\/[^\s\n<>]+\.(jpg|jpeg|png|gif|webp))/gi,
        /(アップロード[\/\\]メッセージ[\/\\][^\s\n<>]+\.(jpg|jpeg|png|gif|webp))/gi,
        /((?:msg_|screenshot_|スクリーンショット_)[^\s\n<>]+\.(jpg|jpeg|png|gif|webp))/gi
    ];
    
    let result = text;
    let foundPaths = [];
    
    // 画像パスを検出してタグに変換
    for (const pattern of imagePatterns) {
        result = result.replace(pattern, (match, path) => {
            let normalized = path
                .replace(/アップロード[\/\\]/g, 'uploads/')
                .replace(/メッセージ[\/\\]/g, 'messages/')
                .replace(/\\/g, '/');
            if (!normalized.includes('/')) {
                normalized = 'uploads/messages/' + normalized;
            }
            foundPaths.push(normalized);
            return `<img src="${normalized}" loading="lazy" style="max-width:100%;max-height:300px;border-radius:8px;cursor:pointer;" onclick="openMediaViewer('image', '${normalized}', '画像')" onerror="this.onerror=null;this.style.display='none';">`;
        });
    }
    
    // 📷絵文字を削除
    result = result.replace(/📷\s*/g, '');
    
    return result;
};

// ========================================
// Chat.config 初期化（新システム）
// ========================================
if (typeof Chat !== 'undefined' && Chat.config && Chat.config.init) {
    Chat.config.init({
        userId: <?= $user_id ?>,
        displayName: '<?= addslashes($display_name ?? '') ?>',
        conversationId: <?= $selected_conversation_id ?: 'null' ?>,
        conversationType: 'dm',
        lang: '<?= $currentLang ?? 'ja' ?>',
        i18n: {
            showLess: '<?= __('show_less') ?>',
            showMore: '<?= $currentLang === 'en' ? 'Show %d more' : ($currentLang === 'zh' ? '显示其他 %d 项' : '他 %d 件を表示') ?>',
            recentSearch: '<?= __('recent_search') ?>',
            noSearchHistory: '<?= __('no_search_history') ?>',
            searchHint: '<?= __('search_hint') ?>',
            save: '<?= __('save') ?>',
            edit: '<?= __('edit') ?>',
            saving: '<?= __('saving') ?>',
            saved: '<?= __('saved') ?>'
        }
    });
}

// 既読をAPIでも送信（DB永続化の二重化：次回ログイン時も既読を維持）
(function() {
    const cid = <?= $selected_conversation_id ? (int)$selected_conversation_id : 'null' ?>;
    if (cid) {
        fetch('api/conversations.php?action=mark_read&conversation_id=' + cid, { method: 'POST', credentials: 'same-origin' }).catch(function() {});
    }
})();

// ========================================
// チャット内タスク依頼機能
// ========================================

window.openTaskModal = function() {
    const modal = document.getElementById('chatTaskModal');
    if (!modal) {
        alert('タスクモーダルが見つかりません');
        return;
    }
    
    // 会話IDを確認（window.currentConversationId または URL の c パラメータから取得）
    let convId = window.currentConversationId || null;
    if (!convId) {
        const match = (window.location.search || '').match(/[?&]c=(\d+)/);
        convId = match ? parseInt(match[1], 10) : null;
    }
    if (!convId) {
        alert('会話を選択してください');
        return;
    }
    
    // フォームをリセット
    const contentEl = document.getElementById('chatTaskContent');
    const assigneeListEl = document.getElementById('chatTaskAssigneeList');
    const assigneeHintEl = document.getElementById('chatTaskAssigneeHint');
    const dueDateEl = document.getElementById('chatTaskDueDate');
    const priorityEl = document.getElementById('chatTaskPriority');
    
    if (contentEl) contentEl.value = '';
    if (dueDateEl) dueDateEl.value = '';
    if (priorityEl) priorityEl.value = '1';
    if (assigneeHintEl) { assigneeHintEl.style.display = 'none'; assigneeHintEl.textContent = ''; }
    
    const currentUserId = window._currentUserId || (typeof userId !== 'undefined' ? userId : null);
    
    function renderAssigneeCheckboxes(members) {
        if (!assigneeListEl) return;
        assigneeListEl.innerHTML = '';
        const others = members.filter(function(m) { return m.id != currentUserId; });
        if (others.length === 0) {
            assigneeListEl.innerHTML = '<div class="chat-task-assignee-empty">この会話に他のメンバーがいません</div>';
            return;
        }
        const name = function(m) { return m.display_name || m.name || 'ユーザー'; };
        const initial = function(m) { return (name(m) || '?').charAt(0).toUpperCase(); };
        const avatarUrl = function(m) { return m.avatar_path || m.avatar || ''; };
        const onlineClass = function(m) {
            const s = (m.online_status || '').toLowerCase();
            if (s === 'online' || s === 'available') return 'online';
            if (s === 'away' || s === 'busy') return 'away';
            return '';
        };
        const updateHintAndSelected = function() {
            const checked = assigneeListEl.querySelectorAll('.chat-task-assignee-cb:checked');
            assigneeListEl.querySelectorAll('.chat-task-assignee-item').forEach(function(item) {
                const cb = item.querySelector('.chat-task-assignee-cb');
                item.classList.toggle('chat-task-assignee-selected', cb && cb.checked);
            });
            if (assigneeHintEl) {
                if (checked.length > 0) {
                    const names = Array.from(checked).map(function(cb) {
                        const n = cb.closest('.chat-task-assignee-item');
                        if (!n) return '';
                        const nm = n.querySelector('.chat-task-assignee-name');
                        return nm ? nm.textContent.trim() : '';
                    }).filter(Boolean);
                    assigneeHintEl.textContent = '選択中: ' + names.join(', ');
                    assigneeHintEl.style.display = 'block';
                } else {
                    assigneeHintEl.textContent = '';
                    assigneeHintEl.style.display = 'none';
                }
            }
        };
        others.forEach(function(m) {
            const item = document.createElement('label');
            item.className = 'chat-task-assignee-item';
            const avatar = avatarUrl(m);
            const avatarHtml = avatar
                ? '<div class="chat-task-assignee-avatar"><img src="' + escapeHtml(avatar) + '" alt=""><span class="chat-task-assignee-online ' + onlineClass(m) + '"></span></div>'
                : '<div class="chat-task-assignee-avatar">' + escapeHtml(initial(m)) + '<span class="chat-task-assignee-online ' + onlineClass(m) + '"></span></div>';
            const roleHtml = (m.role === 'admin') ? '<span class="chat-task-assignee-role">管理者</span>' : '';
            item.innerHTML = avatarHtml +
                '<div class="chat-task-assignee-info">' +
                '<span class="chat-task-assignee-name">' + escapeHtml(name(m)) + '</span>' + roleHtml +
                '</div>' +
                '<div class="chat-task-assignee-cb-wrap">' +
                '<input type="checkbox" name="chatTaskAssignee" value="' + escapeHtml(String(m.id)) + '" class="chat-task-assignee-cb">' +
                '</div>';
            item.querySelector('.chat-task-assignee-cb').addEventListener('change', updateHintAndSelected);
            assigneeListEl.appendChild(item);
        });
    }
    
    // 作業者（担当者）リストを読み込み（このグループのメンバーのみ）
    if (assigneeListEl) {
        assigneeListEl.innerHTML = '<div class="assignee-loading" style="color: #6b7280; font-size: 13px;">読み込み中...</div>';
        
        const members = window.currentConversationMembers || window.conversationMembers || [];
        if (members.length > 0) {
            renderAssigneeCheckboxes(members);
        } else {
            fetch('api/conversations.php?action=members&conversation_id=' + convId)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.members) {
                        renderAssigneeCheckboxes(data.members);
                    } else {
                        assigneeListEl.innerHTML = '<div style="color: #6b7280; font-size: 13px;">メンバーを取得できませんでした</div>';
                    }
                })
                .catch(err => {
                    console.error('Failed to load members:', err);
                    assigneeListEl.innerHTML = '<div style="color: #dc2626; font-size: 13px;">読み込みエラー</div>';
                });
        }
    }
    
    modal.classList.add('active');
};

window.closeChatTaskModal = function() {
    const modal = document.getElementById('chatTaskModal');
    if (modal) modal.classList.remove('active');
};

// openChatTaskModal は openTaskModal のエイリアス（右パネルから呼び出し用）
window.openChatTaskModal = window.openTaskModal;

// 会話のタスク一覧を読み込んで右パネルに表示
window.loadConversationTasks = async function() {
    const taskListEl = document.getElementById('taskListPanel');
    const noTaskEl = document.getElementById('noTaskText');
    let convId = window.currentConversationId || null;
    if (!convId) {
        const match = (window.location.search || '').match(/[?&]c=(\d+)/);
        convId = match ? parseInt(match[1], 10) : null;
    }
    
    if (!taskListEl) return;
    
    if (!convId) {
        taskListEl.innerHTML = '';
        if (noTaskEl) noTaskEl.style.display = 'block';
        return;
    }
    
    try {
        const response = await fetch('api/tasks.php?action=list&conversation_id=' + convId + '&limit=50');
        const data = await response.json();
        
        if (data.success && data.tasks && data.tasks.length > 0) {
            if (noTaskEl) noTaskEl.style.display = 'none';
            
            const currentUserId = window._currentUserId || (typeof userId !== 'undefined' ? userId : null);
            
            taskListEl.innerHTML = data.tasks.map(function(task) {
                const isCompleted = task.status === 'completed';
                const isOverdue = task.due_date && new Date(task.due_date) < new Date() && !isCompleted;
                const isRequester = task.created_by == currentUserId;
                const isWorker = task.assigned_to == currentUserId;
                
                const statusClass = isCompleted ? 'completed' : (isOverdue ? 'overdue' : '');
                const statusLabel = {
                    'pending': '未着手',
                    'in_progress': '進行中',
                    'completed': '完了',
                    'cancelled': 'キャンセル'
                }[task.status] || task.status;
                
                let roleHtml = '';
                if (isRequester && !isWorker) {
                    roleHtml = '<span class="task-role task-role-requester">依頼者</span>';
                } else if (isWorker && !isRequester) {
                    roleHtml = '<span class="task-role task-role-worker">担当者</span>';
                } else if (isRequester && isWorker) {
                    roleHtml = '<span class="task-role task-role-self">自分</span>';
                }
                
                const dueDateHtml = task.due_date 
                    ? '<span class="task-due ' + (isOverdue ? 'overdue' : '') + '">📅 ' + new Date(task.due_date).toLocaleDateString('ja-JP', {month: 'numeric', day: 'numeric'}) + '</span>'
                    : '';
                
                const completeBtn = !isCompleted && isWorker
                    ? '<button type="button" class="task-complete-btn" onclick="completeTaskFromPanel(' + task.id + ', this)" title="完了">✓</button>'
                    : '';
                const editBtn = '<button type="button" class="task-edit-btn" onclick="editTaskFromPanel(' + task.id + ')" title="編集">✏️</button>';
                const deleteBtn = '<button type="button" class="task-delete-btn" onclick="deleteTaskFromPanel(' + task.id + ', this)" title="削除">🗑️</button>';
                
                return '<div class="task-panel-item ' + statusClass + '" data-task-id="' + task.id + '">' +
                    '<div class="task-panel-header">' +
                        roleHtml +
                        '<span class="task-status task-status-' + task.status + '">' + statusLabel + '</span>' +
                    '</div>' +
                    '<div class="task-panel-title">' + (task.title || '（内容なし）').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>' +
                    '<div class="task-panel-meta">' +
                        dueDateHtml +
                        (task.worker_name ? '<span class="task-worker">👤 ' + task.worker_name.replace(/</g, '&lt;') + '</span>' : '') +
                    '</div>' +
                    '<div class="task-panel-actions">' +
                        completeBtn +
                        editBtn +
                        deleteBtn +
                    '</div>' +
                '</div>';
            }).join('');
            if (typeof updateTaskBadge === 'function') updateTaskBadge();
        } else {
            taskListEl.innerHTML = '';
            if (noTaskEl) noTaskEl.style.display = 'block';
            if (typeof updateTaskBadge === 'function') updateTaskBadge();
        }
    } catch (error) {
        console.error('Failed to load tasks:', error);
        taskListEl.innerHTML = '<div style="color: #dc2626; font-size: 12px;">タスクの読み込みに失敗しました</div>';
    }
};

// タスクを削除する（右パネルから）
window.deleteTaskFromPanel = async function(taskId, btnEl) {
    if (!confirm('<?= $currentLang === "en" ? "Delete this task?" : ($currentLang === "zh" ? "确定删除此任务？" : "このタスクを削除しますか？") ?>')) return;
    if (btnEl) btnEl.disabled = true;
    try {
        const response = await fetch('api/tasks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', task_id: taskId })
        });
        const data = await response.json();
        if (data.success) {
            if (typeof window.loadConversationTasks === 'function') window.loadConversationTasks();
            if (typeof checkNewMessages === 'function') await checkNewMessages();
        } else {
            alert(data.message || '<?= $currentLang === "en" ? "Failed to delete" : ($currentLang === "zh" ? "删除失败" : "削除に失敗しました") ?>');
            if (btnEl) btnEl.disabled = false;
        }
    } catch (error) {
        console.error('Delete task error:', error);
        alert('<?= $currentLang === "en" ? "An error occurred" : ($currentLang === "zh" ? "发生错误" : "エラーが発生しました") ?>');
        if (btnEl) btnEl.disabled = false;
    }
};

// タスクを編集する（右パネルから）— モーダルを開いて更新
window.editTaskFromPanel = async function(taskId) {
    try {
        const res = await fetch('api/tasks.php?action=get&id=' + taskId);
        const data = await res.json();
        if (!data.success || !data.task) {
            alert('<?= $currentLang === "en" ? "Task not found" : ($currentLang === "zh" ? "任务未找到" : "タスクが見つかりません") ?>');
            return;
        }
        const task = data.task;
        const modal = document.getElementById('editTaskPanelModal');
        if (!modal) return;
        document.getElementById('editTaskPanelId').value = task.id;
        document.getElementById('editTaskPanelTitle').value = task.title || '';
        document.getElementById('editTaskPanelDescription').value = task.description || '';
        document.getElementById('editTaskPanelDueDate').value = task.due_date ? task.due_date.substring(0, 10) : '';
        const priorityEl = document.getElementById('editTaskPanelPriority');
        if (priorityEl) priorityEl.value = String(task.priority ?? 1);
        const assigneeEl = document.getElementById('editTaskPanelAssignee');
        if (assigneeEl) {
            await populateEditTaskPanelAssignees(task.conversation_id || window.currentConversationId);
            assigneeEl.value = task.assigned_to ? String(task.assigned_to) : '';
        }
        modal.classList.add('active');
    } catch (error) {
        console.error('Edit task load error:', error);
        alert('<?= $currentLang === "en" ? "Failed to load task" : ($currentLang === "zh" ? "加载任务失败" : "タスクの読み込みに失敗しました") ?>');
    }
};

window.closeEditTaskPanelModal = function() {
    document.getElementById('editTaskPanelModal')?.classList.remove('active');
};

window.submitEditTaskPanel = async function() {
    const taskId = parseInt(document.getElementById('editTaskPanelId').value, 10);
    const title = (document.getElementById('editTaskPanelTitle')?.value || '').trim();
    const description = (document.getElementById('editTaskPanelDescription')?.value || '').trim();
    const dueDate = document.getElementById('editTaskPanelDueDate')?.value || null;
    const priority = parseInt(document.getElementById('editTaskPanelPriority')?.value || '1', 10);
    const assignedTo = document.getElementById('editTaskPanelAssignee')?.value;
    if (!taskId) return;
    try {
        const body = { action: 'update', task_id: taskId };
        if (title !== '') body.title = title;
        if (description !== '') body.description = description;
        body.due_date = dueDate || null;
        body.priority = priority;
        body.assigned_to = assignedTo ? parseInt(assignedTo, 10) : null;
        const response = await fetch('api/tasks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        const data = await response.json();
        if (data.success) {
            window.closeEditTaskPanelModal();
            if (typeof window.loadConversationTasks === 'function') window.loadConversationTasks();
            if (typeof updateTaskBadge === 'function') updateTaskBadge();
            if (typeof checkNewMessages === 'function') await checkNewMessages();
        } else {
            alert(data.message || '<?= $currentLang === "en" ? "Update failed" : ($currentLang === "zh" ? "更新失败" : "更新に失敗しました") ?>');
        }
    } catch (error) {
        console.error('Update task error:', error);
        alert('<?= $currentLang === "en" ? "An error occurred" : ($currentLang === "zh" ? "发生错误" : "エラーが発生しました") ?>');
    }
};

async function populateEditTaskPanelAssignees(conversationId) {
    const sel = document.getElementById('editTaskPanelAssignee');
    if (!sel) return;
    const convId = conversationId || window.currentConversationId;
    const unassignedLabel = '<?= $currentLang === "en" ? "Unassigned" : ($currentLang === "zh" ? "未分配" : "未割当") ?>';
    if (!convId) { sel.innerHTML = '<option value="">' + unassignedLabel + '</option>'; return; }
    try {
        const r = await fetch('api/conversations.php?action=members&conversation_id=' + convId);
        const d = await r.json();
        const list = (d.members != null ? d.members : d) || [];
        if (!Array.isArray(list)) { sel.innerHTML = '<option value="">' + unassignedLabel + '</option>'; return; }
        sel.innerHTML = '<option value="">' + unassignedLabel + '</option>' +
            list.map(function(m) {
                const id = m.id ?? m.user_id;
                const name = (m.display_name || m.name || 'ID:' + id);
                return '<option value="' + id + '">' + (name.replace(/</g, '&lt;')) + '</option>';
            }).join('');
    } catch (e) {
        sel.innerHTML = '<option value="">' + unassignedLabel + '</option>';
    }
}

// タスクを完了にする（右パネルから）
window.completeTaskFromPanel = async function(taskId, btnEl) {
    if (btnEl) btnEl.disabled = true;
    
    try {
        const response = await fetch('api/tasks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'complete', task_id: taskId })
        });
        
        const data = await response.json();
        
        if (data.success) {
            if (typeof window.loadConversationTasks === 'function') window.loadConversationTasks();
            if (typeof updateTaskBadge === 'function') updateTaskBadge();
            if (typeof checkNewMessages === 'function') await checkNewMessages();
        } else {
            alert(data.message || 'エラーが発生しました');
            if (btnEl) btnEl.disabled = false;
        }
    } catch (error) {
        console.error('Complete task error:', error);
        alert('エラーが発生しました');
        if (btnEl) btnEl.disabled = false;
    }
};

window.submitChatTask = async function() {
    const contentEl = document.getElementById('chatTaskContent');
    const assigneeListEl = document.getElementById('chatTaskAssigneeList');
    const dueDateEl = document.getElementById('chatTaskDueDate');
    const priorityEl = document.getElementById('chatTaskPriority');
    
    const content = (contentEl?.value || '').trim();
    const dueDate = dueDateEl?.value || null;
    const priority = parseInt(priorityEl?.value || '1', 10);
    
    const checked = assigneeListEl ? assigneeListEl.querySelectorAll('.chat-task-assignee-cb:checked') : [];
    const assigneeIds = Array.from(checked).map(function(cb) { return parseInt(cb.value, 10); }).filter(function(id) { return id; });
    
    if (!content) {
        alert('タスク内容を入力してください');
        return;
    }
    
    if (assigneeIds.length === 0) {
        alert('作業者（担当者）を1人以上選択してください');
        return;
    }
    
    let convId = window.currentConversationId || null;
    if (!convId) {
        const match = (window.location.search || '').match(/[?&]c=(\d+)/);
        convId = match ? parseInt(match[1], 10) : null;
    }
    if (!convId) {
        alert('会話が選択されていません');
        return;
    }
    
    try {
        const response = await fetch('api/tasks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create',
                description: content,
                assignee_ids: assigneeIds,
                due_date: dueDate,
                priority: priority,
                conversation_id: parseInt(convId, 10),
                post_to_chat: true
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            window.closeChatTaskModal();
            if (typeof window.loadConversationTasks === 'function') window.loadConversationTasks();
            if (typeof updateTaskBadge === 'function') updateTaskBadge();
            if (typeof checkNewMessages === 'function') await checkNewMessages();
            console.log('Task(s) created:', data);
        } else {
            alert(data.message || 'タスクの作成に失敗しました');
        }
    } catch (error) {
        console.error('Task creation error:', error);
        alert('エラーが発生しました: ' + error.message);
    }
};

// 翻訳テキスト（PHP→JS）
        const LANG = {
            showLess: '<?= __('show_less') ?>',
            showMore: '<?= $currentLang === 'en' ? 'Show %d more' : ($currentLang === 'zh' ? '显示其他 %d 项' : '他 %d 件を表示') ?>',
            all: '<?= addslashes(__('all')) ?>',
            unread: '<?= addslashes(__('unread')) ?>',
            group: '<?= addslashes(__('group')) ?>',
            filter_friends: '<?= addslashes(__('filter_friends')) ?>',
            recentSearch: '<?= __('recent_search') ?>',
            noSearchHistory: '<?= __('no_search_history') ?>',
            searchHint: '<?= __('search_hint') ?>',
            searchResults: '<?= $currentLang === 'en' ? 'Search Results' : ($currentLang === 'zh' ? '搜索结果' : '検索結果') ?>',
            searching: '<?= $currentLang === 'en' ? 'Searching...' : ($currentLang === 'zh' ? '搜索中...' : '検索中...') ?>',
            searchAll: '<?= $currentLang === 'en' ? 'All' : ($currentLang === 'zh' ? '全部' : 'すべて') ?>',
            searchNoMatch: '<?= $currentLang === 'en' ? 'No matches' : ($currentLang === 'zh' ? '无匹配' : '該当なし') ?>',
            save: '<?= __('save') ?>',
            edit: '<?= __('edit') ?>',
            saving: '<?= __('saving') ?>',
            saved: '<?= __('saved') ?>'
        };
        
        const userId = <?= $user_id ?>;
        const currentUserDisplayName = (document.body.dataset.displayName || '').trim() || '<?= addslashes($display_name ?? '') ?>';
        let conversationId = <?= $selected_conversation_id ?: 'null' ?>;
        let currentConversationId = conversationId; // グループ設定用にも参照
        window.currentConversationId = conversationId; // 右パネルタスク一覧（loadConversationTasks）用
        let conversationType = 'dm';
        let selectedUsers = [];
        
        // ========== オンライン状態ハートビート ==========
        // 2分ごとにサーバーにハートビートを送信してオンライン状態を維持
        (function initHeartbeat() {
            function sendHeartbeat() {
                fetch('api/friends.php?action=heartbeat').catch(() => {});
            }
            
            // 初回送信
            sendHeartbeat();
            
            // 2分ごとに送信
            setInterval(sendHeartbeat, 2 * 60 * 1000);
            
            // ページ離脱時にオフラインを通知（可能であれば）
            window.addEventListener('beforeunload', function() {
                if (navigator.sendBeacon) {
                    navigator.sendBeacon('api/friends.php', JSON.stringify({ action: 'offline' }));
                }
            });
        })();
        
        // 会話IDをLocalStorageにも保存（JavaScript側で参照用）
        if (conversationId) {
            localStorage.setItem('lastConversationId', conversationId);
        }
        
        // ========== 会話ドラフトの保存・復元 ==========
        const DRAFT_KEY_PREFIX = 'social9_chat_draft_';
        function saveChatDraft(convId) {
            if (!convId) return;
            if (isAISecretaryActive) {
                try { localStorage.removeItem(DRAFT_KEY_PREFIX + convId); } catch (e) {}
                return;
            }
            const input = document.getElementById('messageInput');
            if (!input) return;
            if (input.getAttribute('data-ai-mode') === 'true') return;
            const text = (input.value || '').trim();
            if (isLikelyAISecretaryDraft(text)) return;
            try {
                if (!text) {
                    localStorage.removeItem(DRAFT_KEY_PREFIX + convId);
                    return;
                }
                const draft = { text: input.value };
                localStorage.setItem(DRAFT_KEY_PREFIX + convId, JSON.stringify(draft));
            } catch (e) {}
        }
        function isLikelyAISecretaryDraft(text) {
            if (!text || text.length < 15) return false;
            var t = text.trim();
            if (/\b実行\s*セブン\s*実行/i.test(t)) return true;
            if (/に.*(送っ|送信).*実行\s*$/m.test(t)) return true;
            if (/に.*(送っといて|送信して).*実行/i.test(t)) return true;
            if (t.indexOf('実行') !== -1 && t.indexOf('に') !== -1 && (t.indexOf('送っ') !== -1 || t.indexOf('送信') !== -1) && t.length > 50) return true;
            if (/[\u4e00-\u9fa5\u3040-\u309f\u30a0-\u30ff]{10,}に[\u4e00-\u9fa5\u3040-\u309f\u30a0-\u30ff]+(っていう|という)メッセージを送っ/i.test(t) && t.length > 40) return true;
            return false;
        }
        function restoreChatDraft(convId) {
            if (!convId) return;
            try {
                const saved = localStorage.getItem(DRAFT_KEY_PREFIX + convId);
                if (saved) {
                    const draft = JSON.parse(saved);
                    if (draft.text && isLikelyAISecretaryDraft(draft.text)) {
                        localStorage.removeItem(DRAFT_KEY_PREFIX + convId);
                        return;
                    }
                    const input = document.getElementById('messageInput');
                    if (input && draft.text) {
                        if (isLikelyAISecretaryDraft(draft.text)) {
                            localStorage.removeItem(DRAFT_KEY_PREFIX + convId);
                            return;
                        }
                        input.value = draft.text;
                        if (typeof autoResizeInput === 'function') autoResizeInput(input);
                    }
                }
            } catch (e) {}
        }
        function clearChatDraft(convId) {
            if (!convId) return;
            try { localStorage.removeItem(DRAFT_KEY_PREFIX + convId); } catch (e) {}
        }
        window.clearChatDraft = clearChatDraft;
        // 別ページ（タスク・デザイン・他チャットなど）へ遷移する前にドラフトを保存（CW同様に書きかけを残す）
        function saveDraftOnLeave() {
            if (conversationId) saveChatDraft(conversationId);
        }
        window.addEventListener('beforeunload', saveDraftOnLeave);
        window.addEventListener('pagehide', saveDraftOnLeave);
        window.switchToConversation = function(newConvId) {
            if (conversationId) saveChatDraft(conversationId);
            if (isAISecretaryActive && newConvId) {
                try { localStorage.removeItem(DRAFT_KEY_PREFIX + newConvId); } catch (e) {}
            }
            // 別会話へ移るときに、今の会話を既読にしてからタスクバーバッジを更新し、遷移
            if (conversationId && newConvId && Number(newConvId) !== Number(conversationId)) {
                fetch('api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'mark_read', conversation_id: conversationId })
                }).then(function() {
                    if (typeof PushNotifications !== 'undefined' && typeof PushNotifications.updateBadgeFromServer === 'function') {
                        return PushNotifications.updateBadgeFromServer();
                    }
                }).catch(function() {}).finally(function() {
            location.href = '?c=' + newConvId;
                });
            } else {
                location.href = '?c=' + newConvId;
            }
        };
        /** 検索結果のメッセージをクリックしたとき：会話へ遷移（参加処理は行わない） */
        window.openMessageFromSearch = function(convId, messageId) {
            closeModal('searchModal');
            var url = '?c=' + encodeURIComponent(convId);
            if (messageId) url += '#message-' + encodeURIComponent(String(messageId));
            location.href = url;
        };
        window.openGroupFromSearch = async function(convId, isMember) {
            if (isMember) {
                switchToConversation(convId);
                return;
            }
            try {
                const r = await fetch('api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'admin_join', conversation_id: convId })
                });
                const d = await r.json();
                if (d.success) {
                    closeModal('searchModal');
                    switchToConversation(convId);
                } else {
                    alert(d.error || '参加に失敗しました');
                }
            } catch (e) {
                alert('参加に失敗しました');
            }
        };
        if (conversationId) {
            setTimeout(function() {
                restoreChatDraft(conversationId);
                var inp = document.getElementById('messageInput');
                if (inp && inp.getAttribute('data-ai-mode') !== 'true' && isLikelyAISecretaryDraft(inp.value)) {
                    inp.value = '';
                    if (typeof autoResizeInput === 'function') autoResizeInput(inp);
                    try { localStorage.removeItem(DRAFT_KEY_PREFIX + conversationId); } catch (e) {}
                }
            }, 0);
        }
        
        // ========== タスクカードの空表示対策（クライアント側フォールバック） ==========
        async function fillEmptyTaskCards() {
            if (!conversationId) return;
            const cards = document.querySelectorAll('.task-card[data-task-id]');
            for (const card of cards) {
                const body = card.querySelector('.task-card-body');
                if (!body) continue;
                const text = (body.textContent || '').trim();
                if (text.includes('内容')) continue;
                const taskId = card.dataset.taskId;
                if (!taskId) continue;
                try {
                    const r = await fetch('api/messages.php?action=get_task_detail&task_id=' + taskId + '&conversation_id=' + conversationId, { cache: 'no-store' });
                    const data = await r.json();
                    if (!data.success || !data.task_detail) continue;
                    const td = data.task_detail;
                    const esc = s => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
                    const req = esc(td.requester_name || '').trim() || '（不明）';
                    const wrk = esc(td.worker_name || '').trim() || '（未定）';
                    const title = esc(td.title || '').trim() || '（内容なし）';
                    const header = card.querySelector('.task-card-header');
                    const footer = card.querySelector('.task-card-footer');
                    const isComplete = card.classList.contains('task-card-complete');
                    if (header) {
                        const meta = card.querySelector('.task-card-meta');
                        const metaHtml = isComplete ? '完了者 ' + (wrk || req || '（不明）') : (req || '（未定）') + ' ⇒ ' + (wrk || '（未定）');
                        if (meta) meta.textContent = metaHtml; else {
                            const span = document.createElement('span');
                            span.className = 'task-card-meta';
                            span.textContent = metaHtml;
                            header.appendChild(span);
                        }
                    }
                    body.innerHTML = '<div class="task-card-row task-card-row-content"><span class="task-card-label">内容</span><span class="task-card-value">' + title + '</span></div>';
                    if (footer && td.due_date && !isComplete) {
                        const d = new Date(td.due_date);
                        const ds = d.getFullYear() + '年' + String(d.getMonth()+1).padStart(2,'0') + '月' + String(d.getDate()).padStart(2,'0') + '日';
                        let dl = footer.querySelector('.task-card-deadline');
                        let lbl = footer.querySelector('.task-card-label');
                        if (dl) {
                            dl.textContent = ds;
                        } else {
                            if (!lbl) {
                                lbl = document.createElement('span');
                                lbl.className = 'task-card-label';
                                lbl.textContent = '期限';
                                footer.insertBefore(lbl, footer.firstChild);
                            }
                            dl = document.createElement('span');
                            dl.className = 'task-card-deadline';
                            dl.textContent = ds;
                            footer.insertBefore(dl, lbl ? lbl.nextSibling : footer.firstChild);
                        }
                    }
                } catch (e) {}
            }
        }
        if (conversationId) {
            setTimeout(fillEmptyTaskCards, 100);
        }
        
        // ========== Enterで送信設定の永続化 ==========
        (function initEnterSendSetting() {
            const checkbox = document.getElementById('enterSendCheck');
            if (!checkbox) return;
            
            // LocalStorageから設定を読み込む（デフォルトはtrue）
            const savedSetting = localStorage.getItem('enterSendEnabled');
            if (savedSetting !== null) {
                checkbox.checked = savedSetting === 'true';
            } else {
                // 初回はデフォルトでチェック
                checkbox.checked = true;
            }
        })();
        
        // Enterで送信設定を保存（グローバルに公開）
        window.saveEnterSendSetting = function(isEnabled) {
            localStorage.setItem('enterSendEnabled', isEnabled ? 'true' : 'false');
        };
        
        // ========== 会話リスト更新機能 ==========
        // 新着メッセージ用のポーリング（30秒ごと）
        (function initConversationListPolling() {
            let lastUpdateTime = Date.now();
            
            async function updateConversationList() {
                // ページが非表示の場合はスキップ
                if (!isPageVisible) return;
                
                try {
                    const response = await fetch('api/conversations.php?action=list_with_unread');
                    const text = await response.text();
                    if (!text || text.trim() === '') return;
                    let data;
                    try { data = JSON.parse(text); } catch(e) { return; }
                    
                    if (data.success && data.conversations) {
                        const convList = document.getElementById('conversationList');
                        if (!convList) return;
                        
                        const currentActiveId = document.querySelector('.conv-item.active')?.dataset.convId;
                        
                        data.conversations.forEach((conv, index) => {
                            const convItem = convList.querySelector(`.conv-item[data-conv-id="${conv.id}"]`);
                            if (!convItem) return;
                            
                            const unreadCount = parseInt(conv.unread_count, 10) || 0;
                            convItem.dataset.unread = String(unreadCount);
                            
                            // 未読バッジを更新
                            let unreadBadge = convItem.querySelector('.conv-unread');
                            const isActive = conv.id == currentActiveId;
                            
                            if (unreadCount > 0 && !isActive) {
                                if (!unreadBadge) {
                                    unreadBadge = document.createElement('div');
                                    unreadBadge.className = 'conv-unread';
                                    const meta = convItem.querySelector('.conv-meta');
                                    if (meta) meta.appendChild(unreadBadge);
                                }
                                unreadBadge.textContent = unreadCount > 99 ? '99+' : unreadCount;
                            } else if (unreadBadge) {
                                unreadBadge.remove();
                            }
                            
                            // 時間を更新
                            const timeEl = convItem.querySelector('.conv-time');
                            if (timeEl && conv.time_display) {
                                timeEl.textContent = conv.time_display;
                            }
                            
                            // ピン留め状態を同期
                            convItem.dataset.isPinned = conv.is_pinned ? '1' : '0';
                            if (conv.is_pinned) {
                                convItem.classList.add('is-pinned');
                            } else {
                                convItem.classList.remove('is-pinned');
                            }
                            
                            // 順序を更新（is_pinned を考慮）
                            convItem.style.order = conv.is_pinned ? -1000 + index : index;
                        });
                        
                        // flexbox orderで並び替え
                        convList.style.display = 'flex';
                        convList.style.flexDirection = 'column';
                        
                        // 未読タブ選択中は表示を再フィルタ（data-unread 更新後の反映）
                        if (typeof window.applyLeftPanelFilter === 'function' && window.currentLeftPanelFilter) {
                            window.applyLeftPanelFilter(window.currentLeftPanelFilter);
                        }
                        
                        // タスクバーのアプリバッジも更新
                        if (typeof PushNotifications !== 'undefined' && typeof PushNotifications.updateBadgeFromServer === 'function') {
                            PushNotifications.updateBadgeFromServer();
                        }
                    }
                } catch (e) {
                    console.log('会話リスト更新エラー:', e);
                }
            }
            
            // 動的間隔でポーリング（会話リスト用：メッセージより長い間隔）
            function scheduleConversationListPoll() {
                // getPollingIntervalの3倍の間隔（9秒〜90秒）
                const baseInterval = typeof getPollingInterval === 'function' ? getPollingInterval() : 30000;
                const interval = Math.min(baseInterval * 3, 90000);
                setTimeout(() => {
                    updateConversationList();
                    scheduleConversationListPoll();
                }, interval);
            }
            
            // 初回は5秒後に開始
            setTimeout(scheduleConversationListPoll, 5000);
            
            // グローバルに公開
            window.updateConversationList = updateConversationList;
        })();
        
        // ========== ページ可視状態管理（ポーリング最適化） ==========
        let isPageVisible = !document.hidden;
        let lastUserActivity = Date.now();
        
        // ユーザーアクティビティ追跡（スクロール、クリック、キー入力）
        function trackUserActivity() {
            lastUserActivity = Date.now();
            if (typeof pollConsecutiveErrors !== 'undefined' && pollConsecutiveErrors >= POLL_MAX_ERRORS) {
                pollConsecutiveErrors = 0;
                console.log('ポーリング再開: ユーザー操作を検出');
                if (typeof scheduleNextPoll === 'function') scheduleNextPoll();
            }
        }
        document.addEventListener('mousemove', trackUserActivity, { passive: true });
        document.addEventListener('keydown', trackUserActivity, { passive: true });
        document.addEventListener('scroll', trackUserActivity, { passive: true });
        document.addEventListener('touchstart', trackUserActivity, { passive: true });
        
        // 動的ポーリング間隔（アクティブ時は短く、非アクティブ時は長く）
        function getPollingInterval() {
            const idleTime = Date.now() - lastUserActivity;
            if (idleTime > 120000) return 30000;  // 2分以上操作なし → 30秒間隔
            if (idleTime > 60000) return 15000;   // 1分以上操作なし → 15秒間隔
            if (idleTime > 30000) return 8000;    // 30秒以上操作なし → 8秒間隔
            return 3000;                           // アクティブ → 3秒間隔
        }
        
        document.addEventListener('visibilitychange', function() {
            isPageVisible = !document.hidden;
            if (isPageVisible) {
                // ページが再表示されたら即座にチェック＋アクティビティ更新
                lastUserActivity = Date.now();
                window.checkNewMessages && window.checkNewMessages();
                window.updateConversationList && window.updateConversationList();
            }
        });
        
        // ウィンドウフォーカス時にも即座にチェック（タブ切り替え・スリープ復帰対策）
        window.addEventListener('focus', function() {
            lastUserActivity = Date.now();
            // 少し遅延させて重複実行を防ぐ
            setTimeout(() => {
                window.checkNewMessages && window.checkNewMessages();
                window.updateConversationList && window.updateConversationList();
            }, 100);
        });
        
        // ========== リアルタイムメッセージポーリング ==========
        // 新しいメッセージを確認して追加（バックグラウンドでも継続）
        (function initMessagePolling() {
            if (!conversationId) return;
            
            let lastMessageId = 0;
            let isPolling = false;
            let lastPollTime = 0;
            let pollTimeoutId = null;
            let pollConsecutiveErrors = 0;
            const POLL_MAX_ERRORS = 10;
            const POLL_BACKOFF_BASE = 5000;
            
            // 初期化時に最後のメッセージIDを取得
            const messages = document.querySelectorAll('[data-message-id]');
            if (messages.length > 0) {
                const lastMsg = messages[messages.length - 1];
                lastMessageId = parseInt(lastMsg.dataset.messageId) || 0;
            }
            
            async function checkNewMessages() {
                // 既にポーリング中の場合はスキップ
                if (isPolling || !conversationId) return;
                isPolling = true;
                lastPollTime = Date.now();
                
                try {
                    const url = `api/messages.php?action=get&conversation_id=${conversationId}&after_id=${lastMessageId}&limit=20&_t=${Date.now()}`;
                    const response = await fetch(url, { cache: 'no-store' });
                    
                    if (response.status === 403 || response.status === 404) {
                        if (typeof window.clearInvalidConversationAndRedirect === 'function') window.clearInvalidConversationAndRedirect();
                        isPolling = false;
                        return;
                    }

                    const text = await response.text();
                    if (!text || text.trim() === '') {
                        throw new Error('Empty response from server');
                    }
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (parseErr) {
                        console.error('ポーリング JSON parse error:', text.substring(0, 200));
                        throw new Error('Invalid JSON response');
                    }

                    pollConsecutiveErrors = 0;

                    if (data.success && data.messages && data.messages.length > 0) {
                        const messagesArea = document.getElementById('messagesArea');
                        if (!messagesArea) {
                            isPolling = false;
                            return;
                        }
                        // 過去ログを見ているときはスクロールしない（ユーザーが最下部付近にいる場合のみ最下部へ）
                        const threshold = 200;
                        const wasNearBottom = (messagesArea.scrollHeight - messagesArea.scrollTop - messagesArea.clientHeight) <= threshold;
                        
                        // 今回のポーリングで「新規に1件以上追加した」ときだけ着信音を鳴らす（同じ7件が返り続けると毎回鳴るのを防ぐ）
                        var didAppendAnyNotifiableMessage = false;
                        var appendedAnyToMe = false;
                        
                        data.messages.forEach(msg => {
                            // 既に表示されているメッセージはスキップ
                            if (document.querySelector(`[data-message-id="${msg.id}"]`)) return;
                            
                            // 送信中のメッセージがある場合はスキップ（自分が送ったメッセージの重複を防ぐ）
                            const sendingMessages = document.querySelectorAll('.message-card.sending, .message-card[data-temp="true"]');
                            if (sendingMessages.length > 0 && msg.sender_id == userId) {
                                let isDuplicate = false;
                                sendingMessages.forEach(tempCard => {
                                    const tempContent = tempCard.dataset.content || tempCard.querySelector('.content')?.textContent;
                                    if (tempContent && msg.content && tempContent.trim() === msg.content.trim()) {
                                        isDuplicate = true;
                                        tempCard.dataset.messageId = msg.id;
                                        tempCard.dataset.temp = 'false';
                                        tempCard.classList.remove('sending');
                                        tempCard.classList.add('sent');
                                        const timestampEl = tempCard.querySelector('.timestamp');
                                        if (timestampEl) {
                                            const ts = new Date(msg.created_at);
                                            timestampEl.textContent = ts.toLocaleDateString('ja-JP', {year:'numeric',month:'numeric',day:'numeric'}) + ' ' + ts.toLocaleTimeString('ja-JP', {hour:'2-digit',minute:'2-digit'});
                                        }
                                        // [TO_POLL_DEBUG] ポーリングが一時カードを上書き
                                        if (/\[To:/.test(msg.content || '')) {
                                            console.log('[TO_POLL_DEBUG] Polling found temp card for msg.id=' + msg.id + ' → isDuplicate=true, temp card KEPT (no chip conversion!)');
                                            console.log('[TO_POLL_DEBUG] tempCard .content innerHTML:', tempCard.querySelector('.content')?.innerHTML?.substring(0, 200));
                                        }
                                    }
                                });
                                if (isDuplicate) return;
                            }
                            
                            var isOwn = msg.sender_id == userId;
                            var isSystem = msg.message_type === 'system';
                            if (!isOwn && !isSystem) {
                                didAppendAnyNotifiableMessage = true;
                                var toMe = msg.is_mentioned_me || msg.show_to_all_badge ||
                                    (Array.isArray(msg.to_member_ids_list) && msg.to_member_ids_list.some(function(id) { return id == userId; }));
                                if (toMe) appendedAnyToMe = true;
                            }
                            
                            // メッセージをUIに追加（着信音はループ後に1回だけ鳴らすため skipNotification: true）
                            appendMessageToUI(msg, true);
                            
                            if (typeof fillEmptyTaskCards === 'function') setTimeout(fillEmptyTaskCards, 50);
                            if (msg.id > lastMessageId) lastMessageId = msg.id;
                        });
                        
                        // 今回のポーリングで新規に1件以上追加したときだけ着信音を1回鳴らす（同じデータが返るたびに鳴り続けるのを防止）
                        if (didAppendAnyNotifiableMessage && typeof window.checkAndPlayMessageNotification === 'function') {
                            window.checkAndPlayMessageNotification(appendedAnyToMe);
                        }
                        
                        // 過去ログ閲覧中でなければ最下部にスクロール
                        if (wasNearBottom) {
                        messagesArea.scrollTop = messagesArea.scrollHeight;
                        }
                        
                        // 既読を更新（会話リストのバッジ更新）
                        window.updateConversationList && window.updateConversationList();
                        
                        // タスクバーのアプリバッジも更新
                        if (typeof PushNotifications !== 'undefined' && typeof PushNotifications.updateBadgeFromServer === 'function') {
                            PushNotifications.updateBadgeFromServer();
                        }
                    }
                } catch (error) {
                    pollConsecutiveErrors++;
                    if (pollConsecutiveErrors <= 3) {
                        console.warn('メッセージポーリングエラー (' + pollConsecutiveErrors + '/' + POLL_MAX_ERRORS + '):', error.message || error);
                    }
                    if (pollConsecutiveErrors >= POLL_MAX_ERRORS) {
                        console.error('ポーリング停止: 連続' + POLL_MAX_ERRORS + '回エラー。ページを操作すると再開します。');
                    }
                }
                
                isPolling = false;
            }
            
            /**
             * 本文を表示用HTMLに変換（[To:ID]→チップ、改行→br、URLリンク化）
             */
            function getContentDisplayHtml(content) {
                if (!content) return '';
                var memberMap = {};
                (window.currentConversationMembers || []).forEach(function(m) {
                    memberMap[m.id] = { display_name: m.display_name || m.name, avatar_path: m.avatar_path || m.avatar };
                });
                var html = contentWithToChips(content, memberMap).replace(/\n/g, '<br>');
                return html.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer" style="color:var(--primary);text-decoration:underline;">$1</a>');
            }
            /**
             * 本文中の [To:ID]名前 / [To:all]全員 をボタン風TOチップHTMLに変換（送信後の表示用）
             */
            function contentWithToChips(content, memberMap) {
                if (!content || typeof content !== 'string') return '';
                memberMap = memberMap || {};
                var out = '';
                // 名前部分は次の [To: または改行まで（[^\n[]*）。連続 [To:9]名1[To:6]名2 を複数チップに分ける
                var re = /\[To:(all|\d+)\]([^\n[]*)/gi;
                var lastIdx = 0;
                var m;
                while ((m = re.exec(content)) !== null) {
                    out += escapeHtml(content.substring(lastIdx, m.index));
                    var id = m[1];
                    var namePart = (m[2] || '').trim();
                    var displayName;
                    if (String(id).toLowerCase() === 'all') {
                        displayName = namePart || '全員';
                    } else {
                        displayName = namePart || (memberMap[id] && (memberMap[id].display_name || memberMap[id].name)) || ('ID:' + id);
                    }
                    out += '<b data-to="' + escapeHtml(id) + '" style="display:inline-block;background:#7cb342;color:#fff;padding:1px 8px;border-radius:4px;font-size:12px;font-weight:600;margin:2px 4px 2px 0;vertical-align:middle;line-height:1.6">TO ' + escapeHtml(displayName) + '</b>';
                    lastIdx = m.index + m[0].length;
                }
                out += escapeHtml(content.substring(lastIdx));
                return out;
            }
            window.contentWithToChips = contentWithToChips;
            // メッセージをUIに追加する関数（skipNotification: true のときは着信音を鳴らさない。ポーリングで複数追加時は呼び出し側で1回だけ鳴らす）
            function appendMessageToUI(msg, skipNotification) {
                if (isAISecretaryActive) return;
                const messagesArea = document.getElementById('messagesArea');
                if (!messagesArea) return;
                
                const isOwn = msg.sender_id == userId;
                const isMentioned = msg.is_mentioned_me;
                const isSystem = msg.message_type === 'system';
                
                const timestamp = new Date(msg.created_at);
                const timeStr = timestamp.toLocaleDateString('ja-JP', { year: 'numeric', month: 'numeric', day: 'numeric' }) + ' ' + 
                               timestamp.toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
                
                let messageHtml = '';
                
                if (isSystem) {
                    // タスク関連のシステムメッセージ: contentに📋/✅、またはtask_id/task_detailがあればカード表示
                    const hasTaskEmoji = msg.content && (msg.content.includes('📋') || msg.content.includes('✅'));
                    const hasTaskRef = msg.task_id || msg.task_detail;
                    const isTaskMessage = hasTaskEmoji || hasTaskRef;
                    
                    if (isTaskMessage) {
                        // タスクメッセージを解析して横長カード形式のHTMLに変換
                        const raw = (msg.content || '').trim();
                        const lines = raw.split(/\r?\n/).filter(l => l.trim());
                        const headerLine = lines[0] || '';
                        const isComplete = headerLine.includes('✅');
                        let headerTitle = headerLine.replace(/^[📋✅\s*]+/g, '').replace(/[\s*]+$/g, '').replace(/[📋✅]/g, '').trim();
                        headerTitle = headerTitle || (isComplete ? 'タスク完了' : 'タスク依頼');
                        const colonOrFullwidth = /[:\uFF1A]/;
                        const parsed = {};
                        for (let i = 1; i < lines.length; i++) {
                            const line = lines[i];
                            const m = line.match(/^\*\*(.+?)\*\*[:\uFF1A]\s*(.*)$/);
                            if (m) {
                                parsed[m[1].trim()] = m[2].trim();
                            } else {
                                const colonMatch = line.match(colonOrFullwidth);
                                const colonIdx = colonMatch ? line.indexOf(colonMatch[0]) : -1;
                                if (colonIdx >= 0) {
                                    const lbl = line.slice(0, colonIdx).replace(/\*\*/g, '').trim();
                                    if (lbl) parsed[lbl] = line.slice(colonIdx + colonMatch[0].length).trim();
                                }
                            }
                        }
                        let headerMeta = '';
                        let bodyContent = '';
                        let footerDeadline = '';
                        if (Object.keys(parsed).length > 0) {
                            const req = escapeHtml(parsed['依頼者'] || '');
                            const wrk = escapeHtml(parsed['担当者'] || '');
                            const compl = escapeHtml(parsed['完了者'] || '');
                            const title = escapeHtml(parsed['内容'] || '（内容なし）');
                            const due = parsed['期限'] || '';
                            if (isComplete) {
                                headerMeta = compl ? '完了者 ' + compl : '';
                                bodyContent = `<div class="task-card-row task-card-row-content"><span class="task-card-label">内容</span><span class="task-card-value">${title}</span></div>`;
                            } else {
                                headerMeta = (req || wrk) ? (req || '（未定）') + ' ⇒ ' + (wrk || '（未定）') : '';
                                bodyContent = `<div class="task-card-row task-card-row-content"><span class="task-card-label">内容</span><span class="task-card-value">${title}</span></div>`;
                                if (due) footerDeadline = escapeHtml(due);
                            }
                        } else if (msg.task_detail) {
                            const td = msg.task_detail;
                            const title = escapeHtml((td.title || '').trim() || '（内容なし）');
                            if (isComplete) {
                                const completerName = escapeHtml((td.worker_name || td.requester_name || '').trim() || '（不明）');
                                headerMeta = '完了者 ' + completerName;
                                bodyContent = `<div class="task-card-row task-card-row-content"><span class="task-card-label">内容</span><span class="task-card-value">${title}</span></div>`;
                            } else {
                                const reqName = escapeHtml((td.requester_name || '').trim() || '（不明）');
                                const workerName = escapeHtml((td.worker_name || '').trim() || '（未定）');
                                headerMeta = reqName + ' ⇒ ' + workerName;
                                bodyContent = `<div class="task-card-row task-card-row-content"><span class="task-card-label">内容</span><span class="task-card-value">${title}</span></div>`;
                                if (td.due_date) {
                                    const d = new Date(td.due_date);
                                    const y = d.getFullYear(), m = String(d.getMonth() + 1).padStart(2, '0'), day = String(d.getDate()).padStart(2, '0');
                                    footerDeadline = y + '年' + m + '月' + day + '日';
                                }
                            }
                        } else if (lines.length > 1) {
                            const fallbackContent = lines.slice(1).map(l => escapeHtml(l).replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')).join('<br>');
                            bodyContent = `<div class="task-card-fallback">${fallbackContent}</div>`;
                        } else {
                            const fullFormatted = escapeHtml(raw).replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>');
                            bodyContent = `<div class="task-card-fallback">${fullFormatted.replace(/^[📋✅]\s*/, '')}</div>`;
                        }
                        const cardClass = 'system-message task-system-message task-card' + (isComplete ? ' task-card-complete' : '');
                        const td = msg.task_detail || {};
                        const reqId = td.requester_id != null ? parseInt(td.requester_id, 10) : 0;
                        const wrkId = td.worker_id != null ? parseInt(td.worker_id, 10) : 0;
                        const senderId = msg.sender_id != null ? parseInt(msg.sender_id, 10) : 0;
                        const canDel = (reqId && reqId === userId) || (wrkId && wrkId === userId) || (senderId && senderId === userId);
                        const delBtn = (canDel && msg.task_id) ? `<button type="button" class="task-card-delete-btn" onclick="deleteTaskDisplay(${msg.task_id}, this)" title="タスク表示を削除">🗑️</button>` : '';
                        const headerMetaHtml = headerMeta ? `<span class="task-card-meta">${headerMeta}</span>` : '';
                        const footerDeadlineHtml = footerDeadline ? `<span class="task-card-label">期限</span><span class="task-card-deadline">${footerDeadline}</span>` : '';
                        messageHtml = `
                            <div class="${cardClass}" data-message-id="${msg.id}" ${msg.task_id ? 'data-task-id="' + msg.task_id + '"' : ''}>
                                <div class="task-card-header">
                                    <span class="task-card-label task-card-title">${escapeHtml(headerTitle)}</span>
                                    <span class="task-card-posted">（${timeStr}）</span>
                                    ${headerMetaHtml}
                                </div>
                                <div class="task-card-body">${bodyContent}</div>
                                <div class="task-card-footer">${footerDeadlineHtml}${delBtn}</div>
                            </div>
                        `;
                    } else {
                        let formattedContent = escapeHtml(msg.content || '');
                        formattedContent = formattedContent.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
                        formattedContent = formattedContent.replace(/\n/g, '<br>');
                        messageHtml = `
                            <div class="system-message" data-message-id="${msg.id}">
                                <span class="system-message-content">${formattedContent}</span>
                                <span class="system-message-time">${timeStr}</span>
                            </div>
                        `;
                    }
                } else {
                    // 他人のメッセージは送信者名のみ表示。To宛先は本文中の緑のTOチップで表示（グレーのTo行は廃止）
                    const senderName = escapeHtml(msg.sender_name || '');
                    let chatLabel = isOwn ? '' : (senderName ? `<div class="from-label message-sender-to-line"><span class="from-name">${senderName}</span></div>` : '');
                    
                    // 画像・動画・PDFの処理
                    let contentHtml = '';
                    const content = msg.content || '';
                    // 翻訳キャッシュがあれば表示に使い、APIを再呼びしない
                    var displayLangForConv = '<?= $currentLang ?? 'ja' ?>';
                    var useCachedTranslation = !!(msg.cached_translation && displayLangForConv !== 'ja');
                    var displayContent = useCachedTranslation ? (msg.cached_translation || content) : content;
                    // [To:ID]→TOチップ変換用のメンバーマップ（画像・ファイル付きメッセージの「ファイル前テキスト」でもToを表示するため）
                    var memberMapForContent = {};
                    (window.currentConversationMembers || []).forEach(function(m) {
                        memberMapForContent[m.id] = { display_name: m.display_name || m.name, avatar_path: m.avatar_path || m.avatar };
                    });
                    function formatTextContentWithToChips(text) {
                        if (!text) return '';
                        var withChips = contentWithToChips(text, memberMapForContent);
                        return withChips.replace(/\n/g, '<br>').replace(
                            /(https?:\/\/[^\s<]+)/g,
                            '<a href="$1" target="_blank" rel="noopener noreferrer" style="color:var(--primary);text-decoration:underline;">$1</a>'
                        );
                    }
                    /** 本文中に既に [To:ID] または To 名前 があれば true（先頭のToチップは出さない） */
                    function hasToInContent(text) {
                        if (!text || typeof text !== 'string') return false;
                        return /\[To:(?:\d+|all)\]/i.test(text) || /(?:^|\n)\s*To\s+[^\n]+/i.test(text);
                    }
                    /** メッセージのメンション（to_info / show_to_all_badge / to_member_ids_list）からTOチップHTMLを生成。本文に[To:ID]が無い場合に表示 */
                    function buildToChipsFromMentionIds(message, memberMap) {
                        if (!message || !memberMap) return '';
                        var showAll = message.show_to_all_badge || (message.to_info && message.to_info.type === 'to_all');
                        var ids = message.to_member_ids_list || (message.to_info && message.to_info.user_ids) || [];
                        if (showAll) {
                            var namePart = (message.to_info && message.to_info.users && message.to_info.users[0]) ? message.to_info.users[0] : '全員';
                            return '<b data-to="all" style="display:inline-block;background:#7cb342;color:#fff;padding:1px 8px;border-radius:4px;font-size:12px;font-weight:600;margin:2px 4px 2px 0;vertical-align:middle;line-height:1.6">TO ' + escapeHtml(String(namePart)) + '</b>';
                        }
                        if (!Array.isArray(ids) || ids.length === 0) return '';
                        var chips = [];
                        for (var i = 0; i < ids.length; i++) {
                            var id = ids[i];
                            var key = id === 'all' ? 'all' : String(id);
                            var m = memberMap[key] || memberMap[id];
                            var name = (m && (m.display_name || m.name)) ? escapeHtml(String(m.display_name || m.name)) : ('ID:' + key);
                            chips.push('<b data-to="' + escapeHtml(key) + '" style="display:inline-block;background:#7cb342;color:#fff;padding:1px 8px;border-radius:4px;font-size:12px;font-weight:600;margin:2px 4px 2px 0;vertical-align:middle;line-height:1.6">TO ' + name + '</b>');
                        }
                        return chips.join(' ');
                    }
                    
                    // 画像ファイルのパスをチェック（アップロード済みパスのみ）
                    const imageMatch = content.match(/(uploads\/messages\/[^\s\n]+\.(jpg|jpeg|png|gif|webp))/i) ||
                                       content.match(/(アップロード[\/\\]メッセージ[\/\\][^\s\n]+\.(jpg|jpeg|png|gif|webp))/i);
                    // 動画ファイルのパスをチェック（アップロード済みパスのみ）
                    const videoMatch = content.match(/(uploads\/messages\/[^\s\n]+\.(mp4|webm|ogg))/i) ||
                                       content.match(/(アップロード[\/\\]メッセージ[\/\\][^\s\n]+\.(mp4|webm|ogg))/i);
                    // PDFファイルのパスをチェック（アップロード済みパスのみ。本文中の参照はファイル扱いしない）
                    const pdfMatch = content.match(/(?:📄)?\s*(uploads\/messages\/[^\s\n]+\.pdf)/i) ||
                                     content.match(/(uploads\/messages\/[^\s\n]+\.pdf)/i) ||
                                     content.match(/(アップロード[\/\\]メッセージ[\/\\][^\s\n]+\.pdf)/i);
                    // Office/ドキュメントファイルのパスをチェック（アップロード済みパスのみ）
                    const officeMatch = content.match(/(uploads\/messages\/[^\s\n]+\.(docx?|xlsx?|pptx?))/i) ||
                                        content.match(/[📊📝📽️📎]?\s*(uploads\/messages\/[^\s\n]+\.(docx?|xlsx?|pptx?))/i) ||
                                        content.match(/(アップロード[\/\\]メッセージ[\/\\][^\s\n]+\.(docx?|xlsx?|pptx?))/i);
                    // 音声ファイルのパスをチェック（アップロード済みパスのみ）
                    const audioMatch = content.match(/(?:🎵)?\s*(uploads\/messages\/[^\s\n]+\.(mp3|wav|ogg|m4a))/i) ||
                                       content.match(/(uploads\/messages\/[^\s\n]+\.(mp3|wav|ogg|m4a))/i) ||
                                       content.match(/(アップロード[\/\\]メッセージ[\/\\][^\s\n]+\.(mp3|wav|ogg|m4a))/i);
                    // 圧縮ファイルのパスをチェック（アップロード済みパスのみ）
                    const archiveMatch = content.match(/(?:📦)?\s*(uploads\/messages\/[^\s\n]+\.(zip|rar|7z))/i) ||
                                         content.match(/(uploads\/messages\/[^\s\n]+\.(zip|rar|7z))/i) ||
                                         content.match(/(アップロード[\/\\]メッセージ[\/\\][^\s\n]+\.(zip|rar|7z))/i);
                    // テキスト/コードファイルのパスをチェック（アップロード済みパスのみ）
                    const textFileMatch = content.match(/(?:📃)?\s*(uploads\/messages\/[^\s\n]+\.(txt|csv|json|xml|html|css|js))/i) ||
                                          content.match(/(uploads\/messages\/[^\s\n]+\.(txt|csv|json|xml|html|css|js))/i) ||
                                          content.match(/(アップロード[\/\\]メッセージ[\/\\][^\s\n]+\.(txt|csv|json|xml|html|css|js))/i);
                    // 外部GIF URL（GIPHY, Tenor等）をチェック
                    const externalGifMatch = content.match(/^(https?:\/\/[^\s]+\.(gif|gifv))$/i) || 
                                             content.match(/^(https?:\/\/media\.giphy\.com\/[^\s]+)$/i) ||
                                             content.match(/^(https?:\/\/[^\s]*tenor[^\s]*\.(gif|mp4))$/i) ||
                                             content.match(/^(https?:\/\/[^\s]+\/giphy\.(gif|mp4))$/i);
                    
                    // パスを正規化する関数（日本語パス→英語パス、ファイル名補完）
                    function normalizeFilePath(path) {
                        if (!path) return path;
                        // 日本語パスを英語に変換
                        let normalized = path
                            .replace(/アップロード[\/\\]/g, 'uploads/')
                            .replace(/メッセージ[\/\\]/g, 'messages/')
                            .replace(/\\/g, '/');
                        
                        // ファイル名のみの場合はパスを追加
                        if (!normalized.includes('/')) {
                            normalized = 'uploads/messages/' + normalized;
                        }
                        
                        return normalized;
                    }
                    
                    // ファイル添付メッセージで、画像/動画等の前に付いたテキストを抽出
                    function getTextBeforeFile(content, fileMatch) {
                        if (!fileMatch || !content) return '';
                        const matched = fileMatch[1] || fileMatch[0];
                        const idx = content.indexOf(matched);
                        if (idx <= 0) return '';
                        let text = content.substring(0, idx)
                            .replace(/\n\s*[📷🎬📄🎵📦📎📊📝📽️]\s*$/, '')
                            .trim();
                        return text;
                    }
                    
                    function formatTextContent(text) {
                        if (!text) return '';
                        return escapeHtml(text).replace(/\n/g, '<br>').replace(
                            /(https?:\/\/[^\s<]+)/g,
                            '<a href="$1" target="_blank" rel="noopener noreferrer" style="color:var(--primary);text-decoration:underline;">$1</a>'
                        );
                    }
                    
                    let hasEditableFile = false;
                    if (externalGifMatch) {
                        // 外部GIF URLを画像として表示
                        const gifUrl = externalGifMatch[1];
                        contentHtml = `<img src="${escapeHtml(gifUrl)}" loading="lazy" style="max-width:100%;max-height:250px;border-radius:12px;cursor:pointer;display:block;" onclick="openMediaViewer('image', '${escapeHtml(gifUrl)}', 'GIF')" onerror="this.onerror=null;this.style.display='none';this.parentElement.innerHTML='<a href=\\'${escapeHtml(gifUrl)}\\' target=\\'_blank\\'>GIFを開く</a>';">`;
                    } else if (imageMatch) {
                        let imagePath = normalizeFilePath(imageMatch[1]);
                        if (!imagePath.startsWith('uploads/') && !imagePath.startsWith('http')) {
                            imagePath = 'uploads/messages/' + imagePath;
                        }
                        const textBefore = getTextBeforeFile(content, imageMatch);
                        const imageHtml = `<img src="${escapeHtml(imagePath)}" loading="lazy" style="max-width:100%;max-height:300px;border-radius:8px;cursor:pointer;" onclick="openMediaViewer('image', '${escapeHtml(imagePath)}', '画像')" onerror="this.onerror=null;this.style.display='block';this.style.background='#f0f0f0';this.style.padding='20px';this.alt='画像を読み込めません';">`;
                        contentHtml = textBefore ? (formatTextContentWithToChips(textBefore) + '<br>' + imageHtml) : imageHtml;
                        var toChipsImg = buildToChipsFromMentionIds(msg, memberMapForContent);
                        if (toChipsImg && !hasToInContent(content)) contentHtml = toChipsImg + '<br>' + contentHtml;
                    } else if (videoMatch) {
                        let videoPath = normalizeFilePath(videoMatch[1]);
                        if (!videoPath.startsWith('uploads/') && !videoPath.startsWith('http')) {
                            videoPath = 'uploads/messages/' + videoPath;
                        }
                        const textBefore = getTextBeforeFile(content, videoMatch);
                        const videoHtml = `<video src="${escapeHtml(videoPath)}" controls style="max-width:100%;max-height:300px;border-radius:8px;"></video>`;
                        contentHtml = textBefore ? (formatTextContentWithToChips(textBefore) + '<br>' + videoHtml) : videoHtml;
                        var toChipsVid = buildToChipsFromMentionIds(msg, memberMapForContent);
                        if (toChipsVid && !hasToInContent(content)) contentHtml = toChipsVid + '<br>' + contentHtml;
                    } else if (pdfMatch) {
                        hasEditableFile = true;
                        const pdfPath = normalizeFilePath(pdfMatch[1]);
                        let pdfFileName = pdfPath.split('/').pop();
                        const displayNameMatch = content.match(/[📄📷📬📝📊📽️📎🎵📦📃]\s*([^\n]+)\n\s*(?:uploads[\/\\]messages[\/\\][^\s\n]+\.pdf)/i);
                        if (displayNameMatch && displayNameMatch[1] && displayNameMatch[1].indexOf('uploads') === -1) {
                            pdfFileName = displayNameMatch[1].trim();
                        }
                        const textBeforePdf = getTextBeforeFile(content, pdfMatch);
                        const pdfCardHtml = `<div class="file-attachment-card" data-file-path="${escapeHtml(pdfPath)}" data-file-display-name="${escapeHtml(pdfFileName)}" style="background:var(--bg-main);padding:12px;border-radius:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;"><span style="font-size:24px;">📄</span><div style="flex:1;min-width:0;overflow:hidden;padding:4px 0;"><div style="font-weight:500;word-break:break-word;">${escapeHtml(pdfFileName)}</div><div style="font-size:11px;color:var(--text-light);">PDF ドキュメント</div></div><a href="${escapeHtml(pdfPath)}" target="_blank" style="background:var(--bg-hover);color:var(--text);border:none;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:12px;flex-shrink:0;">開く</a><button onclick="openMediaViewer('pdf', '${escapeHtml(pdfPath)}', 'PDF')" style="background:var(--primary);color:white;border:none;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px;flex-shrink:0;">プレビュー</button>${isOwn ? `<button type="button" class="js-edit-file-display-name" data-edit-file-message-id="${msg.id}" onclick="openEditFileDisplayNameModal(${msg.id}); return false;" title="名前を変更" style="background:none;color:var(--text-light);border:none;padding:4px 6px;border-radius:4px;cursor:pointer;font-size:14px;flex-shrink:0;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text-light)'">✏️</button>` : ''}</div>`;
                        contentHtml = textBeforePdf ? (formatTextContentWithToChips(textBeforePdf) + '<br>' + pdfCardHtml) : pdfCardHtml;
                        var toChipsPdf = buildToChipsFromMentionIds(msg, memberMapForContent);
                        if (toChipsPdf && !hasToInContent(content)) contentHtml = toChipsPdf + '<br>' + contentHtml;
                    } else if (officeMatch) {
                        hasEditableFile = true;
                        // Officeファイル（Excel, Word, PowerPoint）
                        let filePath = normalizeFilePath(officeMatch[1]);
                        if (!filePath.startsWith('uploads/') && !filePath.startsWith('http')) {
                            filePath = 'uploads/messages/' + filePath;
                        }
                        let fileName = filePath.split('/').pop();
                        const officeDnMatch = content.match(/[📄📷📬📝📊📽️📎🎵📦📃]\s*([^\n]+)\n\s*(?:uploads[\/\\]messages[\/\\][^\s\n]+\.(?:docx?|xlsx?|pptx?))/i);
                        if (officeDnMatch && officeDnMatch[1] && officeDnMatch[1].indexOf('uploads') === -1) {
                            fileName = officeDnMatch[1].trim();
                        }
                        const ext = filePath.split('.').pop().toLowerCase();
                        let icon = '📎';
                        let typeName = 'ファイル';
                        if (['xls', 'xlsx'].includes(ext)) { icon = '📊'; typeName = 'Excel'; }
                        else if (['doc', 'docx'].includes(ext)) { icon = '📝'; typeName = 'Word'; }
                        else if (['ppt', 'pptx'].includes(ext)) { icon = '📽️'; typeName = 'PowerPoint'; }
                        const officeEditBtn = isOwn ? `<button type="button" class="js-edit-file-display-name" data-edit-file-message-id="${msg.id}" onclick="openEditFileDisplayNameModal(${msg.id}); return false;" title="名前を変更" style="background:none;color:var(--text-light);border:none;padding:4px 6px;border-radius:4px;cursor:pointer;font-size:14px;flex-shrink:0;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text-light)'">✏️</button>` : '';
                        const officeCardHtml = `<div class="file-attachment-card" data-file-path="${escapeHtml(filePath)}" data-file-display-name="${escapeHtml(fileName)}" style="background:var(--bg-main);padding:12px;border-radius:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <span style="font-size:28px;">${icon}</span>
                            <div style="flex:1;min-width:0;overflow:hidden;padding:4px 0;"><div style="font-weight:500;word-break:break-word;">${escapeHtml(fileName)}</div><div style="font-size:12px;color:var(--text-light);">${typeName}ファイル</div></div>
                            <a href="${escapeHtml(filePath)}" download="${escapeHtml(fileName)}" style="padding:6px 12px;background:var(--primary);color:white;border-radius:6px;text-decoration:none;font-size:13px;">ダウンロード</a>
                            ${officeEditBtn}
                        </div>`;
                        const textBeforeOffice = getTextBeforeFile(content, officeMatch);
                        contentHtml = textBeforeOffice ? (formatTextContentWithToChips(textBeforeOffice) + '<br>' + officeCardHtml) : officeCardHtml;
                        var toChipsOffice = buildToChipsFromMentionIds(msg, memberMapForContent);
                        if (toChipsOffice && !hasToInContent(content)) contentHtml = toChipsOffice + '<br>' + contentHtml;
                    } else if (audioMatch) {
                        // 音声ファイル
                        let filePath = normalizeFilePath(audioMatch[1]);
                        if (!filePath.startsWith('uploads/') && !filePath.startsWith('http')) {
                            filePath = 'uploads/messages/' + filePath;
                        }
                        contentHtml = `<div class="file-attachment-card" style="background:var(--bg-main);padding:12px;border-radius:8px;">
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                                <span style="font-size:24px;">🎵</span>
                                <span style="font-weight:500;">音声ファイル</span>
                            </div>
                            <audio src="${escapeHtml(filePath)}" controls style="width:100%;"></audio>
                        </div>`;
                    } else if (archiveMatch) {
                        // 圧縮ファイル
                        let filePath = normalizeFilePath(archiveMatch[1]);
                        if (!filePath.startsWith('uploads/') && !filePath.startsWith('http')) {
                            filePath = 'uploads/messages/' + filePath;
                        }
                        const fileName = filePath.split('/').pop();
                        contentHtml = `<div class="file-attachment-card" style="background:var(--bg-main);padding:12px;border-radius:8px;display:flex;align-items:center;gap:10px;">
                            <span style="font-size:28px;">📦</span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:500;word-break:break-word;">${escapeHtml(fileName)}</div>
                                <div style="font-size:12px;color:var(--text-light);">圧縮ファイル</div>
                            </div>
                            <a href="${escapeHtml(filePath)}" download style="padding:6px 12px;background:var(--primary);color:white;border-radius:6px;text-decoration:none;font-size:13px;">ダウンロード</a>
                        </div>`;
                    } else if (textFileMatch) {
                        // テキスト/コードファイル
                        let filePath = normalizeFilePath(textFileMatch[1]);
                        if (!filePath.startsWith('uploads/') && !filePath.startsWith('http')) {
                            filePath = 'uploads/messages/' + filePath;
                        }
                        const fileName = filePath.split('/').pop();
                        contentHtml = `<div class="file-attachment-card" style="background:var(--bg-main);padding:12px;border-radius:8px;display:flex;align-items:center;gap:10px;">
                            <span style="font-size:28px;">📃</span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:500;word-break:break-word;">${escapeHtml(fileName)}</div>
                                <div style="font-size:12px;color:var(--text-light);">テキストファイル</div>
                            </div>
                            <a href="${escapeHtml(filePath)}" target="_blank" style="padding:6px 12px;background:var(--primary);color:white;border-radius:6px;text-decoration:none;font-size:13px;">開く</a>
                        </div>`;
                    } else {
                        // [To:ID]名前 をTOチップに変換してからURLをリンク化（キャッシュ翻訳時は displayContent を使用）
                        var memberMap = {};
                        (window.currentConversationMembers || []).forEach(function(m) {
                            memberMap[m.id] = { display_name: m.display_name || m.name, avatar_path: m.avatar_path || m.avatar };
                        });
                        var hasToPattern = /\[To:(?:\d+|all)\]/i.test(displayContent);
                        contentHtml = contentWithToChips(displayContent, memberMap).replace(/\n/g, '<br>').replace(
                            /(https?:\/\/[^\s<]+)/g, 
                            '<a href="$1" target="_blank" rel="noopener noreferrer" style="color:var(--primary);text-decoration:underline;">$1</a>'
                        );
                        // 文中にToが無い場合のみ先頭にToチップを表示（文中にあれば重複表示しない）
                        var toChipsText = buildToChipsFromMentionIds(msg, memberMapForContent);
                        if (toChipsText && !hasToInContent(content)) contentHtml = toChipsText + '<br>' + contentHtml;
                    }
                    
                    // リアクション表示（絵文字のみ表示、ホバーで誰がしたか表示）
                    let reactionHtml = '';
                    if (msg.reaction_details && msg.reaction_details.length > 0) {
                        reactionHtml = '<div class="message-reactions">';
                        msg.reaction_details.forEach(r => {
                            const type = (r.type != null && r.type !== '') ? r.type : (r.reaction_type || '');
                            const names = (r.users && r.users.length) ? r.users.map(u => escapeHtml(u.name)).join(', ') : '';
                            const titleText = names ? names : 'クリックでリアクション';
                            const titleAttr = titleText.replace(/"/g, '&quot;');
                            const typeEsc = type.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                            reactionHtml += `<span class="reaction-badge ${r.is_mine ? 'my-reaction' : ''}" onclick="toggleReaction(${msg.id}, '${typeEsc}')" title="${titleAttr}">${escapeHtml(type)}</span>`;
                        });
                        reactionHtml += '</div>';
                    }
                    
                    // TO情報をdata属性用に準備
                    let toType = '';
                    let toUsers = '[]';
                    if (msg.show_to_all_badge || msg.mention_type === 'to_all') {
                        toType = 'to_all';
                    } else if (msg.show_to_badge && msg.to_member_ids_list) {
                        toType = 'to';
                        toUsers = JSON.stringify(msg.to_member_ids_list);
                    } else if (isMentioned && msg.mention_type === 'to') {
                        toType = 'to';
                    }
                    
                    // 返信プレビュー（最初の1行だけ表示、残りは「続きを見る」で展開。CSSで折りたたみ時は1行表示）
                    let replyPreviewHtml = '';
                    const replyIdNum = parseInt(msg.reply_to_id, 10);
                    const hasReply = !isNaN(replyIdNum) && replyIdNum > 0;
                    if (hasReply) {
                        const fullReplyContent = (msg.reply_to_content || '').toString();
                        const replyUnavailableText = '<?= $currentLang === 'en' ? 'Message deleted or unavailable' : ($currentLang === 'zh' ? '消息已删除或不可用' : '削除されたメッセージまたは利用できません') ?>';
                        const replyTextHtml = fullReplyContent ? escapeHtml(fullReplyContent).replace(/\n/g, '<br>') : escapeHtml(replyUnavailableText);
                        const isExpandable = fullReplyContent.indexOf('\n') >= 0 || fullReplyContent.length > 60;
                        const replyPreviewId = 'reply-preview-' + msg.id;
                        const gotoLabel = '<?= $currentLang === 'en' ? 'Go to original message' : ($currentLang === 'zh' ? '跳转到原消息' : '元メッセージに移動') ?>';
                        const showMoreLabel = '<?= $currentLang === 'en' ? 'Show more' : ($currentLang === 'zh' ? '展开' : '続きを見る') ?>';
                        replyPreviewHtml = `
                            <div class="reply-preview reply-preview-collapsed" id="${replyPreviewId}" data-reply-to-id="${replyIdNum}" data-owner-msg-id="${msg.id}" onclick="handleReplyPreviewAreaClick(event, ${msg.id}, ${replyIdNum})">
                                <span class="reply-preview-icon">↩️</span>
                                <span class="reply-preview-sender">${escapeHtml(msg.reply_to_sender_name || '<?= $currentLang === 'en' ? 'Deleted user' : ($currentLang === 'zh' ? '已删除用户' : '削除されたユーザー') ?>')}</span>
                                <div class="reply-preview-body">
                                    <span class="reply-preview-text">${replyTextHtml}</span>
                                    ${isExpandable ? `<span class="reply-preview-links"><button type="button" class="reply-preview-toggle" onclick="event.stopPropagation(); toggleReplyPreviewExpand(${msg.id})">${showMoreLabel}</button><a href="#" class="reply-preview-goto" onclick="event.preventDefault(); event.stopPropagation(); scrollToMessage(${replyIdNum}); return false;">${gotoLabel}</a></span>` : `<span class="reply-preview-links"><a href="#" class="reply-preview-goto" onclick="event.preventDefault(); event.stopPropagation(); scrollToMessage(${replyIdNum}); return false;">${gotoLabel}</a></span>`}
                                </div>
                            </div>
                        `;
                    }
                    
                    const isToAll = !!(msg.show_to_all_badge || msg.mention_type === 'to_all');
                    // メンション（自分宛・To全員含む）はすべて枠で囲む（改善提案に沿う）
                    const showMentionFrame = isMentioned && !isOwn;
                    const translatedContentAttr = (useCachedTranslation && msg.cached_translation) ? ' data-translated-content="' + escapeHtml(msg.cached_translation).replace(/"/g, '&quot;') + '"' : '';
                    const senderNameAttr = (msg.sender_name != null && msg.sender_name !== '') ? ' data-sender-name="' + escapeHtml(String(msg.sender_name)).replace(/"/g, '&quot;') + '"' : '';
                    messageHtml = `
                        <div class="message-card ${isOwn ? 'own' : ''} ${isMentioned ? 'mentioned-me' : ''} ${isToAll ? 'to-all' : ''} ${showMentionFrame ? 'mention-frame' : ''} ${useCachedTranslation ? 'showing-translation' : ''}" data-message-id="${msg.id}" data-content="${escapeHtml(content)}" data-to-type="${toType}" data-to-users='${toUsers}'${translatedContentAttr}${senderNameAttr}>
                            ${replyPreviewHtml}
                            <div class="message-hover-actions">
                                <button class="hover-action-btn" onclick="replyToMessage(${msg.id})" title="返信">
                                    <img src="assets/icons/line/reply.svg" alt="" class="icon icon-line" width="18" height="18">
                                    <span>返信</span>
                                </button>
                                <button class="hover-action-btn reaction-trigger" onclick="event.stopPropagation(); toggleReactionPicker(${msg.id}, event)" title="リアクション">
                                    <span class="icon">😊</span>
                                    <span>リアクション</span>
                                </button>
                                <button class="hover-action-btn" onclick="addToMemo(${msg.id})" title="メモ">
                                    <img src="assets/icons/line/memo.svg" alt="" class="icon icon-line" width="18" height="18">
                                    <span>メモ</span>
                                </button>
                                <button class="hover-action-btn" onclick="addToTask(${msg.id})" title="タスク">
                                    <img src="assets/icons/line/clipboard.svg" alt="" class="icon icon-line" width="18" height="18">
                                    <span>タスク</span>
                                </button>
                                ${isOwn ? `
                                    <button class="hover-action-btn" onclick="editMessage(${msg.id})" title="編集">
                                        <img src="assets/icons/line/pencil.svg" alt="" class="icon icon-line" width="18" height="18">
                                        <span>編集</span>
                                    </button>
                                    <button class="hover-action-btn" onclick="deleteMessage(${msg.id})" title="削除">
                                        <img src="assets/icons/line/trash.svg" alt="" class="icon icon-line" width="18" height="18">
                                        <span>削除</span>
                                    </button>
                                ` : ''}
                            </div>
                            ${chatLabel}
                            <div class="content">${contentHtml}</div>
                            ${reactionHtml}
                            <div class="message-footer">
                                <span class="timestamp">${timeStr}${msg.is_edited ? ' (編集済み)' : ''}</span>
                                <div class="message-actions-inline">
                                    <button class="inline-action-btn" onclick="replyToMessage(${msg.id})" title="返信"><img src="assets/icons/line/reply.svg" alt="" class="icon-line icon-line--sm" width="14" height="14"> 返信</button>
                                    <button class="inline-action-btn reaction-trigger" onclick="event.stopPropagation(); toggleReactionPicker(${msg.id}, event)" title="リアクション">😊 リアクション</button>
                                    <button class="inline-action-btn" onclick="addToMemo(${msg.id})" title="メモ"><img src="assets/icons/line/memo.svg" alt="" class="icon-line icon-line--sm" width="14" height="14"> メモ</button>
                                    <button class="inline-action-btn" onclick="addToTask(${msg.id})" title="タスク"><img src="assets/icons/line/clipboard.svg" alt="" class="icon-line icon-line--sm" width="14" height="14"> タスク</button>
                                    ${isOwn ? `
                                        <button class="inline-action-btn" onclick="editMessage(${msg.id})" title="編集"><img src="assets/icons/line/pencil.svg" alt="" class="icon-line icon-line--sm" width="14" height="14"> 編集</button>
                                        <button class="inline-action-btn danger" onclick="deleteMessage(${msg.id})" title="削除"><img src="assets/icons/line/trash.svg" alt="" class="icon-line icon-line--sm" width="14" height="14"> 削除</button>
                                    ` : ''}
                                    <button class="inline-action-btn translate-btn" onclick="toggleTranslation(${msg.id})" title="翻訳"><img src="assets/icons/line/globe.svg" alt="" class="icon-line icon-line--sm" width="14" height="14"></button>
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                // メッセージを追加
                messagesArea.insertAdjacentHTML('beforeend', messageHtml);
                if (useCachedTranslation) {
                    if (typeof autoTranslatedMessages !== 'undefined') autoTranslatedMessages.add(parseInt(msg.id, 10));
                    var card = document.querySelector('[data-message-id="' + msg.id + '"]');
                    if (card && typeof updateTranslateButton === 'function') updateTranslateButton(card, 'original');
                }
                
                // 自分宛メンション（自分の送信でない）場合、AIクローン返信提案ボタンを挿入（通常グループチャットでも表示する）
                if (isMentioned && !isOwn && !isSystem) {
                    try {
                        var insertedCard = messagesArea.querySelector('[data-message-id="' + msg.id + '"]');
                        if (insertedCard && !insertedCard.nextElementSibling?.classList?.contains('ai-reply-suggest-bar')) {
                            var suggestBar = document.createElement('div');
                            suggestBar.className = 'ai-reply-suggest-bar';
                            suggestBar.dataset.msgId = msg.id;
                            suggestBar.innerHTML = '<button class="ai-reply-suggest-btn" onclick="AIReplySuggest.generate(' + msg.id + ', ' + conversationId + ', this)">🤖 AI返信提案を生成</button>';
                            insertedCard.insertAdjacentElement('afterend', suggestBar);
                        }
                    } catch (e) {}
                }

                // 着信音・バイブ（skipNotification でないときのみ。ポーリング時は呼び出し側で1回だけ鳴らす）
                if (!skipNotification && !isOwn && !isSystem && typeof window.checkAndPlayMessageNotification === 'function') {
                    var toMe = isMentioned || (msg.show_to_all_badge) ||
                        (Array.isArray(msg.to_member_ids_list) && msg.to_member_ids_list.some(function(id) { return id == userId; }));
                    window.checkAndPlayMessageNotification(toMe);
                }
            }
            
            // HTMLエスケープ関数
            function escapeHtml(str) {
                if (!str) return '';
                return str.toString()
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }
            
            // 動的間隔でポーリング（アクティビティに応じて1.5〜60秒、受信遅延を最小化）
            function getAdaptiveInterval() {
                // ページが非表示の場合は長い間隔（60秒）
                if (!isPageVisible) return 60000;
                
                const idleTime = Date.now() - lastUserActivity;
                if (idleTime > 300000) return 60000;  // 5分以上操作なし → 60秒間隔
                if (idleTime > 120000) return 20000;  // 2分以上操作なし → 20秒間隔
                if (idleTime > 60000) return 10000;   // 1分以上操作なし → 10秒間隔
                if (idleTime > 30000) return 5000;    // 30秒以上操作なし → 5秒間隔
                return 1500;                           // アクティブ → 1.5秒間隔（テキスト+画像を同時表示）
            }
            
            function scheduleNextPoll() {
                if (pollTimeoutId) {
                    clearTimeout(pollTimeoutId);
                }

                if (pollConsecutiveErrors >= POLL_MAX_ERRORS) return;

                let interval = getAdaptiveInterval();
                if (pollConsecutiveErrors > 0) {
                    interval = Math.min(POLL_BACKOFF_BASE * Math.pow(2, pollConsecutiveErrors - 1), 120000);
                }

                pollTimeoutId = setTimeout(() => {
                    checkNewMessages();
                    scheduleNextPoll();
                }, interval);
            }
            
            // 初回実行（ページ読み込み後、できるだけ早く新着を取得）
            setTimeout(() => {
                checkNewMessages();
                scheduleNextPoll();
            }, 500);
            
            // バックアップポーリング: 60秒ごとに必ずチェック（ポーリングチェーン断絶対策）
            setInterval(() => {
                const timeSinceLastPoll = Date.now() - lastPollTime;
                // 最後のポーリングから90秒以上経過していたらチェックを実行
                if (timeSinceLastPoll > 90000) {
                    console.log('バックアップポーリング: 長時間ポーリングが停止していたため再開');
                    checkNewMessages();
                    scheduleNextPoll();
                }
            }, 60000);
            
            // グローバルに公開（他の場所から呼べるように）
            window.checkNewMessages = checkNewMessages;
            window.appendMessageToUI = appendMessageToUI;
            window.lastMessageId = lastMessageId;
            window.updateLastMessageId = function(id) {
                if (id > lastMessageId) lastMessageId = id;
            };
        })();
        
        // ========== 自動翻訳機能（GPT-4o） ==========
        const currentLang = '<?= $currentLang ?>';
        const autoTranslationDays = <?= defined('AUTO_TRANSLATION_DAYS') ? AUTO_TRANSLATION_DAYS : 3 ?>;
        let translationBudgetStatus = null;
        let autoTranslatedMessages = new Set(); // 自動翻訳済みメッセージを追跡
        // 現在の表示言語を取得（APIから取得してセッション反映を確実に・携帯で英語選択時に翻訳するため）
        async function getDisplayLanguageForTranslation() {
            try {
                const res = await fetch('api/language.php', { credentials: 'same-origin' });
                const raw = await res.text();
                const data = raw ? JSON.parse(raw) : {};
                if (data.success && data.language) return data.language;
            } catch (e) { /* ignore */ }
            return currentLang;
        }

        // 予算状況を取得
        async function getTranslationBudgetStatus() {
            if (translationBudgetStatus && translationBudgetStatus.cachedAt > Date.now() - 300000) {
                return translationBudgetStatus;
            }
            try {
                const response = await fetch('api/translate.php?action=budget_status', { credentials: 'same-origin' });
                const raw = await response.text();
                let data = null;
                try {
                    data = raw ? JSON.parse(raw) : {};
                } catch (e) {
                    console.error('Budget status: response is not JSON. Status:', response.status, 'Body:', raw.slice(0, 150));
                    return { allowed: true, auto_translation_enabled: true, usage_percent: 0 };
                }
                if (data.success) {
                    translationBudgetStatus = {
                        ...data,
                        allowed: data.allowed !== false,
                        auto_translation_enabled: data.auto_translation_enabled !== false,
                        cachedAt: Date.now()
                    };
                    return translationBudgetStatus;
                }
            } catch (e) {
                console.warn('Failed to get translation budget status:', e);
            }
            // API失敗時も自動翻訳は試行する（allowed / auto_translation_enabled を true にしておく）
            return { allowed: true, auto_translation_enabled: true, usage_percent: 0 };
        }
        
        // メッセージが自動翻訳対象かどうかを判定
        function isAutoTranslateTarget(card) {
            return card.dataset.autoTranslate === '1';
        }
        
        // 翻訳トグル（自動翻訳と手動翻訳を切り替え）
        async function toggleTranslation(messageId) {
            const card = document.querySelector(`[data-message-id="${messageId}"]`);
            if (!card) return;
            
            const contentEl = card.querySelector('.content');
            const originalContent = card.dataset.content;
            const isOwn = card.dataset.isOwn === '1' || card.classList.contains('own');
            const isAutoTarget = isAutoTranslateTarget(card) && !isOwn;
            
            // 現在の表示状態を確認
            const isShowingTranslation = card.classList.contains('showing-translation');
            const isShowingOriginal = card.classList.contains('showing-original');
            
            if (isAutoTarget) {
                // 自動翻訳対象: 翻訳表示 ⇔ 原文表示 のトグル
                if (isShowingOriginal) {
                    // 原文を表示中 → 翻訳に戻す
                    const translatedContent = card.dataset.translatedContent;
                    if (translatedContent) {
                        contentEl.innerHTML = translatedContent;
                        card.classList.remove('showing-original');
                        card.classList.add('showing-translation');
                        updateTranslateButton(card, 'original'); // 原文表示ボタンに
                    }
                } else if (isShowingTranslation) {
                    // 翻訳を表示中 → 原文を表示（Toチップ含む）
                    contentEl.innerHTML = typeof getContentDisplayHtml === 'function' ? getContentDisplayHtml(originalContent) : escapeHtml(originalContent).replace(/\n/g, '<br>');
                    card.classList.remove('showing-translation');
                    card.classList.add('showing-original');
                    updateTranslateButton(card, 'translated'); // 翻訳表示ボタンに
                } else {
                    // まだ翻訳されていない → 翻訳を実行
                    await translateAndShow(messageId, true);
                }
            } else {
                // 手動翻訳対象: 従来の動作
                const existingTranslation = card.querySelector('.translation-result');
                if (existingTranslation) {
                    existingTranslation.remove();
                    return;
                }
                await translateAndShow(messageId, false);
            }
        }
        
        // 翻訳を実行して表示
        async function translateAndShow(messageId, isAuto) {
            const card = document.querySelector(`[data-message-id="${messageId}"]`);
            if (!card) return;
            
            const contentEl = card.querySelector('.content');
            const originalContent = card.dataset.content;
            
            if (!originalContent) return;
            
            // 画像のみのメッセージは翻訳しない（そのまま表示）
            if (isImageOnlyContent(originalContent)) return;
            
            // 同じ言語かチェック
            const sourceLang = card.dataset.sourceLang || detectLanguage(originalContent);
            if (normalizeLanguage(sourceLang) === normalizeLanguage(currentLang)) {
                if (!isAuto) {
                    alert('既に同じ言語です');
                }
                return;
            }
            
            // ローディング表示
            if (isAuto) {
                contentEl.innerHTML = '<span class="translation-loading">🌐 翻訳中...</span>';
            } else {
                const loadingDiv = document.createElement('div');
                loadingDiv.className = 'translation-result loading';
                loadingDiv.innerHTML = '🌐 翻訳中...';
                contentEl.after(loadingDiv);
            }
            
            try {
                const response = await fetch('api/translate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'translate',
                        text: originalContent, 
                        target_lang: currentLang,
                        message_id: messageId,
                        is_auto: isAuto
                    })
                });
                const raw = await response.text();
                let data = null;
                try {
                    data = raw ? JSON.parse(raw) : {};
                } catch (parseErr) {
                    console.error('Translation: API response is not JSON. Status:', response.status, 'Body:', raw.slice(0, 200));
                    if (isAuto) contentEl.innerHTML = typeof getContentDisplayHtml === 'function' ? getContentDisplayHtml(originalContent) : escapeHtml(originalContent).replace(/\n/g, '<br>');
                    else { const loadingDiv = contentEl.nextElementSibling; if (loadingDiv && loadingDiv.classList.contains('loading')) loadingDiv.remove(); }
                    return;
                }
                
                if (data.success && data.translated_text) {
                    if (isAuto) {
                        // 自動翻訳: コンテンツを置き換え（ファイルパスを画像に変換）
                        let translatedHtml = escapeHtml(data.translated_text).replace(/\n/g, '<br>');
                        translatedHtml = window.processFilePathsInContent ? window.processFilePathsInContent(translatedHtml) : translatedHtml;
                        card.dataset.translatedContent = translatedHtml;
                        contentEl.innerHTML = translatedHtml;
                        card.classList.add('showing-translation');
                        updateTranslateButton(card, 'original');
                        autoTranslatedMessages.add(messageId);
                    } else {
                        // 手動翻訳: 下に結果を表示（ファイルパスを画像に変換）
                        let translatedHtml = escapeHtml(data.translated_text);
                        translatedHtml = window.processFilePathsInContent ? window.processFilePathsInContent(translatedHtml) : translatedHtml;
                        const loadingDiv = card.querySelector('.translation-result');
                        if (loadingDiv) {
                            loadingDiv.classList.remove('loading');
                            loadingDiv.innerHTML = `
                                <div class="translation-header">🌐 翻訳結果</div>
                                <div class="translation-text">${translatedHtml}</div>
                                <button class="translation-close" onclick="this.parentElement.remove()">×</button>
                            `;
                        }
                    }
                } else if (data.budget_exceeded) {
                    // 予算超過
                    if (isAuto) {
                        contentEl.innerHTML = typeof getContentDisplayHtml === 'function' ? getContentDisplayHtml(originalContent) : escapeHtml(originalContent).replace(/\n/g, '<br>');
                    } else {
                        const loadingDiv = card.querySelector('.translation-result');
                        if (loadingDiv) {
                            loadingDiv.innerHTML = '予算上限のため手動翻訳をご利用ください';
                            setTimeout(() => loadingDiv.remove(), 3000);
                        }
                    }
                } else {
                    // APIが失敗または translated_text なし（throw せずメッセージ表示）
                    const msg = (data && data.message) ? data.message : '翻訳に失敗しました';
                    if (isAuto) {
                        contentEl.innerHTML = typeof getContentDisplayHtml === 'function' ? getContentDisplayHtml(originalContent) : escapeHtml(originalContent).replace(/\n/g, '<br>');
                    } else {
                        const loadingDiv = card.querySelector('.translation-result');
                        if (loadingDiv) {
                            loadingDiv.innerHTML = msg;
                            setTimeout(() => loadingDiv.remove(), 3000);
                        }
                    }
                    if (data && !data.success) console.warn('Translation API:', msg, data);
                }
            } catch (e) {
                console.error('Translation error:', e);
                if (isAuto) {
                    contentEl.innerHTML = typeof getContentDisplayHtml === 'function' ? getContentDisplayHtml(originalContent) : escapeHtml(originalContent).replace(/\n/g, '<br>');
                } else {
                    const loadingDiv = card.querySelector('.translation-result');
                    if (loadingDiv) {
                        loadingDiv.innerHTML = '翻訳に失敗しました';
                        setTimeout(() => loadingDiv.remove(), 2000);
                    }
                }
            }
        }
        
        // 翻訳ボタンの表示を更新
        function updateTranslateButton(card, mode) {
            const btn = card.querySelector('.translate-btn');
            if (!btn) return;
            
            if (mode === 'original') {
                btn.title = '原文を表示';
                btn.innerHTML = '🌍'; // 地球アイコン変更で区別
            } else {
                btn.title = '翻訳を表示';
                btn.innerHTML = '🌐';
            }
        }
        
        // 言語を簡易検出
        function detectLanguage(text) {
            if (/[\u3040-\u309F\u30A0-\u30FF]/.test(text)) return 'ja';
            if (/[\u4E00-\u9FAF]/.test(text) && !/[\u3040-\u309F\u30A0-\u30FF]/.test(text)) return 'zh';
            if (/[\uAC00-\uD7AF]/.test(text)) return 'ko';
            return 'en';
        }
        
        // 言語コードを正規化
        function normalizeLanguage(lang) {
            const map = { 'zh-CN': 'zh', 'zh-TW': 'zh', 'zh-Hans': 'zh', 'zh-Hant': 'zh' };
            return map[lang] || lang;
        }
        
        // 画像のみのメッセージか（翻訳対象外・そのまま表示）
        function isImageOnlyContent(text) {
            if (!text || !text.trim()) return true;
            const imgPattern = /(?:uploads[\/\\]messages[\/\\][^\s\n]+\.(jpg|jpeg|png|gif|webp)|アップロード[\/\\]メッセージ[\/\\][^\s\n]+\.(jpg|jpeg|png|gif|webp)|(?:msg_|screenshot_|スクリーンショット_)[^\s\n]+\.(jpg|jpeg|png|gif|webp)|https?:\/\/[^\s]+\.(jpg|jpeg|png|webp)(?:\?[^\s]*)?)/gi;
            const stripped = text.replace(imgPattern, '').replace(/[\u{1F4F7}\u{1F3AC}\u{1F4C4}\u{1F4FD}\u{1F4CE}\s]/gu, '').replace(/\s+/g, '');
            return stripped.length < 3;
        }
        
        // 自動翻訳を初期化（3日以内のメッセージを翻訳）
        async function initAutoTranslation() {
            const budgetStatus = await getTranslationBudgetStatus();
            if (!budgetStatus.allowed || !budgetStatus.auto_translation_enabled) {
                console.log('[Auto translation] Skipped: budget disabled or exceeded');
                return;
            }
            // 表示言語をAPIから取得（英語選択後リロードでも確実に en を参照するため）
            const targetLang = await getDisplayLanguageForTranslation();
            if (normalizeLanguage(targetLang) === 'ja') {
                console.log('[Auto translation] Skipped: display language is ja');
                return;
            }
            
            // 自動翻訳対象のメッセージを収集（自分のメッセージは除外）
            const autoTranslateCards = document.querySelectorAll('.message-card[data-auto-translate="1"]:not(.own)');
            const messageIds = [];
            
            autoTranslateCards.forEach(card => {
                const messageId = parseInt(card.dataset.messageId, 10);
                if (!messageId) return;
                
                if (card.dataset.isOwn === '1' || card.classList.contains('own')) return;
                
                const sourceLang = card.dataset.sourceLang || detectLanguage(card.dataset.content || '');
                if (normalizeLanguage(sourceLang) === normalizeLanguage(targetLang)) return;
                if (autoTranslatedMessages.has(messageId)) return;
                if (isImageOnlyContent(card.dataset.content || '')) return;
                
                messageIds.push(messageId);
            });
            
            if (messageIds.length === 0) {
                console.log('[Auto translation] No messages to translate (cards with data-auto-translate=1:', autoTranslateCards.length, ', targetLang:', targetLang, ')');
                return;
            }
            // 1リクエストあたりの上限（先に表示される分だけ翻訳し、体感を短くする）
            const autoTranslateBatchSize = 12;
            const batch = messageIds.slice(0, autoTranslateBatchSize);
            const hasMore = messageIds.length > autoTranslateBatchSize;
            if (hasMore) {
                console.log('[Auto translation] Batch 1:', batch.length, 'of', messageIds.length, '(rest in next batch)');
            }
            try {
                const response = await fetch('api/translate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        action: 'auto_translate_messages',
                        message_ids: batch,
                        target_lang: targetLang
                    })
                });
                const raw = await response.text();
                let data = null;
                try {
                    data = raw ? JSON.parse(raw) : {};
                } catch (parseErr) {
                    console.error('Auto translation: API response is not JSON. Status:', response.status, 'Body:', raw.slice(0, 200));
                    return;
                }
                
                if (data.success && data.translations) {
                    let applied = 0;
                    for (const [msgId, translatedText] of Object.entries(data.translations)) {
                        const card = document.querySelector(`[data-message-id="${msgId}"]`);
                        if (card) {
                            const contentEl = card.querySelector('.content');
                            const originalContent = card.dataset.content;
                            if (translatedText === originalContent) continue;
                            let translatedHtml = escapeHtml(translatedText).replace(/\n/g, '<br>');
                            translatedHtml = window.processFilePathsInContent ? window.processFilePathsInContent(translatedHtml) : translatedHtml;
                            card.dataset.translatedContent = translatedHtml;
                            if (contentEl) contentEl.innerHTML = translatedHtml;
                            card.classList.add('showing-translation');
                            updateTranslateButton(card, 'original');
                            autoTranslatedMessages.add(parseInt(msgId, 10));
                            applied++;
                        }
                    }
                    if (applied > 0) console.log('[Auto translation] Applied', applied, 'translations');
                }
                if (data.budget_exceeded) {
                    console.log('[Auto translation] Budget exceeded during batch');
                }
                // 残りがあれば2秒後に次のバッチを実行（非同期で順次翻訳）
                if (hasMore && !(data.budget_exceeded)) {
                    setTimeout(initAutoTranslation, 2000);
                }
            } catch (e) {
                console.error('Auto translation batch error:', e);
            }
        }
        
        // ページ読み込み時に自動翻訳を実行（DOM準備後1秒＋既に読み込み済みなら即スケジュール）
        function scheduleAutoTranslation() {
            setTimeout(initAutoTranslation, 1000);
            // 初回で0件だった場合に備え、少し遅れて再実行（DOMが遅れて描画される環境用）
            setTimeout(initAutoTranslation, 3500);
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', scheduleAutoTranslation);
        } else {
            scheduleAutoTranslation();
        }
        
        // 従来の翻訳機能（互換性のため維持）
        async function translateMessage(messageId) {
            await toggleTranslation(messageId);
        }
        
        // 通話メニューを開く
        function openCallMenu(e) {
            e.stopPropagation();
            
            if (!conversationId) {
                alert('会話を選択してください');
                return;
            }
            
            const menu = document.getElementById('callMenu');
            const btn = e.currentTarget;
            const rect = btn.getBoundingClientRect();
            const menuWidth = 160;
            const menuHeight = 100;
            
            // ボタンの上側に表示（ビデオ・音声の両方が見えるように）
            menu.style.left = (rect.left + rect.width / 2 - menuWidth / 2) + 'px';
            menu.style.top = (rect.top - menuHeight - 8) + 'px';
            menu.style.right = 'auto';
            menu.classList.add('show');
            
            // 画面からはみ出さないように調整
            requestAnimationFrame(function() {
                const mr = menu.getBoundingClientRect();
                if (mr.top < 8) {
                    menu.style.top = '8px';
                }
                if (mr.left < 8) {
                    menu.style.left = '8px';
                }
                if (mr.right > window.innerWidth - 8) {
                    menu.style.left = (window.innerWidth - menuWidth - 8) + 'px';
                }
            });
            
            setTimeout(() => {
                document.addEventListener('click', hideCallMenu, { once: true });
            }, 10);
        }
        
        function hideCallMenu() {
            document.getElementById('callMenu').classList.remove('show');
        }
        
        // ============================================
        // 通話機能（Jitsi Meet統合）
        // ============================================
        let jitsiApi = null;
        let callStartTime = null;
        let callDurationInterval = null;
        let isCallActive = false;
        let isMicMuted = false;
        let isVideoMuted = false;
        let isScreenSharing = false;
        /** リモート参加者数（3人以上レイアウト用） */
        let remoteParticipantCount = 0;
        
        // 通話開始（APIでルーム作成→相手に着信通知・Pushが飛ぶ）
        async function startCall(type) {
            hideCallMenu();
            
            if (!conversationId) {
                alert('会話を選択してください');
                return;
            }
            
            if (isCallActive) {
                alert('すでに通話中です');
                return;
            }
            
            const callType = type === 'video' ? 'video' : 'audio';
            let roomId = null;
            try {
                const form = new URLSearchParams();
                form.append('action', 'create');
                form.append('conversation_id', String(conversationId));
                form.append('call_type', callType);
                const res = await fetch('api/calls.php', { method: 'POST', credentials: 'same-origin', body: form });
                const data = await res.json();
                if (!data.success || !data.room_id) {
                    alert(data.message || data.error || '通話の開始に失敗しました');
                    return;
                }
                roomId = data.room_id;
            } catch (e) {
                console.error('Call create error:', e);
                alert('通話の開始に失敗しました');
                return;
            }
            
            showCallUIAndStartJitsi(roomId, type === 'video');
        }
        
        // 通話UI表示＋Jitsi開始（発信時・着信で「出る」押下時の共通）
        // 2画面レイアウト: 自分・相手を常に表示（Android/iPhone両方で確実に表示）
        function showCallUIAndStartJitsi(roomName, startWithVideo) {
            const videoContainer = document.getElementById('callVideoContainer');
            const controlsContainer = document.getElementById('callControlsContainer');
            const callIndicator = document.getElementById('callStatusIndicator');
            const participantsDiv = document.getElementById('callParticipants');
            
            videoContainer.classList.add('active');
            controlsContainer.classList.add('active');
            callIndicator.classList.add('active');
            isCallActive = true;
            
            window.addEventListener('beforeunload', handleBeforeUnload);
            
            // 2画面: 自分用パネル ＋ 相手用パネル（Jitsi）。丸い表示用ラッパーで囲む
            const twoPanels = document.createElement('div');
            twoPanels.className = 'call-two-panels';
            twoPanels.innerHTML = `
                <div class="call-panel call-panel-draggable call-panel-self" data-call-panel="self">
                    <div class="call-panel-label">自分</div>
                    <div class="call-panel-circle call-panel-circle-self">
                        <video id="selfVideo" autoplay muted playsinline></video>
                    </div>
                </div>
                <div class="call-panel call-panel-draggable call-panel-remote" data-call-panel="remote">
                    <div class="call-panel-label">相手</div>
                    <div class="call-panel-circle call-panel-circle-remote">
                        <div id="jitsiContainer" class="call-jitsi-wrap"></div>
                    </div>
                </div>
            `;
            videoContainer.appendChild(twoPanels);
            videoContainer.classList.add('call-video-container--draggable-panels');
            initDraggableCallPanels();
            
            callStartTime = new Date();
            callDurationInterval = setInterval(updateCallDuration, 1000);
            
            initLocalCamera(startWithVideo);
            initJitsiMeet(roomName, startWithVideo);
        }
        
        // ローカルカメラ初期化
        let localStream = null;
        async function initLocalCamera(startWithVideo) {
            try {
                const constraints = {
                    audio: true,
                    video: startWithVideo ? {
                        width: { ideal: 640 },
                        height: { ideal: 480 },
                        facingMode: 'user'
                    } : false
                };
                
                localStream = await navigator.mediaDevices.getUserMedia(constraints);
                
                const selfVideo = document.getElementById('selfVideo');
                if (selfVideo) {
                    selfVideo.srcObject = localStream;
                }
                
                console.log('Local camera initialized');
            } catch (err) {
                console.error('Camera access error:', err);
                alert('カメラへのアクセスが許可されませんでした。');
            }
        }
        
        // Jitsi API を遅延読み込み（初期表示高速化）
        let jitsiApiLoaded = false;
        function loadJitsiApi() {
            return new Promise((resolve, reject) => {
                if (window.JitsiMeetExternalAPI) {
                    jitsiApiLoaded = true;
                    resolve();
                    return;
                }
                
                const script = document.createElement('script');
                script.src = 'https://meet.jit.si/external_api.js';
                script.async = true;
                script.onload = () => {
                    jitsiApiLoaded = true;
                    console.log('Jitsi API loaded');
                    resolve();
                };
                script.onerror = () => {
                    reject(new Error('Jitsi API の読み込みに失敗しました'));
                };
                document.head.appendChild(script);
            });
        }
        
        // Jitsi Meet 初期化
        async function initJitsiMeet(roomName, startWithVideo) {
            const container = document.getElementById('jitsiContainer');
            
            if (!container) {
                console.error('Jitsi container not found');
                return;
            }
            
            // Jitsi API がまだ読み込まれていない場合は読み込む
            if (!window.JitsiMeetExternalAPI) {
                try {
                    document.getElementById('callStatusText').textContent = '接続中...';
                    await loadJitsiApi();
                    document.getElementById('callStatusText').textContent = '通話中';
                } catch (error) {
                    console.error('Jitsi API load error:', error);
                    alert('通話機能の読み込みに失敗しました。ページを再読み込みしてください。');
                    endCall();
                    return;
                }
            }
            
            const options = {
                roomName: roomName,
                width: '100%',
                height: '100%',
                parentNode: container,
                configOverwrite: {
                    startWithAudioMuted: false,
                    startWithVideoMuted: !startWithVideo,
                    prejoinPageEnabled: false,
                    disableDeepLinking: true,
                    enableClosePage: false,
                    disableInviteFunctions: true,
                    toolbarButtons: [],
                    hideConferenceSubject: true,
                    hideConferenceTimer: true,
                    disableProfile: true,
                    enableWelcomePage: false,
                    enableLobbyChat: false,
                    hideConferenceTimer: true,
                    subject: ' ',
                    disableModeratorIndicator: true,
                    disableReactions: true,
                    disablePolls: true,
                    hideLobbyButton: true,
                    requireDisplayName: false,
                    disableSelfView: true,
                    disableSelfViewSettings: true,
                    filmstrip: {
                        disabled: false
                    },
                    notifications: [],
                    disableNotifications: true,
                    hideParticipantsStats: true,
                    disableRemoteMute: true,
                    remoteVideoMenu: {
                        disabled: true
                    },
                    disableLocalVideoFlip: true,
                },
                interfaceConfigOverwrite: {
                    SHOW_JITSI_WATERMARK: false,
                    SHOW_WATERMARK_FOR_GUESTS: false,
                    SHOW_BRAND_WATERMARK: false,
                    BRAND_WATERMARK_LINK: '',
                    SHOW_POWERED_BY: false,
                    SHOW_PROMOTIONAL_CLOSE_PAGE: false,
                    DISABLE_JOIN_LEAVE_NOTIFICATIONS: true,
                    FILM_STRIP_MAX_HEIGHT: 0,
                    TOOLBAR_ALWAYS_VISIBLE: false,
                    TOOLBAR_BUTTONS: [],
                    SETTINGS_SECTIONS: [],
                    VIDEO_LAYOUT_FIT: 'cover',
                    HIDE_INVITE_MORE_HEADER: true,
                    MOBILE_APP_PROMO: false,
                    TILE_VIEW_MAX_COLUMNS: 1,
                    DISABLE_DOMINANT_SPEAKER_INDICATOR: true,
                    DISABLE_FOCUS_INDICATOR: true,
                    DISABLE_VIDEO_BACKGROUND: false,
                    HIDE_KICK_BUTTON_FOR_GUESTS: true,
                    DEFAULT_REMOTE_DISPLAY_NAME: '',
                    DEFAULT_LOCAL_DISPLAY_NAME: '',
                    SHOW_CHROME_EXTENSION_BANNER: false,
                    VERTICAL_FILMSTRIP: false,
                    filmStripOnly: false,
                    DISPLAY_WELCOME_FOOTER: false,
                    GENERATE_ROOMNAMES_ON_WELCOME_PAGE: false,
                    APP_NAME: '',
                    NATIVE_APP_NAME: '',
                    PROVIDER_NAME: '',
                    RECENT_LIST_ENABLED: false,
                    DISABLE_PRESENCE_STATUS: true,
                    DISABLE_TRANSCRIPTION_SUBTITLES: true,
                    DISABLE_RINGING: true,
                    AUTHENTICATION_ENABLE: false,
                    INVITATION_POWERED_BY: false,
                },
                userInfo: {
                    displayName: '<?= htmlspecialchars($_SESSION['display_name'] ?? 'ユーザー') ?>'
                }
            };
            
            try {
                jitsiApi = new JitsiMeetExternalAPI('meet.jit.si', options);
                
                // イベントリスナー
                jitsiApi.addListener('participantJoined', (participant) => {
                    console.log('参加者が入室:', participant);
                    addParticipantBubble(participant);
                    remoteParticipantCount++;
                    updateCallPanelsMulti();
                });
                
                jitsiApi.addListener('participantLeft', (participant) => {
                    console.log('参加者が退出:', participant);
                    removeParticipantBubble(participant.id);
                    remoteParticipantCount = Math.max(0, remoteParticipantCount - 1);
                    updateCallPanelsMulti();
                });
                
                jitsiApi.addListener('audioMuteStatusChanged', (status) => {
                    isMicMuted = status.muted;
                    updateMicButton();
                });
                
                jitsiApi.addListener('videoMuteStatusChanged', (status) => {
                    isVideoMuted = status.muted;
                    updateVideoButton();
                });
                
                jitsiApi.addListener('readyToClose', () => {
                    endCall();
                });
                
                isVideoMuted = !startWithVideo;
                updateVideoButton();
                
            } catch (error) {
                console.error('Jitsi Meet 初期化エラー:', error);
                alert('通話の開始に失敗しました。ブラウザの設定を確認してください。');
                endCall();
            }
        }
        
        // 参加者バブルを追加
        function addParticipantBubble(participant) {
            const participantsDiv = document.getElementById('callParticipants');
            
            // 既に存在するか確認
            if (document.getElementById(`participant-${participant.id}`)) {
                return;
            }
            
            const bubble = document.createElement('div');
            bubble.className = 'call-video-bubble';
            bubble.id = `participant-${participant.id}`;
            bubble.innerHTML = `
                <div class="user-name-label">${escapeHtml(participant.displayName || '参加者')}</div>
                <div class="resize-handle">↘</div>
            `;
            
            const selfBubble = document.getElementById('selfVideoBubble');
            if (selfBubble) {
                participantsDiv.insertBefore(bubble, selfBubble);
            } else {
                participantsDiv.appendChild(bubble);
            }
        }
        
        // 参加者バブルを削除
        function removeParticipantBubble(participantId) {
            const bubble = document.getElementById(`participant-${participantId}`);
            if (bubble) {
                bubble.remove();
            }
        }
        
        /** 3人以上のとき通話パネルに .call-multi-participant を付与してレイアウトを変更 */
        function updateCallPanelsMulti() {
            const twoPanels = document.querySelector('.call-two-panels');
            if (!twoPanels) return;
            if (remoteParticipantCount >= 2) {
                twoPanels.classList.add('call-multi-participant');
            } else {
                twoPanels.classList.remove('call-multi-participant');
            }
        }
        
        /** 丸い通話パネルをドラッグで動かせるようにする（デフォルトサイズ2倍＝100vmin） */
        function initDraggableCallPanels() {
            const panels = document.querySelectorAll('.call-panel-draggable');
            const sizeVmin = 100;
            const gap = 20;
            panels.forEach((panel, index) => {
                panel.style.width = sizeVmin + 'vmin';
                panel.style.height = sizeVmin + 'vmin';
                panel.style.position = 'fixed';
                panel.style.zIndex = '9999';
                if (!panel.dataset.dragInited) {
                    const rect = panel.getBoundingClientRect();
                    if (index === 0) {
                        panel.style.left = gap + 'px';
                        panel.style.top = (gap + 64) + 'px';
                        panel.style.right = 'auto';
                    } else {
                        panel.style.left = 'auto';
                        panel.style.right = gap + 'px';
                        panel.style.top = (gap + 64) + 'px';
                    }
                    panel.dataset.dragInited = '1';
                }
                setupPanelDrag(panel);
            });
        }
        
        function setupPanelDrag(panel) {
            let startX = 0, startY = 0, startLeft = 0, startTop = 0;
            function getLeft(el) {
                const v = el.style.left;
                if (v && v !== 'auto') return parseFloat(v);
                const r = el.getBoundingClientRect();
                return r.left;
            }
            function getTop(el) {
                const v = el.style.top;
                if (v && v !== 'auto') return parseFloat(v);
                const r = el.getBoundingClientRect();
                return r.top;
            }
            function onPointerDown(e) {
                e.preventDefault();
                startX = (e.touches ? e.touches[0] : e).clientX;
                startY = (e.touches ? e.touches[0] : e).clientY;
                startLeft = getLeft(panel);
                startTop = getTop(panel);
                panel.style.right = 'auto';
                const move = (e2) => {
                    const x = (e2.touches ? e2.touches[0] : e2).clientX;
                    const y = (e2.touches ? e2.touches[0] : e2).clientY;
                    panel.style.left = (startLeft + (x - startX)) + 'px';
                    panel.style.top = (startTop + (y - startY)) + 'px';
                };
                const up = () => {
                    document.removeEventListener('mousemove', move);
                    document.removeEventListener('mouseup', up);
                    document.removeEventListener('touchmove', move, { passive: false });
                    document.removeEventListener('touchend', up);
                };
                document.addEventListener('mousemove', move);
                document.addEventListener('mouseup', up);
                document.addEventListener('touchmove', move, { passive: false });
                document.addEventListener('touchend', up);
            }
            panel.addEventListener('mousedown', onPointerDown, { passive: false });
            panel.addEventListener('touchstart', onPointerDown, { passive: false });
        }
        
        // 通話時間更新
        function updateCallDuration() {
            if (!callStartTime) return;
            
            const now = new Date();
            const diff = Math.floor((now - callStartTime) / 1000);
            const minutes = Math.floor(diff / 60).toString().padStart(2, '0');
            const seconds = (diff % 60).toString().padStart(2, '0');
            
            document.getElementById('callDuration').textContent = `${minutes}:${seconds}`;
        }
        
        // マイクトグル
        function toggleMic() {
            // ローカルストリームのオーディオトラックを制御
            if (localStream) {
                const audioTrack = localStream.getAudioTracks()[0];
                if (audioTrack) {
                    audioTrack.enabled = !audioTrack.enabled;
                    isMicMuted = !audioTrack.enabled;
                    updateMicButton();
                }
            }
            
            // Jitsi側も同期
            if (jitsiApi) {
                jitsiApi.executeCommand('toggleAudio');
            }
        }
        
        function updateMicButton() {
            const btn = document.getElementById('micToggleBtn');
            if (isMicMuted) {
                btn.classList.add('muted');
                btn.innerHTML = '🔇';
            } else {
                btn.classList.remove('muted');
                btn.innerHTML = '🎤';
            }
        }
        
        // ビデオトグル
        function toggleVideo() {
            // ローカルストリームのビデオトラックを制御
            if (localStream) {
                const videoTrack = localStream.getVideoTracks()[0];
                if (videoTrack) {
                    videoTrack.enabled = !videoTrack.enabled;
                    isVideoMuted = !videoTrack.enabled;
                    updateVideoButton();
                }
            }
            
            // Jitsi側も同期
            if (jitsiApi) {
                jitsiApi.executeCommand('toggleVideo');
            }
        }
        
        function updateVideoButton() {
            const btn = document.getElementById('videoToggleBtn');
            if (isVideoMuted) {
                btn.classList.add('muted');
                btn.innerHTML = '📷';
            } else {
                btn.classList.remove('muted');
                btn.innerHTML = '📹';
            }
        }
        
        // 画面共有トグル
        function toggleScreenShare() {
            if (!jitsiApi) return;
            jitsiApi.executeCommand('toggleShareScreen');
            isScreenSharing = !isScreenSharing;
            
            const btn = document.getElementById('screenShareBtn');
            btn.classList.toggle('active', isScreenSharing);
        }
        
        // 背景ぼかしトグル
        let isBackgroundBlurred = false;
        function toggleBackgroundBlur() {
            if (!jitsiApi) return;
            
            isBackgroundBlurred = !isBackgroundBlurred;
            
            if (isBackgroundBlurred) {
                // 背景ぼかしを有効にする
                jitsiApi.executeCommand('toggleVirtualBackgroundDialog');
            } else {
                // 背景ぼかしを無効にする（ダイアログで解除）
                jitsiApi.executeCommand('toggleVirtualBackgroundDialog');
            }
            
            const btn = document.getElementById('blurToggleBtn');
            btn.classList.toggle('active', isBackgroundBlurred);
            btn.innerHTML = isBackgroundBlurred ? '🔵' : '🌫️';
            
            // バーチャル背景モーダルの選択状態も更新
            updateVirtualBgSelection(isBackgroundBlurred ? 'blur' : 'none');
        }
        
        // バーチャル背景選択
        let currentVirtualBg = 'none';
        
        function openVirtualBackgroundSelector() {
            const modal = document.getElementById('virtualBgModal');
            modal.style.display = modal.style.display === 'none' ? 'block' : 'none';
        }
        
        function closeVirtualBgModal() {
            document.getElementById('virtualBgModal').style.display = 'none';
        }
        
        function updateVirtualBgSelection(bgType) {
            currentVirtualBg = bgType;
            document.querySelectorAll('.virtual-bg-item').forEach(item => {
                item.classList.toggle('active', item.dataset.bg === bgType);
            });
        }
        
        function setVirtualBackground(bgType) {
            if (!jitsiApi) {
                console.warn('Jitsi API not initialized');
                return;
            }
            
            currentVirtualBg = bgType;
            updateVirtualBgSelection(bgType);
            
            // Jitsi APIでバーチャル背景を設定
            switch(bgType) {
                case 'none':
                    // 背景効果を無効にする
                    jitsiApi.executeCommand('setVideoInputDevice', 'default');
                    isBackgroundBlurred = false;
                    document.getElementById('blurToggleBtn').innerHTML = '🌫️';
                    document.getElementById('blurToggleBtn').classList.remove('active');
                    break;
                    
                case 'blur':
                    // 背景ぼかし
                    jitsiApi.executeCommand('toggleVirtualBackgroundDialog');
                    isBackgroundBlurred = true;
                    document.getElementById('blurToggleBtn').innerHTML = '🔵';
                    document.getElementById('blurToggleBtn').classList.add('active');
                    break;
                    
                default:
                    // 画像背景（JitsiのダイアログでURL設定が必要）
                    // Jitsi MeetのWeb APIでは直接背景画像を設定することは制限があるため
                    // ダイアログを開いてユーザーに設定してもらう
                    jitsiApi.executeCommand('toggleVirtualBackgroundDialog');
                    break;
            }
            
            closeVirtualBgModal();
        }
        
        function uploadCustomBackground(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            // ファイルサイズチェック（5MB以下）
            if (file.size > 5 * 1024 * 1024) {
                alert('ファイルサイズは5MB以下にしてください');
                return;
            }
            
            const reader = new FileReader();
            reader.onload = function(e) {
                // プレビュー用に新しいアイテムを追加
                const optionsContainer = document.querySelector('.virtual-bg-options');
                const existingCustom = optionsContainer.querySelector('[data-bg="custom"]');
                if (existingCustom) {
                    existingCustom.remove();
                }
                
                const customItem = document.createElement('div');
                customItem.className = 'virtual-bg-item';
                customItem.dataset.bg = 'custom';
                customItem.onclick = function() { setVirtualBackground('custom'); };
                customItem.innerHTML = `
                    <div class="virtual-bg-preview" style="background: url('${e.target.result}') center/cover;"></div>
                    <span>カスタム</span>
                `;
                optionsContainer.appendChild(customItem);
                
                // Jitsiダイアログを開く（ユーザーがそこで画像を適用）
                jitsiApi.executeCommand('toggleVirtualBackgroundDialog');
                closeVirtualBgModal();
            };
            reader.readAsDataURL(file);
        }
        
        // ページ離脱時の警告ハンドラ
        function handleBeforeUnload(e) {
            if (isCallActive) {
                e.preventDefault();
                e.returnValue = '通話中です。ページを離れると通話が終了します。';
                return e.returnValue;
            }
        }
        
        // 新しいメッセージをチャットに追加（リロードなし）
        function appendNewMessage(messageData) {
            const messagesContainer = document.querySelector('.messages-container');
            if (!messagesContainer) return;
            
            const content = messageData.content || '';
            let contentHtml = '';
            
            // 外部GIF URLをチェック
            const externalGifMatch = content.match(/^(https?:\/\/[^\s]+\.(gif|gifv))$/i) || 
                                     content.match(/^(https?:\/\/media\.giphy\.com\/[^\s]+)$/i) ||
                                     content.match(/^(https?:\/\/[^\s]*tenor[^\s]*\.(gif|mp4))$/i);
            
            if (externalGifMatch) {
                contentHtml = `<img src="${escapeHtml(content)}" loading="lazy" style="max-width:100%;max-height:250px;border-radius:12px;display:block;">`;
            } else {
                contentHtml = escapeHtml(content);
            }
            
            const messageDiv = document.createElement('div');
            messageDiv.className = 'message outgoing';
            messageDiv.innerHTML = `
                <div class="message-content">
                    <div class="message-text">${contentHtml}</div>
                    <div class="message-time">${new Date().toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' })}</div>
                </div>
            `;
            messagesContainer.appendChild(messageDiv);
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
        
        // 通話終了
        function endCall() {
            // beforeunloadイベントを削除
            window.removeEventListener('beforeunload', handleBeforeUnload);
            
            // ローカルカメラストリームを停止
            if (localStream) {
                localStream.getTracks().forEach(track => track.stop());
                localStream = null;
            }
            
            if (jitsiApi) {
                jitsiApi.dispose();
                jitsiApi = null;
            }
            
            // Jitsiコンテナは .call-two-panels ごと削除するためここでは不要
            
            // タイマー停止
            if (callDurationInterval) {
                clearInterval(callDurationInterval);
                callDurationInterval = null;
            }
            
            // UIをリセット
            const videoContainer = document.getElementById('callVideoContainer');
            const controlsContainer = document.getElementById('callControlsContainer');
            const callIndicator = document.getElementById('callStatusIndicator');
            const participantsDiv = document.getElementById('callParticipants');
            
            videoContainer.classList.remove('active');
            videoContainer.classList.remove('call-video-container--draggable-panels');
            controlsContainer.classList.remove('active');
            callIndicator.classList.remove('active');
            participantsDiv.innerHTML = '';
            remoteParticipantCount = 0;
            const twoPanels = videoContainer.querySelector('.call-two-panels');
            if (twoPanels) twoPanels.remove();
            
            // 位置をリセット
            videoContainer.style.left = '';
            videoContainer.style.top = '';
            videoContainer.style.right = '20px';
            videoContainer.style.bottom = '160px';
            
            controlsContainer.style.left = '50%';
            controlsContainer.style.top = '';
            controlsContainer.style.bottom = '30px';
            controlsContainer.style.transform = 'translateX(-50%)';
            
            // 状態リセット
            isCallActive = false;
            isMicMuted = false;
            isVideoMuted = false;
            isScreenSharing = false;
            callStartTime = null;
            
            // ボタンリセット
            document.getElementById('micToggleBtn').classList.remove('muted');
            document.getElementById('micToggleBtn').innerHTML = '🎤';
            document.getElementById('videoToggleBtn').classList.remove('muted');
            document.getElementById('videoToggleBtn').innerHTML = '📹';
            document.getElementById('screenShareBtn').classList.remove('active');
        }
        
        // ============================================
        // 着信ポーリング・着信音・バイブ・応答/拒否
        // ============================================
        let currentIncomingCallId = null;
        let incomingCallRingtoneInterval = null;
        let incomingCallAudioElement = null;
        let incomingCallVibrateInterval = null;
        
        function stopIncomingCallAlert() {
            if (incomingCallRingtoneInterval) {
                clearInterval(incomingCallRingtoneInterval);
                incomingCallRingtoneInterval = null;
            }
            if (incomingCallAudioElement) {
                try {
                    incomingCallAudioElement.pause();
                    incomingCallAudioElement.currentTime = 0;
                } catch (e) {}
                incomingCallAudioElement = null;
            }
            if (incomingCallVibrateInterval) {
                clearInterval(incomingCallVibrateInterval);
                incomingCallVibrateInterval = null;
            }
            if (navigator.vibrate) navigator.vibrate(0);
            currentIncomingCallId = null;
            const overlay = document.getElementById('incomingCallOverlay');
            if (overlay) overlay.style.display = 'none';
        }
        
        function startIncomingCallAlert() {
            if (incomingCallRingtoneInterval || incomingCallAudioElement) return;
            if (typeof window.getNotificationSettings !== 'function') return;
            window.getNotificationSettings(function(s) {
                var ringtone = (s.call_ringtone !== undefined && s.call_ringtone !== '') ? s.call_ringtone : 'default';
                if (ringtone === 'silent') return;
                var paths = window.__RINGTONE_PATHS;
                var path = paths && paths[ringtone];
                if (path) {
                    try {
                        var a = new Audio(path);
                        a.loop = true; // 通話着信のみ繰り返し。メッセージ着信は playSoundFileOnce で1回のみ。
                        a.play().then(function() {
                            incomingCallAudioElement = a;
                        }).catch(function() {});
                    } catch (e) {}
                } else {
            if (typeof window.playMessageNotification === 'function') {
                window.playMessageNotification();
                incomingCallRingtoneInterval = setInterval(function() {
                    if (currentIncomingCallId && typeof window.playMessageNotification === 'function') {
                        window.playMessageNotification();
                    }
                }, 2200);
            }
                }
            });
            if (navigator.vibrate && (window._userHasInteracted || (navigator.userActivation && navigator.userActivation.hasBeenActive))) {
                var pattern = [200, 100, 200, 100, 200];
                navigator.vibrate(pattern);
                incomingCallVibrateInterval = setInterval(function() {
                    if (currentIncomingCallId) navigator.vibrate(pattern);
                }, 2500);
            }
        }
        
        function showIncomingCallModal(call) {
            currentIncomingCallId = call.id;
            var titleEl = document.getElementById('incomingCallTitle');
            var fromEl = document.getElementById('incomingCallFrom');
            if (titleEl) titleEl.textContent = call.call_type === 'video' ? 'ビデオ通話' : '音声通話';
            if (fromEl) fromEl.textContent = (call.initiator_name || '') + ' さんから';
            document.getElementById('incomingCallOverlay').style.display = 'flex';
            startIncomingCallAlert();
        }
        
        setInterval(function() {
            if (isCallActive) return;
            fetch('api/calls.php?action=get_active', { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success || !data.calls || !data.calls.length) {
                        if (currentIncomingCallId) stopIncomingCallAlert();
                        return;
                    }
                    var invited = data.calls.filter(function(c) { return (c.my_participant_status === 'invited'); });
                    if (invited.length === 0) {
                        if (currentIncomingCallId) stopIncomingCallAlert();
                        return;
                    }
                    var call = invited[0];
                    if (currentIncomingCallId === call.id) return;
                    if (currentIncomingCallId) stopIncomingCallAlert();
                    showIncomingCallModal(call);
                })
                .catch(function() {});
        }, 3000);
        
        document.addEventListener('DOMContentLoaded', function() {
            var declineBtn = document.getElementById('incomingCallDecline');
            var answerBtn = document.getElementById('incomingCallAnswer');
            if (declineBtn) {
                declineBtn.addEventListener('click', function() {
                    if (!currentIncomingCallId) return;
                    var cid = currentIncomingCallId;
                    stopIncomingCallAlert();
                    var form = new URLSearchParams();
                    form.append('action', 'decline');
                    form.append('call_id', String(cid));
                    fetch('api/calls.php', { method: 'POST', credentials: 'same-origin', body: form }).catch(function() {});
                });
            }
            if (answerBtn) {
                answerBtn.addEventListener('click', function() {
                    if (!currentIncomingCallId) return;
                    var cid = currentIncomingCallId;
                    var form = new URLSearchParams();
                    form.append('action', 'join');
                    form.append('call_id', String(cid));
                    fetch('api/calls.php', { method: 'POST', credentials: 'same-origin', body: form })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            stopIncomingCallAlert();
                            if (data.success && data.room_id) {
                                showCallUIAndStartJitsi(data.room_id, data.call_type === 'video');
                            }
                        })
                        .catch(function() { stopIncomingCallAlert(); });
                });
            }
        });
        
        // ============================================
        // コントロールバー ドラッグ移動
        // ============================================
        (function() {
            let isDragging = false;
            let dragTarget = null;
            let dragOffsetX = 0;
            let dragOffsetY = 0;
            
            document.addEventListener('DOMContentLoaded', function() {
                const controlsBar = document.getElementById('callControlsBar');
                
                // コントロールバーをドラッグ可能にする
                controlsBar.addEventListener('mousedown', startControlsDrag);
                controlsBar.addEventListener('touchstart', startControlsDrag, { passive: false });
                
                document.addEventListener('mousemove', dragControls);
                document.addEventListener('touchmove', dragControls, { passive: false });
                
                document.addEventListener('mouseup', stopControlsDrag);
                document.addEventListener('touchend', stopControlsDrag);
            });
            
            function startControlsDrag(e) {
                // ボタンクリックは除外
                if (e.target.closest('.call-control-btn')) return;
                
                const controlsContainer = document.getElementById('callControlsContainer');
                isDragging = true;
                dragTarget = controlsContainer;
                controlsContainer.classList.add('dragging');
                
                const clientX = e.type === 'touchstart' ? e.touches[0].clientX : e.clientX;
                const clientY = e.type === 'touchstart' ? e.touches[0].clientY : e.clientY;
                
                const rect = controlsContainer.getBoundingClientRect();
                dragOffsetX = clientX - rect.left;
                dragOffsetY = clientY - rect.top;
                
                // transformを解除してleft/topで位置指定
                controlsContainer.style.left = rect.left + 'px';
                controlsContainer.style.top = rect.top + 'px';
                controlsContainer.style.bottom = 'auto';
                controlsContainer.style.transform = 'none';
                
                e.preventDefault();
            }
            
            function dragControls(e) {
                if (!isDragging || !dragTarget) return;
                
                const clientX = e.type === 'touchmove' ? e.touches[0].clientX : e.clientX;
                const clientY = e.type === 'touchmove' ? e.touches[0].clientY : e.clientY;
                
                let newX = clientX - dragOffsetX;
                let newY = clientY - dragOffsetY;
                
                // 画面内に制限
                const rect = dragTarget.getBoundingClientRect();
                newX = Math.max(0, Math.min(newX, window.innerWidth - rect.width));
                newY = Math.max(0, Math.min(newY, window.innerHeight - rect.height));
                
                dragTarget.style.left = newX + 'px';
                dragTarget.style.top = newY + 'px';
                
                e.preventDefault();
            }
            
            function stopControlsDrag() {
                if (!isDragging) return;
                
                isDragging = false;
                if (dragTarget) {
                    dragTarget.classList.remove('dragging');
                }
                dragTarget = null;
            }
        })();
        
        // ============================================
        // ビデオバブル ドラッグ移動
        // ============================================
        (function() {
            let isDragging = false;
            let dragTarget = null;
            let dragOffsetX = 0;
            let dragOffsetY = 0;
            
            document.addEventListener('mousedown', function(e) {
                const bubble = e.target.closest('.call-video-bubble');
                if (bubble && !e.target.classList.contains('resize-handle')) {
                    startBubbleDrag(e, bubble);
                }
            });
            
            document.addEventListener('touchstart', function(e) {
                const bubble = e.target.closest('.call-video-bubble');
                if (bubble && !e.target.classList.contains('resize-handle')) {
                    startBubbleDrag(e, bubble);
                }
            }, { passive: false });
            
            document.addEventListener('mousemove', dragBubble);
            document.addEventListener('touchmove', dragBubble, { passive: false });
            
            document.addEventListener('mouseup', stopBubbleDrag);
            document.addEventListener('touchend', stopBubbleDrag);
            
            function startBubbleDrag(e, bubble) {
                isDragging = true;
                dragTarget = bubble;
                bubble.classList.add('dragging');
                
                const clientX = e.type === 'touchstart' ? e.touches[0].clientX : e.clientX;
                const clientY = e.type === 'touchstart' ? e.touches[0].clientY : e.clientY;
                
                const rect = bubble.getBoundingClientRect();
                dragOffsetX = clientX - rect.left;
                dragOffsetY = clientY - rect.top;
                
                // 親からの相対位置をabsoluteに変更
                bubble.style.position = 'fixed';
                bubble.style.left = rect.left + 'px';
                bubble.style.top = rect.top + 'px';
                
                e.preventDefault();
            }
            
            function dragBubble(e) {
                if (!isDragging || !dragTarget) return;
                
                const clientX = e.type === 'touchmove' ? e.touches[0].clientX : e.clientX;
                const clientY = e.type === 'touchmove' ? e.touches[0].clientY : e.clientY;
                
                let newX = clientX - dragOffsetX;
                let newY = clientY - dragOffsetY;
                
                // 画面内に制限
                const width = dragTarget.offsetWidth;
                const height = dragTarget.offsetHeight;
                newX = Math.max(0, Math.min(newX, window.innerWidth - width));
                newY = Math.max(0, Math.min(newY, window.innerHeight - height));
                
                dragTarget.style.left = newX + 'px';
                dragTarget.style.top = newY + 'px';
                
                e.preventDefault();
            }
            
            function stopBubbleDrag() {
                if (!isDragging) return;
                
                isDragging = false;
                if (dragTarget) {
                    dragTarget.classList.remove('dragging');
                }
                dragTarget = null;
            }
        })();
        
        // ============================================
        // ビデオバブル リサイズ
        // ============================================
        (function() {
            let isResizing = false;
            let resizeTarget = null;
            let startSize = 0;
            let startX = 0;
            let startY = 0;
            
            // リサイズハンドルにイベントを設定（動的に追加される要素用）
            document.addEventListener('mousedown', function(e) {
                if (e.target.classList.contains('resize-handle')) {
                    startResize(e);
                }
            });
            
            document.addEventListener('touchstart', function(e) {
                if (e.target.classList.contains('resize-handle')) {
                    startResize(e);
                }
            }, { passive: false });
            
            document.addEventListener('mousemove', resize);
            document.addEventListener('touchmove', resize, { passive: false });
            
            document.addEventListener('mouseup', stopResize);
            document.addEventListener('touchend', stopResize);
            
            function startResize(e) {
                e.preventDefault();
                e.stopPropagation();
                
                isResizing = true;
                resizeTarget = e.target.closest('.call-video-bubble');
                
                if (!resizeTarget) return;
                
                const clientX = e.type === 'touchstart' ? e.touches[0].clientX : e.clientX;
                const clientY = e.type === 'touchstart' ? e.touches[0].clientY : e.clientY;
                
                startSize = resizeTarget.offsetWidth;
                startX = clientX;
                startY = clientY;
                
                resizeTarget.style.transition = 'none';
            }
            
            function resize(e) {
                if (!isResizing || !resizeTarget) return;
                
                e.preventDefault();
                
                const clientX = e.type === 'touchmove' ? e.touches[0].clientX : e.clientX;
                const clientY = e.type === 'touchmove' ? e.touches[0].clientY : e.clientY;
                
                const deltaX = clientX - startX;
                const deltaY = clientY - startY;
                
                // 正方形を維持（対角線方向の変化量を使用）
                const delta = (deltaX + deltaY) / 2;
                
                let newSize = startSize + delta;
                // 60px〜600px（初期サイズの約6倍）
                newSize = Math.max(60, Math.min(600, newSize));
                
                resizeTarget.style.width = newSize + 'px';
                resizeTarget.style.height = newSize + 'px';
            }
            
            function stopResize() {
                if (resizeTarget) {
                    resizeTarget.style.transition = '';
                }
                isResizing = false;
                resizeTarget = null;
            }
        })();
        
        // ========================================
        // AIセクレタリー機能（あなたの秘書）
        // グループチャットと同じ見た目で動作
        // ========================================
        
        let isAISecretaryActive = false;
        let aiConversationHistory = [];
        let savedChatContent = null;
        
        // AIセクレタリーを選択
        async function selectAISecretary() {
            console.log('[AI Secretary] Selected');

            // 既に開いている場合は二重実行を防ぐ
            if (isAISecretaryActive && document.getElementById('aiTranscribeBar')) {
                console.log('[AI Secretary] Already active, skipping');
                return;
            }

            isAISecretaryActive = true;
            
            // ポーリングを止めるため conversationId を退避して null にする
            if (typeof conversationId !== 'undefined' && conversationId) {
                window.__savedConversationIdBeforeAI = conversationId;
                conversationId = null;
            }
            
            // URLを更新（リロード時も秘書チャットを維持）
            try {
            const newUrl = new URL(window.location.href);
            newUrl.searchParams.set('secretary', '1');
            newUrl.searchParams.delete('c');
            window.history.replaceState({}, '', newUrl);
            } catch (e) { console.error('[AI Secretary] URL update error:', e); }
            
            // 他の会話の選択を解除
            document.querySelectorAll('.conv-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // AIセクレタリーをアクティブに
            const aiItem = document.querySelector('.conv-item.ai-secretary');
            if (aiItem) {
                aiItem.classList.add('active');
            }
            
            // 既存のチャットコンテンツを保存
            const messagesArea = document.getElementById('messagesArea');
            if (messagesArea && !savedChatContent) {
                savedChatContent = messagesArea.innerHTML;
            }
            
            // 1回のタップ/クリックで開くよう、同期的に中央パネルを「読み込み中」表示にして白画面を防ぐ（携帯はここで左パネルを閉じる）
            const centerPanel = document.querySelector('.center-panel');
            if (centerPanel) {
                centerPanel.innerHTML = `
                    <div class="chat-header">
                        <div class="chat-header-left"><div class="chat-title-area"><h2>🤖 あなたの秘書</h2></div></div>
                    </div>
                    <div class="messages-area" id="messagesArea">
                        <div class="ai-welcome-card" style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:200px;color:var(--text-muted);">
                            <span style="font-size:24px;margin-bottom:8px;">読み込み中...</span>
                        </div>
                    </div>
                    <div class="input-area" id="inputArea" style="visibility:hidden;"><div class="input-container"></div></div>
                `;
            }
            if (window.innerWidth <= 768) {
                try {
                    if (typeof closeMobileLeftPanel === 'function') closeMobileLeftPanel();
                } catch (e) {}
            }
            
            // サーバーから最新の設定を取得
            try {
                const response = await fetch('api/ai-get-settings-only.php');
                if (response.ok) {
                    const text = await response.text();
                    if (text && text.trim()) {
                        const data = JSON.parse(text);
                        if (data.success) {
                            aiSecretaryName = data.name || 'あなたの秘書';
                            aiCharacterTypes = data.character_types || {};
                            const selected = (data.character_selected === true || data.character_selected === 1 || data.character_selected === '1');
                            if (selected && data.character_type) {
                                aiCharacterType = data.character_type;
                                aiCharacterSelected = true;
                                localStorage.setItem('aiCharacterType', aiCharacterType);
                                localStorage.setItem('aiCharacterSelected', 'true');
                            } else {
                                const hadLocal = localStorage.getItem('aiCharacterSelected') === 'true' && localStorage.getItem('aiCharacterType');
                                if (hadLocal) {
                                    aiCharacterType = localStorage.getItem('aiCharacterType');
                                    aiCharacterSelected = true;
                                } else {
                                    aiCharacterSelected = false;
                                }
                            }
                            localStorage.setItem('aiSecretaryName', aiSecretaryName);

                            window._aiSecSettings = {
                                clone_training_language: data.clone_training_language || 'ja',
                                clone_auto_reply_enabled: data.clone_auto_reply_enabled || 0,
                                conversation_memory_summary: data.conversation_memory_summary || '',
                                reply_stats: data.reply_stats || {}
                            };
                        }
                    }
                }
            } catch (e) {
                console.error('[AI Secretary] Settings fetch error (continuing):', e);
            }
            
            // サイドバーの名前を更新
            try {
            const sidebarNameEl = document.querySelector('.conv-item.ai-secretary .conv-name');
            if (sidebarNameEl) sidebarNameEl.textContent = aiSecretaryName;
            } catch (e) {}
            
            // 表示直前の最終復元
            if (!aiCharacterSelected) {
                const localType = localStorage.getItem('aiCharacterType');
                if (localStorage.getItem('aiCharacterSelected') === 'true' && localType) {
                    aiCharacterType = localType;
                    aiCharacterSelected = true;
                }
            }
            
            // メッセージエリアをAI用に変更
            try {
            await showAIMessages();
            } catch (e) {
                console.error('[AI Secretary] showAIMessages error:', e);
                // フォールバック：最低限のAIパネルを表示
                const cp = document.querySelector('.center-panel');
                if (cp) {
                    cp.innerHTML = `
                        <div class="chat-header"><div class="chat-header-left"><div class="chat-title-area"><h2>🤖 ${aiSecretaryName || 'あなたの秘書'}</h2></div></div></div>
                        <div class="messages-area" id="messagesArea"><div class="ai-welcome-card"><div class="ai-welcome-icon">🤖</div><div class="ai-welcome-text">読み込みに失敗しました。ページを再読み込みしてください。</div></div></div>
                        <div class="input-area" id="inputArea"><div class="input-area-resize-handle" id="inputAreaResizeHandle" title="ドラッグで入力欄の高さを変更" aria-label="入力欄の高さを変更"></div><div class="input-container"><div class="input-row"><div class="input-wrapper"><textarea id="messageInput" class="message-input" placeholder="あなたの秘書に質問..." rows="1" data-ai-mode="true"></textarea></div><button type="button" class="input-send-btn theme-action-btn" onclick="sendMessage()">➤</button></div></div></div>
                    `;
                }
            }
            
            // ヘッダーに秘書名を表示
            try { updateHeaderForAI(); } catch (e) {}
            
            // 入力エリアのイベントを変更
            try { setupAIInput(); } catch (e) {}
            
            // 通常の右パネルを非表示にし、秘書用右パネルを表示
            try {
                const rightPanel = document.getElementById('rightPanel');
                if (rightPanel) rightPanel.style.display = 'none';
                if (typeof SecRP !== 'undefined') {
                    SecRP.show();
                    SecRP.init(window._aiSecSettings || null);
                }
            } catch (e) {}
            
            // リマインダー通知をチェック
            try { checkPendingReminders(); } catch (e) {}
            
            // モバイルの場合、左パネルを閉じる
            if (window.innerWidth <= 768) {
                try { closeMobileLeftPanel(); } catch (e) {}
            }
        }
        
        // selectAISecretaryを即座にグローバル公開（sidebar.phpから呼び出されるため）
        window.selectAISecretary = selectAISecretary;
        
        // 期限が来たリマインダーをチェックして表示
        async function checkPendingReminders() {
            try {
                const response = await fetch('api/ai-pending-notifications.php');
                if (!response.ok) return;
                const text = await response.text();
                if (!text || text.trim() === '') return;
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    return;
                }
                if (data.success && data.notifications && data.notifications.length > 0) {
                    const container = document.getElementById('messagesArea');
                    if (!container) return;
                    
                    data.notifications.forEach(reminder => {
                        showReminderNotification(reminder);
                    });
                }
            } catch (error) {
                // エラー時は静かに無視（リマインダーはオプション機能）
            }
        }
        
        // リマインダー通知を表示
        function showReminderNotification(reminder) {
            const container = document.getElementById('messagesArea');
            if (!container) return;
            
            const notifDiv = document.createElement('div');
            notifDiv.className = 'ai-reminder-notification';
            notifDiv.dataset.reminderId = reminder.id;
            
            const remindAt = new Date(reminder.remind_at);
            const timeStr = remindAt.toLocaleString('ja-JP', {
                month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit'
            });
            
            notifDiv.innerHTML = `
                <div class="reminder-notif-header">
                    <span class="reminder-notif-icon">🔔</span>
                    <span class="reminder-notif-time">${timeStr}</span>
                    <button class="reminder-notif-dismiss" onclick="dismissReminder(${reminder.id}, this)" title="既読にする">✕</button>
                </div>
                <div class="reminder-notif-title">${escapeHtml(reminder.title)}</div>
                ${reminder.description ? `<div class="reminder-notif-desc">${escapeHtml(reminder.description)}</div>` : ''}
            `;
            
            // メッセージエリアの最初に追加
            container.insertBefore(notifDiv, container.firstChild);
        }
        
        // リマインダーを既読にする
        async function dismissReminder(reminderId, button) {
            try {
                const response = await fetch('api/ai.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'mark_reminder_read',
                        reminder_id: reminderId
                    })
                });
                
                if (response.ok) {
                    // 通知要素を削除
                    const notifDiv = button.closest('.ai-reminder-notification');
                    if (notifDiv) {
                        notifDiv.style.opacity = '0';
                        setTimeout(() => notifDiv.remove(), 300);
                    }
                }
            } catch (error) {
                console.error('[Reminder] Failed to dismiss:', error);
            }
        }
        
        // グローバルに公開
        window.dismissReminder = dismissReminder;
        
        // AIセクレタリーの設定（強制リロード対策: ページ埋め込みのDB値を最優先）
        let aiSecretaryName = localStorage.getItem('aiSecretaryName') || 'あなたの秘書';
        let aiCharacterType = localStorage.getItem('aiCharacterType') || null;
        let aiCharacterSelected = localStorage.getItem('aiCharacterSelected') === 'true';
        if (window.__AI_SECRETARY_PREFILL && window.__AI_SECRETARY_PREFILL.character_type) {
            aiCharacterType = window.__AI_SECRETARY_PREFILL.character_type;
            aiCharacterSelected = !!(window.__AI_SECRETARY_PREFILL.character_selected);
            if (window.__AI_SECRETARY_PREFILL.name) aiSecretaryName = window.__AI_SECRETARY_PREFILL.name;
            localStorage.setItem('aiCharacterType', aiCharacterType);
            localStorage.setItem('aiCharacterSelected', 'true');
            localStorage.setItem('aiSecretaryName', aiSecretaryName);
        }
        let aiCharacterTypes = {};
        
        // サーバーから秘書の設定を取得
        async function loadAISecretarySettings() {
            try {
                const response = await fetch('api/ai-get-settings-only.php');
                if (!response.ok) return;
                const text = await response.text();
                if (!text || !text.trim()) return;
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    return;
                }
                if (data.success) {
                    aiSecretaryName = data.name || 'あなたの秘書';
                    aiCharacterTypes = data.character_types || {};
                    // サーバーで明示的に選択済みフラグを確認（0/1 の文字列返却にも対応）
                    const selected = (data.character_selected === true || data.character_selected === 1 || data.character_selected === '1');
                    if (selected && data.character_type) {
                        aiCharacterType = data.character_type;
                        aiCharacterSelected = true;
                        localStorage.setItem('aiCharacterType', aiCharacterType);
                        localStorage.setItem('aiCharacterSelected', 'true');
                    } else {
                        // サーバーが未選択の場合：localStorage に選択があればメモリだけ復元（removeItem は絶対にしない）
                        const localType = localStorage.getItem('aiCharacterType');
                        const hadLocal = (localStorage.getItem('aiCharacterSelected') === 'true' && localType);
                        if (hadLocal) {
                            aiCharacterType = localType;
                            aiCharacterSelected = true;
                        } else {
                            aiCharacterSelected = false;
                        }
                    }
                    localStorage.setItem('aiSecretaryName', aiSecretaryName);
                    
                    // サイドバーの名前を更新
                    const sidebarName = document.querySelector('.conv-item.ai-secretary .conv-name');
                    if (sidebarName) {
                        sidebarName.textContent = aiSecretaryName;
                    }
                    
                    // サイドバーのアバターを更新
                    updateSidebarAvatar();
                }
            } catch (e) {
                // 500や無効JSON時はローカル/デフォルト値のまま続行
            }
        }
        
        // サイドバーのアバターを更新
        function updateSidebarAvatar() {
            const avatar = document.querySelector('.conv-item.ai-secretary .conv-avatar');
            if (avatar) {
                if (aiCharacterSelected && aiCharacterTypes[aiCharacterType] && aiCharacterTypes[aiCharacterType].image) {
                    // 画像で表示
                    avatar.innerHTML = `<img src="${aiCharacterTypes[aiCharacterType].image}" alt="秘書" class="ai-sidebar-avatar-img">`;
                    avatar.style.background = 'transparent';
                } else {
                    avatar.textContent = '🤖';
                    avatar.style.background = '';
                }
            }
        }
        
        // ページ読み込み時に秘書の設定を取得
        loadAISecretarySettings();
        
        // ヘッダーをAI用に更新
        function updateHeaderForAI() {
            // 選択したキャラクターの画像またはデフォルト絵文字
            let avatarHtml = '👩';
            if (aiCharacterSelected && aiCharacterTypes && aiCharacterTypes[aiCharacterType] && aiCharacterTypes[aiCharacterType].image) {
                avatarHtml = `<img src="${aiCharacterTypes[aiCharacterType].image}" alt="秘書" class="ai-header-avatar-img">`;
            } else if (aiCharacterType === 'male_20s') {
                avatarHtml = '👨';
            }
            
            // chat-title-area内のh2を変更
            const titleArea = document.querySelector('.chat-title-area');
            const h2El = titleArea?.querySelector('h2');
            if (h2El) {
                if (!h2El.getAttribute('data-original')) {
                    h2El.setAttribute('data-original', h2El.innerHTML);
                }
                h2El.innerHTML = `<span class="ai-header-avatar" title="クリックでキャラクターを変更">${avatarHtml}</span> ${aiSecretaryName}`;
            }

            // 携帯版: トップバーのロゴ横タイトル（.logo-mobile-chat-title）を秘書名に更新（別チャットのグループ名が残るバグ防止）
            const mobileTitleEl = document.querySelector('.logo-mobile-chat-title');
            if (mobileTitleEl) {
                if (!mobileTitleEl.getAttribute('data-original')) {
                    mobileTitleEl.setAttribute('data-original', mobileTitleEl.textContent || '');
                }
                mobileTitleEl.textContent = aiSecretaryName;
            }
            
            // タイトルエリアのクリックイベントを無効化
            if (titleArea) {
                if (!titleArea.getAttribute('data-original-onclick')) {
                    titleArea.setAttribute('data-original-onclick', titleArea.getAttribute('onclick') || '');
                }
                titleArea.removeAttribute('onclick');
                titleArea.classList.remove('clickable-group');
            }
            
            // 右側のボタンを変更（設定・クリアボタン）- キャラクター選択済みの場合のみ
            const headerRight = document.querySelector('.chat-header-right');
            if (headerRight) {
                if (!headerRight.getAttribute('data-original-html')) {
                    headerRight.setAttribute('data-original-html', headerRight.innerHTML);
                }
                if (aiCharacterSelected) {
                    headerRight.innerHTML = `
                        <button class="header-action-btn ai-settings-btn" onclick="showAISettings()" title="性格設定"><img src="assets/icons/line/gear.svg" alt="" class="icon-line" width="20" height="20"></button>
                    `;
                } else {
                    headerRight.innerHTML = '';
                }
            }
        }
        
        // ヘッダーを元に戻す
        function restoreHeader() {
            // h2を元に戻す
            const titleArea = document.querySelector('.chat-title-area');
            const h2El = titleArea?.querySelector('h2');
            if (h2El && h2El.getAttribute('data-original')) {
                h2El.innerHTML = h2El.getAttribute('data-original');
            }

            // 携帯版: トップバーのロゴ横タイトルを元の表示に戻す
            const mobileTitleEl = document.querySelector('.logo-mobile-chat-title');
            if (mobileTitleEl && mobileTitleEl.getAttribute('data-original')) {
                mobileTitleEl.textContent = mobileTitleEl.getAttribute('data-original');
            }
            
            // タイトルエリアのクリックイベントを復元
            if (titleArea) {
                const originalOnclick = titleArea.getAttribute('data-original-onclick');
                if (originalOnclick) {
                    titleArea.setAttribute('onclick', originalOnclick);
                }
                titleArea.classList.add('clickable-group');
            }
            
            // 右側のボタンを復元
            const headerRight = document.querySelector('.chat-header-right');
            if (headerRight && headerRight.getAttribute('data-original-html')) {
                headerRight.innerHTML = headerRight.getAttribute('data-original-html');
            }
        }
        
        // 記憶タグを処理してAPIを呼び出す
        async function processMemoryTag(content) {
            try {
                const response = await fetch('api/ai.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_memory',
                        content: content
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    console.log('[Memory] Saved:', data);
                    // 記憶保存の確認を表示（オプション）
                    showMemoryConfirmation(content, data.category);
                } else {
                    console.error('[Memory] Failed:', data.message);
                }
            } catch (error) {
                console.error('[Memory] Error:', error);
            }
        }
        
        // 記憶保存の確認表示（トースト表示でレイアウト崩れを防止）
        function showMemoryConfirmation(content, category) {
            const esc = (s) => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            const categoryLabels = {
                'family': '👨‍👩‍👧‍👦 家族',
                'pet': '🐾 ペット',
                'anniversary': '🎂 記念日',
                'work': '💼 仕事',
                'preference': '❤️ 好み',
                'general': '📝 メモ'
            };
            
            const wrapper = document.createElement('div');
            wrapper.className = 'ai-confirm-toast';
            wrapper.innerHTML = `
                <div class="ai-memory-confirm">
                    <div class="memory-confirm-icon">🧠</div>
                    <div class="memory-confirm-content">
                        <strong>記憶しました</strong><br>
                        <span class="memory-category">${categoryLabels[category] || category}</span>: 
                        <span class="memory-content">${esc(content)}</span>
                    </div>
                </div>
            `;
            document.body.appendChild(wrapper);
            
            // 5秒後にフェードアウト
            setTimeout(() => {
                wrapper.style.opacity = '0';
                wrapper.style.transition = 'opacity 0.5s ease';
                setTimeout(() => wrapper.remove(), 500);
            }, 5000);
        }
        
        // ユーザー発言から秘書の名前を抽出（AIタグに頼らないフォールバック）
        function extractSecretaryNameFromUserMessage(text) {
            if (!text || typeof text !== 'string') return null;
            const t = text.trim();
            if (t.length < 2 || t.length > 120) return null;
            // カレンダー・リマインダー・予定の文脈では抽出しない（引用符内はイベントタイトル）
            if (/(?:カレンダー|リマインド|予定)(?:を|に|で|の).*(?:入れて|追加|登録)|(?:入れて|追加).*(?:カレンダー|予定)/.test(t)) {
                return null;
            }
            let name = null;
            // 「〇〇」または"〇〇"で囲まれた名前
            const quoted = t.match(/[「『"]([^」』"]{1,20})[」』"]/);
            if (quoted) {
                // 明示的な名前指定表現がある場合のみ採用（「〇〇」のみの抽出は誤検知しやすい）
                if (/という名前|と呼んで|と命名|名前を|名前は/.test(t)) {
                    name = quoted[1].trim();
                }
            }
            // 〇〇という名前で / 名前は〇〇 / 〇〇と命名 / 〇〇と呼んで
            if (!name) {
                const m1 = t.match(/(.+?)という名前で(いかが|して)/);
                const m2 = t.match(/名前は(?:「|『|")([^」』"]{1,20})(?:」|』|")/);
                const m3 = t.match(/名前を(.+?)(?:に|と)/);
                const m4 = t.match(/(.+?)と命名/);
                const m5 = t.match(/(.+?)と呼んで/);
                const m6 = t.match(/あなたの名前は(.+?)です/);
                const m7 = t.match(/(.+?)という名前に/);
                name = (m1 && m1[1]) || (m2 && m2[1]) || (m3 && m3[1]) || (m4 && m4[1]) || (m5 && m5[1]) || (m6 && m6[1]) || (m7 && m7[1]);
            }
            if (name) {
                name = name.trim();
                if (name.length >= 1 && name.length <= 20) return name;
            }
            return null;
        }
        
        // 秘書の名前タグを処理してAPIを呼び出しUIを更新
        async function processSecretaryNameTag(name) {
            name = String(name || '').trim();
            if (!name) return;
            if (name.length > 20) name = name.substring(0, 20);
            try {
                const response = await fetch('api/ai.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'save_secretary_name', name: name })
                });
                const data = await response.json();
                if (data.success) {
                    aiSecretaryName = name;
                    localStorage.setItem('aiSecretaryName', name);
                    const sidebarName = document.querySelector('.conv-item.ai-secretary .conv-name');
                    if (sidebarName) sidebarName.textContent = name;
                    if (typeof updateHeaderForAI === 'function') updateHeaderForAI();
                    showSecretaryNameConfirmation(name);
                }
            } catch (error) {
                console.error('[Secretary Name] Error:', error);
            }
        }
        
        // 秘書の名前変更確認トースト
        function showSecretaryNameConfirmation(name) {
            const esc = (s) => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            const wrapper = document.createElement('div');
            wrapper.className = 'ai-confirm-toast';
            wrapper.innerHTML = `
                <div class="ai-memory-confirm" style="background:linear-gradient(135deg,#f0fdf4 0%,#dcfce7 100%);border-color:#86efac;">
                    <div class="memory-confirm-icon">✨</div>
                    <div class="memory-confirm-content" style="color:#166534;">
                        <strong>名前を変更しました</strong><br>
                        <span>${esc(name)} と呼ぶようにしました</span>
                    </div>
                </div>
            `;
            document.body.appendChild(wrapper);
            setTimeout(() => {
                wrapper.style.opacity = '0';
                wrapper.style.transition = 'opacity 0.5s ease';
                setTimeout(() => wrapper.remove(), 500);
            }, 3000);
        }
        
        // 改善提案の聞き取り完了後：「提案を送信」ボタンを表示
        function processImprovementConfirmed() {
            const container = document.getElementById('messagesArea');
            if (!container) return;

            const btnCard = document.createElement('div');
            btnCard.className = 'improvement-submit-card';
            btnCard.innerHTML =
                '<button class="improvement-submit-btn" type="button">' +
                '<span class="improvement-submit-icon">📋</span> 提案を送信' +
                '</button>';
            container.appendChild(btnCard);

            const scrollArea = container.closest('.messages-area') || container;
            scrollArea.scrollTop = scrollArea.scrollHeight;

            btnCard.querySelector('.improvement-submit-btn').addEventListener('click', function() {
                submitImprovementReport(btnCard);
            });
        }

        // 改善提案の送信処理（ボタンクリック時）
        async function submitImprovementReport(btnCard) {
            const btn = btnCard.querySelector('.improvement-submit-btn');
            btn.disabled = true;
            btn.textContent = '送信中...';

            const container = document.getElementById('messagesArea');
            if (!container) return;

            const cards = Array.from(container.querySelectorAll('.message-card:not(.ai-loading)'));

            const isSkippable = function(text) {
                return /改善提案を受け付けました|改善提案を記録しました|記録しました。管理画面|IMPROVEMENT_CONFIRMED|提案を送信/.test(text);
            };

            // ステップ1: 最新のAI確認サマリー（■ 場所 等）を探す
            let summaryIdx = -1;
            let confirmationSummary = '';
            for (let i = cards.length - 1; i >= 0; i--) {
                const card = cards[i];
                if (card.classList.contains('own')) continue;
                const text = (card.querySelector('.message-text')?.textContent?.trim() || '');
                if (!text || isSkippable(text)) continue;
                if (/■\s*場所/.test(text) && /■\s*(現在の状態|現状)/.test(text)) {
                    confirmationSummary = text;
                    summaryIdx = i;
                    break;
                }
            }

            // ステップ2: サマリーより前のユーザーメッセージを収集（この改善提案に関連するもののみ）
            let userMessages = [];
            if (summaryIdx > 0) {
                // サマリーの直前数件のみ（別の改善提案の会話を含まない）
                const searchStart = Math.max(0, summaryIdx - 6);
                for (let i = searchStart; i < summaryIdx; i++) {
                    const card = cards[i];
                    const isOwn = card.classList.contains('own');
                    const text = (card.querySelector('.message-text')?.textContent?.trim() || '');
                    if (!text || isSkippable(text)) continue;
                    if (isOwn) {
                        const t = text.trim();
                        if (!/^(そうです|はい|うん|OK|お願い|合って|それで|いいよ|いいです)/i.test(t) || t.length > 30) {
                            userMessages.push(t);
                        }
                    }
                }
            }

            // サマリーが見つからない場合: 直近のAI発言とユーザー発言を1組取得
            if (!confirmationSummary) {
                for (let i = cards.length - 1; i >= Math.max(0, cards.length - 6); i--) {
                    const card = cards[i];
                    const text = (card.querySelector('.message-text')?.textContent?.trim() || '');
                    if (!text || isSkippable(text)) continue;
                    if (!card.classList.contains('own') && !confirmationSummary) {
                        confirmationSummary = text;
                    } else if (card.classList.contains('own') && confirmationSummary && userMessages.length === 0) {
                        userMessages.push(text);
                        break;
                    }
                }
            }

            const userMessageToSend = userMessages.join('\n\n');
            const aiReplyToSend = confirmationSummary;

            if (!userMessageToSend && !aiReplyToSend) {
                btn.textContent = '送信失敗（会話が不足）';
                return;
            }

            try {
                console.log('[AI Secretary] 提案を送信:', { userLen: userMessageToSend.length, aiLen: aiReplyToSend.length });
                const res = await fetch('api/ai.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'extract_improvement_report',
                        user_message: userMessageToSend || aiReplyToSend,
                        ai_reply: aiReplyToSend
                    })
                });
                const data = await res.json();
                if (data.success) {
                    console.log('[AI Secretary] 改善提案を記録しました', data.report_id);
                    btnCard.innerHTML = '<div class="improvement-submit-done">✅ 改善提案を受け付けました。改善が完了しましたら通知でお知らせいたします。</div>';
                    if (typeof showAIToast === 'function') showAIToast('改善提案を受け付けました');
                } else {
                    console.warn('[AI Secretary] 改善提案の記録失敗', data.message);
                    btn.disabled = false;
                    btn.innerHTML = '<span class="improvement-submit-icon">📋</span> 再送信';
                    addAIChatMessage('記録に失敗しました: ' + (data.message || 'しばらくしてからお試しください'), 'ai');
                }
            } catch (e) {
                console.error('[AI Secretary] 改善提案の記録中にエラー', e);
                btn.disabled = false;
                btn.innerHTML = '<span class="improvement-submit-icon">📋</span> 再送信';
                addAIChatMessage('通信エラーが発生しました。再度お試しください。', 'ai');
            }
        }

        // カレンダーイベント追加タグを処理
        async function processCalendarCreateTag(tagContent) {
            try {
                // 形式: カレンダー名:YYYY-MM-DDTHH:MM:YYYY-MM-DDTHH:MM:タイトル
                // 日時は T またはスペース可。前後の空白を許容
                const raw = String(tagContent || '').trim();
                const normalized = raw.replace(/\s+/g, ' ');
                const match = normalized.match(/^([^:]+)\s*:\s*(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?)\s*:\s*(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?)\s*:\s*(.+)$/);
                if (!match) {
                    console.error('[Calendar] Invalid tag format:', tagContent);
                    return;
                }
                let calendarTarget = (match[1] || 'default').trim();
                calendarTarget = calendarTarget.replace(/\s*[（(]\s*デフォルト\s*[)）]\s*$/g, '').trim() || 'default';
                const startDatetime = (match[2] || '').replace('T', ' ').trim();
                const endDatetime = (match[3] || '').replace('T', ' ').trim();
                const title = (match[4] || '予定').trim();
                const response = await fetch('api/google-calendar.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        action: 'create_event',
                        calendar_target: calendarTarget,
                        start_datetime: startDatetime,
                        end_datetime: endDatetime,
                        title: title,
                        description: ''
                    })
                });
                const text = await response.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseErr) {
                    console.error('[Calendar] Invalid JSON response:', text.substring(0, 200));
                    showCalendarConfirmToast('予定の追加に失敗しました（サーバーエラー）', title);
                    return;
                }
                if (data.success) {
                    showCalendarConfirmToast('カレンダーに追加しました', title);
                } else {
                    console.error('[Calendar] Failed:', data.message, data.error_detail || '');
                    if (typeof ErrorCollector !== 'undefined' && ErrorCollector.report) {
                        const msg = data.error_detail
                            ? '[Calendar] 予定追加失敗: ' + data.error_detail
                            : '[Calendar] 予定追加失敗: ' + (data.message || '');
                        ErrorCollector.report(msg, {
                            error_detail: data.error_detail || null,
                            calendar_target: calendarTarget,
                            title: title,
                            start: startDatetime,
                            end: endDatetime
                        });
                    }
                    showCalendarConfirmToast(data.message || '予定の追加に失敗しました', title);
                }
            } catch (error) {
                console.error('[Calendar] Error:', error);
                if (typeof ErrorCollector !== 'undefined' && ErrorCollector.report) {
                    ErrorCollector.report('[Calendar] 予定追加例外: ' + (error && error.message), {
                        stack: error && error.stack
                    });
                }
                showCalendarConfirmToast('予定の追加に失敗しました', '');
            }
        }
        function showCalendarConfirmToast(msg, sub) {
            const isError = /失敗|エラー|接続できません|認証|切れて|無効/.test(msg || '');
            const wrapper = document.createElement('div');
            wrapper.className = 'ai-confirm-toast';
            const icon = isError ? '⚠️' : '📅';
            const style = isError ? 'background:#fff0f0;border-left:4px solid #e74c3c;color:#c0392b;' : '';
            wrapper.innerHTML = '<div class="ai-reminder-confirm" style="' + style + '">' + icon + ' ' + (msg || '') + '<br><small>' + (sub || '') + '</small></div>';
            document.body.appendChild(wrapper);
            setTimeout(() => { wrapper.style.opacity = '0'; setTimeout(() => wrapper.remove(), 500); }, isError ? 6000 : 3000);
        }
        async function processCalendarUpdateTag(tagContent) {
            try {
                // 形式: カレンダー名:YYYY-MM-DD:元タイトル:YYYY-MM-DDTHH:MM:YYYY-MM-DDTHH:MM:新タイトル
                const match = tagContent.match(/^([^:]+):(\d{4}-\d{2}-\d{2}):([^:]+):(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}):(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}):(.+)$/);
                if (!match) { console.error('[Calendar] Invalid UPDATE format:', tagContent); return; }
                let calendarTarget = (match[1] || 'default').trim();
                calendarTarget = calendarTarget.replace(/\s*[（(]\s*デフォルト\s*[)）]\s*$/g, '').trim() || 'default';
                const eventDate = match[2] || '';
                const oldTitle = match[3] || '';
                const newStart = (match[4] || '').replace('T', ' ');
                const newEnd = (match[5] || '').replace('T', ' ');
                const newTitle = (match[6] || oldTitle).trim();
                const response = await fetch('api/google-calendar.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update_event',
                        calendar_target: calendarTarget,
                        event_date: eventDate,
                        old_title: oldTitle,
                        new_start: newStart,
                        new_end: newEnd,
                        new_title: newTitle
                    })
                });
                const data = await response.json();
                if (data.success) showCalendarConfirmToast('予定を更新しました', newTitle);
                else console.error('[Calendar] Update failed:', data.message);
            } catch (e) { console.error('[Calendar] Update error:', e); }
        }
        async function processCalendarDeleteTag(tagContent) {
            try {
                const parts = tagContent.split(':');
                if (parts.length < 3) { console.error('[Calendar] Invalid DELETE format:', tagContent); return; }
                let calendarTarget = (parts[0] || 'default').trim();
                calendarTarget = calendarTarget.replace(/\s*[（(]\s*デフォルト\s*[)）]\s*$/g, '').trim() || 'default';
                const eventDate = parts[1] || '';
                const title = parts.slice(2).join(':').trim() || '';
                const response = await fetch('api/google-calendar.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete_event',
                        calendar_target: calendarTarget,
                        event_date: eventDate,
                        title: title
                    })
                });
                const data = await response.json();
                if (data.success) showCalendarConfirmToast('予定を削除しました', title);
                else console.error('[Calendar] Delete failed:', data.message);
            } catch (e) { console.error('[Calendar] Delete error:', e); }
        }

        async function processSheetsEditTag(spreadsheetId, instruction) {
            try {
                const response = await fetch('api/sheets-edit.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ spreadsheet_id: spreadsheetId, instruction: instruction })
                });
                const data = await response.json().catch(() => ({}));
                if (data.success) {
                    if (typeof showCalendarConfirmToast === 'function') {
                        showCalendarConfirmToast('スプレッドシートを更新しました', data.updated_range || '');
                    } else {
                        addAIChatMessage('📊 スプレッドシートを更新しました。', 'ai');
                    }
                } else {
                    addAIChatMessage('スプレッドシートの更新に失敗しました: ' + (data.message || '未連携の場合は設定で連携してください'), 'ai');
                }
            } catch (e) {
                console.error('[Sheets] Edit error:', e);
                addAIChatMessage('スプレッドシートの更新に失敗しました。', 'ai');
            }
        }

        async function processDocumentEditTag(fileId, instruction) {
            try {
                const response = await fetch('api/document-edit.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ file_id: fileId, instruction: instruction })
                });
                const data = await response.json().catch(() => ({}));
                if (data.success) {
                    if (typeof showCalendarConfirmToast === 'function') {
                        showCalendarConfirmToast(data.message || 'ファイルを更新しました', '');
                    } else {
                        addAIChatMessage('📄 ' + (data.message || 'ファイルを更新しました'), 'ai');
                    }
                } else {
                    addAIChatMessage('ファイルの更新に失敗しました: ' + (data.message || ''), 'ai');
                }
            } catch (e) {
                console.error('[Document] Edit error:', e);
                addAIChatMessage('ファイルの更新に失敗しました。', 'ai');
            }
        }

        // リマインダータグを処理してAPIを呼び出す
        async function processReminderTag(tagContent) {
            try {
                // タグの形式: YYYY-MM-DD HH:MM:タイトル:繰り返しタイプ
                const parts = tagContent.split(':');
                if (parts.length < 3) {
                    console.error('[Reminder] Invalid tag format:', tagContent);
                    return;
                }
                
                // 日付と時刻を結合
                const datetime = parts[0] + ':' + parts[1]; // YYYY-MM-DD HH:MM
                const title = parts[2] || 'リマインダー';
                const remindType = parts[3] || 'once';
                
                const response = await fetch('api/ai.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'create_reminder',
                        title: title,
                        remind_at: datetime,
                        remind_type: remindType
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    console.log('[Reminder] Created:', data);
                    // 通知を表示
                    showReminderConfirmation(title, datetime, remindType);
                } else {
                    console.error('[Reminder] Failed:', data.message);
                }
            } catch (error) {
                console.error('[Reminder] Error:', error);
            }
        }
        
        // リマインダー設定完了の確認表示（トースト表示でレイアウト崩れを防止）
        function showReminderConfirmation(title, datetime, type) {
            const esc = (s) => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            const typeLabels = {
                'once': '1回のみ',
                'daily': '毎日',
                'weekly': '毎週',
                'monthly': '毎月',
                'yearly': '毎年'
            };
            
            const wrapper = document.createElement('div');
            wrapper.className = 'ai-confirm-toast';
            wrapper.innerHTML = `
                <div class="ai-reminder-confirm">
                    <div class="reminder-confirm-icon">⏰</div>
                    <div class="reminder-confirm-content">
                        <strong>リマインダーを設定しました</strong><br>
                        <span class="reminder-title">${esc(title)}</span><br>
                        <span class="reminder-datetime">${esc(datetime)} (${typeLabels[type] || type})</span>
                    </div>
                </div>
            `;
            document.body.appendChild(wrapper);
            
            // 5秒後にフェードアウト
            setTimeout(() => {
                wrapper.style.opacity = '0';
                wrapper.style.transition = 'opacity 0.5s ease';
                setTimeout(() => wrapper.remove(), 500);
            }, 5000);
        }
        
        // AIメッセージエリアを表示（履歴読み込み完了まで待つ）
        // 他会話から戻った場合は center-panel がグループ用のため #aiTranscribeBar が無い → 必ずAI用パネルを再構築する
        async function showAIMessages() {
            const centerPanel = document.querySelector('.center-panel');
            if (!centerPanel) return;
            const hasAIPanel = document.getElementById('aiTranscribeBar') && document.getElementById('aiAlwaysOnBtn');
            let container = document.getElementById('messagesArea');
            
            // AI用パネルが無い場合（初回 or 他会話から戻った直後）は必ず作成する
            if (!hasAIPanel) {
                
                // 中央パネルの内容を置き換え
                const nameForHeader = aiSecretaryName || 'あなたの秘書';
                centerPanel.innerHTML = `
                    <div class="chat-header">
                        <div class="chat-header-left">
                            <div class="chat-title-area">
                                <h2>🤖 ${escapeHtml(nameForHeader)}</h2>
                            </div>
                        </div>
                        <div class="chat-header-right">
                            <button class="header-action-btn ai-settings-btn" onclick="showAISettings()" title="性格設定"><img src="assets/icons/line/gear.svg" alt="" class="icon-line" width="20" height="20"></button>
                        </div>
                    </div>
                    <div class="messages-area" id="messagesArea"></div>
                    <div class="input-area" id="inputArea">
                        <div class="input-area-resize-handle" id="inputAreaResizeHandle" title="ドラッグで入力欄の高さを変更" aria-label="入力欄の高さを変更"></div>
                        <div class="input-container">
                            <div class="input-toolbar">
                                <div class="input-toolbar-left">
                                    <button class="toolbar-btn to-btn" disabled style="opacity:0.5">TO</button>
                                    <button class="toolbar-btn gif-btn" disabled style="opacity:0.5">GIF</button>
                                    <button class="toolbar-btn call-toolbar-btn" disabled style="opacity:0.5"><span class="btn-icon">☎</span></button>
                                    <button class="toolbar-btn attach-btn ai-attach-btn" type="button" onclick="document.getElementById('aiFileInput').click()" title="ファイルを添付"><span class="btn-icon">⊕</span></button>
                                    <button class="toolbar-btn attach-btn ai-always-on-btn" type="button" id="aiAlwaysOnBtn" title="常時起動で音声指示（名前～指示～実行）" aria-label="常時起動">常時起動</button>
                                    <input type="file" id="aiFileInput" accept=".txt,.md,.csv,.tsv,.json,.xml,.html,.css,.js,.py,.java,.sql,.yaml,.yml,.log,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.webp" style="display:none;" aria-label="AI秘書にファイルを添付" onchange="handleAIFileAttach(this)">
                                </div>
                                <div class="input-toolbar-right">
                                    <label class="enter-send-label"><input type="checkbox" id="aiEnterSendCheck" checked> Enterで送信</label>
                                    <button type="button" class="toolbar-toggle-btn" onclick="toggleInputArea && toggleInputArea()" title="入力欄を非表示" aria-label="入力欄を非表示">☰</button>
                                </div>
                            </div>
                            <div class="ai-transcribe-bar" id="aiTranscribeBar" style="display:none;" role="status" aria-live="polite">
                                <span class="ai-transcribe-bar-label" id="aiTranscribeBarLabel">常時起動中</span>
                                <button type="button" class="ai-transcribe-bar-stop" id="aiTranscribeBarStop">停止</button>
                            </div>
                            <div class="input-row">
                                <div class="input-wrapper">
                                    <textarea id="messageInput" class="message-input" placeholder="あなたの秘書に質問..." rows="1" data-ai-mode="true" style="min-height:52px;max-height:300px;height:52px;"></textarea>
                                </div>
                                <button type="button" class="input-send-btn theme-action-btn" onclick="sendMessage()" title="送信" aria-label="送信">➤</button>
                            </div>
                        </div>
                    </div>
                `;
                container = document.getElementById('messagesArea');
                
                // 入力欄のイベント設定
                const input = document.getElementById('messageInput');
                if (input) {
                    input.addEventListener('keydown', function(e) {
                        if (e.key !== 'Enter') return;
                        var enterToSend = document.getElementById('aiEnterSendCheck');
                        var useEnterToSend = (enterToSend ? enterToSend.checked : true);
                        if (useEnterToSend && !e.shiftKey) {
                            e.preventDefault();
                            sendMessage();
                        } else if (!useEnterToSend && e.shiftKey) {
                            e.preventDefault();
                            sendMessage();
                        }
                    });
                    input.addEventListener('input', function() {
                        var cap = 180;
                        this.style.setProperty('min-height', '0px', 'important');
                        this.style.setProperty('max-height', 'none', 'important');
                        this.style.setProperty('height', 'auto', 'important');
                        var sh = this.scrollHeight;
                        var h = Math.min(sh, cap);
                        this.style.setProperty('height', h + 'px', 'important');
                        this.style.setProperty('max-height', cap + 'px', 'important');
                        this.style.setProperty('min-height', '0px', 'important');
                    });
                }
                // AI用⊕は openUnifiedAttachFilePicker({ imageOnly: true }) に統一（onclick で呼び出し）。aiFileInput は後方互換のため残す。
                const aiFileInput = document.getElementById('aiFileInput');
                if (aiFileInput) {
                    aiFileInput.onchange = function() {
                        if (this.files && this.files.length > 0) onAttachFileSelected(this.files);
                        this.value = '';
                    };
                }
                // AI秘書 常時起動UI：グローバル __aiAlwaysOn に接続（共通の wireAlwaysOnUI でグループ・AI両方対応）
                if (typeof wireAlwaysOnUI === 'function') wireAlwaysOnUI();
            }
            if (typeof window.initInputAreaResize === 'function') window.initInputAreaResize();
            
            if (!container) return;
            
            // 表示直前の最終復元：localStorage に選択があればメモリに反映（強制リロード対策）
            if (!aiCharacterSelected) {
                const localType = localStorage.getItem('aiCharacterType');
                if (localStorage.getItem('aiCharacterSelected') === 'true' && localType) {
                    aiCharacterType = localType;
                    aiCharacterSelected = true;
                }
            }
            
            // キャラクター未選択の場合は選択画面を表示（履歴は読み込まない）
            if (!aiCharacterSelected) {
                showCharacterSelectionScreen(container);
                return;
            }
            
            // キャラクター情報を取得
            let emoji = '👩';
            let image = 'assets/icons/secretary_female.png';
            if (aiCharacterTypes && aiCharacterTypes[aiCharacterType]) {
                emoji = aiCharacterTypes[aiCharacterType].emoji;
                image = aiCharacterTypes[aiCharacterType].image;
            } else if (aiCharacterType === 'male_20s') {
                emoji = '👨';
                image = 'assets/icons/secretary_male.png';
            }
            
            // AI用のメッセージを表示（画像を使用）
            container.innerHTML = `
                <div class="ai-welcome-card" id="aiWelcomeCard">
                    <div class="ai-welcome-icon">
                        <img src="${image}" alt="${aiSecretaryName}" class="ai-welcome-img" onerror="this.style.display='none';this.parentNode.innerHTML='${emoji}';">
                    </div>
                    <div class="ai-welcome-text">
                        こんにちは！私は<strong>${aiSecretaryName}</strong>です。<br>
                        Social9の使い方や機能について、何でもお気軽にご質問ください。
                    </div>
                </div>
            `;
            container.classList.add('ai-mode');
            
            // 過去の履歴を読み込み（await してリロード後も確実に表示）
            const welcomeText = container.querySelector('.ai-welcome-text');
            if (welcomeText) welcomeText.innerHTML = '履歴を読み込み中...';
            await loadAIHistory();
            if (window.__aiVoiceHistoryDirty) {
                try { window.__aiVoiceHistoryDirty = false; } catch (e) {}
                await loadAIHistory();
            }
            const welcomeCard = document.getElementById('aiWelcomeCard');
            if (welcomeCard && !container.querySelector('.message-card') && welcomeText) {
                welcomeText.innerHTML = `こんにちは！私は<strong>${aiSecretaryName || 'あなたの秘書'}</strong>です。<br>Social9の使い方や機能について、何でもお気軽にご質問ください。`;
            }
            
            // 常時起動表示の同期（履歴描画後・他会話から戻った直後に確実にバーとボタンを表示）
            var g = window.__aiAlwaysOn;
            if (g && typeof g.syncPanelUI === 'function') {
                g.syncPanelUI();
                setTimeout(g.syncPanelUI.bind(g), 500);
                setTimeout(g.syncPanelUI.bind(g), 1000);
                setTimeout(g.syncPanelUI.bind(g), 1500);
            }
            
            // リマインダー通知をチェック
            checkPendingReminders();
        }
        
        // キャラクター選択画面を表示
        function showCharacterSelectionScreen(container) {
            container.innerHTML = `
                <div class="ai-character-selection-screen">
                    <div class="ai-character-selection-header">
                        <div class="ai-character-selection-title">🎉 あなたの秘書を選んでください</div>
                        <p class="ai-character-selection-subtitle">どちらのタイプがお好みですか？</p>
                    </div>
                    
                    <div class="ai-character-cards">
                        <div class="ai-character-card" onclick="selectInitialCharacter('female_20s')">
                            <div class="ai-character-card-avatar">
                                <img src="assets/icons/secretary_female.png" alt="女性秘書" class="ai-character-img">
                            </div>
                            <div class="ai-character-card-name">女性タイプ</div>
                            <div class="ai-character-card-personality">
                                <div class="ai-personality-title">✨ 性格・特徴</div>
                                <ul class="ai-personality-list">
                                    <li>明るく親しみやすい</li>
                                    <li>相手の気持ちに寄り添う共感力</li>
                                    <li>丁寧だけど堅すぎない話し方</li>
                                    <li>時々絵文字を使って和やかに</li>
                                    <li>落ち込んでいる時は優しく励ます</li>
                                </ul>
                            </div>
                            <button class="ai-character-select-btn">このタイプを選ぶ</button>
                        </div>
                        
                        <div class="ai-character-card" onclick="selectInitialCharacter('male_20s')">
                            <div class="ai-character-card-avatar">
                                <img src="assets/icons/secretary_male.png" alt="男性秘書" class="ai-character-img">
                            </div>
                            <div class="ai-character-card-name">男性タイプ</div>
                            <div class="ai-character-card-personality">
                                <div class="ai-personality-title">✨ 性格・特徴</div>
                                <ul class="ai-personality-list">
                                    <li>頼りがいがあり落ち着いている</li>
                                    <li>論理的で分かりやすい説明</li>
                                    <li>問題解決に積極的</li>
                                    <li>目標達成を一緒に考える</li>
                                    <li>時には冗談も交えてリラックス</li>
                                </ul>
                            </div>
                            <button class="ai-character-select-btn">このタイプを選ぶ</button>
                        </div>
                    </div>
                    
                    <p class="ai-character-selection-note">※ 後から設定で変更することもできます</p>
                </div>
            `;
            container.classList.add('ai-mode');
        }
        
        // 初回キャラクター選択
        async function selectInitialCharacter(type) {
            if (!aiCharacterTypes[type]) {
                // まだロードされていない場合はデフォルト値を使用
                aiCharacterTypes = {
                    'female_20s': { name: '女性', emoji: '👩', image: 'assets/icons/secretary_female.png', description: '明るく親しみやすい女性アシスタント' },
                    'male_20s': { name: '男性', emoji: '👨', image: 'assets/icons/secretary_male.png', description: '頼りがいのある男性アシスタント' }
                };
            }
            
            // サーバーに保存
            try {
                await fetch('api/ai.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_character_type',
                        type: type
                    })
                });
            } catch (error) {
                console.error('Failed to save character type:', error);
            }
            
            // ローカルに保存
            aiCharacterType = type;
            aiCharacterSelected = true;
            localStorage.setItem('aiCharacterType', type);
            localStorage.setItem('aiCharacterSelected', 'true');
            
            // ヘッダーとサイドバーを更新
            updateHeaderForAI();
            updateSidebarAvatar();
            
            // 通常のウェルカム画面を表示
            const container = document.getElementById('messagesArea');
            const image = aiCharacterTypes[type].image || '';
            const typeName = aiCharacterTypes[type].name;
            
            container.innerHTML = `
                <div class="ai-welcome-card" id="aiWelcomeCard">
                    <div class="ai-welcome-icon">
                        ${image ? `<img src="${image}" alt="${typeName}" class="ai-welcome-img">` : aiCharacterTypes[type].emoji}
                    </div>
                    <div class="ai-welcome-text">
                        はじめまして！私は<strong>${aiSecretaryName}</strong>です。<br>
                        ${typeName}タイプとして、これからよろしくお願いします！<br><br>
                        Social9の使い方や機能について、何でもお気軽にご質問ください。<br>
                        会話の中で私に名前をつけてくれたら、その名前でお呼びくださいね。
                    </div>
                </div>
            `;
        }
        
        // 入力エリアをAI用に設定
        function setupAIInput() {
            const messageInput = document.getElementById('messageInput');
            if (!messageInput) return;
            
            // 既存のイベントを上書きするフラグを設定
            messageInput.setAttribute('data-ai-mode', 'true');
            messageInput.placeholder = 'あなたの秘書に質問...';
        }
        
        // 通常のチャットに戻す
        function hideAISecretaryChat() {
            isAISecretaryActive = false;
            
            // 退避した conversationId を復元
            if (window.__savedConversationIdBeforeAI) {
                conversationId = window.__savedConversationIdBeforeAI;
                window.__savedConversationIdBeforeAI = null;
            }
            
            // URLから秘書パラメータを削除
            const newUrl = new URL(window.location.href);
            newUrl.searchParams.delete('secretary');
            window.history.replaceState({}, '', newUrl);
            
            // ヘッダーを元に戻す
            restoreHeader();
            
            // メッセージエリアを元に戻す
            const container = document.getElementById('messagesArea');
            if (container && savedChatContent) {
                container.innerHTML = savedChatContent;
                container.classList.remove('ai-mode');
            }
            
            // 入力エリアを元に戻す
            const messageInput = document.getElementById('messageInput');
            if (messageInput) {
                messageInput.removeAttribute('data-ai-mode');
                messageInput.placeholder = 'メッセージを入力...';
            }
            
            // 秘書用右パネルを非表示にし、通常の右パネルを復帰
            try {
                if (typeof SecRP !== 'undefined') SecRP.hide();
            } catch (e) {}
            const rightPanel = document.getElementById('rightPanel');
            if (rightPanel) {
                rightPanel.style.display = '';
            }
            
            savedChatContent = null;
        }
        
        // 位置情報を取得
        let userLocation = null;
        
        async function getUserLocation() {
            return new Promise((resolve) => {
                if (!navigator.geolocation) {
                    resolve(null);
                    return;
                }
                
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        userLocation = {
                            lat: position.coords.latitude,
                            lng: position.coords.longitude
                        };
                        resolve(userLocation);
                    },
                    (error) => {
                        console.log('[Location] Error:', error.message);
                        resolve(null);
                    },
                    { timeout: 5000, maximumAge: 300000 } // 5秒タイムアウト、5分キャッシュ
                );
            });
        }
        
        // 位置情報が必要な質問かどうかを判定
        function needsLocation(text) {
            const keywords = [
                '近く', 'この辺', '周辺', 'おすすめのお店', '美味しい店',
                'レストラン', 'カフェ', 'ランチ', 'ディナー', '居酒屋',
                'コンビニ', 'スーパー', '病院', 'クリニック', '薬局',
                '美容院', 'ヘアサロン', 'ガソリンスタンド', '駐車場',
                'ラーメン', '寿司', 'ピザ', 'ハンバーガー', 'パン屋',
                '現在地', '今いる場所', 'ここから'
            ];
            return keywords.some(kw => text.includes(kw));
        }
        
        // 近くのお店を検索（クライアント側でAPIに渡すために位置情報を準備）
        async function searchNearbyPlaces(lat, lng, query) {
            // この関数はサーバー側のPlaces APIを呼び出すのではなく、
            // 位置情報をai.phpに渡してサーバー側で検索させる
            // ここでは位置情報を返すだけ
            return []; // 空の配列を返し、実際の検索はサーバー側で行う
        }
        
        // AIメッセージを送信（通常の送信関数から呼ばれる）
        // imagePath: 画像添付時のパス（api/upload.phpでアップロード済み）
        // fileMeta: { path, name, isImage } 画像以外のファイル添付時の情報
        async function sendAIMessage(content, imagePath = null, fileMeta = null) {
            if (!content && !imagePath && !fileMeta) return false;
            
            const container = document.getElementById('messagesArea');
            if (!container) return false;
            
            // ウェルカムカードを非表示
            const welcome = document.getElementById('aiWelcomeCard');
            if (welcome) welcome.style.display = 'none';
            
            // フォールバック：直前のAIメッセージが改善確認サマリーで、今回が肯定的メッセージの場合を検出
            let _pendingImprovementConfirm = false;
            if (content && !imagePath) {
                const affirmRegex = /^(そう(です)?|はい|うん|OK|おk|お願い|合って|それで|いいよ|いいです|大丈夫|問題な|オッケー)/i;
                if (affirmRegex.test(content.trim())) {
                    const cards = Array.from(container.querySelectorAll('.message-card:not(.ai-loading)'));
                    for (let i = cards.length - 1; i >= 0; i--) {
                        const card = cards[i];
                        if (!card.classList.contains('own')) {
                            const text = (card.querySelector('.message-text')?.textContent || '');
                            if (/■\s*場所/.test(text) && /■\s*現在の状態/.test(text) && /■\s*望ましい状態/.test(text)) {
                                console.log('[AI Secretary] フォールバック: 改善確認への肯定を検出');
                                _pendingImprovementConfirm = true;
                            }
                            break;
                        }
                    }
                }
            }

            // ユーザーメッセージを表示（画像・ファイルがある場合はそれも表示）
            let displayContent = content || '';
            if (fileMeta && !fileMeta.isImage) {
                displayContent = '📎 ' + fileMeta.name + (displayContent ? '\n' + displayContent : '\nこのファイルの内容を読み取ってください');
            }
            const displayImagePath = imagePath || (fileMeta && fileMeta.isImage ? fileMeta.path : null);
            addAIChatMessage(displayContent, 'user', null, false, displayImagePath);
            
            // ローディング表示
            const loadingId = 'ai-loading-' + Date.now();
            const loadingText = (fileMeta && !fileMeta.isImage) ? 'ファイルを読み取り中...' : '考え中...';
            addAIChatMessage(loadingText, 'ai', loadingId, true);
            
            // 位置情報が必要かチェック
            let locationData = null;
            
            if (needsLocation(content)) {
                // ローディングメッセージを更新
                const loadingEl = document.getElementById(loadingId);
                if (loadingEl) {
                    loadingEl.querySelector('.message-text').textContent = '位置情報を取得中...';
                }
                
                // 位置情報を取得
                const location = await getUserLocation();
                if (location) {
                    locationData = location;
                    
                    // ローディングメッセージを更新
                    if (loadingEl) {
                        loadingEl.querySelector('.message-text').textContent = 'お店を検索中...';
                    }
                }
            }
            
            try {
                const questionText = content || (imagePath ? 'この画像について説明してください' : '');

                // 熟慮モード判定
                const deliberationKeywords = ['熟慮モードで', 'よく考えて', '調べてから答えて', 'じっくり調べて', '詳しく調査して'];
                let isDeliberation = false;
                if (!imagePath) {
                    for (const kw of deliberationKeywords) {
                        if (questionText.includes(kw)) { isDeliberation = true; break; }
                    }
                }

                if (isDeliberation && typeof window.sendDeliberationMessage === 'function') {
                    const loadEl = document.getElementById(loadingId);
                    if (loadEl) loadEl.remove();
                    await new Promise((resolve) => {
                        window.sendDeliberationMessage(questionText, container, function(answer) {
                            addAIChatMessage(answer, 'ai');
                            resolve();
                        });
                    });
                    return true;
                }

                const requestBody = {
                    action: 'ask',
                    question: questionText,
                    language: 'ja'
                };
                if (imagePath) {
                    requestBody.image_path = imagePath;
                }
                if (fileMeta && !fileMeta.isImage) {
                    requestBody.file_path = fileMeta.path;
                    requestBody.file_name = fileMeta.name;
                    requestBody.path = fileMeta.path; // サーバーが path のみ受け取る場合の互換
                } else if (fileMeta && fileMeta.isImage) {
                    requestBody.image_path = fileMeta.path;
                }
                
                if (locationData) {
                    requestBody.latitude = locationData.lat;
                    requestBody.longitude = locationData.lng;
                }
                
                let response = await fetch('api/ai.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(requestBody)
                });
                
                // 画像・ファイル添付時はフォールバックを使わない（画像・ファイルは渡せないため）
                const contentType = response.headers.get('content-type');
                if ((!response.ok || !contentType || !contentType.includes('application/json')) && response.status !== 401) {
                    if (imagePath || (fileMeta && !fileMeta.isImage)) {
                        document.getElementById(loadingId)?.remove();
                        addAIChatMessage((imagePath ? '画像' : 'ファイル') + 'の処理中にエラーが発生しました。しばらくしてから再度お試しください。', 'ai');
                        return false;
                    }
                    console.warn('[AI Secretary] Main API failed, trying fallback...');
                    response = await fetch('api/ai-ask-fallback.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ question: content, language: 'ja' })
                    });
                }
                
                // レスポンスがJSONかチェック
                const contentType2 = response.headers.get('content-type');
                if (!contentType2 || !contentType2.includes('application/json')) {
                    const text = await response.text();
                    console.error('[AI Secretary] Non-JSON response:', text);
                    document.getElementById(loadingId)?.remove();
                    addAIChatMessage('申し訳ございません、サーバーエラーが発生しました。しばらくしてからお試しください。', 'ai');
                    return false;
                }
                
                const data = await response.json();
                
                // ローディングを削除
                document.getElementById(loadingId)?.remove();
                
                if (data.success && data.answer) {
                    // リマインダータグを検出して処理
                    let displayAnswer = data.answer;
                    // MEMORYタグを検出して処理
                    const memoryMatches = data.answer.matchAll(/\[MEMORY:([^\]]+)\]/g);
                    for (const match of memoryMatches) {
                        processMemoryTag(match[1]);
                        displayAnswer = displayAnswer.replace(match[0], '').trim();
                    }
                    
                    // REMINDERタグを検出して処理
                    const reminderMatch = data.answer.match(/\[REMINDER:([^\]]+)\]/);
                    if (reminderMatch) {
                        displayAnswer = displayAnswer.replace(/\[REMINDER:[^\]]+\]/g, '').trim();
                        processReminderTag(reminderMatch[1]);
                    }
                    
                    // SECRETARY_NAMEタグを検出して処理（秘書の名前を付けたとき）
                    const nameMatch = data.answer.match(/\[SECRETARY_NAME:([^\]]+)\]/);
                    if (nameMatch) {
                        displayAnswer = displayAnswer.replace(/\[SECRETARY_NAME:[^\]]+\]/g, '').trim();
                        processSecretaryNameTag(nameMatch[1]);
                    }
                    
                    // CALENDAR_CREATEタグを検出して処理（複数ある場合はすべて登録）
                    const calendarCreateRegex = /\[CALENDAR_CREATE:([^\]]+)\]/g;
                    const calendarCreateMatches = [...data.answer.matchAll(calendarCreateRegex)];
                    if (calendarCreateMatches.length > 0) {
                        displayAnswer = displayAnswer.replace(/\[CALENDAR_CREATE:[^\]]+\]/g, '').trim();
                        for (const m of calendarCreateMatches) {
                            await processCalendarCreateTag(m[1]);
                        }
                    }
                    // CALENDAR_UPDATEタグを検出して処理
                    const calendarUpdateMatch = data.answer.match(/\[CALENDAR_UPDATE:([^\]]+)\]/);
                    if (calendarUpdateMatch) {
                        displayAnswer = displayAnswer.replace(/\[CALENDAR_UPDATE:[^\]]+\]/g, '').trim();
                        processCalendarUpdateTag(calendarUpdateMatch[1]);
                    }
                    // CALENDAR_DELETEタグを検出して処理
                    const calendarDeleteMatch = data.answer.match(/\[CALENDAR_DELETE:([^\]]+)\]/);
                    if (calendarDeleteMatch) {
                        displayAnswer = displayAnswer.replace(/\[CALENDAR_DELETE:[^\]]+\]/g, '').trim();
                        processCalendarDeleteTag(calendarDeleteMatch[1]);
                    }
                    // SHEETS_EDITタグを検出して処理（形式: [SHEETS_EDIT:spreadsheetId|instruction]）
                    const sheetsEditMatch = data.answer.match(/\[SHEETS_EDIT:([^|]+)\|([^\]]+)\]/);
                    if (sheetsEditMatch) {
                        displayAnswer = displayAnswer.replace(/\[SHEETS_EDIT:[^\]]+\]/g, '').trim();
                        processSheetsEditTag(sheetsEditMatch[1].trim(), sheetsEditMatch[2].trim());
                    }
                    // DOCUMENT_EDITタグを検出して処理（形式: [DOCUMENT_EDIT:fileId|instruction]）
                    const documentEditMatch = data.answer.match(/\[DOCUMENT_EDIT:(\d+)\|([^\]]+)\]/);
                    if (documentEditMatch) {
                        displayAnswer = displayAnswer.replace(/\[DOCUMENT_EDIT:[^\]]+\]/g, '').trim();
                        processDocumentEditTag(parseInt(documentEditMatch[1], 10), documentEditMatch[2].trim());
                    }

                    // IMPROVEMENT_CONFIRMED: サーバー側フラグで判定（最も信頼性が高い）
                    let shouldRecordImprovement = false;
                    if (data.improvement_confirmed) {
                        console.log('[AI Secretary] improvement_confirmed フラグ検出（サーバー側）');
                        shouldRecordImprovement = true;
                    }
                    // フォールバック: JS側でも念のためテキスト検索
                    if (!shouldRecordImprovement && displayAnswer.indexOf('IMPROVEMENT_CONFIRMED') !== -1) {
                        console.log('[AI Secretary] IMPROVEMENT_CONFIRMED をテキスト検出（JS側）');
                        shouldRecordImprovement = true;
                        displayAnswer = displayAnswer.replace(/\s*[\[［【]?\s*IMPROVEMENT_CONFIRMED\s*[\]］】]?\s*/g, '').trim();
                        if (!displayAnswer) displayAnswer = '改善提案として記録しますね。';
                    }
                    // フォールバック2: ユーザーの肯定応答 + 直前AIの改善確認サマリー
                    if (!shouldRecordImprovement && _pendingImprovementConfirm) {
                        console.log('[AI Secretary] フォールバック: 肯定応答によりボタンを表示');
                        shouldRecordImprovement = true;
                    }
                    
                    addAIChatMessage(displayAnswer, 'ai');

                    if (shouldRecordImprovement) {
                        processImprovementConfirmed();
                    }
                    
                    // 店舗検索結果があればカード表示
                    if (data.places && data.places.length > 0) {
                        showPlacesCards(data.places);
                    }
                } else {
                    const errorMsg = data.message || 'エラーが発生しました';
                    addAIChatMessage(`申し訳ございません、${errorMsg}。もう一度お試しください。`, 'ai');
                }
            } catch (error) {
                console.error('[AI Secretary] Error:', error);
                document.getElementById(loadingId)?.remove();
                addAIChatMessage('通信エラーが発生しました。ネットワーク接続を確認してください。', 'ai');
            }
            
            return true;
        }
        
        // 店舗カードを表示
        function showPlacesCards(places) {
            const container = document.getElementById('messagesArea');
            if (!container || !places || places.length === 0) return;
            
            const cardsDiv = document.createElement('div');
            cardsDiv.className = 'ai-places-cards';
            
            places.forEach(place => {
                const card = document.createElement('div');
                card.className = 'ai-place-card';
                
                // 評価の星を生成
                let starsHtml = '';
                if (place.rating) {
                    const fullStars = Math.floor(place.rating);
                    const hasHalf = place.rating % 1 >= 0.5;
                    for (let i = 0; i < fullStars; i++) starsHtml += '★';
                    if (hasHalf) starsHtml += '☆';
                }
                
                // 価格帯
                const priceLevel = place.price_level ? '¥'.repeat(place.price_level) : '';
                
                card.innerHTML = `
                    <div class="place-card-name">${escapeHtml(place.name)}</div>
                    <div class="place-card-info">
                        ${starsHtml ? `<span class="place-rating">${starsHtml} ${place.rating}</span>` : ''}
                        ${place.user_ratings_total ? `<span class="place-reviews">(${place.user_ratings_total}件)</span>` : ''}
                        ${priceLevel ? `<span class="place-price">${priceLevel}</span>` : ''}
                        ${place.open_now !== null ? `<span class="place-status ${place.open_now ? 'open' : 'closed'}">${place.open_now ? '営業中' : '営業時間外'}</span>` : ''}
                    </div>
                    ${place.address ? `<div class="place-card-address">${escapeHtml(place.address)}</div>` : ''}
                `;
                
                // クリックでGoogleマップを開く
                card.onclick = () => {
                    const query = encodeURIComponent(place.name + ' ' + (place.address || ''));
                    window.open('https://www.google.com/maps/search/' + query, '_blank');
                };
                
                cardsDiv.appendChild(card);
            });
            
            container.appendChild(cardsDiv);
            container.scrollTop = container.scrollHeight;
        }
        
        // 朝のニュース動画ブロックのHTMLを組み立て（一覧＋埋め込み用の空コンテナ）
        function buildMorningNewsVideoBlockHTML(videos) {
            if (!videos || !Array.isArray(videos) || videos.length === 0) {
                return '<h3 class="ai-today-topics-heading">本日のニューストピックス</h3><p class="ai-morning-news-no-videos">動画はありません</p>';
            }
            var embedId = 'ai-morning-news-embed-' + Date.now() + '-' + Math.random().toString(36).slice(2);
            var listHtml = '';
            videos.forEach(function(v, i) {
                var vid = (v && v.id) ? String(v.id) : '';
                var title = (v && v.title) ? escapeHtml(v.title) : '';
                var channel = (v && v.channelTitle) ? escapeHtml(v.channelTitle) : '';
                listHtml += '<li class="ai-morning-news-video-item" data-video-id="' + escapeHtml(vid) + '" data-index="' + i + '"><span class="ai-morning-news-video-title">' + title + '</span><span class="ai-morning-news-video-channel">' + channel + '</span></li>';
            });
            var jsonAttr = JSON.stringify(videos).replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            return '<h3 class="ai-today-topics-heading">本日のニューストピックス</h3>' +
                '<ul class="ai-morning-news-video-list">' + listHtml + '</ul>' +
                '<div class="ai-morning-news-embed" id="' + embedId + '" data-videos-json="' + jsonAttr + '"></div>';
        }
        
        // YouTube IFrame API 読み込みと朝のニュース動画プレーヤー初期化（小窓で再生・終了で次を自動再生）
        window.__aiMorningNewsEmbedQueue = window.__aiMorningNewsEmbedQueue || [];
        function initMorningNewsEmbed(frame, videos) {
            if (!videos || videos.length === 0) return;
            var embedEl = frame.querySelector('.ai-morning-news-embed');
            if (!embedEl) return;
            var iframeId = embedEl.id + '-iframe';
            var firstId = videos[0].id;
            var iframe = document.createElement('iframe');
            iframe.id = iframeId;
            iframe.setAttribute('src', 'https://www.youtube.com/embed/' + firstId + '?enablejsapi=1');
            iframe.setAttribute('frameborder', '0');
            iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
            iframe.setAttribute('allowfullscreen', 'true');
            iframe.className = 'ai-morning-news-embed-iframe';
            embedEl.appendChild(iframe);
            frame.__morningNewsVideos = videos;
            frame.__morningNewsIndex = 0;
            function playNext() {
                var idx = (frame.__morningNewsIndex + 1) % videos.length;
                frame.__morningNewsIndex = idx;
                var nextId = videos[idx].id;
                if (frame.__morningNewsPlayer && frame.__morningNewsPlayer.loadVideoById) {
                    try { frame.__morningNewsPlayer.loadVideoById(nextId); } catch (e) {}
                }
                var list = frame.querySelectorAll('.ai-morning-news-video-item');
                list.forEach(function(li, i) { li.classList.toggle('ai-morning-news-video-item-active', i === idx); });
            }
            function createPlayer() {
                if (!window.YT || !window.YT.Player) return;
                try {
                    frame.__morningNewsPlayer = new window.YT.Player(iframeId, {
                        videoId: firstId,
                        events: {
                            onStateChange: function(ev) {
                                if (ev.data === 0) playNext();
                            }
                        }
                    });
                } catch (e) { console.warn('YT.Player init error', e); }
            }
            if (window.YT && window.YT.Player) {
                createPlayer();
            } else {
                window.__aiMorningNewsEmbedQueue.push({ createPlayer: createPlayer });
                if (!document.getElementById('youtube-iframe-api')) {
                    var tag = document.createElement('script');
                    tag.id = 'youtube-iframe-api';
                    tag.src = 'https://www.youtube.com/iframe_api';
                    var firstScript = document.getElementsByTagName('script')[0];
                    firstScript.parentNode.insertBefore(tag, firstScript);
                }
                if (!window.onYouTubeIframeAPIReady) {
                    window.onYouTubeIframeAPIReady = function() {
                        while (window.__aiMorningNewsEmbedQueue.length) {
                            var item = window.__aiMorningNewsEmbedQueue.shift();
                            if (item && item.createPlayer) item.createPlayer();
                        }
                    };
                }
            }
            var list = frame.querySelectorAll('.ai-morning-news-video-item');
            if (list.length) list[0].classList.add('ai-morning-news-video-item-active');
        }
        
        // 朝のニュース動画一覧のクリックでその動画を小窓で再生
        document.addEventListener('click', function(e) {
            var item = e.target && e.target.closest ? e.target.closest('.ai-morning-news-video-item') : null;
            if (!item) return;
            var frame = item.closest('.ai-morning-news-video-frame');
            if (!frame || !frame.__morningNewsPlayer || !frame.__morningNewsVideos) return;
            var videoId = item.getAttribute('data-video-id');
            var idx = parseInt(item.getAttribute('data-index'), 10);
            if (!videoId || isNaN(idx)) return;
            e.preventDefault();
            frame.__morningNewsIndex = idx;
            try { frame.__morningNewsPlayer.loadVideoById(videoId); } catch (err) {}
            frame.querySelectorAll('.ai-morning-news-video-item').forEach(function(li, i) { li.classList.toggle('ai-morning-news-video-item-active', i === idx); });
        });
        
        // AIチャットメッセージを表示（グループチャットと同じスタイル）
        // imagePath: ユーザーが送信した画像のパス（uploads/...形式）
        function addAIChatMessage(content, type, id = null, isLoading = false, imagePath = null, isTodayTopicsContent = false) {
            const container = document.getElementById('messagesArea');
            if (!container) return;
            
            const div = document.createElement('div');
            div.className = `message-card ${type === 'user' ? 'own' : ''}`;
            if (id) div.id = id;
            if (isLoading) div.classList.add('ai-loading');
            
            let processedContent = (content || '');
            let formattedContent;
            if (isTodayTopicsContent) {
                const VIDEO_BLOCK_MARKER = '（朝のニュース動画）';
                if (processedContent.indexOf(VIDEO_BLOCK_MARKER) !== -1) {
                    const parts = processedContent.split(VIDEO_BLOCK_MARKER);
                    const greetingPart = (parts[0] || '').trim();
                    const jsonPart = (parts[1] || '').trim();
                    let videos = [];
                    try {
                        videos = JSON.parse(jsonPart);
                        if (!Array.isArray(videos)) videos = [];
                    } catch (err) { videos = []; }
                    const greetingHtml = greetingPart ? escapeHtml(greetingPart).replace(/\n/g, '<br>') : '';
                    const videoBlockHtml = buildMorningNewsVideoBlockHTML(videos);
                    formattedContent = (greetingHtml ? '<div class="ai-morning-news-greeting">' + greetingHtml + '</div>' : '') +
                        '<div class="ai-today-topics-frame ai-morning-news-video-frame">' + videoBlockHtml + '</div>';
                    processedContent = formattedContent;
                } else {
                    // 従来のテキスト形式（RSSリンク一覧）
                    processedContent = processedContent.replace(/・\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/g, function(_, title, url) {
                        const safeUrl = url.replace(/"/g, '&quot;');
                        return '<a href="' + safeUrl + '" class="ai-today-topic-link ai-today-topic-item" data-external-url="' + safeUrl + '" target="_blank" rel="noopener">' + escapeHtml(title) + '</a>';
                    });
                    processedContent = processedContent.replace(/^\s*https?:\/\/[^\s]+\s*$/gm, '');
                    processedContent = processedContent.replace(/^##\s*本日のニューストピックス\s*$/m, '<h3 class="ai-today-topics-heading">本日のニューストピックス</h3>');
                    processedContent = processedContent.replace(/\*\*【([^】]+)】\*\*/g, '<div class="ai-today-topics-category">【$1】</div>');
                    processedContent = '<div class="ai-today-topics-frame">' + processedContent.replace(/\n/g, '<br>') + '</div>';
                    formattedContent = processedContent;
                }
            } else {
                formattedContent = processedContent
                    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                    .replace(/\[([^\]]+)\]\(([^)]*chat\.php\?c=\d+[^)]*)\)/g, (_, text, url) => {
                        const m = url.match(/chat\.php\?c=\d+/);
                        const safeUrl = m ? m[0] : url.replace(/[^a-zA-Z0-9?=&._\-\/]/g, '');
                        return '<a href="' + escapeHtml(safeUrl) + '" target="_self" class="ai-chat-link">' + escapeHtml(text) + '</a>';
                    })
                    .replace(/\n/g, '<br>');
            }
            
            const time = new Date().toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
            
            if (type === 'user') {
                // 自分のメッセージ（右側）— 画像があれば表示
                let bodyHtml = '';
                if (imagePath) {
                    const imgSrc = imagePath.startsWith('/') || imagePath.startsWith('http') ? imagePath : imagePath;
                    const imgSrcEsc = imgSrc.replace(/'/g, "\\'").replace(/"/g, '&quot;');
                    bodyHtml += `<div class="ai-sent-image-wrap" style="margin-bottom:8px;"><img src="${escapeHtml(imgSrc)}" alt="送信した画像" loading="lazy" style="max-width:100%;max-height:300px;border-radius:8px;display:block;cursor:pointer;" onclick="if(typeof openMediaViewer==='function')openMediaViewer('image','${imgSrcEsc}','画像')" onerror="this.onerror=null;this.style.background='#f0f0f0';this.alt='画像を読み込めません'"></div>`;
                }
                if (formattedContent) {
                    bodyHtml += `<div class="message-text">${formattedContent}</div>`;
                }
                if (!bodyHtml) bodyHtml = '<div class="message-text">📷 画像を送信</div>';
                div.innerHTML = `
                    <div class="message-bubble own">
                        ${bodyHtml}
                        <div class="message-time">${time}</div>
                    </div>
                `;
            } else {
                // AIのメッセージ（左側）- 選択したキャラクターの画像を表示
                let avatarHtml = '🤖';
                if (aiCharacterSelected && aiCharacterTypes && aiCharacterTypes[aiCharacterType] && aiCharacterTypes[aiCharacterType].image) {
                    avatarHtml = `<img src="${aiCharacterTypes[aiCharacterType].image}" alt="秘書" class="ai-chat-avatar-img">`;
                }
                
                div.innerHTML = `
                    <div class="message-avatar ai-msg-avatar">${avatarHtml}</div>
                    <div class="message-content">
                        <div class="message-sender">${aiSecretaryName || 'あなたの秘書'}</div>
                        <div class="message-bubble">
                            <div class="message-text">${formattedContent}</div>
                            <div class="message-time">${time}</div>
                        </div>
                    </div>
                `;
                if (!isLoading && content && typeof window.__aiAlwaysOnTTS === 'function') {
                    var bubble = div.querySelector('.message-bubble');
                    if (bubble) {
                        var readAloudBtn = document.createElement('button');
                        readAloudBtn.className = 'ai-msg-read-aloud-btn';
                        readAloudBtn.type = 'button';
                        readAloudBtn.textContent = '🔊';
                        readAloudBtn.title = '読み上げ';
                        readAloudBtn.setAttribute('aria-label', '読み上げ');
                        readAloudBtn.addEventListener('click', function() { window.__aiAlwaysOnTTS(content); });
                        bubble.appendChild(readAloudBtn);
                    }
                }
            }
            
            container.appendChild(div);
            container.scrollTop = container.scrollHeight;
            
            // 朝のニュース動画ブロックがあれば埋め込みを初期化
            var videoFrame = div.querySelector('.ai-morning-news-video-frame');
            if (videoFrame) {
                var embedEl = videoFrame.querySelector('.ai-morning-news-embed');
                if (embedEl) {
                    var jsonStr = embedEl.getAttribute('data-videos-json');
                    if (jsonStr) {
                        try {
                            var decoded = jsonStr.replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>');
                            var vs = JSON.parse(decoded);
                            if (Array.isArray(vs) && vs.length > 0) initMorningNewsEmbed(videoFrame, vs);
                        } catch (e) { console.warn('Morning news videos parse error', e); }
                    }
                }
            }
        }
        
        // 本日のニューストピックス「詳細を見る」クリック時: 記録APIを呼んでから開く
        document.addEventListener('click', function(e) {
            var a = e.target && e.target.closest ? e.target.closest('a.ai-today-topic-link') : null;
            if (!a || !a.href) return;
            e.preventDefault();
            var url = a.getAttribute('data-external-url') || a.href;
            fetch('api/today_topic_click.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'record', external_url: url })
            }).then(function() { window.open(url, '_blank', 'noopener'); }).catch(function() { window.open(url, '_blank', 'noopener'); });
        });
        
        // 履歴読み込み失敗時にウェルカムカードにメッセージを表示
        function showAIHistoryLoadError() {
            const welcome = document.getElementById('aiWelcomeCard');
            if (!welcome) return;
            const textEl = welcome.querySelector('.ai-welcome-text');
            if (textEl) {
                textEl.innerHTML = '履歴を読み込めませんでした。<br><small>上の🔄ボタンで再読み込みできます。</small>';
            }
        }
        
        // AI会話履歴を読み込み（戻り値: true=成功 / false=失敗）
        async function loadAIHistory() {
            try {
                const response = await fetch('api/ai-history.php?limit=20');
                if (!response.ok) {
                    showAIHistoryLoadError();
                    await checkPendingReminders();
                    return false;
                }
                const text = await response.text();
                if (!text || !text.trim()) {
                    showAIHistoryLoadError();
                    await checkPendingReminders();
                    return false;
                }
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    showAIHistoryLoadError();
                    await checkPendingReminders();
                    return false;
                }
                if (data.success && data.conversations && data.conversations.length > 0) {
                    const welcome = document.getElementById('aiWelcomeCard');
                    if (welcome) welcome.style.display = 'none';
                    const conversations = data.conversations.reverse();
                    const TODAY_TOPICS_QUESTION = '（本日のニューストピックス）';
                    const PROACTIVE_QUESTION = '（自動挨拶）';
                    const EVENING_TOPICS_QUESTION = '（興味トピックレポート）';
                    conversations.forEach(conv => {
                        if (conv.question) {
                            let displayQuestion = conv.question;
                            if (conv.question === TODAY_TOPICS_QUESTION) displayQuestion = '📰 本日のニューストピックス';
                            else if (conv.question === PROACTIVE_QUESTION) displayQuestion = '👋 自動挨拶';
                            else if (conv.question === EVENING_TOPICS_QUESTION) displayQuestion = '📋 興味トピックレポート';
                            addAIChatMessage(displayQuestion, 'user');
                        }
                        if (conv.answer) addAIChatMessage(conv.answer, 'ai', null, false, null, conv.question === TODAY_TOPICS_QUESTION);
                    });
                }
                await checkPendingReminders();
                return true;
            } catch (e) {
                showAIHistoryLoadError();
                try { await checkPendingReminders(); } catch (_) {}
                return false;
            }
        }
        
        // リマインダーを完了としてマーク
        async function markReminderComplete(reminderId) {
            try {
                await fetch('api/ai.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'mark_reminder_read',
                        reminder_id: reminderId
                    })
                });
                
                // 通知を削除
                const notification = document.querySelector(`.ai-reminder-notification[data-reminder-id="${reminderId}"]`);
                if (notification) {
                    notification.style.animation = 'fadeOut 0.3s ease';
                    setTimeout(() => notification.remove(), 300);
                }
            } catch (error) {
                console.error('[Reminder] Failed to mark complete:', error);
            }
        }
        
        // リマインダー通知を閉じる（一時的に非表示）
        function dismissReminderNotification(reminderId) {
            const notification = document.querySelector(`.ai-reminder-notification[data-reminder-id="${reminderId}"]`);
            if (notification) {
                notification.style.animation = 'fadeOut 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }
        }
        
        // グローバルに公開
        window.markReminderComplete = markReminderComplete;
        window.dismissReminderNotification = dismissReminderNotification;
        
        // 会話選択時にAIモードを解除＆タスク一覧を読み込み
        const originalSelectConversation = window.selectConversation;
        window.selectConversation = function(id, event) {
            if (isAISecretaryActive) {
                hideAISecretaryChat();
            }
            // 会話切り替え後にタスク一覧を読み込み
            setTimeout(function() {
                if (typeof window.loadConversationTasks === 'function') {
                    window.loadConversationTasks();
                }
            }, 500);
            if (originalSelectConversation) {
                return originalSelectConversation(id, event);
            }
        };
        
        // 名前変更エディターを表示
        function showAINameEditor(event) {
            if (event) event.stopPropagation();
            
            const container = document.getElementById('messagesArea');
            if (!container) return;
            
            // 既存のエディターがあれば削除
            const existingEditor = document.getElementById('aiNameEditor');
            if (existingEditor) {
                existingEditor.remove();
                return;
            }
            
            // 名前入力エディターを作成
            const editor = document.createElement('div');
            editor.id = 'aiNameEditor';
            editor.className = 'ai-name-editor-card';
            editor.innerHTML = `
                <div class="ai-name-editor-content">
                    <div class="ai-name-editor-title">🤖 秘書の名前を変更</div>
                    <p class="ai-name-editor-desc">あなたの秘書に好きな名前をつけてください</p>
                    <input type="text" id="aiNameInput" class="ai-name-input" 
                           value="${aiSecretaryName}" 
                           placeholder="例: マイアシスタント、ヘルパー" 
                           maxlength="20">
                    <div class="ai-name-editor-actions">
                        <button class="ai-name-cancel-btn" onclick="closeAINameEditor()">キャンセル</button>
                        <button class="ai-name-save-btn" onclick="saveAIName()">保存</button>
                    </div>
                </div>
            `;
            
            // メッセージエリアの先頭に追加
            container.insertBefore(editor, container.firstChild);
            
            // 入力欄にフォーカス
            setTimeout(() => {
                const input = document.getElementById('aiNameInput');
                if (input) {
                    input.focus();
                    input.select();
                }
            }, 100);
        }
        
        // 名前エディターを閉じる
        function closeAINameEditor() {
            const editor = document.getElementById('aiNameEditor');
            if (editor) editor.remove();
        }
        
        // 秘書の名前を保存
        async function saveAIName() {
            const input = document.getElementById('aiNameInput');
            const newName = input?.value.trim();
            
            if (!newName) {
                alert('名前を入力してください');
                return;
            }
            
            if (newName.length > 20) {
                alert('名前は20文字以内で入力してください');
                return;
            }
            
            // ローカルストレージに保存
            localStorage.setItem('aiSecretaryName', newName);
            aiSecretaryName = newName;
            
            // サーバーにも保存（ユーザー設定として）
            try {
                await fetch('api/ai.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_secretary_name',
                        name: newName
                    })
                });
            } catch (error) {
                console.error('Failed to save name to server:', error);
            }
            
            // ヘッダーを更新
            updateHeaderForAI();
            
            // サイドバーの名前も更新
            const sidebarName = document.querySelector('.conv-item.ai-secretary .conv-name');
            if (sidebarName) {
                sidebarName.textContent = aiSecretaryName;
            }
            
            // エディターを閉じる
            closeAINameEditor();
            
            // 確認メッセージを表示
            addAIChatMessage(`名前を「${newName}」に変更しました！これからよろしくお願いします。`, 'ai');
        }
        
        // キャラクター選択画面を表示
        function showCharacterSelector(event) {
            if (event) event.stopPropagation();
            
            // 既存のセレクターがあれば削除
            const existingSelector = document.getElementById('aiCharacterSelector');
            if (existingSelector) {
                existingSelector.remove();
                return;
            }
            
            const container = document.getElementById('messagesArea');
            
            // キャラクター選択カードを作成
            let optionsHtml = '';
            for (const [type, info] of Object.entries(aiCharacterTypes)) {
                const isSelected = type === aiCharacterType;
                optionsHtml += `
                    <div class="ai-character-option ${isSelected ? 'selected' : ''}" 
                         onclick="selectCharacterType('${type}')"
                         data-type="${type}">
                        <div class="ai-character-emoji">${info.emoji}</div>
                        <div class="ai-character-name">${info.name}</div>
                        <div class="ai-character-desc">${info.description}</div>
                        ${isSelected ? '<div class="ai-character-check">✓</div>' : ''}
                    </div>
                `;
            }
            
            const selectorCard = document.createElement('div');
            selectorCard.className = 'ai-character-selector-card';
            selectorCard.innerHTML = `
                <div class="ai-character-selector-content">
                    <div class="ai-character-selector-title">🎭 キャラクターを選択</div>
                    <p class="ai-character-selector-desc">秘書のタイプを選んでください</p>
                    <div class="ai-character-options">
                        ${optionsHtml}
                    </div>
                    <button class="ai-character-close-btn" onclick="closeCharacterSelector()">閉じる</button>
                </div>
            `;
            
            if (container) {
                // メッセージエリアがある場合は先頭に追加
                const wrapper = document.createElement('div');
                wrapper.id = 'aiCharacterSelector';
                wrapper.appendChild(selectorCard);
                container.insertBefore(wrapper, container.firstChild);
            } else {
                // messagesArea がない場合（秘書未選択時など）は body にオーバーレイ表示
                const overlay = document.createElement('div');
                overlay.id = 'aiCharacterSelector';
                overlay.className = 'ai-character-selector-overlay';
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) closeCharacterSelector();
                });
                overlay.appendChild(selectorCard);
                selectorCard.addEventListener('click', function(e) { e.stopPropagation(); });
                document.body.appendChild(overlay);
            }
        }
        
        // キャラクタータイプを選択
        async function selectCharacterType(type) {
            if (!aiCharacterTypes[type]) return;
            
            // サーバーに保存
            try {
                const response = await fetch('api/ai.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_character_type',
                        type: type
                    })
                });
                const data = await response.json();
                if (!data.success) {
                    alert('保存に失敗しました');
                    return;
                }
            } catch (error) {
                console.error('Failed to save character type:', error);
                alert('保存に失敗しました');
                return;
            }
            
            // ローカルに保存
            aiCharacterType = type;
            localStorage.setItem('aiCharacterType', type);
            
            // UIを更新
            document.querySelectorAll('.ai-character-option').forEach(opt => {
                const isSelected = opt.dataset.type === type;
                opt.classList.toggle('selected', isSelected);
                const check = opt.querySelector('.ai-character-check');
                if (isSelected && !check) {
                    opt.insertAdjacentHTML('beforeend', '<div class="ai-character-check">✓</div>');
                } else if (!isSelected && check) {
                    check.remove();
                }
            });
            
            // ヘッダーとサイドバーを更新
            updateHeaderForAI();
            updateSidebarAvatar();
            
            // セレクターを閉じる
            closeCharacterSelector();
            
            // 確認メッセージ
            const emoji = aiCharacterTypes[type].emoji;
            const name = aiCharacterTypes[type].name;
            addAIChatMessage(`${emoji} キャラクターを「${name}」に変更しました！よろしくお願いします。`, 'ai');
        }
        
        // キャラクターセレクターを閉じる
        function closeCharacterSelector() {
            const selector = document.getElementById('aiCharacterSelector');
            if (selector) selector.remove();
        }
        
        // グローバルに公開
        window.selectAISecretary = selectAISecretary;
        window.sendAIMessage = sendAIMessage;
        window.showCharacterSelector = showCharacterSelector;
        window.selectCharacterType = selectCharacterType;
        window.closeCharacterSelector = closeCharacterSelector;
        window.selectInitialCharacter = selectInitialCharacter;
        window.isAISecretaryActive = function() { return isAISecretaryActive; };
        
        // 常時起動グローバル（AIパネル外でも維持・localStorage永続化）
        (function() {
            var STORAGE_KEY = 'aiAlwaysOn';
            var TRIGGER_WORDS = ['実行', 'お願いします', 'お願い', '頼みます', '頼む'];
            var recognition = null;
            var active = false;
            var transcriptParts = [];
            var interim = '';
            var onTranscriptUpdate = null;
            var lastInstructions = [];
            var MAX_VOICE_CONTEXT = 3;
            var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            function getSecretaryName() {
                try {
                    return localStorage.getItem('aiSecretaryName') || 'あなたの秘書';
                } catch (e) {
                    return 'あなたの秘書';
                }
            }
            function getTranscript() {
                return (transcriptParts.join(' ') + ' ' + interim).trim();
            }
            function clearBuffer() {
                transcriptParts = [];
                interim = '';
            }
            function tryDetectAndExecute(full) {
                if (!full) return false;
                var name = getSecretaryName().trim();
                if (!name) return false;
                var text = full.trim();
                var nameIdx = text.indexOf(name);
                if (nameIdx === -1) return false;
                var rest = text.slice(nameIdx + name.length);
                var triggerIdx = -1;
                for (var i = 0; i < TRIGGER_WORDS.length; i++) {
                    var idx = rest.indexOf(TRIGGER_WORDS[i]);
                    if (idx !== -1 && (triggerIdx === -1 || idx < triggerIdx)) triggerIdx = idx;
                }
                if (triggerIdx === -1) return false;
                var instruction = rest.slice(0, triggerIdx).replace(/^[、\s]+|[、\s]+$/g, '').trim();
                if (!instruction) return false;
                var resetPhrases = ['以上', 'おしまい', '次は別の話', '次は別', '切り替え'];
                for (var j = 0; j < resetPhrases.length; j++) {
                    if (instruction === resetPhrases[j] || instruction.indexOf(resetPhrases[j]) === 0) {
                        lastInstructions = [];
                        if (onTranscriptUpdate) onTranscriptUpdate('');
                        return true;
                    }
                }
                executeVoiceCommandViaLLM(full, instruction);
                return true;
            }
            // 「実行」検出時: 発話全文をLLMで解釈し、意図に応じたアクションを実行。解釈できなければ従来の exec(instruction) にフォールバック
            function executeVoiceCommandViaLLM(fullTranscript, fallbackInstruction) {
                var groupNames = [];
                try {
                    var items = document.querySelectorAll('.conv-item[data-conv-id]');
                    for (var i = 0; i < items.length; i++) {
                        if (items[i].classList.contains('ai-secretary')) continue;
                        var n = items[i].getAttribute('data-conv-name');
                        if (n && n.trim()) groupNames.push(n.trim());
                        var nameEl = items[i].querySelector('.conv-name-text') || items[i].querySelector('.conv-name');
                        if (nameEl && nameEl.textContent) {
                            var t = nameEl.textContent.trim().replace(/\s*\(\d+\)\s*$/, '').trim();
                            if (t && groupNames.indexOf(t) === -1) groupNames.push(t);
                        }
                    }
                } catch (e) {}
                fetch('api/ai.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'execute_voice_command', full_transcript: fullTranscript.trim(), group_names: groupNames })
                }).then(function(r) {
                    if (!r.ok) return null;
                    return r.json().catch(function() { return null; });
                }).then(function(data) {
                    if (data && data.detected && data.action) {
                        if (data.action === 'send_to_group' && data.content) {
                            var convId = (data.conversation_id != null && data.conversation_id !== '') ? parseInt(data.conversation_id, 10) : null;
                            var groupName = data.group_name || '';
                            var mentionIds = Array.isArray(data.mention_ids) ? data.mention_ids.map(function(id) { return parseInt(id, 10); }).filter(function(id) { return !isNaN(id) && id > 0; }) : [];
                            if (!convId) {
                                sendToGroup(groupName, data.content, fullTranscript, null, mentionIds);
                                return;
                            }
                            window.__pendingSendToGroup = { conversation_id: convId, group_name: groupName, mention_ids: mentionIds };
                            var inp = document.getElementById('messageInput');
                            if (inp) {
                                inp.value = data.content;
                                inp.dispatchEvent(new Event('input', { bubbles: true }));
                            }
                            if (typeof showAIToast === 'function') showAIToast('送信先: ' + groupName + (mentionIds.length ? '（宛先: ' + mentionIds.length + '名）' : '') + '。内容を確認・編集して送信ボタンで送信してください');
                            return;
                        }
                        if (data.action === 'add_memo' && data.content) {
                            fetch('api/tasks.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ action: 'create', type: 'memo', content: data.content, title: data.content.length > 50 ? data.content.slice(0, 50) : data.content })
                            }).then(function(r) { return r.json(); }).then(function(res) {
                                if (res && res.success && typeof showAIToast === 'function') showAIToast('メモを追加しました');
                                else if (typeof showAIToast === 'function') showAIToast(res && res.message ? res.message : 'メモの追加に失敗しました');
                            }).catch(function() { if (typeof showAIToast === 'function') showAIToast('メモの追加に失敗しました'); });
                            return;
                        }
                        if (data.action === 'add_task' && data.title) {
                            var title = data.title;
                            var desc = data.description || title;
                            fetch('api/tasks.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ action: 'create', title: title.length > 100 ? title.slice(0, 100) + '...' : title, description: desc, post_to_chat: false })
                            }).then(function(r) { return r.json(); }).then(function(res) {
                                if (res && (res.task_ids && res.task_ids.length || res.task) && typeof showAIToast === 'function') showAIToast('タスクを追加しました');
                                else if (typeof showAIToast === 'function') showAIToast(res && res.error ? res.error : 'タスクの追加に失敗しました');
                            }).catch(function() { if (typeof showAIToast === 'function') showAIToast('タスクの追加に失敗しました'); });
                            return;
                        }
                        if (data.action === 'chat' && data.message) {
                            var inp = document.getElementById('messageInput');
                            if (inp && typeof sendMessage === 'function') {
                                inp.value = data.message;
                                inp.dispatchEvent(new Event('input', { bubbles: true }));
                                sendMessage();
                            } else if (typeof sendAIMessage === 'function') {
                                sendAIMessage(data.message);
                            } else {
                                exec(fallbackInstruction);
                            }
                            return;
                        }
                    }
                    exec(fallbackInstruction);
                }).catch(function() {
                    exec(fallbackInstruction);
                });
            }
            function matchName(part, name) {
                if (!name || !part) return false;
                var p = part.trim();
                var n = String(name).trim();
                if (p === n) return true;
                if (n.indexOf(p) !== -1 || p.indexOf(n) !== -1) return true;
                return false;
            }
            function getConversationIdByName(namePart) {
                if (!namePart || typeof document.querySelectorAll !== 'function') return null;
                var part = namePart.trim();
                if (!part) return null;
                var items = document.querySelectorAll('.conv-item[data-conv-id]');
                for (var i = 0; i < items.length; i++) {
                    var el = items[i];
                    if (el.classList.contains('ai-secretary')) continue;
                    var id = el.getAttribute('data-conv-id');
                    var dataName = el.getAttribute('data-conv-name');
                    var dataNameEn = el.getAttribute('data-conv-name-en');
                    var dataNameZh = el.getAttribute('data-conv-name-zh');
                    if (matchName(part, dataName) || matchName(part, dataNameEn) || matchName(part, dataNameZh)) return id;
                    var nameEl = el.querySelector('.conv-name-text') || el.querySelector('.conv-name');
                    var name = (nameEl && nameEl.textContent) ? nameEl.textContent.trim() : '';
                    if (matchName(part, name)) return id;
                }
                return null;
            }
            function sendToGroup(groupName, content, instruction, conversationIdFromApi, mentionIds) {
                var convId = conversationIdFromApi || getConversationIdByName(groupName);
                if (!content) return false;
                if (!convId) {
                    if (typeof showAIToast === 'function') showAIToast(groupName + ' が見つかりません。左のリストに表示されている名前で指定してください。');
                    return false;
                }
                var payload = { action: 'send', conversation_id: parseInt(convId, 10), content: content };
                if (Array.isArray(mentionIds) && mentionIds.length > 0) {
                    payload.mention_ids = mentionIds;
                }
                fetch('api/messages.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                }).then(function(r) { return r.json(); }).then(function(data) {
                    if (data && (data.success || data.message_id)) {
                        if (typeof showAIToast === 'function') showAIToast((groupName || 'グループ') + 'に送信しました');
                    } else {
                        var errMsg = (data && data.error) ? data.error : (data && data.message) ? data.message : '送信に失敗しました';
                        if (typeof showAIToast === 'function') showAIToast(errMsg);
                    }
                }).catch(function(err) {
                    if (typeof showAIToast === 'function') showAIToast('送信に失敗しました');
                });
                return true;
            }
            // 他会話への送信指示をパース（音声・テキスト両方で利用）。{ groupName, content } または null
            function parseSendToGroupInstruction(instruction) {
                if (!instruction || typeof instruction !== 'string') return null;
                var t = instruction.trim();
                var replyMatch = t.match(/(.+?)(に|へ)返信[、\s]*(?:宛先[^\s、]+[、\s]*)?内容[、\s]*(.+)/);
                if (replyMatch) return { groupName: replyMatch[1].trim(), content: replyMatch[3].trim() };
                var sendQuoted = t.match(/(.+?)(に|へ)[「'"]([^」'"]+)[」'"].*?(?:送信|返信|送って|送信して|送ってください)/);
                if (sendQuoted) return { groupName: sendQuoted[1].trim(), content: sendQuoted[3].trim() };
                var sendUnquoted = t.match(/(.+?)(に|へ)\s*([^を]+?)\s+(?:を|と(いう)?(?:メッセージ)?)\s*(?:送信|送って|送信して|送ってください)/);
                if (sendUnquoted) return { groupName: sendUnquoted[1].trim(), content: sendUnquoted[2].trim() };
                // 「という」の口語「っていう」にも対応（例: おはようございますっていうメッセージを送信して）
                var sendToiu = t.match(/(.+?)(に|へ)\s*(.+?)\s+(という|っていう)メッセージを\s*(?:送信|送って|送信して|送信してください|送ってください)/);
                if (sendToiu) return { groupName: sendToiu[1].trim(), content: sendToiu[2].trim() };
                var sendKudasai = t.match(/(.+?)(に|へ)\s*(.+?)\s*(?:を|と(いう)?(?:メッセージ)?)\s*送って(ください)?/);
                if (sendKudasai) return { groupName: sendKudasai[1].trim(), content: sendKudasai[2].trim() };
                return null;
            }
            // テキスト送信時に「XXにYY送信」なら他会話に送り、true を返す。未マッチ時は false
            function trySendToGroup(instruction) {
                var parsed = parseSendToGroupInstruction(instruction);
                if (!parsed || !parsed.groupName || !parsed.content) return false;
                var c = parsed.content.trim();
                if (c.length <= 1 && /^[にへをと]$/.test(c)) return false;
                return sendToGroup(parsed.groupName, parsed.content, instruction);
            }
            // AIで意図解釈して他会話に送信。送信したら true、しなければ false（Promise）
            // バックエンドが会話一覧をDBから取得するため group_names は空でも可。conversation_id が返れば確実に送信
            function interpretAndSendToGroup(message) {
                var groupNames = [];
                try {
                    var items = document.querySelectorAll('.conv-item[data-conv-id]');
                    for (var i = 0; i < items.length; i++) {
                        if (items[i].classList.contains('ai-secretary')) continue;
                        var n = items[i].getAttribute('data-conv-name');
                        if (n && n.trim()) groupNames.push(n.trim());
                        var nameEl = items[i].querySelector('.conv-name-text') || items[i].querySelector('.conv-name');
                        if (nameEl && nameEl.textContent) {
                            var t = nameEl.textContent.trim().replace(/\s*\(\d+\)\s*$/, '').trim();
                            if (t && groupNames.indexOf(t) === -1) groupNames.push(t);
                        }
                    }
                } catch (e) {}
                if (!message || message.trim() === '') return Promise.resolve(false);
                return fetch('api/ai.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'interpret_send_to_group', message: message.trim(), group_names: groupNames })
                }).then(function(r) {
                    if (!r.ok) return null;
                    return r.json().catch(function() { return null; });
                }).then(function(data) {
                    if (!data || !data.detected || !data.content) return false;
                    var groupName = (data.group_name != null) ? String(data.group_name).trim() : '';
                    var content = String(data.content).trim();
                    if (!content) return false;
                    var convId = (data.conversation_id != null && data.conversation_id !== '') ? parseInt(data.conversation_id, 10) : null;
                    if (convId > 0) {
                        sendToGroup(groupName, content, message, convId);
                        return true;
                    }
                    if (groupName) {
                        return sendToGroup(groupName, content, message, null);
                    }
                    return false;
                }).catch(function() { return false; });
            }
            function exec(instruction) {
                var isAI = typeof isAISecretaryActive === 'function' && isAISecretaryActive();
                var payload = { action: 'ask', question: instruction, language: 'ja' };
                if (lastInstructions.length) payload.voice_context = lastInstructions.slice(-MAX_VOICE_CONTEXT).join('\n');
                lastInstructions.push(instruction);
                if (lastInstructions.length > MAX_VOICE_CONTEXT) lastInstructions.shift();
                var parsed = parseSendToGroupInstruction(instruction);
                if (parsed && parsed.groupName && parsed.content && sendToGroup(parsed.groupName, parsed.content, instruction)) return;
                var memoMatch = instruction.match(/^(?:メモして|メモ|記録して)[、\s]*(.+)$/);
                if (memoMatch && memoMatch[1]) {
                    var memoContent = memoMatch[1].trim();
                    if (memoContent) {
                        fetch('api/tasks.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'create', type: 'memo', content: memoContent, title: memoContent.length > 50 ? memoContent.slice(0, 50) : memoContent })
                        }).then(function(r) { return r.json(); }).then(function(data) {
                            if (data && data.success) {
                                if (typeof showAIToast === 'function') showAIToast('メモを追加しました');
                            } else {
                                if (typeof showAIToast === 'function') showAIToast(data && data.error ? data.error : 'メモの追加に失敗しました');
                            }
                        }).catch(function() {
                            if (typeof showAIToast === 'function') showAIToast('メモの追加に失敗しました');
                        });
                        return;
                    }
                }
                var taskMatch = instruction.match(/^(?:タスクに追加|タスク|やること)[、\s]*(.+)$/);
                if (taskMatch && taskMatch[1]) {
                    var taskContent = taskMatch[1].trim();
                    if (taskContent) {
                        fetch('api/tasks.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'create', title: taskContent.length > 100 ? taskContent.slice(0, 100) + '...' : taskContent, description: taskContent, post_to_chat: false })
                        }).then(function(r) { return r.json(); }).then(function(data) {
                            if (data && (data.task_ids && data.task_ids.length || data.task)) {
                                if (typeof showAIToast === 'function') showAIToast('タスクを追加しました');
                            } else {
                                if (typeof showAIToast === 'function') showAIToast(data && data.error ? data.error : 'タスクの追加に失敗しました');
                            }
                        }).catch(function() {
                            if (typeof showAIToast === 'function') showAIToast('タスクの追加に失敗しました');
                        });
                        return;
                    }
                }
                if (isAI) {
                    var inp = document.getElementById('messageInput');
                    if (inp && typeof sendMessage === 'function') {
                        inp.value = instruction;
                        inp.dispatchEvent(new Event('input', { bubbles: true }));
                        sendMessage();
                    } else if (typeof sendAIMessage === 'function') {
                        sendAIMessage(instruction);
                    }
                } else {
                    fetch('api/ai.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    }).then(function(r) { return r.json(); }).then(function(data) {
                        if (data && data.answer) {
                            try { window.__aiVoiceHistoryDirty = true; } catch (e) {}
                            if (typeof window.__aiAlwaysOnTTS === 'function') {
                                var t = String(data.answer);
                                if (t.length > 500) t = t.slice(0, 497) + '...';
                                window.__aiAlwaysOnTTS(t);
                            }
                        }
                        if (typeof showAIToast === 'function') showAIToast('指示を実行しました');
                    }).catch(function() {
                        if (typeof showAIToast === 'function') showAIToast('指示の送信に失敗しました');
                    });
                }
            }
            function ttsSpeak(text) {
                if (!text || typeof window.speechSynthesis === 'undefined') return;
                try {
                    window.speechSynthesis.cancel();
                    var u = new SpeechSynthesisUtterance(String(text));
                    u.lang = 'ja-JP';
                    u.rate = 0.95;
                    window.speechSynthesis.speak(u);
                } catch (e) { console.warn('TTS error:', e); }
            }
            window.__aiAlwaysOnTTS = ttsSpeak;
            function attachRecognitionHandlers(rec) {
                rec.onresult = function(e) {
                    for (var i = e.resultIndex; i < e.results.length; i++) {
                        var r = e.results[i];
                        var t = (r[0] && r[0].transcript) ? r[0].transcript.trim() : '';
                        if (r.isFinal && t) {
                            transcriptParts.push(t);
                            interim = '';
                            var full = getTranscript();
                            if (tryDetectAndExecute(full)) {
                                clearBuffer();
                                if (onTranscriptUpdate) onTranscriptUpdate('');
                                return;
                            }
                        } else {
                            interim = t;
                        }
                        var txt = getTranscript();
                        if (onTranscriptUpdate) onTranscriptUpdate(txt);
                        else {
                            var inp = document.getElementById('messageInput');
                            if (inp && inp.getAttribute('data-ai-mode') === 'true') {
                                inp.value = txt;
                                inp.dispatchEvent(new Event('input', { bubbles: true }));
                            }
                        }
                    }
                    var txt2 = getTranscript();
                    if (onTranscriptUpdate) onTranscriptUpdate(txt2);
                    else {
                        var inp2 = document.getElementById('messageInput');
                        if (inp2 && inp2.getAttribute('data-ai-mode') === 'true') {
                            inp2.value = txt2;
                            inp2.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                    }
                };
                rec.onerror = function(e) {
                    if (e.error === 'aborted') return;
                    if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
                        active = false;
                        try { localStorage.setItem(STORAGE_KEY, '0'); } catch (e2) {}
                        if (onTranscriptUpdate) onTranscriptUpdate('');
                        if (typeof showAIToast === 'function') showAIToast('マイクの使用が許可されていません。ブラウザの設定でマイクを許可してください。');
                        else console.warn('Speech recognition: microphone not allowed');
                        return;
                    }
                    if (e.error === 'audio-capture') {
                        active = false;
                        try { localStorage.setItem(STORAGE_KEY, '0'); } catch (e2) {}
                        if (typeof showAIToast === 'function') showAIToast('マイクにアクセスできません。マイクが接続されているか確認してください。');
                        else console.warn('Speech recognition: no microphone');
                        return;
                    }
                    if (e.error === 'network') {
                        if (typeof showAIToast === 'function') showAIToast('音声認識はネットワーク接続が必要です。');
                    }
                    console.warn('Speech recognition error:', e.error);
                };
                rec.onend = function() {
                    if (!active) return;
                    var recRef = recognition;
                    setTimeout(function() {
                        if (!active) return;
                        try {
                            if (recRef && recRef === recognition) {
                                recognition.start();
                            }
                        } catch (err) {
                            try {
                                recognition = new SpeechRecognition();
                                recognition.lang = 'ja-JP';
                                recognition.continuous = true;
                                recognition.interimResults = true;
                                attachRecognitionHandlers(recognition);
                                recognition.start();
                            } catch (err2) {
                                console.warn('Speech recognition restart failed:', err2);
                            }
                        }
                    }, 250);
                };
            }
            function start() {
                if (!SpeechRecognition) return false;
                if (active) return true;
                if (!recognition) {
                    recognition = new SpeechRecognition();
                    recognition.lang = 'ja-JP';
                    recognition.continuous = true;
                    recognition.interimResults = true;
                    attachRecognitionHandlers(recognition);
                }
                function doStartRecognition() {
                    try {
                        clearBuffer();
                        recognition.start();
                        active = true;
                        try { localStorage.setItem(STORAGE_KEY, '1'); } catch (e) {}
                        return true;
                    } catch (err) {
                        console.warn('Speech recognition start failed:', err);
                        return false;
                    }
                }
                var nav = typeof navigator !== 'undefined' && navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function';
                if (nav) {
                    return new Promise(function(resolve) {
                        navigator.mediaDevices.getUserMedia({ audio: true }).then(function(stream) {
                            stream.getTracks().forEach(function(t) { t.stop(); });
                            resolve(doStartRecognition());
                        }).catch(function() {
                            if (typeof showAIToast === 'function') showAIToast('マイクの使用が許可されていません。ブラウザでマイクを許可してください。');
                            resolve(false);
                        });
                    });
                }
                return doStartRecognition();
            }
            function stop(onStoppedWithTranscript) {
                if (!active) {
                    if (onStoppedWithTranscript) onStoppedWithTranscript('');
                    return;
                }
                var transcript = getTranscript();
                clearBuffer();
                active = false;
                if (recognition) try { recognition.stop(); } catch (e) {}
                try { localStorage.setItem(STORAGE_KEY, '0'); } catch (e) {}
                if (onTranscriptUpdate) onTranscriptUpdate('');
                if (onStoppedWithTranscript) onStoppedWithTranscript(transcript);
            }
            function isActive() { return active; }
            function syncPanelUI() {
                if (!isActive()) return;
                var bar = document.getElementById('aiTranscribeBar');
                var btn = document.getElementById('aiAlwaysOnBtn');
                if (bar) bar.style.display = 'flex';
                if (btn) {
                    btn.textContent = '停止';
                    btn.classList.add('ai-always-on-active');
                    btn.title = 'クリックで停止';
                }
            }
            window.__aiAlwaysOn = {
                start: start,
                stop: stop,
                isActive: isActive,
                getTranscript: getTranscript,
                setOnTranscriptUpdate: function(fn) { onTranscriptUpdate = fn; },
                syncPanelUI: syncPanelUI,
                trySendToGroup: trySendToGroup,
                interpretAndSendToGroup: interpretAndSendToGroup,
                supported: !!SpeechRecognition
            };
            if (typeof localStorage !== 'undefined' && localStorage.getItem(STORAGE_KEY) === '1') {
                setTimeout(function() {
                    var r = start();
                    if (r && typeof r.then === 'function') {
                        r.then(function(ok) { if (ok && syncPanelUI) syncPanelUI(); });
                    } else if (r && syncPanelUI) {
                        syncPanelUI();
                    }
                }, 500);
            }
        })();
        
        // 常時起動UIの接続（グループチャット・AI秘書のどちらの入力欄からもON/OFF可能）
        function wireAlwaysOnUI() {
            var alwaysOnBtn = document.getElementById('aiAlwaysOnBtn');
            var msgInput = document.getElementById('messageInput');
            var transcribeBar = document.getElementById('aiTranscribeBar');
            var transcribeStopBtn = document.getElementById('aiTranscribeBarStop');
            if (!alwaysOnBtn || !msgInput) return;
            if (alwaysOnBtn.getAttribute('data-always-on-wired') === '1') return;
            var g = window.__aiAlwaysOn;
            if (!g || !g.supported) {
                alwaysOnBtn.disabled = true;
                alwaysOnBtn.title = 'お使いのブラウザでは音声入力に対応していません';
                alwaysOnBtn.style.opacity = '0.5';
                return;
            }
            alwaysOnBtn.setAttribute('data-always-on-wired', '1');
            function updateUIFull() {
                var bar = document.getElementById('aiTranscribeBar');
                var btn = document.getElementById('aiAlwaysOnBtn');
                if (!btn) return;
                var on = g.isActive();
                if (bar) bar.style.display = on ? 'flex' : 'none';
                btn.textContent = on ? '停止' : '常時起動';
                btn.classList.toggle('ai-always-on-active', on);
                btn.title = on ? 'クリックで停止' : '常時起動で音声指示（名前～指示～実行）';
            }
            g.setOnTranscriptUpdate(function(text) {
                var inp = document.getElementById('messageInput');
                if (inp && inp.getAttribute('data-ai-mode') === 'true') {
                    inp.value = text || '';
                    inp.dispatchEvent(new Event('input', { bubbles: true }));
                }
            });
            if (g.isActive()) {
                updateUIFull();
                if (msgInput && g.getTranscript) msgInput.value = g.getTranscript();
                setTimeout(updateUIFull, 0);
                setTimeout(function() { updateUIFull(); var el = document.getElementById('messageInput'); if (el && g.isActive() && g.getTranscript) el.value = g.getTranscript(); }, 100);
            }
            function doStop() {
                g.stop(function(transcript) {
                    var inp = document.getElementById('messageInput');
                    if (transcript && inp && typeof sendMessage === 'function') {
                        inp.value = transcript;
                        inp.dispatchEvent(new Event('input', { bubbles: true }));
                        sendMessage();
                    }
                    updateUIFull();
                });
            }
            function doStart() {
                var result = g.start();
                function applyResult(ok) {
                    if (ok) updateUIFull();
                    else {
                        if (typeof showAIToast === 'function') showAIToast('音声認識を開始できませんでした');
                        else alert('音声認識を開始できませんでした');
                    }
                }
                if (result && typeof result.then === 'function') result.then(applyResult);
                else applyResult(result);
            }
            if (transcribeStopBtn) transcribeStopBtn.addEventListener('click', doStop);
            alwaysOnBtn.addEventListener('click', function() {
                if (g.isActive()) doStop();
                else doStart();
            });
            updateUIFull();
        }
        window.wireAlwaysOnUI = wireAlwaysOnUI;
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', wireAlwaysOnUI);
        } else {
            wireAlwaysOnUI();
        }
        
        // アバタークリックでキャラクター変更を開く（サイドバー・ヘッダー・メッセージ内の秘書アバター）
        document.addEventListener('click', function(e) {
            const sidebarAvatar = e.target.closest('.conv-item.ai-secretary .conv-avatar');
            const headerAvatar = e.target.closest('.ai-header-avatar');
            const messageAvatar = e.target.closest('.message-avatar.ai-msg-avatar');
            if (sidebarAvatar) {
                e.preventDefault();
                e.stopPropagation();
                showCharacterSelector(e);
                return;
            }
            if (headerAvatar) {
                e.preventDefault();
                e.stopPropagation();
                showCharacterSelector(e);
                return;
            }
            if (messageAvatar) {
                e.preventDefault();
                e.stopPropagation();
                showCharacterSelector(e);
                return;
            }
        }, true);
        
        // ========== メディアビューアー機能 ==========
        
        // メディアデータ格納
        let mediaItems = [];
        let contextMenuTargetId = null;
        
        // LocalStorageキーを取得（会話ごと）
        function getMediaStorageKey() {
            return `media_items_conv_${conversationId || 'global'}`;
        }
        
        // メディアデータをLocalStorageに保存
        function saveMediaToStorage() {
            const key = getMediaStorageKey();
            const dataToSave = mediaItems.map(item => ({
                ...item,
                createdAt: item.createdAt.toISOString()
            }));
            localStorage.setItem(key, JSON.stringify(dataToSave));
        }
        
        // LocalStorageからメディアデータを読み込み
        function loadMediaFromStorage() {
            const key = getMediaStorageKey();
            const saved = localStorage.getItem(key);
            if (saved) {
                try {
                    const parsed = JSON.parse(saved);
                    mediaItems = parsed.map(item => ({
                        ...item,
                        createdAt: new Date(item.createdAt)
                    }));
                } catch (e) {
                    console.error('メディアデータの読み込みエラー:', e);
                    mediaItems = [];
                }
            } else {
                mediaItems = [];
            }
            renderMediaCards();
        }
        
        // 会話切り替え時にメディアを読み込み
        function onConversationChange() {
            loadMediaFromStorage();
        }
        
        // 右クリックメニューを表示
        function showMediaContextMenu(e, itemId) {
            e.preventDefault();
            e.stopPropagation();
            
            contextMenuTargetId = itemId;
            
            const menu = document.getElementById('mediaContextMenu');
            menu.style.left = e.clientX + 'px';
            menu.style.top = e.clientY + 'px';
            menu.classList.add('show');
            
            // メニュー外クリックで閉じる
            setTimeout(() => {
                document.addEventListener('click', hideMediaContextMenu);
                document.addEventListener('contextmenu', hideMediaContextMenu);
            }, 10);
        }
        
        // 右クリックメニューを非表示
        function hideMediaContextMenu() {
            const menu = document.getElementById('mediaContextMenu');
            menu.classList.remove('show');
            document.removeEventListener('click', hideMediaContextMenu);
            document.removeEventListener('contextmenu', hideMediaContextMenu);
        }
        
        // タイトル編集
        function editMediaTitle() {
            hideMediaContextMenu();
            
            const item = mediaItems.find(m => m.id === contextMenuTargetId);
            if (!item) return;
            
            const newTitle = prompt('新しいタイトルを入力してください:', item.title);
            if (newTitle !== null && newTitle.trim() !== '') {
                item.title = newTitle.trim();
                saveMediaToStorage();
                renderMediaCards();
            }
        }
        
        // 右クリックメニューから削除（1回で即削除）
        function deleteMediaFromContext() {
            hideMediaContextMenu();
            const id = contextMenuTargetId;
            if (id == null) return;
            mediaItems = mediaItems.filter(m => m.id !== id);
            saveMediaToStorage();
            renderMediaCards();
        }
        
        // フォームをクリア
        function clearMediaForm() {
            const titleInput = document.getElementById('mediaTitleInput');
            const urlInput = document.getElementById('mediaUrlInput');
            if (titleInput) titleInput.value = '';
            if (urlInput) urlInput.value = '';
        }
        
        // URLからメディアを追加
        function addMediaFromUrl() {
            const titleInput = document.getElementById('mediaTitleInput');
            const urlInput = document.getElementById('mediaUrlInput');
            const title = titleInput ? titleInput.value.trim() : '';
            const url = urlInput ? urlInput.value.trim() : '';
            
            if (!url && !title) {
                alert('タイトルまたはURLを入力してください');
                return;
            }
            
            // YouTube URLを埋め込み形式に変換
            let embedUrl = url;
            let thumbUrl = '';
            let mediaType = 'iframe';
            let autoTitle = title || '動画';
            
            // YouTube
            const youtubeMatch = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]+)/);
            if (youtubeMatch) {
                embedUrl = `https://www.youtube.com/embed/${youtubeMatch[1]}`;
                thumbUrl = `https://img.youtube.com/vi/${youtubeMatch[1]}/mqdefault.jpg`;
                if (!title) autoTitle = 'YouTube動画';
            }
            
            // Vimeo
            const vimeoMatch = url.match(/vimeo\.com\/(\d+)/);
            if (vimeoMatch) {
                embedUrl = `https://player.vimeo.com/video/${vimeoMatch[1]}`;
                if (!title) autoTitle = 'Vimeo動画';
            }
            
            // 直接動画ファイル
            if (url.match(/\.(mp4|webm|ogg)(\?|$)/i)) {
                mediaType = 'video';
                embedUrl = url;
                if (!title) autoTitle = 'ビデオ';
            }
            
            // メディアアイテムを追加
            const item = {
                id: Date.now(),
                type: mediaType,
                title: autoTitle,
                url: embedUrl,
                thumb: thumbUrl,
                originalUrl: url,
                createdAt: new Date()
            };
            
            mediaItems.push(item);
            saveMediaToStorage();
            renderMediaCards();
            clearMediaForm();
        }
        
        // メディアファイルアップロード処理
        function handleMediaUpload(input) {
            const files = input.files;
            if (!files.length) return;
            
            Array.from(files).forEach(file => {
                const reader = new FileReader();
                const fileType = file.type;
                
                reader.onload = function(e) {
                    let mediaType = 'image';
                    if (fileType.startsWith('video/')) mediaType = 'video';
                    else if (fileType === 'application/pdf') mediaType = 'pdf';
                    
                    const dataUrl = e.target.result;
                    
                    // 動画の場合はサムネイルを生成
                    if (mediaType === 'video') {
                        generateVideoThumbnail(dataUrl, function(thumbUrl) {
                            const item = {
                                id: Date.now() + Math.random(),
                                type: mediaType,
                                title: file.name,
                                url: dataUrl,
                                thumb: thumbUrl,
                                createdAt: new Date()
                            };
                            mediaItems.push(item);
                            saveMediaToStorage();
                            renderMediaCards();
                        });
                    } else {
                        const item = {
                            id: Date.now() + Math.random(),
                            type: mediaType,
                            title: file.name,
                            url: dataUrl,
                            thumb: mediaType === 'image' ? dataUrl : '',
                            createdAt: new Date()
                        };
                        mediaItems.push(item);
                        saveMediaToStorage();
                        renderMediaCards();
                    }
                };
                
                reader.readAsDataURL(file);
            });
            
            input.value = '';
        }
        
        // 動画サムネイル生成
        function generateVideoThumbnail(videoUrl, callback) {
            const video = document.createElement('video');
            video.crossOrigin = 'anonymous';
            video.src = videoUrl;
            video.muted = true;
            video.playsInline = true;
            
            video.addEventListener('loadeddata', function() {
                // 動画の1秒目に移動
                video.currentTime = Math.min(1, video.duration / 2);
            });
            
            video.addEventListener('seeked', function() {
                const canvas = document.createElement('canvas');
                canvas.width = 160;
                canvas.height = 90;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                const thumbUrl = canvas.toDataURL('image/jpeg', 0.7);
                callback(thumbUrl);
            });
            
            video.addEventListener('error', function() {
                callback('');
            });
        }
        
        // メディアカードをレンダリング
        function renderMediaCards() {
            const cardList = document.getElementById('mediaCardList');
            const noMediaText = document.getElementById('noMediaText');
            const mediaSectionHeader = document.getElementById('mediaSectionHeader');
            
            if (mediaItems.length === 0) {
                cardList.innerHTML = '';
                noMediaText.style.display = 'block';
                // メディアがない場合は閉じた状態を維持
                return;
            }
            
            noMediaText.style.display = 'none';
            
            // メディアがある場合はセクションを開く
            if (mediaSectionHeader && mediaSectionHeader.classList.contains('collapsed')) {
                mediaSectionHeader.classList.remove('collapsed');
            }
            
            cardList.innerHTML = mediaItems.map(item => {
                const date = item.createdAt.toLocaleDateString('ja-JP', { month: '2-digit', day: '2-digit' }) + ' ' + 
                             item.createdAt.toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
                
                let thumbContent = '';
                if (item.type === 'image' && item.thumb) {
                    thumbContent = `<img src="${item.thumb}" alt="">`;
                } else if ((item.type === 'video' || item.type === 'iframe') && item.thumb) {
                    // 動画・YouTube等のサムネイル表示
                    thumbContent = `<img src="${item.thumb}" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"><span class="play-icon">▶</span>`;
                } else if (item.type === 'video' || item.type === 'iframe') {
                    thumbContent = `<span class="type-icon">🎬</span><span class="play-icon">▶</span>`;
                } else if (item.type === 'pdf') {
                    thumbContent = `<span class="type-icon">📄</span>`;
                } else {
                    thumbContent = `<span class="type-icon">📁</span>`;
                }
                
                return `
                    <div class="media-card" onclick="playMedia(${item.id})" oncontextmenu="showMediaContextMenu(event, ${item.id})">
                        <div class="media-card-thumb">${thumbContent}</div>
                        <div class="media-card-info">
                            <div class="media-card-title">${escapeHtml(item.title)}</div>
                            <div class="media-card-meta">${date}</div>
                        </div>
                        <button type="button" class="media-card-delete" title="削除" aria-label="削除" data-media-id="${item.id}">×</button>
                    </div>
                `;
            }).join('');
        }
        
        // メディアを再生
        function playMedia(id) {
            const item = mediaItems.find(m => m.id === id);
            if (!item) return;
            
            if (item.type === 'iframe') {
                openMediaViewer('iframe', item.url + '?autoplay=1', item.title);
            } else {
                openMediaViewer(item.type, item.url, item.title);
            }
        }
        
        // メディアを削除（携帯で1タップで即削除するため確認ダイアログなし）
        function deleteMedia(id) {
            mediaItems = mediaItems.filter(m => m.id !== id);
            saveMediaToStorage();
            renderMediaCards();
        }
        
        // 削除ボタン: 携帯で1タップ即削除（touchstartで先に処理し、親のplayMediaに取られないようにする）
        document.addEventListener('touchstart', function(e) {
            const btn = e.target.closest('.media-card-delete');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            const id = btn.getAttribute('data-media-id');
            if (id !== null && id !== '') deleteMedia(Number(id));
        }, { passive: false, capture: true });
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.media-card-delete');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            const id = btn.getAttribute('data-media-id');
            if (id !== null && id !== '') deleteMedia(Number(id));
        }, true);
        
        // メディアビューアーを開く
        function openMediaViewer(type, src, title) {
            const viewer = document.getElementById('mediaViewer');
            const content = document.getElementById('mediaViewerContent');
            const titleEl = document.getElementById('mediaViewerTitle');
            
            titleEl.textContent = title || 'メディア';
            
            switch(type) {
                case 'image':
                    content.innerHTML = `<img src="${src}" style="max-width:100%;max-height:100%;object-fit:contain;border-radius:8px;">`;
                    break;
                case 'video':
                    content.innerHTML = `<video src="${src}" controls autoplay style="max-width:100%;max-height:100%;"></video>`;
                    break;
                case 'pdf':
                    content.innerHTML = `<embed src="${src}" type="application/pdf">`;
                    break;
                case 'iframe':
                    content.innerHTML = `<iframe src="${src}" allowfullscreen allow="autoplay; encrypted-media"></iframe>`;
                    break;
            }
            
            viewer.style.display = 'flex';
        }
        
        // メディアビューアーを閉じる
        function closeMediaViewer() {
            const viewer = document.getElementById('mediaViewer');
            const content = document.getElementById('mediaViewerContent');
            
            // 動画/iframe停止
            content.innerHTML = '';
            viewer.style.display = 'none';
        }
        
        let isSending = false; // 二重送信防止フラグ
        
        async function sendMessage() {
            const input = document.getElementById('messageInput');
            var content = (input && input.value) ? input.value.trim() : '';
            if (!content) return;
            
            // AIセクレタリーモードの場合
            if (isAISecretaryActive) {
                var pending = window.__pendingSendToGroup;
                if (pending && pending.conversation_id && content) {
                    window.__pendingSendToGroup = null;
                    input.value = '';
                    input.style.height = 'auto';
                    var payload = { action: 'send', conversation_id: parseInt(pending.conversation_id, 10), content: content };
                    if (Array.isArray(pending.mention_ids) && pending.mention_ids.length > 0) {
                        payload.mention_ids = pending.mention_ids;
                    }
                    fetch('api/messages.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    }).then(function(r) { return r.json(); }).then(function(data) {
                        if (data && (data.success || data.message_id)) {
                            if (typeof showAIToast === 'function') showAIToast((pending.group_name || 'グループ') + 'に送信しました');
                        } else {
                            var errMsg = (data && data.error) ? data.error : (data && data.message) ? data.message : '送信に失敗しました';
                            if (typeof showAIToast === 'function') showAIToast(errMsg);
                        }
                    }).catch(function() {
                        if (typeof showAIToast === 'function') showAIToast('送信に失敗しました');
                    });
                    return;
                }
                input.value = '';
                input.style.height = 'auto';
                // ユーザー発言から秘書の名前を検出して即時保存（AIタグに頼らないフォールバック）
                const extractedName = extractSecretaryNameFromUserMessage(content);
                if (extractedName && typeof processSecretaryNameTag === 'function') {
                    processSecretaryNameTag(extractedName);
                }
                // 他会話送信: まずAIで意図解釈、未検出時は正規表現で送信
                var sentToGroup = false;
                if (window.__aiAlwaysOn && typeof window.__aiAlwaysOn.interpretAndSendToGroup === 'function') {
                    sentToGroup = await window.__aiAlwaysOn.interpretAndSendToGroup(content);
                }
                if (!sentToGroup && window.__aiAlwaysOn && typeof window.__aiAlwaysOn.trySendToGroup === 'function') {
                    window.__aiAlwaysOn.trySendToGroup(content);
                }
                await sendAIMessage(content);
                return;
            }
            
            if (!conversationId) {
                alert('会話が選択されていません');
                return;
            }
            
            // 編集モードの場合
            if (editingMessageId) {
                console.log('Calling updateMessage with messageId:', editingMessageId);
                await updateMessage(editingMessageId, content);
                return;
            }
            
            // 二重送信防止
            if (isSending) return;
            // 送信時点の返信先IDを確定（変数＋返信バーの data-reply-to-id の両方を見る）
            const replyBarEl = document.getElementById('replyModeBar');
            const barReplyId = (replyBarEl && replyBarEl.dataset.replyToId) ? parseInt(replyBarEl.dataset.replyToId, 10) : null;
            const fromVar = (replyToMessageId != null && replyToMessageId !== '') ? parseInt(replyToMessageId, 10) : null;
            const effectiveReplyToId = (!isNaN(fromVar) && fromVar > 0) ? fromVar : ((!isNaN(barReplyId) && barReplyId > 0) ? barReplyId : null);
            const replyToIdForSend = effectiveReplyToId;
            isSending = true;
            
            // 送信ボタンを無効化
            const sendBtn = document.querySelector('.input-send-btn');
            if (sendBtn) {
                sendBtn.disabled = true;
                sendBtn.style.opacity = '0.5';
            }
            
            // ========== 楽観的UI更新: メッセージを即座に表示 ==========
            const tempId = 'temp-' + Date.now();
            const displayName = document.body.dataset.displayName || 'あなた';
            const messagesArea = document.getElementById('messagesArea');
            
            // 返信プレビューを作成（返信モードの場合）
            let tempReplyPreviewHtml = '';
            let savedReplyInfo = null;
            if (replyToIdForSend) {
                const replyMsgCard = document.querySelector(`[data-message-id="${replyToIdForSend}"]`);
                const replyContent = replyMsgCard ? replyMsgCard.dataset.content : '';
                const replySenderName = (replyMsgCard && replyMsgCard.dataset.senderName) ? replyMsgCard.dataset.senderName : (function() {
                    const replyFromNameEl = replyMsgCard ? replyMsgCard.querySelector('.from-name') : null;
                    const replyLabelEl = replyMsgCard ? replyMsgCard.querySelector('.label') : null;
                    return (replyFromNameEl && replyFromNameEl.textContent.trim()) || (replyLabelEl ? replyLabelEl.textContent.trim().replace(/^[●・]\s*/, '') : '') || '<?= $currentLang === 'en' ? 'Sender' : ($currentLang === 'zh' ? '发件人' : '送信者') ?>';
                })();
                const replyPreviewText = replyContent.length > 40 ? replyContent.substring(0, 40) + '...' : replyContent;
                
                tempReplyPreviewHtml = `
                    <div class="reply-preview">
                        <span class="reply-preview-icon">↩️</span>
                        <span class="reply-preview-sender">${escapeHtml(replySenderName)}</span>
                        <span class="reply-preview-text">${escapeHtml(replyPreviewText)}</span>
                    </div>
                `;
                
                // 返信情報を保存（後で使用）
                savedReplyInfo = {
                    id: replyToIdForSend,
                    content: replyContent,
                    senderName: replySenderName
                };
            }
            
            // 一時的なメッセージカードを作成（Toチップも表示）
            var tempContentHtml;
            if (/\[To:(?:\d+|all)\]/i.test(content)) {
                var tempMemberMap = {};
                (window.currentConversationMembers || []).forEach(function(m) {
                    tempMemberMap[m.id] = { display_name: m.display_name || m.name, avatar_path: m.avatar_path || m.avatar };
                });
                var toChipsFn = typeof window.contentWithToChips === 'function' ? window.contentWithToChips : null;
                if (toChipsFn) {
                    tempContentHtml = toChipsFn(content, tempMemberMap).replace(/\n/g, '<br>').replace(
                        /(https?:\/\/[^\s<]+)/g,
                        '<a href="$1" target="_blank" rel="noopener noreferrer" style="color:var(--primary);text-decoration:underline;">$1</a>'
                    );
                } else {
                    tempContentHtml = escapeHtml(content).replace(/\n/g, '<br>');
                }
            } else {
                tempContentHtml = escapeHtml(content).replace(/\n/g, '<br>');
            }
            const tempMessageHtml = `
                <div class="message-card own sending" data-message-id="${tempId}" data-temp="true" data-content="${escapeHtml(content)}">
                    ${tempReplyPreviewHtml}
                    <div class="content">${tempContentHtml}</div>
                    <div class="timestamp">送信中...</div>
                </div>
            `;
            messagesArea.insertAdjacentHTML('beforeend', tempMessageHtml);
            messagesArea.scrollTop = messagesArea.scrollHeight;
            
            // 入力欄をすぐにクリア（体感速度向上）
            input.value = '';
            autoResizeInput(input);
            
            try {
                const messageData = { 
                    action: 'send', 
                    conversation_id: conversationId, 
                    content: content
                };
                
                // 返信モードの場合は送信時点で確定した reply_to_id を付与
                if (replyToIdForSend) {
                    messageData.reply_to_id = replyToIdForSend;
                }
                // To機能: (1)本文中の [To:ID] (2)「To 名前」 (3)Toボタンで選択した宛先 の全てをメンションに含める
                if ((/\[To:\d+\]/.test(content) || /To\s+/i.test(content)) && (!window.currentConversationMembers || window.currentConversationMembers.length === 0) && typeof loadConversationMembers === 'function') {
                    await loadConversationMembers();
                }
                var mentionIdsFromBracket = parseToIdsFromContent(content);
                var mentionIdsFromContent = parseToNamesFromContent(content);
                // To機能は一時削除のため mention_ids は送信しない（Phase B）
                var mergedMentions = [];
                // messageData.mention_ids は設定しない
                
                if (content.length >= 1000) {
                    console.log('[長文] 1000文字以上のメッセージ送信（文字数:', content.length, '）→ 長文はテキストのまま保存され、検索・AI学習に利用されます');
                }
                
                const response = await fetch('api/messages.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(messageData)
                });
                const data = await response.json();
                if (data.success) {
                    if (data.pdf_error) {
                        console.warn('[長文] 変換失敗:', data.pdf_error);
                        alert('1000文字以上の長文をPDFに変換できませんでした。\n' + data.pdf_error + '\n\nテキストのまま送信しました。');
                    }
                    // 一時メッセージを削除（ポーリングがIDを変更済みの場合もフォールバック検索）
                    let tempCard = document.querySelector(`[data-message-id="${tempId}"]`);
                    if (!tempCard) {
                        tempCard = document.querySelector(`.message-card[data-temp="true"].sending`);
                    }
                    if (tempCard) {
                        tempCard.remove();
                    }
                    
                    // ポーリングが先に同じメッセージを追加していた場合は削除してから再作成
                    if (data.message && data.message.id) {
                        var existingCard = document.querySelector(`[data-message-id="${data.message.id}"]`);
                        if (existingCard) {
                            existingCard.remove();
                        }
                    }
                    
                    // APIから返された完全なメッセージ情報で正確に表示
                    if (data.message) {
                        // メンション情報を補完
                        const msg = data.message;
                        
                        // TO全員の場合
                        if (msg.has_to_all) {
                            msg.is_mentioned_me = false; // 自分が送ったので自分へのメンションではない
                            msg.mention_type = 'to_all';
                            msg.show_to_all_badge = true;
                        }
                        // 個別TOの場合
                        else if (msg.to_member_ids && msg.to_member_ids.length > 0) {
                            msg.mention_type = 'to';
                            msg.show_to_badge = true;
                            msg.to_member_ids_list = msg.to_member_ids;
                        }
                        
                        // 正確なメッセージをUIに追加
                        if (typeof window.appendMessageToUI === 'function') {
                            window.appendMessageToUI(msg);
                        }
                        
                        // lastMessageIdを更新
                        if (window.updateLastMessageId) {
                            window.updateLastMessageId(msg.id);
                        }
                        
                        // スクロール
                        const messagesArea = document.getElementById('messagesArea');
                        if (messagesArea) {
                            messagesArea.scrollTop = messagesArea.scrollHeight;
                        }
                    }
                    
                    // ドラフトをクリア
                    if (window.clearChatDraft) window.clearChatDraft(conversationId);
                    
                    // 返信モードをクリア
                    cancelReply();
                    // To選択をクリア（window と To モジュールの両方）
                    // To機能一時削除のためクリア処理は無効化
                    // if (Array.isArray(window.chatSelectedToIds)) { window.chatSelectedToIds = []; if (typeof updateToRowBar === 'function') updateToRowBar(); }
                    if (typeof Chat !== 'undefined' && Chat.toSelector && typeof Chat.toSelector.clear === 'function') {
                        Chat.toSelector.clear();
                    }
                    
                    isSending = false;
                    if (sendBtn) {
                        sendBtn.disabled = false;
                        sendBtn.style.opacity = '1';
                    }
                } else {
                    // 送信失敗: 一時メッセージを削除
                    const tempCard = document.querySelector(`[data-message-id="${tempId}"]`);
                    if (tempCard) {
                        tempCard.remove();
                    }
                    console.error('Send failed:', data);
                    alert(data.message || '送信に失敗しました');
                    // 元のテキストを復元
                    input.value = content;
                    isSending = false;
                    if (sendBtn) {
                        sendBtn.disabled = false;
                        sendBtn.style.opacity = '1';
                    }
                }
            } catch (e) {
                // エラー時: 一時メッセージを削除し、テキストを復元
                const tempCard = document.querySelector(`[data-message-id="${tempId}"]`);
                if (tempCard) {
                    tempCard.remove();
                }
                console.error('Send error:', e);
                alert('送信エラーが発生しました');
                input.value = content; // 元のテキストを復元
                isSending = false;
                if (sendBtn) {
                    sendBtn.disabled = false;
                    sendBtn.style.opacity = '1';
                }
            }
        }
        
        // メッセージメニューの表示/非表示
        function toggleMessageMenu(btn) {
            // 他のメニューを閉じる
            document.querySelectorAll('.message-menu.show').forEach(m => m.classList.remove('show'));
            const menu = btn.nextElementSibling;
            menu.classList.toggle('show');
        }
        
        // 外側クリックでメニューを閉じる
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.message-actions')) {
                document.querySelectorAll('.message-menu.show').forEach(m => m.classList.remove('show'));
            }
            // 通知ドロップダウンを閉じる
            if (!e.target.closest('.notification-menu-container')) {
                const notificationDropdown = document.getElementById('notificationDropdown');
                if (notificationDropdown) notificationDropdown.style.display = 'none';
            }
        });
        
        // メッセージ編集
        let editingMessageId = null;
        
        window.editMessage = function editMessage(messageId) {
            const card = document.querySelector(`[data-message-id="${messageId}"]`);
            if (!card) {
                alert('メッセージが見つかりません');
                return;
            }
            
            // ファイル添付メッセージ: .file-attachment-card の data 属性から取得（最優先）
            // 全文を取得（ファイル付きメッセージも本文＋ファイル行を編集可能にする）
            let content = card.dataset.content || '';
            if (!content) {
                const contentEl = card.querySelector('.content');
                if (contentEl) content = contentEl.textContent || '';
            }
            
            editingMessageId = messageId;
            
            // 編集元メッセージのTo宛先を復元（data-to-users / data-to-type）
            var toType = (card.dataset.toType || '').trim();
            var toUsersJson = card.dataset.toUsers || card.getAttribute('data-to-users') || '[]';
            var parsed = [];
            try {
                parsed = JSON.parse(toUsersJson);
                if (toType === 'to_all' || (Array.isArray(parsed) && parsed.indexOf('all') !== -1)) {
                    window.chatSelectedToIds = ['all'];
                } else if (Array.isArray(parsed) && parsed.length > 0) {
                    window.chatSelectedToIds = parsed.map(function(x) { return x === 'all' ? 'all' : parseInt(x, 10); }).filter(function(x) { return x === 'all' || (!isNaN(x) && x > 0); });
                } else {
                    window.chatSelectedToIds = [];
                }
            } catch (e) {
                window.chatSelectedToIds = [];
            }
            if (typeof Chat !== 'undefined' && Chat.toSelector && typeof Chat.toSelector.setSelected === 'function') {
                Chat.toSelector.setSelected(window.chatSelectedToIds);
            }
            if (typeof updateToRowBar === 'function') updateToRowBar();
            
            // 本文の先頭に [To:ID]名前さん を付与（Chatwork風・編集時もToが消えないように）
            var contentToShow = content;
            if (Array.isArray(parsed) && parsed.length > 0 && !/^\[To:/.test(content.trim())) {
                var membersForEdit = window.currentConversationMembers || [];
                var toLines = parsed.map(function(id) {
                    if (id === 'all') return '[To:all]全員';
                    var m = membersForEdit.find(function(x) { return x.id == id; });
                    var name = (m && (m.display_name || m.name)) ? String(m.display_name || m.name) : '';
                    return '[To:' + id + ']' + name + 'さん';
                }).join('\n');
                contentToShow = toLines + '\n' + content;
            }
            
            // メッセージ入力欄に既存のテキストをセット
            const messageInput = document.getElementById('messageInput');
            messageInput.value = contentToShow;
            
            // 編集時はコンテンツに合わせて高さを調整
            messageInput.style.height = 'auto';
            autoResizeInput(messageInput);
            messageInput.focus();
            
            // 編集モードバーを表示
            document.getElementById('editModeBar').style.display = 'flex';
            
            // 編集対象のメッセージをハイライト（スクロールはしない - 入力欄の位置を維持）
            card.style.boxShadow = '0 0 0 2px #3b82f6';
            setTimeout(() => {
                card.style.boxShadow = '';
            }, 2000);
        };
        
        // 編集キャンセル
        function cancelEdit() {
            editingMessageId = null;
            const input = document.getElementById('messageInput');
            const editBar = document.getElementById('editModeBar');
            if (input) {
                input.value = '';
                if (typeof autoResizeInput === 'function') autoResizeInput(input);
            }
            if (editBar) editBar.style.display = 'none';
            window.chatSelectedToIds = [];
            if (typeof updateToRowBar === 'function') updateToRowBar();
        }
        
        // メッセージ更新（編集）
        async function updateMessage(messageId, content) {
            try {
                const updateData = {
                    action: 'edit',
                    message_id: messageId,
                    content: content
                };
                // To機能一時削除のため mention_ids は送信しない（Phase B）
                const response = await fetch('api/messages.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(updateData)
                });
                console.log('Edit response status:', response.status);
                
                // レスポンスをテキストとして取得してからJSONパース
                const responseText = await response.text();
                console.log('Edit response text:', responseText);
                
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('JSON parse error:', parseError, 'Response:', responseText);
                    alert('サーバーエラー: ' + responseText.substring(0, 200));
                    return;
                }
                
                if (data.success) {
                    // 編集モードをリセット
                    cancelEdit();
                    // ページをリロードして最新の状態を表示
                    location.reload();
                } else {
                    alert(data.message || '<?= $currentLang === 'en' ? 'Failed to edit' : ($currentLang === 'zh' ? '编辑失败' : '編集に失敗しました') ?>');
                }
            } catch (e) {
                console.error('Edit error:', e);
                alert('<?= $currentLang === 'en' ? 'An error occurred while editing' : ($currentLang === 'zh' ? '编辑时发生错误' : '編集エラーが発生しました') ?>: ' + e.message);
            }
        }
        
        function closeEditModal() {
            const editModal = document.getElementById('editMessageModal');
            if (editModal) editModal.classList.remove('show');
            const editToSel = document.getElementById('editToSelector');
            if (editToSel) editToSel.style.display = 'none';
            editingMessageId = null;
        }
        
        async function saveEditMessage() {
            const content = document.getElementById('editMessageText').value.trim();
            if (!content || !editingMessageId) return;
            
            // メンション情報を追加
            const messageData = { 
                action: 'edit', 
                message_id: editingMessageId, 
                content: content
            };
            
            try {
                const response = await fetch('api/messages.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(messageData)
                });
                const data = await response.json();
                
                if (data.success) {
                    closeEditModal();
                    location.reload();
                } else {
                    alert(data.message || '<?= $currentLang === 'en' ? 'Failed to edit' : ($currentLang === 'zh' ? '编辑失败' : '編集に失敗しました') ?>');
                }
            } catch (e) {
                console.error('Edit error:', e);
                alert('<?= $currentLang === 'en' ? 'Failed to edit' : ($currentLang === 'zh' ? '编辑失败' : '編集に失敗しました') ?>');
            }
        }
        
        // リアクションピッカー
        let reactionTargetMessageId = null;
        
        // 返信モード管理
        let replyToMessageId = null;
        
        // メッセージに返信
        function replyToMessage(messageId) {
            const msgCard = document.querySelector(`[data-message-id="${messageId}"]`);
            const content = msgCard ? msgCard.dataset.content : '';
            const senderName = (msgCard && msgCard.dataset.senderName) ? msgCard.dataset.senderName : (function() {
                const fromNameEl = msgCard ? msgCard.querySelector('.from-name') : null;
                const labelEl = msgCard ? msgCard.querySelector('.label') : null;
                return (fromNameEl && fromNameEl.textContent.trim()) || (labelEl ? labelEl.textContent.trim().replace(/^[●・]\s*/, '') : '') || '<?= $currentLang === 'en' ? 'Sender' : ($currentLang === 'zh' ? '发件人' : '送信者') ?>';
            })();
            
            // 返信モードを設定
            replyToMessageId = messageId;
            
            // 返信バーを表示し、送信時に確実に参照できるよう data-reply-to-id を付与
            const replyBar = document.getElementById('replyModeBar');
            const replySender = document.getElementById('replySender');
            const replyPreview = document.getElementById('replyPreview');
            
            if (replyBar && replySender && replyPreview) {
                replyBar.dataset.replyToId = String(messageId);
                replySender.textContent = senderName;
                const preview = content.length > 50 ? content.substring(0, 50) + '...' : content;
                replyPreview.textContent = preview;
                replyBar.style.display = 'flex';
            }
            
            // 入力欄にフォーカス
            const textarea = document.getElementById('messageInput');
            if (textarea) {
                textarea.focus();
            }
        }
        
        // 返信モードをキャンセル
        function cancelReply() {
            replyToMessageId = null;
            const replyBar = document.getElementById('replyModeBar');
            if (replyBar) {
                replyBar.style.display = 'none';
                delete replyBar.dataset.replyToId;
            }
        }
        
        // 引用エリアクリック: リンク・ボタン以外なら全文表示に切替（スクロールはしない）
        function handleReplyPreviewAreaClick(event, ownerMsgId, replyToId) {
            if (event.target.closest('.reply-preview-goto') || event.target.closest('.reply-preview-toggle')) return;
            event.preventDefault();
            event.stopPropagation();
            const preview = document.getElementById('reply-preview-' + ownerMsgId);
            if (!preview) return;
            if (preview.classList.contains('reply-preview-collapsed')) {
                toggleReplyPreviewExpand(ownerMsgId);
            }
        }
        window.handleReplyPreviewAreaClick = handleReplyPreviewAreaClick;
        
        // 強力な前ルール対策: 引用エリアのクリックをキャプチャで先に処理し、他ハンドラのスクロールを防ぐ
        document.addEventListener('click', function(e) {
            const replyPreview = e.target.closest('.reply-preview');
            if (!replyPreview) return;
            if (e.target.closest('.reply-preview-goto') || e.target.closest('.reply-preview-toggle')) return;
            const ownerId = replyPreview.dataset.ownerMsgId || (replyPreview.id ? String(replyPreview.id).replace('reply-preview-', '') : '');
            if (!ownerId) return;
            if (replyPreview.classList.contains('reply-preview-collapsed')) {
                e.preventDefault();
                e.stopImmediatePropagation();
                toggleReplyPreviewExpand(parseInt(ownerId, 10));
            }
        }, true);
        
        // 返信プレビューの「続きを見る」/「閉じる」トグル
        function toggleReplyPreviewExpand(messageId) {
            const card = document.querySelector('[data-message-id="' + messageId + '"]');
            if (!card) return;
            const preview = document.getElementById('reply-preview-' + messageId) || card.querySelector('.reply-preview');
            if (!preview) return;
            const btn = preview.querySelector('.reply-preview-toggle');
            const isExpanded = preview.classList.contains('reply-preview-expanded');
            if (isExpanded) {
                preview.classList.remove('reply-preview-expanded');
                preview.classList.add('reply-preview-collapsed');
                if (btn) btn.textContent = '<?= $currentLang === 'en' ? 'Show more' : ($currentLang === 'zh' ? '展开' : '続きを見る') ?>';
            } else {
                preview.classList.remove('reply-preview-collapsed');
                preview.classList.add('reply-preview-expanded');
                if (btn) btn.textContent = '<?= $currentLang === 'en' ? 'Show less' : ($currentLang === 'zh' ? '收起' : '閉じる') ?>';
            }
        }
        
        // メモに追加
        async function addToMemo(messageId) {
            const msgCard = document.querySelector(`[data-message-id="${messageId}"]`);
            const content = msgCard ? msgCard.dataset.content : '';
            const fromNameEl = msgCard ? msgCard.querySelector('.from-name') : null;
            const labelEl = msgCard ? msgCard.querySelector('.label') : null;
            const senderName = (fromNameEl && fromNameEl.textContent.trim()) || (labelEl ? labelEl.textContent.trim().replace(/^[●・]\s*/, '') : '') || '自分';
            
            const timestamp = new Date().toLocaleString('ja-JP', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' });
            const memoTitle = `${senderName} (${timestamp})`;
            
            try {
                // APIでデータベースに保存（会話IDとメッセージIDも含む）
                const response = await fetch('api/tasks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'create',
                        type: 'memo',
                        title: memoTitle,
                        content: content,
                        color: '#dbeafe',
                        is_pinned: false,
                        conversation_id: conversationId,
                        message_id: parseInt(messageId)
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // バッジを更新
                    updateMemoBadge();
                    
                    // 右パネルの概要メモにも追記（オプション）
                    const memoTextarea = document.getElementById('conversationMemo');
                    if (memoTextarea) {
                        const currentMemo = memoTextarea.value;
                        const newLine = currentMemo ? '\n' : '';
                        memoTextarea.value = currentMemo + newLine + `[${timestamp}] ${content}`;
                        
                        // 編集モードに切り替え
                        if (typeof isMemoEditMode !== 'undefined') {
                            isMemoEditMode = true;
                            memoTextarea.removeAttribute('readonly');
                            const actionBtn = document.getElementById('memoActionBtn');
                            if (actionBtn) {
                                actionBtn.textContent = '💾 保存';
                                actionBtn.classList.add('editing');
                            }
                        }
                    }
                } else {
                    console.error('メモ保存エラー:', data.error);
                }
            } catch (error) {
                console.error('メモ保存エラー:', error);
            }
        }
        
        // 統合バッジを更新（タスク件数のみ表示）
        async function updateTaskMemoBadge() {
            try {
                const response = await fetch('api/tasks.php?action=count&my_tasks_only=1&type=task');
                const data = await response.json();
                
                const badge = document.getElementById('taskMemoBadge') || document.getElementById('taskBadge');
                if (badge && data.success) {
                    const count = data.count || 0;
                    if (count > 0) {
                        badge.textContent = count;
                        badge.style.display = 'inline-flex';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            } catch (error) {
                console.error('タスク件数取得エラー:', error);
            }
        }
        
        function updateTaskBadge() { updateTaskMemoBadge(); }
        function updateMemoBadge() { updateTaskMemoBadge(); }
        function updateWishBadge() { updateTaskMemoBadge(); }
        
        // ページ読み込み時にバッジを更新
        document.addEventListener('DOMContentLoaded', function() {
            updateTaskMemoBadge();
            
            // 右パネルのタスク一覧を読み込み
            if (conversationId && typeof window.loadConversationTasks === 'function') {
                setTimeout(function() {
                    window.loadConversationTasks();
                }, 500);
            }
            
            // URLパラメータで秘書チャットが指定されている場合は自動的に開く（設定取得を待ってから開く）
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('secretary') === '1') {
                (async function() {
                    await loadAISecretarySettings();
                    selectAISecretary();
                })();
            }
            
            // 会話メンバーを読み込み（TO機能用）
            if (conversationId) {
                loadConversationMembers();
            }
            
            // 未読部分からの閲覧開始：未読区切りがあればそこへスクロール、なければ最下部へ
            const unreadDivider = document.getElementById('unreadDivider');
            const messagesArea = document.getElementById('messagesArea');
            if (unreadDivider && messagesArea) {
                const scrollToUnread = () => {
                    unreadDivider.scrollIntoView({ behavior: 'smooth', block: 'center' });
                };
                setTimeout(scrollToUnread, 150);
                // 画像等でレイアウトが変わったあとでも未読位置を維持するため、短い追加遅延でも実行
                setTimeout(scrollToUnread, 500);
                // URLハッシュによるスクロール(1000ms)の後に未読を優先して再スクロール（#message-xxx で古い位置に飛ばされないようにする）
                setTimeout(scrollToUnread, 1200);
                // 画像の遅延読込でレイアウトが変わったあとも未読位置を維持
                setTimeout(scrollToUnread, 1500);
                setTimeout(scrollToUnread, 2500);
            } else if (messagesArea) {
                const scrollToBottom = () => { messagesArea.scrollTop = messagesArea.scrollHeight; };
                scrollToBottom();
                setTimeout(scrollToBottom, 500);
                // 画像の遅延読込後も最下部を維持（ハッシュ指定時は scrollToMessageFromHash が 1000ms で実行されるため 1200ms 以降は入れない）
                setTimeout(scrollToBottom, 1500);
            }
            
            // TOボタンのイベントリスナーは削除（onclick属性で処理）
            // 重複するイベントハンドラーがあると、2回トグルされて閉じてしまうため
        });
        
        // タスクリストに追加（モーダルを表示して担当者・期限を入力）
        function addToTask(messageId) {
            const msgCard = document.querySelector(`[data-message-id="${messageId}"]`);
            const content = msgCard ? msgCard.dataset.content : '';
            
            // 常にタスク追加モーダルを表示
            openManualTaskModal(messageId, content);
        }
        
        // 互換性のためのエイリアス
        function addToWish(messageId) { addToTask(messageId); }
        
        // 手動タスク追加モーダルを開く
        async function openManualTaskModal(messageId, content) {
            const originalEl = document.getElementById('wishOriginalText');
            if (originalEl) {
                originalEl.value = content != null ? String(content) : '';
            }
            document.getElementById('wishMessageId').value = messageId;
            document.getElementById('wishTextInput').value = (content != null ? String(content).trim() : '').substring(0, 200);
            document.getElementById('wishAssignTo').value = '';
            
            // 期限と優先度をリセット
            const dueDateEl = document.getElementById('taskDueDateModal');
            if (dueDateEl) dueDateEl.value = '';
            const priorityEl = document.getElementById('taskPriorityModal');
            if (priorityEl) priorityEl.value = '1';
            
            // TOセレクターにグループメンバーを追加
            await populateTaskAssignTo();
            
            openModal('manualWishModal');
            
            // テキスト入力にフォーカス
            setTimeout(() => {
                document.getElementById('wishTextInput').focus();
            }, 100);
        }
        
        // 互換性のためのエイリアス
        function openManualWishModal(messageId, content) { openManualTaskModal(messageId, content); }
        
        // タスクのTOセレクターにグループメンバーを設定
        async function populateTaskAssignTo() {
            const select = document.getElementById('wishAssignTo');
            const currentUserId = <?= $user_id ?>;
            const myselfLabel = '<?= $currentLang === "en" ? "Myself" : ($currentLang === "zh" ? "自己" : "自分") ?>';
            
            // 既存のオプションをクリア
            select.innerHTML = `<option value="">${myselfLabel}</option>`;
            
            // メンバーがない場合はAPIから取得
            if ((!window.currentConversationMembers || window.currentConversationMembers.length === 0) && conversationId) {
                try {
                    const response = await fetch(`api/conversations.php?action=get&id=${conversationId}`);
                    const data = await response.json();
                    if (data.success && data.conversation && data.conversation.members) {
                        window.currentConversationMembers = data.conversation.members;
                    }
                } catch (error) {
                    console.error('メンバー取得エラー:', error);
                }
            }
            
            // 現在の会話のメンバーを取得（APIは id を返す。user_id は未定義なので id を使う）
            if (window.currentConversationMembers && window.currentConversationMembers.length > 0) {
                window.currentConversationMembers.forEach(member => {
                    const mid = member.id != null ? member.id : member.user_id;
                    if (mid != null && mid != currentUserId) {
                        const option = document.createElement('option');
                        option.value = String(mid);
                        option.textContent = member.display_name || member.username || ('ID:' + mid);
                        select.appendChild(option);
                    }
                });
            }
        }
        
        // メッセージからタスクを保存
        function saveTaskFromMessage() {
            const title = document.getElementById('wishTextInput').value.trim();
            const originalText = document.getElementById('wishOriginalText').value.trim();
            const assignToRaw = document.getElementById('wishAssignTo').value;
            const assignTo = assignToRaw ? parseInt(assignToRaw, 10) : null;
            const dueDate = document.getElementById('taskDueDateModal')?.value || null;
            const priority = document.getElementById('taskPriorityModal')?.value || 1;

            const titleToUse = title || originalText.substring(0, 200);
            if (!titleToUse) {
                alert('<?= $currentLang === "en" ? "Please enter the task title" : ($currentLang === "zh" ? "请输入任务标题" : "タスクタイトルを入力してください") ?>');
                return;
            }

            const payload = {
                    action: 'create',
                    title: titleToUse,
                description: originalText || titleToUse,
                    due_date: dueDate,
                priority: parseInt(priority, 10) || 1,
                assigned_to: (assignTo && !isNaN(assignTo)) ? assignTo : null
            };
            if (conversationId) {
                payload.conversation_id = parseInt(conversationId, 10);
            }
            
            // タスク作成APIを呼び出し（相手を選択していれば assigned_to＝その人 → 依頼したタスクに表示）
            fetch('api/tasks.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeModal('manualWishModal');
                    if (typeof updateTaskBadge === 'function') updateTaskBadge();
                    
                    const sel = document.getElementById('wishAssignTo');
                    const assigneeName = (assignTo && sel && sel.selectedIndex >= 0) ? 
                        sel.options[sel.selectedIndex].text : 
                        '<?= $currentLang === "en" ? "myself" : ($currentLang === "zh" ? "自己" : "自分") ?>';
                    const dueDateText = dueDate ? ` (<?= $currentLang === "en" ? "Due" : ($currentLang === "zh" ? "截止" : "期限") ?>: ${dueDate})` : '';
                    
                    alert('<?= $currentLang === "en" ? "Task added" : ($currentLang === "zh" ? "任务已添加" : "タスクを追加しました") ?>:\n' + title + '\n<?= $currentLang === "en" ? "Assignee" : ($currentLang === "zh" ? "担当" : "担当") ?>: ' + assigneeName + dueDateText);
                } else {
                    alert(data.message || '<?= $currentLang === "en" ? "Failed to add" : ($currentLang === "zh" ? "添加失败" : "追加に失敗しました") ?>');
                }
            })
            .catch(error => {
                console.error('タスク追加エラー:', error);
                alert('<?= $currentLang === "en" ? "Failed to add" : ($currentLang === "zh" ? "添加失败" : "追加に失敗しました") ?>');
            });
        }
        
        // 互換性のためのエイリアス
        function saveManualWish() { saveTaskFromMessage(); }
        
        function toggleReactionPicker(messageId, event) {
            const picker = document.getElementById('reactionPicker');
            const msgCard = document.querySelector(`[data-message-id="${messageId}"]`);
            
            if (!msgCard) return;
            
            if (picker.classList.contains('show') && reactionTargetMessageId === messageId) {
                picker.classList.remove('show');
                reactionTargetMessageId = null;
                return;
            }
            
            reactionTargetMessageId = messageId;
            
            // クリックされたボタンの位置を基準に表示（長文対応）
            let rect;
            if (event && event.target) {
                // クリックされたボタン要素の位置を使用
                const button = event.target.closest('.reaction-trigger') || event.target;
                rect = button.getBoundingClientRect();
            } else {
                // フォールバック：メッセージカードの下部を使用
                const footer = msgCard.querySelector('.message-footer');
                rect = footer ? footer.getBoundingClientRect() : msgCard.getBoundingClientRect();
            }
            
            // ビューポート内に収まるよう位置を調整
            const pickerHeight = 50; // ピッカーの高さの目安
            const pickerWidth = 200; // ピッカーの幅の目安
            const viewportHeight = window.innerHeight;
            const viewportWidth = window.innerWidth;
            
            // 上に配置するか下に配置するか判定
            let top = rect.top - pickerHeight - 10;
            if (top < 10) {
                // 上に収まらない場合は下に表示
                top = rect.bottom + 10;
            }
            
            // 横位置の調整
            let left = rect.left;
            if (left + pickerWidth > viewportWidth - 10) {
                left = viewportWidth - pickerWidth - 10;
            }
            if (left < 10) {
                left = 10;
            }
            
            picker.style.left = left + 'px';
            picker.style.top = top + 'px';
            // 1回目のクリックで確実に開くよう、表示を次のティックにずらす（同じクリックが document の外側クリックで閉じる処理に拾われないようにする）
            setTimeout(function() {
            picker.classList.add('show');
                _reactionPickerOpenAt = Date.now();
            }, 0);
        }
        
        // 外側クリックでピッカーを閉じる（ピッカー表示直後の同一クリックでは閉じないよう、表示してから100ms以内は閉じない）
        var _reactionPickerOpenAt = 0;
        document.addEventListener('click', function(e) {
            const picker = document.getElementById('reactionPicker');
            if (!picker || !picker.classList.contains('show')) return;
            if (Date.now() - _reactionPickerOpenAt < 100) return;
            if (!e.target.closest('.reaction-picker') && !e.target.closest('.reaction-picker-v2') && !e.target.closest('.reaction-trigger')) {
                picker.classList.remove('show');
            }
        });
        
        async function addReaction(messageId, reaction) {
            messageId = messageId || reactionTargetMessageId;
            if (!messageId) return;
            
            // ピッカーを閉じる
            document.getElementById('reactionPicker').classList.remove('show');
            
            try {
                const body = new URLSearchParams({
                    action: 'add_reaction',
                    message_id: String(messageId),
                    reaction: reaction
                });
                const response = await fetch('api/messages.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                });
                const data = await response.json();
                
                if (data.success) {
                    // サーバーから返されたリアクション情報でUIを更新
                    updateReactionUI(messageId, data.reactions || []);
                } else {
                    console.error('Reaction failed:', data.error);
                    if (typeof Toast !== 'undefined' && Toast.error) {
                        Toast.error(data.error || '<?= $currentLang === "en" ? "Failed to save reaction" : "リアクションの保存に失敗しました" ?>');
                    }
                }
            } catch (e) {
                console.error('Reaction error:', e);
                if (typeof Toast !== 'undefined' && Toast.error) {
                    Toast.error('<?= $currentLang === "en" ? "Failed to save reaction" : "リアクションの保存に失敗しました" ?>');
                }
            }
        }
        
        // リアクションUIを更新
        function updateReactionUI(messageId, reactions) {
            const card = document.querySelector(`[data-message-id="${messageId}"]`);
            if (!card) return;
            
            let reactionsDiv = card.querySelector('.message-reactions');
            
            if (reactions.length === 0) {
                // リアクションがなくなった場合は要素を削除
                if (reactionsDiv) reactionsDiv.remove();
                return;
            }
            
            if (!reactionsDiv) {
                reactionsDiv = document.createElement('div');
                reactionsDiv.className = 'message-reactions';
                const content = card.querySelector('.content');
                if (content) {
                    content.after(reactionsDiv);
                }
            }
            
            // リアクションバッジを再構築（絵文字のみ表示、ホバーで誰がしたか表示）
            reactionsDiv.innerHTML = '';
            reactions.forEach(r => {
                const badge = document.createElement('span');
                badge.className = 'reaction-badge' + (r.is_mine ? ' my-reaction' : '');
                const type = (r.reaction_type != null && r.reaction_type !== '') ? r.reaction_type : (r.type || '');
                const names = (r.users && r.users.length) ? r.users.map(u => u.name).join(', ') : '';
                badge.textContent = type;
                badge.title = names ? names : 'クリックでリアクション';
                badge.onclick = () => addReaction(messageId, type);
                reactionsDiv.appendChild(badge);
            });
            
            // アニメーション
            reactionsDiv.style.transform = 'scale(1.05)';
            setTimeout(() => reactionsDiv.style.transform = '', 150);
        }
        
        // バッジクリック時も同じ add_reaction を使う（reactions.js 未読込時のフォールバック）
        window.toggleReaction = function(msgId, reaction) { addReaction(msgId, reaction); };
        
        // タスク表示の削除（依頼者・担当者のみ）- グローバルに公開
        window.deleteTaskDisplay = async function deleteTaskDisplay(taskId, btnEl) {
            if (!taskId) return;
            const msg = typeof __LANG !== 'undefined' && __LANG === 'en' ? 'Delete this task display?' : (typeof __LANG !== 'undefined' && __LANG === 'zh' ? '确定删除此任务显示？' : 'このタスク表示を削除しますか？');
            if (!confirm(msg)) return;
            const card = btnEl ? btnEl.closest('.task-card') : document.querySelector(`.task-card[data-task-id="${taskId}"]`);
            try {
                const response = await fetch('api/tasks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', task_id: taskId })
                });
                const data = await response.json();
                if (data.success && card) {
                    card.style.transition = 'opacity 0.3s, transform 0.3s';
                    card.style.opacity = '0';
                    card.style.transform = 'scale(0.95)';
                    setTimeout(() => card.remove(), 300);
                } else if (!data.success) {
                    alert(data.message || (typeof __LANG !== 'undefined' && __LANG === 'en' ? 'Failed to delete' : (typeof __LANG !== 'undefined' && __LANG === 'zh' ? '删除失败' : '削除に失敗しました')));
                }
            } catch (e) {
                alert(typeof __LANG !== 'undefined' && __LANG === 'en' ? 'Failed to delete' : (typeof __LANG !== 'undefined' && __LANG === 'zh' ? '删除失败' : '削除に失敗しました'));
            }
        }
        
        // メッセージ削除
        async function deleteMessage(messageId) {
            if (!confirm('このメッセージを削除しますか？')) return;
            
            try {
                const response = await fetch('api/messages.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', message_id: messageId })
                });
                const data = await response.json();
                
                if (data.success) {
                    // メッセージカードをフェードアウトして削除
                    const card = document.querySelector(`[data-message-id="${messageId}"]`);
                    if (card) {
                        card.style.transition = 'opacity 0.3s, transform 0.3s';
                        card.style.opacity = '0';
                        card.style.transform = 'scale(0.95)';
                        setTimeout(() => card.remove(), 300);
                    }
                    // メディアビューアーやオーバーレイが開いていれば閉じ、通常のチャット画面に戻す
                    if (typeof closeMediaViewer === 'function') {
                        var viewer = document.getElementById('mediaViewer');
                        if (viewer && viewer.style.display === 'flex') closeMediaViewer();
                    }
                } else {
                    alert(data.message || '削除に失敗しました');
                }
            } catch (e) {
                alert('削除に失敗しました');
            }
        }
        
        function handleKeyDown(e) {
            const input = e.target && e.target.id === 'messageInput' ? e.target : document.getElementById('messageInput');
            const enterToSend = document.getElementById('enterSendCheck')?.checked ?? true;
            if (e.key === 'Enter' && !e.shiftKey && enterToSend && input) {
                e.preventDefault();
                sendMessage();
                return;
            }
        }
        window.handleKeyDown = handleKeyDown;

        // テキストエリア自動リサイズ：文章量に応じて入力枠が広がる（52px〜300px）
        // 入力欄を手動リサイズ中（input-area-has-height）のときは高さを触らない
        function autoResizeInput(textarea) {
            if (!textarea) return;
            var inputArea = document.getElementById('inputArea');
            if (inputArea && inputArea.classList.contains('input-area-has-height')) return;
            var cap = 300;
            var minH = 52;
            // 1. 一時的に高さを0にして scrollHeight で内容の実高さを取得（ブラウザ差を吸収）
            textarea.style.setProperty('min-height', '0', 'important');
            textarea.style.setProperty('max-height', 'none', 'important');
            textarea.style.setProperty('height', '0px', 'important');
            textarea.style.setProperty('overflow-y', 'hidden', 'important');
            var sh = textarea.scrollHeight;
            // 2. クランプして入力枠を広げる
            var newHeight = Math.min(cap, Math.max(minH, sh));
            textarea.style.setProperty('height', newHeight + 'px', 'important');
            textarea.style.setProperty('min-height', minH + 'px', 'important');
            textarea.style.setProperty('max-height', cap + 'px', 'important');
            textarea.style.setProperty('overflow-y', newHeight >= cap ? 'auto' : 'hidden', 'important');
        }
        window.autoResizeInput = autoResizeInput;
        
        document.addEventListener('DOMContentLoaded', function() {
            var el = document.getElementById('messageInput');
            if (el) {
                el.style.setProperty('min-height', '52px', 'important');
                el.style.setProperty('max-height', '300px', 'important');
                el.style.setProperty('overflow-y', 'hidden', 'important');
                autoResizeInput(el);
            }
        });
        
        // チャット入力欄マイク：音声入力（Web Speech API）
        (function() {
            var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            var micBtn = null;
            var messageInput = null;
            var recognition = null;
            var isListening = false;
            var lastProcessedResultIndex = -1;
            var lastAppendedTranscript = '';
            function getLang() {
                var lang = (document.documentElement && document.documentElement.lang) || (document.body && document.body.lang);
                if (lang && /^ja/i.test(lang)) return 'ja-JP';
                if (lang && /^en/i.test(lang)) return 'en-US';
                if (lang && /^zh/i.test(lang)) return 'zh-CN';
                return 'ja-JP';
            }
            function setListening(flag) {
                isListening = !!flag;
                if (micBtn) {
                    if (isListening) {
                        micBtn.classList.add('chat-mic-listening');
                        micBtn.title = '音声入力中（クリックで停止）';
                        micBtn.setAttribute('aria-label', '音声入力中（クリックで停止）');
                    } else {
                        micBtn.classList.remove('chat-mic-listening');
                        micBtn.title = '音声入力';
                        micBtn.setAttribute('aria-label', '音声入力');
                    }
                }
            }
            function startListening() {
                if (!messageInput || !recognition) return;
                if (messageInput.getAttribute('data-ai-mode') === 'true') return;
                lastProcessedResultIndex = -1;
                lastAppendedTranscript = '';
                try {
                    recognition.lang = getLang();
                    recognition.continuous = true;
                    recognition.interimResults = true;
                    recognition.start();
                    setListening(true);
                } catch (err) {
                    console.warn('Speech recognition start failed:', err);
                    setListening(false);
                }
            }
            function stopListening() {
                if (!recognition) return;
                try { recognition.stop(); } catch (e) {}
                setListening(false);
            }
            document.addEventListener('DOMContentLoaded', function() {
                micBtn = document.getElementById('chatMicBtn');
                messageInput = document.getElementById('messageInput');
                if (!micBtn || !messageInput) return;
                if (!SpeechRecognition) {
                    micBtn.disabled = true;
                    micBtn.title = '音声入力はこのブラウザでは利用できません';
                    return;
                }
                recognition = new SpeechRecognition();
                recognition.onresult = function(e) {
                    for (var i = lastProcessedResultIndex + 1; i < e.results.length; i++) {
                        var result = e.results[i];
                        if (!result.isFinal || !result[0] || !result[0].transcript) continue;
                        var transcript = result[0].transcript;
                        transcript = transcript.replace(/\sまる\s/g, '。').replace(/\sまる$/g, '。').replace(/^まる\s/g, '。').replace(/^まる$/g, '。');
                        transcript = transcript.replace(/\sてん\s/g, '、').replace(/\sてん$/g, '、').replace(/^てん\s/g, '、').replace(/^てん$/g, '、');
                        var t = transcript.trim();
                        if (t === lastAppendedTranscript) continue;
                        lastAppendedTranscript = t;
                        lastProcessedResultIndex = i;
                        var cur = messageInput.value || '';
                        var sep = (cur.length > 0 && cur.slice(-1) !== '\n') ? ' ' : '';
                        messageInput.value = cur + sep + transcript;
                        if (typeof autoResizeInput === 'function') autoResizeInput(messageInput);
                    }
                };
                recognition.onend = function() {
                    setListening(false);
                };
                recognition.onerror = function(e) {
                    if (e.error === 'not-allowed' || e.error === 'audio-capture') {
                        if (typeof showAIToast === 'function') showAIToast('マイクの使用が許可されていません');
                        else alert('マイクの使用が許可されていません');
                    } else if (e.error === 'network') {
                        if (typeof showAIToast === 'function') showAIToast('音声認識はネットワーク接続が必要です');
                        else alert('音声認識はネットワーク接続が必要です');
                    }
                    setListening(false);
                };
                micBtn.addEventListener('click', function() {
                    if (micBtn.disabled) return;
                    if (isListening) {
                        stopListening();
                    } else {
                        startListening();
                    }
                });
            });
        })();
        
        // 入力欄内でホイールしたときは入力欄（テキストエリア）をスクロール（表示欄に取られないようにする）
        document.addEventListener('wheel', function inputAreaWheelToTextarea(e) {
            var inputArea = document.getElementById('inputArea');
            var ta = document.getElementById('messageInput');
            if (!inputArea || !ta || !inputArea.contains(e.target)) return;
            var maxScroll = ta.scrollHeight - ta.clientHeight;
            if (maxScroll <= 0) return;
            e.preventDefault();
            e.stopPropagation();
            var next = ta.scrollTop + e.deltaY;
            ta.scrollTop = Math.max(0, Math.min(maxScroll, next));
        }, { passive: false });
        
        // ============================================
        // To機能：宛先選択・メンション送信（本文中の「To 名前」も解析）
        window.chatSelectedToIds = window.chatSelectedToIds || [];
        /**
         * 本文から [To:ID] 形式を抽出してメンションIDに変換（Chatwork風）。
         * 例: "[To:123]Kayoさん\n機能実験…\n[To:456]Momoeさん\n上記のあと…" → [123, 456]
         */
        function parseToIdsFromContent(content) {
            if (!content || typeof content !== 'string') return [];
            var ids = [];
            var re = /\[To:(\d+)\]/g;
            var m;
            while ((m = re.exec(content)) !== null) {
                var id = parseInt(m[1], 10);
                if (id && ids.indexOf(id) === -1) ids.push(id);
            }
            return ids;
        }
        /**
         * 本文から「To 名前」行を抽出し、会話メンバーのIDに変換（従来形式）。
         */
        function parseToNamesFromContent(content) {
            if (!content || typeof content !== 'string') return [];
            var members = window.currentConversationMembers || [];
            if (members.length === 0) return [];
            var currentUserId = window._currentUserId;
            var ids = [];
            var re = /(?:^|\n)\s*To\s+([^\n]+)/gi;
            var m;
            while ((m = re.exec(content)) !== null) {
                var namePart = (m[1] || '').trim();
                if (!namePart) continue;
                var found = members.find(function(mem) {
                    if (mem.id == currentUserId) return false;
                    var dn = (mem.display_name || mem.name || '').trim();
                    return dn === namePart || (dn.indexOf(namePart) === 0 && (dn.length === namePart.length || /[\s\u3000]/.test(dn.charAt(namePart.length))));
                });
                if (found && ids.indexOf(found.id) === -1) ids.push(parseInt(found.id, 10));
            }
            return ids;
        }
        /** 入力欄のカーソル位置に [To:ID]名前さん を挿入（Chatwork風・文中にToを表示） */
        window.insertToMentionLine = function(uid, name) {
            var input = document.getElementById('messageInput');
            if (!input) return;
            var text = (uid === 'all') ? '[To:all]全員\n' : '[To:' + uid + ']' + (name || '') + 'さん\n';
            var start = input.selectionStart, end = input.selectionEnd;
            var val = input.value;
            input.value = val.slice(0, start) + text + val.slice(end);
            input.selectionStart = input.selectionEnd = start + text.length;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        };
        function updateToRowBar() {
            var bar = document.getElementById('toRowBar');
            var chips = document.getElementById('toRowChips');
            var toBtn = document.getElementById('toBtn');
            var ids = window.chatSelectedToIds || [];
            if (toBtn) {
                if (ids.length > 0) toBtn.classList.add('to-btn-has-selection');
                else toBtn.classList.remove('to-btn-has-selection');
            }
            if (!bar || !chips) return;
            if (ids.length === 0) {
                bar.style.display = 'none';
                chips.innerHTML = '';
                return;
            }
            var isAll = ids.indexOf('all') !== -1 || ids.some(function(x) { return x === 'all'; });
            if (isAll) {
                chips.innerHTML = '<span class="to-chip" data-to-id="all">ALL<button type="button" class="to-chip-remove" onclick="window.chatSelectedToIds=[];updateToRowBar();" aria-label="削除">×</button></span>';
            } else {
                var members = window.currentConversationMembers || [];
                chips.innerHTML = ids.map(function(uid) {
                    var m = members.find(function(x) { return x.id == uid; });
                    var name = (m && (m.display_name || m.name)) ? escapeHtml(String(m.display_name || m.name)) : 'ID:' + uid;
                    return '<span class="to-chip" data-to-id="' + uid + '">' + name + '<button type="button" class="to-chip-remove" onclick="removeToMember(' + uid + ')" aria-label="削除">×</button></span>';
                }).join('');
            }
            bar.style.display = '';
        }
        window.removeToMember = function(uid) {
            window.chatSelectedToIds = (window.chatSelectedToIds || []).filter(function(id) { return id != uid; });
            updateToRowBar();
        };
        function closeToSelectorPopup() {
            var pop = document.getElementById('toSelectorPopup');
            if (pop) {
                pop.classList.remove('to-selector-open');
                if (pop._toEscapeHandler) {
                    document.removeEventListener('keydown', pop._toEscapeHandler);
                    pop._toEscapeHandler = null;
                }
            }
        }
        window.closeToSelectorPopup = closeToSelectorPopup;
        async function openToSelector() {
            if (!window.currentConversationId) {
                alert('会話を選択してください');
                return;
            }
            await loadConversationMembers();
            var members = window.currentConversationMembers || [];
            var currentUserId = window._currentUserId;
            var others = members.filter(function(m) { return m.id != currentUserId; });
            // 表示名でソート：アルファベットは A～Z、ひらがなは あ～ん の順
            others = others.slice().sort(function(a, b) {
                var nameA = (a.display_name || a.name || '').trim();
                var nameB = (b.display_name || b.name || '').trim();
                return nameA.localeCompare(nameB, 'ja', { sensitivity: 'base' });
            });
            if (others.length === 0) {
                alert('この会話には他のメンバーがいません');
                return;
            }
            openToSelectorPanel(others, members);
        }
        function openToSelectorPanel(others, members) {
            var pop = document.getElementById('toSelectorPopup');
            if (!pop) {
                pop = document.createElement('div');
                pop.id = 'toSelectorPopup';
                pop.className = 'to-selector-popup';
                pop.innerHTML = '<div class="to-selector-backdrop" onclick="closeToSelectorPopup()"></div><div class="to-selector-panel"><div class="to-selector-header"><span>宛先を選択</span><button type="button" class="to-selector-close" onclick="closeToSelectorPopup()" aria-label="閉じる">×</button></div><div class="to-selector-list" id="toSelectorList"></div></div>';
                document.body.appendChild(pop);
            }
            var listEl = document.getElementById('toSelectorList');
            if (!listEl) return;
            // 「選択中:」表示は廃止（文中のToで十分なため）。updateToSelectorSelected は DOM が無い場合は何もしない
            function updateToSelectorSelected() {
                var el = document.getElementById('toSelectorSelected');
                if (!el) return;
                var sel = window.chatSelectedToIds || [];
                if (!sel.length) { el.innerHTML = ''; return; }
                var isAll = sel.indexOf('all') !== -1 || sel.some(function(x) { return x === 'all'; });
                if (isAll) {
                    el.innerHTML = '<span class="to-selector-selected-label">選択中: </span><span class="to-selector-chip">ALL<button type="button" class="to-selector-chip-remove" data-uid="all" aria-label="削除">×</button></span>';
                } else {
                    el.innerHTML = '<span class="to-selector-selected-label">選択中: </span>' + sel.map(function(uid) {
                        var m = members.find(function(x) { return x.id == uid; });
                        var name = (m && (m.display_name || m.name)) ? escapeHtml(String(m.display_name || m.name)) : 'ID:' + uid;
                        return '<span class="to-selector-chip">' + name + '<button type="button" class="to-selector-chip-remove" data-uid="' + uid + '" aria-label="削除">×</button></span>';
                    }).join('');
                }
                el.querySelectorAll('.to-selector-chip-remove').forEach(function(btn) {
                    btn.onclick = function() {
                        var uidRaw = btn.getAttribute('data-uid');
                        if (uidRaw === 'all') {
                            window.chatSelectedToIds = [];
                        } else {
                            removeToMember(parseInt(uidRaw, 10));
                        }
                        renderToSelectorList();
                        updateToSelectorSelected();
                        updateToRowBar();
                    };
                });
            }
            function renderToSelectorList() {
                var sel = window.chatSelectedToIds || [];
                var isAllSelected = sel.indexOf('all') !== -1 || sel.some(function(x) { return x === 'all'; });
                var allBtn = '<button type="button" class="to-selector-item to-selector-item-all' + (isAllSelected ? ' selected' : '') + '" data-uid="all">ALL</button>';
                var itemsHtml = others.map(function(m) {
                    var id = m.id;
                    var name = (m.display_name || m.name) ? escapeHtml(String(m.display_name || m.name)) : 'ID:' + id;
                    var checked = isAllSelected || sel.indexOf(id) !== -1 || sel.some(function(x) { return x == id; });
                    return '<button type="button" class="to-selector-item' + (checked ? ' selected' : '') + '" data-uid="' + id + '">' + name + '</button>';
                }).join('');
                listEl.innerHTML = allBtn + itemsHtml;
                listEl.querySelectorAll('.to-selector-item').forEach(function(btn) {
                    btn.onclick = function() {
                        var uidRaw = btn.getAttribute('data-uid');
                        if (uidRaw === 'all') {
                            var cur = window.chatSelectedToIds || [];
                            var wasAll = cur.indexOf('all') !== -1 || cur.some(function(x) { return x === 'all'; });
                            if (wasAll) {
                                window.chatSelectedToIds = [];
                            } else {
                                window.chatSelectedToIds = ['all'];
                                if (typeof window.insertToMentionLine === 'function') window.insertToMentionLine('all', '全員');
                            }
                            renderToSelectorList();
                            updateToSelectorSelected();
                            updateToRowBar();
                        } else {
                            var uid = parseInt(uidRaw, 10);
                            var cur = window.chatSelectedToIds || [];
                            var idx = cur.findIndex(function(x) { return x == uid; });
                            if (idx !== -1) {
                                window.chatSelectedToIds = cur.slice(0, idx).concat(cur.slice(idx + 1));
                            } else {
                                window.chatSelectedToIds = cur.concat([uid]);
                                var mem = members.find(function(x) { return x.id == uid; });
                                var displayName = (mem && (mem.display_name || mem.name)) ? String(mem.display_name || mem.name) : '';
                                if (typeof window.insertToMentionLine === 'function') window.insertToMentionLine(uid, displayName);
                        }
                        renderToSelectorList();
                        updateToSelectorSelected();
                        updateToRowBar();
                        }
                    };
                });
            }
            renderToSelectorList();
            updateToSelectorSelected();
            updateToRowBar();
            pop.classList.add('to-selector-open');

            // Toボタンの上にパネルを配置
            var toBtn = document.getElementById('toBtn');
            var panel = pop.querySelector('.to-selector-panel');
            if (toBtn && panel) {
                var btnRect = toBtn.getBoundingClientRect();
                var panelH = panel.offsetHeight;
                var panelW = panel.offsetWidth;
                var topPos = btnRect.top - panelH - 6;
                var leftPos = btnRect.left;
                if (topPos < 8) topPos = 8;
                if (leftPos + panelW > window.innerWidth - 8) {
                    leftPos = window.innerWidth - panelW - 8;
                }
                if (leftPos < 8) leftPos = 8;
                panel.style.top = topPos + 'px';
                panel.style.left = leftPos + 'px';
            }

            pop._toEscapeHandler = function(e) {
                if (e.key === 'Escape') closeToSelectorPopup();
            };
            document.addEventListener('keydown', pop._toEscapeHandler);
        }
        document.addEventListener('DOMContentLoaded', function() {
            var toBtn = document.getElementById('toBtn');
            if (toBtn) toBtn.addEventListener('click', openToSelector);
            updateToRowBar();
        });
        
        // ============================================
        let conversationMembers = window.currentConversationMembers || [];
        window._currentUserId = <?= $_SESSION['user_id'] ?? 0 ?>;
        
        function clearInvalidConversationAndRedirect() {
            if (typeof window.clearChatDraft === 'function') window.clearChatDraft(conversationId);
            window.location.href = 'chat.php';
        }
        window.clearInvalidConversationAndRedirect = clearInvalidConversationAndRedirect;
        async function loadConversationMembers() {
            if (!conversationId) return;
            
            try {
                const response = await fetch(`api/conversations.php?action=get&id=${conversationId}`);
                const data = await response.json();
                
                if (response.status === 404 || response.status === 403) {
                    clearInvalidConversationAndRedirect();
                    return;
                }
                if (data.success && data.conversation && data.conversation.members) {
                    conversationMembers = data.conversation.members;
                    window.currentConversationMembers = conversationMembers;
                    window._currentUserId = <?= $_SESSION['user_id'] ?? 0 ?>;
                }
            } catch (error) {
                console.error('メンバー取得エラー:', error);
            }
            
            // グループ設定表示を更新
            updateGroupSettingsVisibility();
        }
        
        // ========== 絵文字・GIF ピッカー（プレミアム版） ==========
        const emojiData = {
            'トレンド': ['😊', '❤️', '👍', '🎉', '😂', '🔥', '✨', '💯', '🥰', '🙏', '💪', '👏', '🤔', '😭', '🥺', '💕'],
            '笑顔': ['😀', '😃', '😄', '😁', '😆', '😅', '🤣', '😂', '🙂', '😊', '😇', '🥰', '😍', '🤩', '😘', '😗', '😚', '😙', '🥲', '😋', '😛', '😜', '🤪', '😝', '🤗', '🤭', '🤫', '🤔', '😎', '🤓', '🧐'],
            '愛': ['❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '🤎', '💔', '❤️‍🔥', '❤️‍🩹', '💕', '💞', '💓', '💗', '💖', '💝', '💘', '🥰', '😍', '😘', '💋', '🫶', '🤗'],
            '手': ['👍', '👎', '👊', '✊', '🤛', '🤜', '🤝', '👏', '🙌', '🫶', '👐', '🤲', '🙏', '✌️', '🤞', '🫰', '🤟', '🤘', '🤙', '👋', '🖐️', '✋', '👌', '🤏', '✍️', '💪'],
            'お祝い': ['🎉', '🎊', '🥳', '🎁', '🎈', '🎂', '🍰', '🎄', '🎃', '🎆', '🎇', '✨', '🌟', '⭐', '🏆', '🥇', '🎯', '🍾', '🥂', '🎵', '🎶', '🎤', '🎸'],
            '悲しい': ['😢', '😭', '😿', '🥺', '😞', '😔', '😟', '😕', '🙁', '☹️', '😣', '😖', '😫', '😩', '🥱', '😤', '😠', '😡', '💔', '😰', '😥', '😓'],
            '驚き': ['😮', '😯', '😲', '😱', '🤯', '😳', '🥵', '🥶', '😨', '😰', '🙀', '❗', '❕', '❓', '❔', '⁉️', '‼️', '💡', '👀', '🔥', '💥', '💫'],
            'OK': ['👌', '✅', '☑️', '✔️', '💯', '⭕', '🆗', '👍', '🤙', '🙆', '🙆‍♂️', '🙆‍♀️', '💪', '🎯', '🏆'],
            'NO': ['🙅', '🙅‍♂️', '🙅‍♀️', '❌', '❎', '🚫', '⛔', '🆖', '👎', '😤', '💢', '🚷', '🙊', '🤐'],
            'おやすみ': ['😴', '💤', '🌙', '🌛', '🌜', '🌚', '🌝', '⭐', '🌟', '✨', '🛏️', '😪', '🥱', '🧸', '🌃', '🌌'],
            '動物': ['🐱', '🐶', '🐭', '🐹', '🐰', '🦊', '🐻', '🐼', '🐨', '🐯', '🦁', '🐮', '🐷', '🐸', '🐵', '🐔', '🐧', '🐦', '🦄', '🐝', '🦋', '🐢', '🐙', '🦀'],
            '食べ物': ['🍕', '🍔', '🍟', '🌭', '🍿', '🍣', '🍜', '🍝', '🍛', '🍙', '🍰', '🎂', '🍩', '🍪', '🍫', '🍬', '🍦', '☕', '🍵', '🧋', '🍺', '🍷', '🥤', '🧃'],
            '自然': ['🌸', '🌺', '🌻', '🌹', '🌷', '💐', '🌿', '🍀', '🍁', '🍂', '🌳', '🌴', '🌵', '🌊', '🌈', '☀️', '🌤️', '⛅', '🌧️', '❄️', '⛄', '🔥', '💧'],
            '仕事': ['💼', '📁', '📂', '📊', '📈', '📉', '📝', '✏️', '📌', '📎', '🔗', '💻', '🖥️', '📱', '⌚', '📧', '✉️', '📞', '🔔', '⏰', '📅', '✅', '❌']
        };
        
        let pickerOpen = false;
        let currentPickerTab = 'emoji'; // 'emoji' or 'gif'
        let currentEmojiCategory = 'トレンド';
        
        window.toggleEmojiPicker = function() {
            const existingPicker = document.getElementById('masterPickerPopup');
            if (existingPicker) {
                existingPicker.remove();
                pickerOpen = false;
                return;
            }
            openMasterPicker('emoji');
        };
        
        function openMasterPicker(tab) {
            pickerOpen = true;
            currentPickerTab = tab;
            
            const picker = document.createElement('div');
            picker.id = 'masterPickerPopup';
            picker.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);width:420px;max-width:95vw;height:450px;background:#fff;border-radius:20px;box-shadow:0 15px 50px rgba(0,0,0,0.25);z-index:1001;overflow:hidden;display:flex;flex-direction:column;';
            
            picker.innerHTML = `
                <div style="display:flex;border-bottom:1px solid #f1f5f9;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);">
                    <button id="tabEmoji" onclick="switchPickerTab('emoji')" style="flex:1;padding:14px;border:none;background:${tab==='emoji'?'rgba(255,255,255,0.2)':'transparent'};color:#fff;font-size:14px;font-weight:600;cursor:pointer;transition:all 0.2s;">😊 絵文字</button>
                    <button id="tabGif" onclick="switchPickerTab('gif')" style="flex:1;padding:14px;border:none;background:${tab==='gif'?'rgba(255,255,255,0.2)':'transparent'};color:#fff;font-size:14px;font-weight:600;cursor:pointer;transition:all 0.2s;">🎬 GIF</button>
                </div>
                <div id="pickerContent" style="flex:1;overflow:hidden;display:flex;flex-direction:column;"></div>
            `;
            
            document.body.appendChild(picker);
            
            if (tab === 'emoji') {
                renderEmojiContent();
            } else {
                renderGifContent();
            }
        }
        
        window.switchPickerTab = function(tab) {
            currentPickerTab = tab;
            document.getElementById('tabEmoji').style.background = tab === 'emoji' ? 'rgba(255,255,255,0.2)' : 'transparent';
            document.getElementById('tabGif').style.background = tab === 'gif' ? 'rgba(255,255,255,0.2)' : 'transparent';
            
            if (tab === 'emoji') {
                renderEmojiContent();
            } else {
                renderGifContent();
            }
        };
        
        function renderEmojiContent() {
            const content = document.getElementById('pickerContent');
            const categories = Object.keys(emojiData);
            
            const catsHtml = categories.map(cat => 
                `<button onclick="selectEmojiCat('${cat}')" class="ep-cat-btn" data-cat="${cat}" style="padding:8px 14px;border:none;background:${cat===currentEmojiCategory?'#667eea':'#f1f5f9'};color:${cat===currentEmojiCategory?'#fff':'#475569'};border-radius:20px;font-size:12px;font-weight:500;cursor:pointer;white-space:nowrap;transition:all 0.2s;">${cat}</button>`
            ).join('');
            
            const emojis = emojiData[currentEmojiCategory];
            const emojisHtml = emojis.map(e => 
                `<button onclick="insertEmoji('${e}')" style="width:42px;height:42px;border:none;background:transparent;font-size:26px;cursor:pointer;border-radius:10px;transition:all 0.12s;display:flex;align-items:center;justify-content:center;" onmouseover="this.style.background='#eef2ff';this.style.transform='scale(1.2)';" onmouseout="this.style.background='transparent';this.style.transform='scale(1)';">${e}</button>`
            ).join('');
            
            content.innerHTML = `
                <div style="padding:12px;border-bottom:1px solid #f1f5f9;overflow-x:auto;">
                    <div style="display:flex;gap:6px;">${catsHtml}</div>
                </div>
                <div id="emojiGrid" style="flex:1;display:grid;grid-template-columns:repeat(8,1fr);gap:4px;padding:12px;overflow-y:auto;align-content:start;">
                    ${emojisHtml}
                </div>
            `;
        }
        
        window.selectEmojiCat = function(cat) {
            currentEmojiCategory = cat;
            renderEmojiContent();
        };
        
        function renderGifContent() {
            const content = document.getElementById('pickerContent');
            // 日本語と英語の両方のキーワードを用意
            const gifCategories = [
                {label: 'トレンド', query: 'trending'},
                {label: '笑い', query: 'laughing funny'},
                {label: '愛', query: 'love heart'},
                {label: 'リアクション', query: 'reaction'},
                {label: '祝う', query: 'celebrate party'},
                {label: '驚き', query: 'surprised shocked'},
                {label: 'ありがとう', query: 'thank you'},
                {label: 'OK', query: 'ok thumbs up'},
                {label: 'NO', query: 'no nope'},
                {label: 'おやすみ', query: 'good night sleep'},
                {label: 'アニメ', query: 'anime'},
                {label: '動物', query: 'cute animals'},
                {label: '食べ物', query: 'food yummy'}
            ];
            
            const catsHtml = gifCategories.map(cat => 
                `<button onclick="searchGif('${cat.query}')" class="gif-cat-btn" style="padding:8px 14px;border:none;background:#f1f5f9;color:#475569;border-radius:20px;font-size:12px;font-weight:500;cursor:pointer;white-space:nowrap;transition:all 0.2s;" onmouseover="this.style.background='#667eea';this.style.color='#fff';" onmouseout="this.style.background='#f1f5f9';this.style.color='#475569';">${cat.label}</button>`
            ).join('');
            
            content.innerHTML = `
                <div style="padding:12px;border-bottom:1px solid #f1f5f9;">
                    <div style="position:relative;margin-bottom:10px;">
                        <input type="text" id="gifSearchInput" placeholder="GIFを検索..." aria-label="GIFを検索" oninput="debounceGifSearch(this.value)" style="width:100%;padding:10px 14px 10px 40px;border:1px solid #e2e8f0;border-radius:12px;font-size:14px;outline:none;transition:border 0.2s;box-sizing:border-box;" onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e2e8f0'">
                        <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8;">🔍</span>
                    </div>
                    <div style="display:flex;gap:6px;overflow-x:auto;padding-bottom:4px;">${catsHtml}</div>
                </div>
                <div id="gifGrid" style="flex:1;display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:12px;overflow-y:auto;align-content:start;">
                    <div style="grid-column:1/-1;text-align:center;color:#94a3b8;padding:40px;">
                        <div style="font-size:40px;margin-bottom:10px;">🎬</div>
                        カテゴリを選択するか、キーワードで検索
                    </div>
                </div>
            `;
            
            // 初期表示でトレンドを読み込む
            searchGif('trending');
        }
        
        let gifSearchTimeout;
        window.debounceGifSearch = function(query) {
            clearTimeout(gifSearchTimeout);
            gifSearchTimeout = setTimeout(() => searchGif(query), 300);
        };
        
        window.searchGif = async function(query) {
            if (!query) return;
            
            const grid = document.getElementById('gifGrid');
            if (!grid) return;
            
            grid.innerHTML = `
                <div style="grid-column:1/-1;text-align:center;color:#94a3b8;padding:40px;">
                    <div style="font-size:30px;display:inline-block;animation:gifSpin 1s linear infinite;">🔄</div>
                    <div style="margin-top:8px;">読み込み中...</div>
                </div>
                <style>@keyframes gifSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }</style>
            `;
            
            try {
                // サーバーサイドAPI経由で検索
                const searchQuery = encodeURIComponent(query);
                const response = await fetch('api/gif.php?q=' + searchQuery + '&limit=24');
                
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                
                const data = await response.json();
                console.log('GIF API response:', data);
                
                if (data.results && data.results.length > 0) {
                    grid.innerHTML = data.results.map(gif => {
                        const tinyUrl = gif.tiny || gif.full;
                        const fullUrl = gif.full || gif.tiny;
                        // URLをエスケープ
                        const escapedUrl = fullUrl.replace(/'/g, "\\'");
                        return `
                            <div onclick="insertGif('${escapedUrl}')" style="cursor:pointer;border-radius:10px;overflow:hidden;aspect-ratio:1;background:linear-gradient(135deg,#f1f5f9,#e2e8f0);transition:all 0.15s;box-shadow:0 2px 8px rgba(0,0,0,0.08);position:relative;" onmouseover="this.style.transform='scale(1.05)';this.style.boxShadow='0 6px 20px rgba(102,126,234,0.3)';" onmouseout="this.style.transform='scale(1)';this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)';">
                                <img src="${tinyUrl}" alt="${gif.title || ''}" style="width:100%;height:100%;object-fit:cover;display:block;" loading="lazy" onerror="this.parentElement.style.display='none';">
                            </div>
                        `;
                    }).join('');
                } else {
                    const errorMsg = data.error || '結果なし';
                    const debugInfo = data.debug ? JSON.stringify(data.debug) : '';
                    grid.innerHTML = `
                        <div style="grid-column:1/-1;text-align:center;color:#94a3b8;padding:40px;">
                            <div style="font-size:48px;margin-bottom:12px;">😢</div>
                            <div style="font-weight:500;">GIFが見つかりませんでした</div>
                            <div style="font-size:12px;margin-top:6px;opacity:0.7;">${errorMsg}</div>
                            ${debugInfo ? '<div style="font-size:10px;margin-top:10px;color:#ef4444;word-break:break-all;">' + debugInfo + '</div>' : ''}
                        </div>
                    `;
                }
            } catch (e) {
                console.error('GIF search error:', e);
                grid.innerHTML = `
                    <div style="grid-column:1/-1;text-align:center;color:#ef4444;padding:40px;">
                        <div style="font-size:48px;margin-bottom:12px;">⚠️</div>
                        <div style="font-weight:500;">読み込みエラー</div>
                        <div style="font-size:12px;margin-top:6px;opacity:0.7;">${e.message || 'もう一度お試しください'}</div>
                    </div>
                `;
            }
        };
        
        window.insertEmoji = function(emoji) {
            const input = document.getElementById('messageInput');
            if (input) {
                const start = input.selectionStart;
                const end = input.selectionEnd;
                const text = input.value;
                input.value = text.substring(0, start) + emoji + text.substring(end);
                input.selectionStart = input.selectionEnd = start + emoji.length;
                input.focus();
            }
        };
        
        window.insertGif = function(gifUrl) {
            // GIFをメッセージとして送信
            const input = document.getElementById('messageInput');
            if (input) {
                input.value = gifUrl;
                sendMessage();
                // ピッカーを閉じる
                const picker = document.getElementById('masterPickerPopup');
                if (picker) picker.remove();
                pickerOpen = false;
            }
        };
        
        // クリックで閉じる
        document.addEventListener('click', function(e) {
            if (pickerOpen && !e.target.closest('#masterPickerPopup') && !e.target.closest('[onclick*="toggleEmojiPicker"]') && !e.target.closest('.toolbar-btn')) {
                const picker = document.getElementById('masterPickerPopup');
                if (picker) picker.remove();
                pickerOpen = false;
            }
        });
        
        // 携帯では添付ボタンで「最近使用したファイル」を第一選択に（カメラ/ファイルの選択肢を避ける）
        var ATTACH_INPUT_DEFAULT_ACCEPT = 'image/*,image/heic,.heic,.heif,video/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z';
        var attachSheetEl = null;
        var attachSheetOverlay = null;
        var recentFileInput = null; // ドキュメント用（カメラを出さない accept）
        var RECENT_FILE_ACCEPT = 'application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/*';
        
        function ensureRecentFileInput() {
            if (recentFileInput && recentFileInput.parentNode) return recentFileInput;
            recentFileInput = document.createElement('input');
            recentFileInput.type = 'file';
            recentFileInput.multiple = true;
            recentFileInput.setAttribute('accept', RECENT_FILE_ACCEPT);
            recentFileInput.setAttribute('aria-label', '最近使用したファイルを選択');
            recentFileInput.style.cssText = 'position:absolute;left:-9999px;top:0;width:0;height:0;opacity:0;pointer-events:none;';
            recentFileInput.onchange = function() { if (this.files && this.files.length > 0 && typeof onAttachFileSelected === 'function') onAttachFileSelected(this.files); this.value = ''; };
            document.body.appendChild(recentFileInput);
            return recentFileInput;
        }
        
        function showAttachSheetMobile() {
            try {
                if (attachSheetOverlay && attachSheetEl) {
                    attachSheetOverlay.classList.add('show');
                    attachSheetEl.classList.add('show');
                    attachSheetEl.style.transform = 'translateY(0)';
                    document.body.style.overflow = 'hidden';
                    return;
                }
            } catch (e) { /* 再表示時エラー時は下で作り直す */ }
            attachSheetOverlay = document.createElement('div');
            attachSheetOverlay.className = 'attach-sheet-overlay';
            attachSheetOverlay.setAttribute('role', 'dialog');
            attachSheetOverlay.setAttribute('aria-label', '添付方法を選択');
            attachSheetOverlay.style.cssText = 'position:fixed;inset:0;z-index:10002;background:rgba(0,0,0,0.4);display:flex;align-items:flex-end;justify-content:center;opacity:0;visibility:hidden;transition:opacity 0.2s, visibility 0.2s;';
            attachSheetEl = document.createElement('div');
            attachSheetEl.className = 'attach-sheet';
            attachSheetEl.style.cssText = 'background:var(--bg-main, #fff);border-radius:16px 16px 0 0;width:100%;max-width:480px;padding:20px;padding-bottom:calc(20px + env(safe-area-inset-bottom, 0));box-shadow:0 -4px 20px rgba(0,0,0,0.15);transform:translateY(100%);transition:transform 0.25s ease;';
            attachSheetEl.innerHTML = '<div style="margin-bottom:16px;font-size:15px;font-weight:600;color:var(--text, #333);">添付する方法を選んでください</div>' +
                '<button type="button" class="attach-sheet-btn attach-sheet-btn-primary" data-action="recent" style="display:flex;align-items:center;gap:14px;width:100%;padding:16px 18px;margin-bottom:10px;border:none;border-radius:12px;background:var(--bg-hover, #f0f4f8);color:var(--text, #333);font-size:16px;text-align:left;cursor:pointer;">' +
                '<span style="font-size:24px;">📁</span><span><strong>最近使用したファイル</strong><br><small style="color:var(--text-light, #666);font-size:13px;">PDF・Office・ZIPなど（ファイル一覧が開きます）</small></span></button>' +
                '<button type="button" class="attach-sheet-btn" data-action="camera" style="display:flex;align-items:center;gap:14px;width:100%;padding:16px 18px;border:none;border-radius:12px;background:var(--bg-hover, #f0f4f8);color:var(--text, #333);font-size:16px;text-align:left;cursor:pointer;">' +
                '<span style="font-size:24px;">📷</span><span><strong>カメラ・写真・動画</strong><br><small style="color:var(--text-light, #666);font-size:13px;">撮影またはギャラリーから選択</small></span></button>' +
                '<button type="button" class="attach-sheet-cancel" style="margin-top:16px;width:100%;padding:14px;border:none;border-radius:10px;background:transparent;color:var(--text-light, #666);font-size:15px;cursor:pointer;">キャンセル</button>';
            attachSheetOverlay.appendChild(attachSheetEl);
            document.body.appendChild(attachSheetOverlay);
            if (!document.getElementById('attach-sheet-styles')) {
                var sty = document.createElement('style');
                sty.id = 'attach-sheet-styles';
                sty.textContent = '.attach-sheet-overlay.show{opacity:1;visibility:visible}.attach-sheet-overlay.show .attach-sheet{transform:translateY(0)}.attach-sheet-btn:active,.attach-sheet-btn:hover{opacity:0.9}';
                document.head.appendChild(sty);
            }
            function closeAttachSheet(animate) {
                if (animate && attachSheetEl) {
                    attachSheetEl.style.transform = 'translateY(100%)';
                    setTimeout(function() {
                        if (attachSheetOverlay) attachSheetOverlay.classList.remove('show');
                        if (attachSheetEl) attachSheetEl.classList.remove('show');
                        document.body.style.overflow = '';
                    }, 250);
                } else {
                    if (attachSheetOverlay) attachSheetOverlay.classList.remove('show');
                    if (attachSheetEl) attachSheetEl.classList.remove('show');
                    document.body.style.overflow = '';
                }
            }
            attachSheetOverlay.addEventListener('click', function(e) {
                if (e.target === attachSheetOverlay || e.target.classList.contains('attach-sheet-cancel')) {
                    closeAttachSheet(true);
                }
            });
            attachSheetEl.addEventListener('click', function(e) {
                var btn = e.target.closest('.attach-sheet-btn');
                if (!btn) return;
                var action = btn.getAttribute('data-action');
                closeAttachSheet(false);
                if (action === 'recent') {
                    var input = ensureRecentFileInput();
                    input.click();
                } else if (action === 'camera') {
                    if (typeof openUnifiedAttachFilePicker === 'function') openUnifiedAttachFilePicker({ imageOnly: true });
                }
            });
            attachSheetOverlay.classList.add('show');
            attachSheetEl.classList.add('show');
            document.body.style.overflow = 'hidden';
            requestAnimationFrame(function() {
                attachSheetEl.style.transform = 'translateY(0)';
            });
        }
        
        function hideAttachSheet() {
            if (attachSheetOverlay) attachSheetOverlay.classList.remove('show');
            if (attachSheetEl) {
                attachSheetEl.classList.remove('show');
                attachSheetEl.style.transform = 'translateY(100%)';
            }
            document.body.style.overflow = '';
        }
        
        window.openAttachPicker = function() {
            var el = document.getElementById('imageInput');
            if (!el) return;
            try {
                if (window.innerWidth <= 768) {
                    showAttachSheetMobile();
                    return;
                }
                el.click();
            } catch (err) {
                try { el.click(); } catch (e2) {}
            }
        };
        
        // ========== ファイル送信の統一規格（DOCS/FILE_ATTACH_SPEC.md） ==========
        // 共通定数（パソコン・携帯・AI秘書で同じ accept を参照）
        var ATTACH_ACCEPT_IMAGE = 'image/png,image/jpeg,image/heic,image/webp,image/gif';
        var ATTACH_ACCEPT_ALL = 'application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.openxmlformats-officedocument.presentationml.presentation,image/png,image/jpeg,image/heic,image/webp,image/gif,video/*,application/*';
        
        // ファイル選択時の共通ハンドラ（全入口からここに集約）
        function onAttachFileSelected(files) {
            if (!files || files.length === 0) return;
            window._pendingFiles = [];
                showFilePreview(files[0]);
        }
        
        // 統一ファイル input（body 直下・グループ・AI・携帯で共有）
        var unifiedAttachInput = null;
        function ensureUnifiedAttachInput() {
            if (unifiedAttachInput && unifiedAttachInput.parentNode) return unifiedAttachInput;
            unifiedAttachInput = document.createElement('input');
            unifiedAttachInput.type = 'file';
            unifiedAttachInput.setAttribute('aria-label', 'ファイル・画像を添付');
            unifiedAttachInput.style.cssText = 'position:absolute;left:-9999px;top:0;width:0;height:0;opacity:0;pointer-events:none;';
            unifiedAttachInput.onchange = function() {
                var f = this.files;
                if (f && f.length > 0) onAttachFileSelected(f);
                this.value = '';
            };
            document.body.appendChild(unifiedAttachInput);
            return unifiedAttachInput;
        }
        
        // ファイル選択ダイアログを開く（imageOnly: true＝画像のみ / false＝全種）
        window.openUnifiedAttachFilePicker = function(opts) {
            opts = opts || {};
            var imageOnly = opts.imageOnly === true;
            var input = ensureUnifiedAttachInput();
            input.setAttribute('accept', imageOnly ? ATTACH_ACCEPT_IMAGE : ATTACH_ACCEPT_ALL);
            input.click();
        };
        
        // 通常グループの ⊕: 統一フローで開く（携帯=画像のみ、PC=全種）
        document.addEventListener('click', function(e) {
            var btn = e.target && e.target.closest && e.target.closest('.attach-btn');
            if (!btn || btn.classList.contains('ai-attach-btn') || btn.classList.contains('ai-always-on-btn')) return;
            var inputArea = document.getElementById('inputArea');
            if (!inputArea || !inputArea.contains(btn)) return;
            e.preventDefault();
            e.stopPropagation();
            if (btn.id !== 'mainAttachBtn' && (document.getElementById('aiFileInput') || !inputArea.contains(btn))) return;
            var isMobile = window.innerWidth <= 768;
            openUnifiedAttachFilePicker({ imageOnly: isMobile });
        }, true);
        
        // 他入力（recentFileInput 等）から呼ばれる共通ラッパー
        window.handleFileSelect = function(input) {
            var defaultAccept = input.getAttribute('data-attach-default-accept');
            if (defaultAccept !== null) {
                input.accept = defaultAccept;
                input.removeAttribute('data-attach-default-accept');
            }
            var files = input.files;
            if (!files || files.length === 0) { input.value = ''; return; }
            input.value = '';
            onAttachFileSelected(files);
        };
        
        /**
         * 複数ファイル選択画面（サムネイル＋チェック）。選択したものだけ送信できる。
         */
        function showMultiFileSelector(filesArray) {
            var overlay = document.getElementById('multiFileSelectorOverlay');
            var panel = document.getElementById('multiFileSelectorPanel');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'multiFileSelectorOverlay';
                overlay.style.cssText = 'position:fixed;inset:0;z-index:10010;background:rgba(0,0,0,0.5);display:flex;align-items:flex-end;justify-content:center;opacity:0;visibility:hidden;transition:opacity 0.2s, visibility 0.2s;';
                overlay.onclick = function(e) { if (e.target === overlay) hideMultiFileSelector(); };
                panel = document.createElement('div');
                panel.id = 'multiFileSelectorPanel';
                panel.style.cssText = 'background:var(--bg-main,#fff);border-radius:16px 16px 0 0;width:100%;max-width:480px;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 -4px 20px rgba(0,0,0,0.15);';
                panel.onclick = function(e) { e.stopPropagation(); };
                overlay.appendChild(panel);
                document.body.appendChild(overlay);
            } else {
                panel = document.getElementById('multiFileSelectorPanel');
            }
            var checked = filesArray.map(function() { return true; });
            function updateCount() {
                var n = checked.filter(function(c) { return c; }).length;
                var btn = panel.querySelector('.multi-select-send-btn');
                if (btn) btn.textContent = '送信（' + n + '件）';
                var toggleBtn = panel.querySelector('.multi-select-toggle-btn');
                if (toggleBtn) toggleBtn.textContent = checked.every(function(c) { return c; }) ? '全解除' : '全選択';
            }
            panel.innerHTML = '<div style="padding:16px;border-bottom:1px solid var(--border-light,#eee);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">' +
                '<span style="font-weight:600;font-size:15px;">送信する写真を選択</span>' +
                '<div style="display:flex;gap:8px;">' +
                '<button type="button" class="multi-select-toggle-btn" style="padding:6px 12px;border:1px solid var(--border-light);border-radius:8px;background:var(--bg-hover,#f5f5f5);font-size:13px;cursor:pointer;">全解除</button>' +
                '<button type="button" class="multi-select-send-btn" style="padding:8px 16px;border:none;border-radius:8px;background:var(--primary,#4f46e5);color:#fff;font-weight:600;font-size:14px;cursor:pointer;">送信（' + filesArray.length + '件）</button>' +
                '</div></div>' +
                '<div id="multiFileSelectorGrid" style="padding:12px;overflow-y:auto;flex:1;display:grid;grid-template-columns:repeat(auto-fill, minmax(100px, 1fr));gap:10px;"></div>' +
                '<div style="padding:12px 16px;border-top:1px solid var(--border-light,#eee);">' +
                '<button type="button" class="multi-select-cancel" style="width:100%;padding:12px;border:1px solid var(--border-light);border-radius:8px;background:transparent;font-size:14px;cursor:pointer;">キャンセル</button></div>';
            var grid = document.getElementById('multiFileSelectorGrid');
            filesArray.forEach(function(file, i) {
                var wrap = document.createElement('div');
                wrap.style.cssText = 'position:relative;border-radius:8px;overflow:hidden;aspect-ratio:1;background:var(--bg-hover,#f0f0f0);';
                var label = document.createElement('label');
                label.style.cssText = 'display:block;width:100%;height:100%;cursor:pointer;';
                var thumb = document.createElement('div');
                thumb.style.cssText = 'width:100%;height:100%;position:relative;';
                if (file.type.startsWith('image/')) {
                    var img = document.createElement('img');
                    img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
                    img.alt = file.name || '';
                    img.src = URL.createObjectURL(file);
                    thumb.appendChild(img);
                } else {
                    thumb.innerHTML = '<span style="font-size:32px;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);">📄</span><span style="position:absolute;bottom:4px;left:4px;right:4px;font-size:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(file.name || 'ファイル') + '</span>';
                }
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.checked = true;
                cb.setAttribute('data-index', String(i));
                cb.style.cssText = 'position:absolute;top:6px;right:6px;width:22px;height:22px;z-index:2;cursor:pointer;';
                thumb.appendChild(cb);
                label.appendChild(thumb);
                label.onclick = function(e) {
                    if (e.target === cb) return;
                    e.preventDefault();
                    checked[i] = !checked[i];
                    cb.checked = checked[i];
                    updateCount();
                };
                cb.onchange = function() { checked[i] = cb.checked; updateCount(); };
                wrap.appendChild(label);
                grid.appendChild(wrap);
            });
            panel.querySelector('.multi-select-toggle-btn').onclick = function() {
                var all = checked.every(function(c) { return c; });
                for (var j = 0; j < checked.length; j++) checked[j] = !all;
                panel.querySelectorAll('#multiFileSelectorGrid input[type="checkbox"]').forEach(function(cb, j) { cb.checked = checked[j]; });
                updateCount();
            };
            panel.querySelector('.multi-select-send-btn').onclick = function() {
                var selected = filesArray.filter(function(_, j) { return checked[j]; });
                if (selected.length === 0) { alert('1件以上選択してください'); return; }
                hideMultiFileSelector();
                if (selected.length === 1) {
                    pendingPasteFile = selected[0];
                    window._pendingFiles = [];
                    showFilePreview(selected[0]);
                    return;
                }
                pendingPasteFile = selected[0];
                window._pendingFiles = selected.slice(1);
                showFilePreview(selected[0]);
            };
            panel.querySelector('.multi-select-cancel').onclick = hideMultiFileSelector;
            overlay.style.opacity = '1';
            overlay.style.visibility = 'visible';
            document.body.style.overflow = 'hidden';
        }
        function hideMultiFileSelector() {
            var panel = document.getElementById('multiFileSelectorPanel');
            if (panel) {
                panel.querySelectorAll('img[src^="blob:"]').forEach(function(img) {
                    try { URL.revokeObjectURL(img.src); } catch (e) {}
                });
            }
            var overlay = document.getElementById('multiFileSelectorOverlay');
            if (overlay) {
                overlay.style.opacity = '0';
                overlay.style.visibility = 'hidden';
            }
            document.body.style.overflow = '';
        }
        
        // 貼り付けプレビュー用DOMが無い場合に作成（ファイル選択後に画面が出ない問題の対策）
        function ensurePastePreviewElements() {
            if (document.getElementById('pastePreview')) return;
            var backdrop = document.createElement('div');
            backdrop.className = 'paste-preview-backdrop';
            backdrop.id = 'pastePreviewBackdrop';
            backdrop.setAttribute('aria-hidden', 'true');
            backdrop.onclick = function() { cancelPaste(); };
            document.body.appendChild(backdrop);
            var panel = document.createElement('div');
            panel.className = 'paste-preview';
            panel.id = 'pastePreview';
            panel.onclick = function(e) { e.stopPropagation(); };
            panel.innerHTML = '<img id="pastePreviewImage" alt="プレビュー" style="display:none;">' +
                '<div id="pasteFileInfo" class="paste-file-info" style="display:none;"></div>' +
                '<div id="pasteFileNameRow" class="paste-file-name-row" style="display:none;margin:10px 0;">' +
                '<label style="font-size:13px;color:#6b7280;margin-bottom:6px;display:block;">📝 ファイル名（任意・変更可）:</label>' +
                '<input type="text" id="pasteFileNameInput" placeholder="表示名を入力" style="width:100%;padding:8px 12px;border:1px solid #e5e7eb;border-radius:6px;font-size:14px;box-sizing:border-box;" aria-label="ファイルの表示名">' +
                '</div>' +
                '<div class="paste-message-input" style="margin:12px 0;"><label style="font-size:13px;color:#6b7280;margin-bottom:6px;display:block;">💬 メッセージ（任意）:</label>' +
                '<textarea id="pasteMessageInput" placeholder="画像と一緒に送るメッセージを入力..." style="width:100%;min-height:60px;padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:14px;resize:vertical;box-sizing:border-box;" aria-label="画像と一緒に送るメッセージ"></textarea>' +
                '</div>' +
                '<div class="paste-to-selector" id="pasteToSelector"><label style="font-size:13px;color:#6b7280;margin-bottom:8px;display:block;">📨 宛先 (TO):</label>' +
                '<div class="paste-to-options" id="pasteToOptions" style="display:flex;flex-wrap:wrap;gap:6px;max-height:100px;overflow-y:auto;"></div>' +
                '<div id="pasteSelectedToDisplay" class="paste-selected-to-display" style="margin-top:8px;font-size:12px;color:var(--primary);min-height:18px;"></div></div>' +
                '<div id="pasteBulkCount" style="display:none;margin-bottom:10px;font-size:13px;color:var(--primary);"></div>' +
                '<div class="paste-preview-actions"><button type="button" class="cancel-btn" onclick="cancelPaste()">キャンセル</button>' +
                '<button type="button" class="send-btn paste-send-btn" onclick="sendPastedImage()">送信</button></div>';
            document.body.appendChild(panel);
        }
        
        // ファイルプレビュー表示（TO選択付き）
        function showFilePreview(file) {
            if (!file) return;
            ensurePastePreviewElements();
            pendingPasteFile = file;
            pasteSelectedTo = [];
            
            const previewImg = document.getElementById('pastePreviewImage');
            const fileInfo = document.getElementById('pasteFileInfo');
            if (!previewImg || !fileInfo) return;
            
            // ファイルタイプに応じたプレビュー
            if (file.type && file.type.startsWith('image/')) {
                // 画像: プレビュー表示（ファイル名変更欄は非表示）
                const fileNameRow = document.getElementById('pasteFileNameRow');
                if (fileNameRow) fileNameRow.style.display = 'none';
                const reader = new FileReader();
                reader.onload = function(e) {
                    if (previewImg) { previewImg.src = e.target.result; previewImg.style.display = 'block'; }
                    if (fileInfo) fileInfo.style.display = 'none';
                    showPastePreviewUI();
                };
                reader.onerror = function() { showPastePreviewUI(); };
                reader.readAsDataURL(file);
            } else {
                // その他のファイル: アイコン+ファイル名表示＋名前変更欄
                previewImg.style.display = 'none';
                fileInfo.style.display = 'flex';
                fileInfo.innerHTML = `
                    <span class="file-icon">${getFileIcon(file.type, file.name)}</span>
                    <div class="file-details">
                        <div class="file-name">${escapeHtml(file.name)}</div>
                        <div class="file-size">${formatFileSize(file.size)}</div>
                    </div>
                `;
                const fileNameRow = document.getElementById('pasteFileNameRow');
                const fileNameInput = document.getElementById('pasteFileNameInput');
                if (fileNameRow) fileNameRow.style.display = 'block';
                if (fileNameInput) {
                    fileNameInput.value = file.name || '';
                    fileNameInput.disabled = false;
                    fileNameInput.readOnly = false;
                }
                showPastePreviewUI();
                // 名前入力欄にフォーカス（タッチデバイス向け、短い遅延で確実に）
                setTimeout(function() {
                    const input = document.getElementById('pasteFileNameInput');
                    if (input) {
                        input.focus();
                        input.select();
                    }
                }, 150);
            }
        }
        
        function showPastePreviewUI() {
            // メッセージ入力欄に残っている文章をスクショ（貼り付け）ポップアップのメッセージ欄に移動
            const mainInput = document.getElementById('messageInput');
            const popupMessageInput = document.getElementById('pasteMessageInput');
            if (mainInput && popupMessageInput) {
                const text = (mainInput.value || '').trim();
                if (text) {
                    popupMessageInput.value = text;
                    mainInput.value = '';
                    mainInput.dispatchEvent(new Event('input', { bubbles: true }));
                    if (typeof adjustMessageInputHeight === 'function') adjustMessageInputHeight(mainInput);
                }
            }

            const pasteToSelector = document.getElementById('pasteToSelector');
            const toOptions = document.getElementById('pasteToOptions');
            const isAI = typeof isAISecretaryActive === 'function' ? isAISecretaryActive() : (window.isAISecretaryActive && window.isAISecretaryActive());
            
            if (isAI && pasteToSelector) {
                // AI秘書モード: TO選択は非表示
                pasteToSelector.style.display = 'none';
            } else {
                // 通常モード: TO選択オプションを生成
                if (pasteToSelector) pasteToSelector.style.display = '';
                if (toOptions) toOptions.innerHTML = '';
                
                const allBtn = document.createElement('button');
                allBtn.type = 'button';
                allBtn.className = 'paste-to-btn';
                allBtn.textContent = '👥 全員';
                allBtn.dataset.value = 'all';
                allBtn.onclick = () => togglePasteTo('all', allBtn);
                if (toOptions) toOptions.appendChild(allBtn);
                
                const members = window.currentConversationMembers || [];
                (members || []).forEach(m => {
                    if (m.id == window._currentUserId) return;
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'paste-to-btn';
                    btn.textContent = m.display_name || m.name;
                    btn.dataset.value = m.id;
                    btn.onclick = () => togglePasteTo(m.id, btn);
                    if (toOptions) toOptions.appendChild(btn);
                });
            }
            
            const previewEl = document.getElementById('pastePreview');
            const backdropEl = document.getElementById('pastePreviewBackdrop');
            if (!previewEl || !backdropEl) return;
            const bulkCountEl = document.getElementById('pasteBulkCount');
            if (bulkCountEl && window._pendingFiles && window._pendingFiles.length > 0) {
                var n = 1 + window._pendingFiles.length;
                bulkCountEl.textContent = '全' + n + '件を送信します（送信ボタンで一括送信）';
                bulkCountEl.style.display = 'block';
            } else if (bulkCountEl) {
                bulkCountEl.style.display = 'none';
            }
            previewEl.classList.add('active');
            backdropEl.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function getFileIcon(mimeType, fileName) {
            const ext = fileName.split('.').pop().toLowerCase();
            if (mimeType.startsWith('video/')) return '🎬';
            if (mimeType.startsWith('audio/')) return '🎵';
            if (mimeType === 'application/pdf') return '📄';
            if (['doc', 'docx'].includes(ext)) return '📝';
            if (['xls', 'xlsx'].includes(ext)) return '📊';
            if (['ppt', 'pptx'].includes(ext)) return '📽️';
            if (['zip', 'rar', '7z'].includes(ext)) return '📦';
            if (['txt', 'csv', 'json', 'xml', 'html', 'css', 'js'].includes(ext)) return '📃';
            return '📎';
        }
        
        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        }
        
        // ========== 入力欄の表示/非表示 ==========
        window.toggleInputArea = function() {
            const inputArea = document.getElementById('inputArea');
            const showBtn = document.getElementById('inputShowBtn');
            if (inputArea) {
                inputArea.classList.toggle('hidden');
                const isHidden = inputArea.classList.contains('hidden');
                localStorage.setItem('inputAreaHidden', isHidden ? '1' : '0');
                if (showBtn) showBtn.style.display = isHidden ? 'flex' : 'none';
            }
        };
        
        window.showInputArea = function() {
            const inputArea = document.getElementById('inputArea');
            const showBtn = document.getElementById('inputShowBtn');
            if (inputArea) {
                inputArea.classList.remove('hidden');
                localStorage.setItem('inputAreaHidden', '0');
                if (showBtn) showBtn.style.display = 'none';
                setTimeout(() => {
                    document.getElementById('messageInput')?.focus();
                }, 100);
            }
        };
        
        // 初期状態を復元（携帯版は未設定時は入力欄を収納した状態をデフォルトにする）
        document.addEventListener('DOMContentLoaded', function() {
            const inputArea = document.getElementById('inputArea');
            const showBtn = document.getElementById('inputShowBtn');
            if (window.innerWidth <= 768 && localStorage.getItem('inputAreaHidden') === null) {
                try { localStorage.setItem('inputAreaHidden', '1'); } catch (e) {}
            }
            if (inputArea && localStorage.getItem('inputAreaHidden') === '1') {
                inputArea.classList.add('hidden');
                if (showBtn) showBtn.style.display = 'flex';
            }
        });
        
        // スクロールを下に
        if (conversationId) {
            const area = document.getElementById('messagesArea');
            if (area) area.scrollTop = area.scrollHeight;
        }
        
        
        document.addEventListener('click', (e) => {
            // ユーザードロップダウンを閉じる（上パネル・左パネル・右パネル）
            if (!e.target.closest('.user-menu-container')) {
                document.querySelectorAll('.user-dropdown.show').forEach(function(d) { d.classList.remove('show'); });
            }
            // タスクドロップダウンを閉じる
            if (!e.target.closest('.task-menu-container')) {
                const taskDropdown = document.getElementById('taskDropdown');
                if (taskDropdown) taskDropdown.style.display = 'none';
            }
            // アプリドロップダウンを閉じる
            if (!e.target.closest('.app-menu-container')) {
                const appDropdown = document.getElementById('appDropdown');
                if (appDropdown) appDropdown.style.display = 'none';
            }
            // 通知ドロップダウンを閉じる
            if (!e.target.closest('.notification-menu-container')) {
                const notifDropdown = document.getElementById('notificationDropdown');
                if (notifDropdown) notifDropdown.style.display = 'none';
            }
            // 言語ドロップダウンを閉じる
            if (!e.target.closest('.language-selector')) {
                const langDropdown = document.getElementById('languageDropdown');
                if (langDropdown) langDropdown.classList.remove('show');
            }
        });
        
        // 上パネル：収納ボタンでメニュー表示/非表示をトグル
        function toggleTaskMemoButtons() {
            const buttons = document.getElementById('taskMemoButtons');
            const toggleBtn = document.getElementById('toggleTaskMemoBtn');
            if (!buttons || !toggleBtn) return;
            buttons.classList.toggle('hidden');
            const isHidden = buttons.classList.contains('hidden');
            toggleBtn.title = isHidden
                ? '<?= $currentLang === 'en' ? 'Show menu' : ($currentLang === 'zh' ? '显示菜单' : 'メニューを表示') ?>'
                : '<?= $currentLang === 'en' ? 'Hide menu' : ($currentLang === 'zh' ? '收起菜单' : 'メニューを収納') ?>';
            try { localStorage.setItem('taskMemoHidden', isHidden); } catch (e) {}
        }
        window.toggleTaskMemoButtons = toggleTaskMemoButtons;
        
        document.addEventListener('DOMContentLoaded', function() {
            const btn = document.getElementById('taskMemoButtons');
            const toggleBtn = document.getElementById('toggleTaskMemoBtn');
            if (btn && toggleBtn) {
                // 初期値は収納（ボタン群を非表示）。≡ クリックで展開できる
                btn.classList.add('hidden');
                toggleBtn.title = '<?= $currentLang === 'en' ? 'Show menu' : ($currentLang === 'zh' ? '显示菜单' : 'メニューを表示') ?>';
            }
        });
        
        // 日程調整・アンケート機能は将来実装予定（現在無効化）
        // function openScheduleAdjust() { ... }
        // function openSurvey() { ... }
        
        // モーダル
        function openModal(id) {
            // モバイルでは右パネルと左パネルを閉じる
            if (window.innerWidth <= 768) {
                const rightPanel = document.querySelector('.right-panel');
                const leftPanel = document.querySelector('.left-panel');
                if (rightPanel) rightPanel.classList.remove('mobile-open');
                if (leftPanel) leftPanel.classList.remove('mobile-open');
            }
            document.getElementById(id).classList.add('active');
        }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }
        function openNewConversation() { openModal('newConversationModal'); }
        function openSearch() { /* 後方で上書き: トップバー値をモーダルに同期 */ }
        
        // ========== グループ作成・編集・削除 ==========
        let contextTargetConvId = null;
        let contextTargetConvEl = null;
        
        // グループ作成モーダルを開く（グループタブをデフォルトで選択）
        function openCreateGroupModal() {
            openModal('newConversationModal');
            switchConversationType('group');
        }
        
        // グループ右クリックメニューを表示
        function showConvContextMenu(e, el) {
            e.preventDefault();
            e.stopPropagation();
            
            contextTargetConvId = el.dataset.convId;
            contextTargetConvEl = el;
            
            const menu = document.getElementById('convContextMenu');
            if (!menu) {
                // コンテキストメニューが削除されているため、代わりにそのグループを選択
                switchToConversation(contextTargetConvId);
                return;
            }
            
            // モバイル対応: タッチイベントの場合は画面中央に表示
            if (e.type === 'touchend' || e.type === 'touchstart') {
                const rect = el.getBoundingClientRect();
                menu.style.left = (rect.left + rect.width / 2) + 'px';
                menu.style.top = (rect.bottom + 10) + 'px';
            } else {
                menu.style.left = e.clientX + 'px';
                menu.style.top = e.clientY + 'px';
            }
            menu.classList.add('show');
            
            // メニュー外クリックで閉じる
            setTimeout(() => {
                document.addEventListener('click', hideConvContextMenu, { once: true });
                document.addEventListener('touchstart', hideConvContextMenu, { once: true });
            }, 10);
        }
        
        function hideConvContextMenu() {
            const menu = document.getElementById('convContextMenu');
            if (menu) {
                menu.classList.remove('show');
            }
        }
        
        // ============================================
        // ピン留め機能
        // ============================================
        async function toggleConvPin(convId) {
            var el = document.querySelector('.conv-item[data-conv-id="' + convId + '"]');
            if (!el) return;
            var isPinned = el.dataset.isPinned === '1';
            var newPinned = !isPinned;
            
            try {
                var res = await fetch('api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'pin', conversation_id: parseInt(convId, 10), is_pinned: newPinned })
                });
                var data = await res.json();
                if (data.success) {
                    el.dataset.isPinned = newPinned ? '1' : '0';
                    if (newPinned) {
                        el.classList.add('is-pinned');
                    } else {
                        el.classList.remove('is-pinned');
                    }
                    reorderConvList();
                }
            } catch (err) {
                console.error('[Pin] Error:', err);
            }
        }
        window.toggleConvPin = toggleConvPin;

        function reorderConvList() {
            var list = document.getElementById('conversationList');
            if (!list) return;
            var items = Array.from(list.querySelectorAll('.conv-item'));
            items.sort(function(a, b) {
                var pinA = a.dataset.isPinned === '1' ? 1 : 0;
                var pinB = b.dataset.isPinned === '1' ? 1 : 0;
                if (pinA !== pinB) return pinB - pinA;
                return 0;
            });
            items.forEach(function(item) { list.appendChild(item); });
        }

        // ============================================
        // 長押しでピン留めトグル（モバイル）
        // ============================================
        let touchTimer = null;
        let touchTarget = null;
        let touchLongPressed = false;
        
        function setupLongPressPin() {
            document.querySelectorAll('.conv-item').forEach(item => {
                item.addEventListener('touchstart', handlePinTouchStart, { passive: false });
                item.addEventListener('touchend', handlePinTouchEnd);
                item.addEventListener('touchmove', handlePinTouchMove);
            });
        }
        
        function handlePinTouchStart(e) {
            touchTarget = e.currentTarget;
            touchLongPressed = false;
            touchTimer = setTimeout(() => {
                touchLongPressed = true;
                var convId = touchTarget.dataset.convId;
                if (convId) {
                    toggleConvPin(convId);
                }
                touchTarget = null;
            }, 600);
        }
        
        function handlePinTouchEnd(e) {
            if (touchTimer) {
                clearTimeout(touchTimer);
                touchTimer = null;
            }
            if (touchLongPressed) {
                e.preventDefault();
                touchLongPressed = false;
            }
        }
        
        function handlePinTouchMove(e) {
            if (touchTimer) {
                clearTimeout(touchTimer);
                touchTimer = null;
            }
        }
        
        document.addEventListener('DOMContentLoaded', setupLongPressPin);
        
        // グループ名変更モーダルを開く
        async function renameGroup() {
            hideConvContextMenu();
            
            if (!contextTargetConvId) return;
            
            // 入力フィールドを一旦クリア
            document.getElementById('renameGroupName').value = '';
            document.getElementById('renameGroupNameEn').value = '';
            document.getElementById('renameGroupNameZh').value = '';
            
            // モーダルを先に開く
            openModal('renameGroupModal');
            
            // APIから最新のグループ情報を取得
            try {
                const response = await fetch('/api/conversations.php?action=get&conversation_id=' + contextTargetConvId);
                const data = await response.json();
                console.log('Group data:', data);
                
                if (data.success && data.conversation) {
                    console.log('name:', data.conversation.name);
                    console.log('name_en:', data.conversation.name_en);
                    console.log('name_zh:', data.conversation.name_zh);
                    document.getElementById('renameGroupName').value = data.conversation.name || '';
                    document.getElementById('renameGroupNameEn').value = data.conversation.name_en || '';
                    document.getElementById('renameGroupNameZh').value = data.conversation.name_zh || '';
                } else {
                    console.log('API failed, using fallback', data);
                    // フォールバック：data属性から取得
                    document.getElementById('renameGroupName').value = contextTargetConvEl?.dataset.convName || '';
                    document.getElementById('renameGroupNameEn').value = contextTargetConvEl?.dataset.convNameEn || '';
                    document.getElementById('renameGroupNameZh').value = contextTargetConvEl?.dataset.convNameZh || '';
                }
            } catch (e) {
                console.error('Error fetching group data:', e);
                // フォールバック：data属性から取得
                document.getElementById('renameGroupName').value = contextTargetConvEl?.dataset.convName || '';
                document.getElementById('renameGroupNameEn').value = contextTargetConvEl?.dataset.convNameEn || '';
                document.getElementById('renameGroupNameZh').value = contextTargetConvEl?.dataset.convNameZh || '';
            }
        }
        
        // グループ名を保存
        async function saveGroupRename() {
            const name = document.getElementById('renameGroupName').value.trim();
            const nameEn = document.getElementById('renameGroupNameEn').value.trim();
            const nameZh = document.getElementById('renameGroupNameZh').value.trim();
            
            if (!name) {
                alert('<?= $currentLang === 'en' ? 'Please enter a group name' : ($currentLang === 'zh' ? '请输入群组名称' : 'グループ名を入力してください') ?>');
                return;
            }
            
            try {
                console.log('Saving group:', { name, nameEn, nameZh });
                const response = await fetch('/api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update',
                        conversation_id: contextTargetConvId,
                        name: name,
                        name_en: nameEn || null,
                        name_zh: nameZh || null
                    })
                });
                const data = await response.json();
                console.log('Save response:', data);
                if (data.success) {
                    // data属性も更新
                    if (contextTargetConvEl) {
                        contextTargetConvEl.dataset.convName = name;
                        contextTargetConvEl.dataset.convNameEn = nameEn || '';
                        contextTargetConvEl.dataset.convNameZh = nameZh || '';
                    }
                    closeModal('renameGroupModal');
                    // そのままリロード
                    location.reload();
                } else {
                    alert(data.message || '<?= $currentLang === 'en' ? 'Failed to update' : ($currentLang === 'zh' ? '更新失败' : '更新に失敗しました') ?>');
                }
            } catch (e) {
                console.error('Save error:', e);
                alert('<?= $currentLang === 'en' ? 'Error occurred' : ($currentLang === 'zh' ? '发生错误' : 'エラーが発生しました') ?>');
            }
        }
        
        // ========== グループ管理機能 ==========
        
        let currentGroupMembers = [];
        let selectedMemberId = null;
        let selectedMemberRole = null;
        let selectedMemberSilenced = false;
        
        // 右パネルでグループ設定を表示/非表示（メンバーセクションは中央パネルに移動済み）
        function updateGroupSettingsVisibility() {
            const settingsSection = document.getElementById('groupSettingsSection');
            
            if (!currentConversationId) {
                if (settingsSection) settingsSection.style.display = 'none';
                return;
            }
            
            // グループかDMかを確認
            const convItem = document.querySelector(`.conv-item[data-conv-id="${currentConversationId}"]`);
            const convType = convItem?.dataset.convType || 'group';
            const myRole = convItem?.dataset.myRole || 'member';
            const isAdmin = myRole === 'admin';
            
            // DMタイプ、または2人だけのグループはDMとして扱う
            let isDM = convType === 'dm';
            let isGroup = convType === 'group';
            
            // 2人グループの場合もDMとして扱う（メンバー数で判断）
            if (isGroup && window.currentConversationMembers && window.currentConversationMembers.length === 2) {
                isDM = true;
                isGroup = false;
            }
            
            // DMの場合は常に表示、グループの場合は管理者のみ表示
            if (isDM || (isGroup && isAdmin)) {
                if (settingsSection) settingsSection.style.display = 'block';
                
                // DMの場合、一部のボタンを非表示/調整
                const dmOnlyHideItems = settingsSection?.querySelectorAll('.dm-hide');
                dmOnlyHideItems?.forEach(item => {
                    item.style.display = isDM ? 'none' : '';
                });
                
                // タイトルとボタンテキストを調整
                const titleEl = document.getElementById('settingsSectionTitle');
                const renameEl = document.getElementById('renameButtonText');
                const deleteEl = document.getElementById('deleteButtonText');
                
                if (isDM) {
                    if (titleEl) titleEl.innerHTML = '⚙️ <?= $currentLang === 'en' ? 'Chat Settings' : ($currentLang === 'zh' ? '聊天设置' : 'チャット設定') ?>';
                    if (renameEl) renameEl.textContent = '<?= $currentLang === 'en' ? 'Rename Chat' : ($currentLang === 'zh' ? '重命名聊天' : 'チャット名変更') ?>';
                    if (deleteEl) deleteEl.textContent = '<?= $currentLang === 'en' ? 'Delete Chat' : ($currentLang === 'zh' ? '删除聊天' : 'チャット削除') ?>';
                } else {
                    if (titleEl) titleEl.innerHTML = '⚙️ <?= $currentLang === 'en' ? 'Group Settings' : ($currentLang === 'zh' ? '群组设置' : 'グループ設定') ?>';
                    if (renameEl) renameEl.textContent = '<?= $currentLang === 'en' ? 'Rename Group' : ($currentLang === 'zh' ? '重命名群组' : 'グループ名変更') ?>';
                    if (deleteEl) deleteEl.textContent = '<?= $currentLang === 'en' ? 'Delete Group' : ($currentLang === 'zh' ? '删除群组' : 'グループ削除') ?>';
                }
            } else {
                if (settingsSection) settingsSection.style.display = 'none';
            }
        }
        
        // メンバー一覧を読み込む
        async function loadGroupMembers() {
            if (!currentConversationId) return;
            
            try {
                const response = await fetch('/api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'get_members',
                        conversation_id: currentConversationId
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    currentGroupMembers = data.members;
                    renderMembersList(data.members, data.my_role === 'admin');
                }
            } catch (e) {
                console.error('Failed to load members:', e);
            }
        }
        
        // メンバー一覧を描画
        function renderMembersList(members, isAdmin) {
            const list = document.getElementById('membersList');
            if (!list) return;
            
            list.innerHTML = members.map(member => {
                const initial = (member.display_name || '?')[0].toUpperCase();
                const roleLabel = member.role === 'admin' ? '<?= $currentLang === 'en' ? 'Admin' : ($currentLang === 'zh' ? '管理员' : '管理者') ?>' : '';
                const silencedClass = member.is_silenced ? 'silenced' : '';
                
                return `
                    <div class="member-item" data-user-id="${member.id}" data-role="${member.role}" data-silenced="${member.is_silenced}">
                        <div class="member-avatar" style="${member.avatar_path ? 'background-image: url(' + member.avatar_path + '); background-size: cover;' : ''}">${member.avatar_path ? '' : initial}</div>
                        <div class="member-info">
                            <div class="member-name">${escapeHtml(member.display_name)}${member.is_silenced ? ' 🔇' : ''}</div>
                            ${roleLabel ? `<div class="member-role admin">${roleLabel}</div>` : ''}
                        </div>
                        ${isAdmin && member.id != <?= $user_id ?> ? `
                            <div class="member-actions">
                                <button class="member-action-btn" onclick="showMemberMenu(event, ${member.id}, '${member.role}', ${member.is_silenced})" title="<?= $currentLang === 'en' ? 'Options' : ($currentLang === 'zh' ? '选项' : 'オプション') ?>">⋮</button>
                            </div>
                        ` : ''}
                    </div>
                `;
            }).join('');
        }
        
        // メンバーメニューを表示
        function showMemberMenu(event, userId, role, isSilenced) {
            event.stopPropagation();
            
            selectedMemberId = userId;
            selectedMemberRole = role;
            selectedMemberSilenced = isSilenced;
            
            const menu = document.getElementById('memberContextMenu');
            const toggleAdminText = document.getElementById('menuToggleAdminText');
            const toggleSilenceText = document.getElementById('menuToggleSilenceText');
            const toggleAdminItem = document.getElementById('menuToggleAdmin');
            
            // 管理者の場合は「管理者を解除」、そうでなければ「管理者に任命」
            if (role === 'admin') {
                toggleAdminText.textContent = '<?= $currentLang === 'en' ? 'Remove Admin' : ($currentLang === 'zh' ? '取消管理员' : '管理者を解除') ?>';
                toggleSilenceText.parentElement.style.display = 'none'; // 管理者は発言制限できない
            } else {
                toggleAdminText.textContent = '<?= $currentLang === 'en' ? 'Make Admin' : ($currentLang === 'zh' ? '设为管理员' : '管理者に任命') ?>';
                toggleSilenceText.parentElement.style.display = 'flex';
            }
            
            // 発言制限の表示
            toggleSilenceText.textContent = isSilenced 
                ? '<?= $currentLang === 'en' ? 'Unmute' : ($currentLang === 'zh' ? '解除禁言' : '発言制限解除') ?>'
                : '<?= $currentLang === 'en' ? 'Mute' : ($currentLang === 'zh' ? '禁言' : '発言制限') ?>';
            
            // メニュー位置
            menu.style.display = 'block';
            menu.style.left = event.clientX + 'px';
            menu.style.top = event.clientY + 'px';
            
            // 画面外にはみ出さないように調整
            const rect = menu.getBoundingClientRect();
            if (rect.right > window.innerWidth) {
                menu.style.left = (window.innerWidth - rect.width - 10) + 'px';
            }
            if (rect.bottom > window.innerHeight) {
                menu.style.top = (window.innerHeight - rect.height - 10) + 'px';
            }
            
            // クリックで閉じる
            setTimeout(() => {
                document.addEventListener('click', hideMemberMenu);
            }, 100);
        }
        
        function hideMemberMenu() {
            document.getElementById('memberContextMenu').style.display = 'none';
            document.removeEventListener('click', hideMemberMenu);
        }
        
        // 管理者権限を切り替え
        async function toggleMemberAdmin() {
            hideMemberMenu();
            
            if (!selectedMemberId) return;
            
            const newRole = selectedMemberRole === 'admin' ? 'member' : 'admin';
            const confirmMsg = newRole === 'admin' 
                ? '<?= $currentLang === 'en' ? 'Make this user an admin?' : ($currentLang === 'zh' ? '确定要将此用户设为管理员吗？' : 'このユーザーを管理者にしますか？') ?>'
                : '<?= $currentLang === 'en' ? 'Remove admin rights from this user?' : ($currentLang === 'zh' ? '确定要取消此用户的管理员权限吗？' : 'このユーザーの管理者権限を解除しますか？') ?>';
            
            if (!confirm(confirmMsg)) return;
            
            try {
                const response = await fetch('/api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'change_role',
                        conversation_id: currentConversationId,
                        user_id: selectedMemberId,
                        role: newRole
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    loadGroupMembers();
                } else {
                    alert(data.message || '<?= $currentLang === 'en' ? 'Failed to change role' : ($currentLang === 'zh' ? '权限更改失败' : '権限の変更に失敗しました') ?>');
                }
            } catch (e) {
                console.error('Error:', e);
                alert('<?= $currentLang === 'en' ? 'Error occurred' : ($currentLang === 'zh' ? '发生错误' : 'エラーが発生しました') ?>');
            }
        }
        
        // 発言制限を切り替え
        async function toggleMemberSilence() {
            hideMemberMenu();
            
            if (!selectedMemberId) return;
            
            const newSilenced = !selectedMemberSilenced;
            
            try {
                const response = await fetch('/api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'silence_member',
                        conversation_id: currentConversationId,
                        user_id: selectedMemberId,
                        is_silenced: newSilenced
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    loadGroupMembers();
                } else {
                    alert(data.message || '<?= $currentLang === 'en' ? 'Failed to update' : ($currentLang === 'zh' ? '更新失败' : '更新に失敗しました') ?>');
                }
            } catch (e) {
                console.error('Error:', e);
                alert('<?= $currentLang === 'en' ? 'Error occurred' : ($currentLang === 'zh' ? '发生错误' : 'エラーが発生しました') ?>');
            }
        }
        
        // メンバーを削除
        async function removeMemberFromGroup() {
            hideMemberMenu();
            
            if (!selectedMemberId) return;
            
            if (!confirm('<?= $currentLang === 'en' ? 'Remove this member from the group?' : ($currentLang === 'zh' ? '确定要将此成员从群组中移除吗？' : 'このメンバーをグループから削除しますか？') ?>')) {
                return;
            }
            
            try {
                const response = await fetch('/api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'remove_member',
                        conversation_id: currentConversationId,
                        user_id: selectedMemberId
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    loadGroupMembers();
                } else {
                    alert(data.message || '<?= $currentLang === 'en' ? 'Failed to remove' : ($currentLang === 'zh' ? '移除失败' : '削除に失敗しました') ?>');
                }
            } catch (e) {
                console.error('Error:', e);
                alert('<?= $currentLang === 'en' ? 'Error occurred' : ($currentLang === 'zh' ? '发生错误' : 'エラーが発生しました') ?>');
            }
        }
        
        // グループ名変更モーダルを開く（右パネルから）
        function openRenameGroupModal() {
            contextTargetConvId = currentConversationId;
            renameGroup();
        }
        
        // サンプルアイコンリスト（20種類）
        const sampleIcons = [
            // 動物（5種）
            { name: 'penguin', emoji: '🐧' },
            { name: 'butterfly', emoji: '🦋' },
            { name: 'dolphin', emoji: '🐬' },
            { name: 'unicorn', emoji: '🦄' },
            { name: 'bee', emoji: '🐝' },
            // 自然（3種）
            { name: 'cactus', emoji: '🌵' },
            { name: 'leaf', emoji: '🍂' },
            { name: 'snow', emoji: '❄️' },
            // 食べ物（3種）
            { name: 'pizza', emoji: '🍕' },
            { name: 'donut', emoji: '🍩' },
            { name: 'icecream', emoji: '🍦' },
            // 乗り物（3種）
            { name: 'rocket', emoji: '🚀' },
            { name: 'airplane', emoji: '✈️' },
            { name: 'car', emoji: '🚗' },
            // その他（6種）
            { name: 'house', emoji: '🏠' },
            { name: 'gift', emoji: '🎁' },
            { name: 'bell', emoji: '🔔' },
            { name: 'target', emoji: '🎯' },
            { name: 'trophy', emoji: '🏆' },
            { name: 'crown', emoji: '👑' },
            // 仕事・ビジネス（15種）
            { name: 'briefcase', emoji: '💼' },
            { name: 'chart', emoji: '📊' },
            { name: 'growth', emoji: '📈' },
            { name: 'laptop', emoji: '💻' },
            { name: 'folder', emoji: '📁' },
            { name: 'clipboard', emoji: '📋' },
            { name: 'pencil', emoji: '✏️' },
            { name: 'memo', emoji: '📝' },
            { name: 'pin', emoji: '📌' },
            { name: 'gear', emoji: '⚙️' },
            { name: 'key', emoji: '🔑' },
            { name: 'email', emoji: '📧' },
            { name: 'phone', emoji: '📞' },
            { name: 'building', emoji: '🏢' },
            { name: 'idea', emoji: '💡' },
            // 特別リクエスト（3種）
            { name: 'shogi', emoji: '♟️' },
            { name: 'earth', emoji: '🌍' },
            { name: 'person', emoji: '👤' },
            // 追加（3種）
            { name: 'calendar', emoji: '📅' },
            { name: 'clock', emoji: '⏰' },
            { name: 'lock', emoji: '🔒' }
        ];
        
        // アイコンスタイル（背景色・枠線）リスト
        const iconStyles = [
            { id: 'default', name: 'デフォルト', bg: 'linear-gradient(135deg, #667eea, #764ba2)', border: 'none' },
            { id: 'white', name: '白', bg: '#FFFFFF', border: '2px solid #e0e0e0' },
            { id: 'black', name: '黒', bg: '#1a1a1a', border: 'none' },
            { id: 'gray', name: 'グレー', bg: '#6b7280', border: 'none' },
            { id: 'red', name: '赤', bg: 'linear-gradient(135deg, #FF6B6B, #ee5a5a)', border: 'none' },
            { id: 'orange', name: 'オレンジ', bg: 'linear-gradient(135deg, #FFA500, #FF8C00)', border: 'none' },
            { id: 'yellow', name: '黄色', bg: 'linear-gradient(135deg, #FFD700, #FFC107)', border: 'none' },
            { id: 'green', name: '緑', bg: 'linear-gradient(135deg, #4CAF50, #43A047)', border: 'none' },
            { id: 'blue', name: '青', bg: 'linear-gradient(135deg, #2196F3, #1976D2)', border: 'none' },
            { id: 'purple', name: '紫', bg: 'linear-gradient(135deg, #9C27B0, #7B1FA2)', border: 'none' },
            { id: 'pink', name: 'ピンク', bg: 'linear-gradient(135deg, #FF69B4, #FF1493)', border: 'none' }
        ];
        
        // 現在のアイコンサイズと位置
        let currentIconSize = 100;
        let currentIconPosX = 0;
        let currentIconPosY = 0;
        const ICON_MOVE_STEP = 7.5; // 移動幅
        
        // ========================================
        // アイコン変更機能（グループ・ユーザー共通）
        // ========================================
        
        let iconChangeSize = 100;
        let iconChangePosX = 0;
        let iconChangePosY = 0;
        /** モーダルを開いた時点のグループアイコン画像パス（スタイルのみ保存時に送る用） */
        let iconChangeCurrentIconPath = '';
        
        /**
         * アイコン変更モーダルを開く
         * @param {string} type - "group" または "user"
         * @param {number|null} targetId - グループの場合はconversation_id、ユーザーの場合はnull
         * @param {string} defaultIcon - デフォルトアイコン（絵文字または文字）
         */
        function openIconChangeModal(type, targetId, defaultIcon) {
            document.getElementById('iconChangeType').value = type;
            document.getElementById('iconChangeTargetId').value = targetId || '';
            document.getElementById('iconChangeFileInput').value = '';
            document.getElementById('iconChangeSelectedSample').value = '';
            document.getElementById('iconChangeSelectedStyle').value = 'default';
            document.getElementById('iconChangeSelectedPosX').value = '0';
            document.getElementById('iconChangeSelectedPosY').value = '0';
            document.getElementById('iconChangeSelectedSize').value = '100';
            
            iconChangeSize = 100;
            iconChangePosX = 0;
            iconChangePosY = 0;
            iconChangeCurrentIconPath = '';
            updateIconChangePosDisplay();
            
            const sizeValueEl = document.getElementById('iconChangeSizeValue');
            if (sizeValueEl) sizeValueEl.textContent = '100';
            
            // プレビューをリセット
            const preview = document.getElementById('iconChangePreview');
            if (preview) {
                preview.style.background = 'linear-gradient(135deg, #667eea, #764ba2)';
                preview.style.border = 'none';
                preview.innerHTML = defaultIcon || '📁';
            }
            
            // グループの場合: 現在の会話のアイコン情報をDOMから読み取り初期化
            if (type === 'group' && targetId) {
                const convItem = document.querySelector('.conv-item[data-conv-id="' + targetId + '"]');
                if (convItem && convItem.dataset) {
                    const d = convItem.dataset;
                    const path = (d.convIconPath || '').trim();
                    iconChangeCurrentIconPath = path;
                    const style = d.convIconStyle || 'default';
                    const posX = parseFloat(d.convIconPosX) || 0;
                    const posY = parseFloat(d.convIconPosY) || 0;
                    const size = parseInt(d.convIconSize, 10);
                    const sizeVal = (size >= 50 && size <= 150) ? size : 100;
                    
                    document.getElementById('iconChangeSelectedStyle').value = style;
                    document.getElementById('iconChangeSelectedPosX').value = String(posX);
                    document.getElementById('iconChangeSelectedPosY').value = String(posY);
                    document.getElementById('iconChangeSelectedSize').value = String(sizeVal);
                    iconChangePosX = posX;
                    iconChangePosY = posY;
                    iconChangeSize = sizeVal;
                    updateIconChangePosDisplay();
                    if (sizeValueEl) sizeValueEl.textContent = String(sizeVal);
                    
                    const styleObj = iconStyles.find(s => s.id === style);
                    if (styleObj && preview) {
                        preview.style.background = styleObj.bg;
                        preview.style.border = styleObj.border || 'none';
                    }
                    if (path) {
                        const img = document.createElement('img');
                        img.src = path;
                        img.alt = '';
                        img.style.cssText = 'width:' + sizeVal + '%;height:' + sizeVal + '%;position:absolute;top:50%;left:50%;transform:translate(calc(-50% + ' + posX + '%), calc(-50% + ' + posY + '%));object-fit:contain;';
                        preview.innerHTML = '';
                        preview.appendChild(img);
                    } else {
                        preview.innerHTML = defaultIcon || '📁';
                    }
                }
            }
            
            // タイトルを設定
            const titleEl = document.getElementById('iconChangeModalTitle');
            if (titleEl) {
                if (type === 'group') {
                    titleEl.textContent = '<?= $currentLang === "en" ? "Change Group Icon" : ($currentLang === "zh" ? "更换群组图标" : "グループアイコン変更") ?>';
                } else {
                    titleEl.textContent = '<?= $currentLang === "en" ? "Change Icon" : ($currentLang === "zh" ? "更换图标" : "アイコン変更") ?>';
                }
            }
            
            loadIconChangeSamples();
            loadIconChangeStyles();
            // グループで読み込んだスタイルをグリッドの選択に反映
            const currentStyle = document.getElementById('iconChangeSelectedStyle').value;
            if (currentStyle && currentStyle !== 'default') {
                selectIconChangeStyle(currentStyle);
            }
            initIconChangeAdjustEvents();
            openModal('iconChangeModal');
        }
        
        // グループアイコン変更用のラッパー関数（互換性のため）
        function openGroupIconModal() {
            openIconChangeModal('group', currentConversationId, '📁');
        }
        
        // ユーザーアイコン変更用のラッパー関数
        function openUserAvatarModal() {
            openIconChangeModal('user', null, '<?= mb_substr($display_name ?? "U", 0, 1) ?>');
        }
        
        function closeIconChangeModal() {
            closeModal('iconChangeModal');
        }
        
        function updateIconChangePosDisplay() {
            const posXEl = document.getElementById('iconChangePosX');
            const posYEl = document.getElementById('iconChangePosY');
            if (posXEl) posXEl.textContent = iconChangePosX.toFixed(1);
            if (posYEl) posYEl.textContent = iconChangePosY.toFixed(1);
        }
        
        function initIconChangeAdjustEvents() {
            const posUpBtn = document.getElementById('iconChangePosUp');
            const posDownBtn = document.getElementById('iconChangePosDown');
            const posLeftBtn = document.getElementById('iconChangePosLeft');
            const posRightBtn = document.getElementById('iconChangePosRight');
            const posResetBtn = document.getElementById('iconChangePosReset');
            
            if (posUpBtn) posUpBtn.onclick = function() { moveIconChangePos(0, -ICON_MOVE_STEP); };
            if (posDownBtn) posDownBtn.onclick = function() { moveIconChangePos(0, ICON_MOVE_STEP); };
            if (posLeftBtn) posLeftBtn.onclick = function() { moveIconChangePos(-ICON_MOVE_STEP, 0); };
            if (posRightBtn) posRightBtn.onclick = function() { moveIconChangePos(ICON_MOVE_STEP, 0); };
            if (posResetBtn) posResetBtn.onclick = function() { resetIconChangePos(); };
            
            const sizeUpBtn = document.getElementById('iconChangeSizeUp');
            const sizeDownBtn = document.getElementById('iconChangeSizeDown');
            if (sizeUpBtn) sizeUpBtn.onclick = function() { adjustIconChangeSize(10); };
            if (sizeDownBtn) sizeDownBtn.onclick = function() { adjustIconChangeSize(-10); };
        }
        
        function moveIconChangePos(dx, dy) {
            iconChangePosX = Math.max(-50, Math.min(50, iconChangePosX + dx));
            iconChangePosY = Math.max(-50, Math.min(50, iconChangePosY + dy));
            document.getElementById('iconChangeSelectedPosX').value = iconChangePosX;
            document.getElementById('iconChangeSelectedPosY').value = iconChangePosY;
            updateIconChangePosDisplay();
            updateIconChangePreview();
        }
        
        function resetIconChangePos() {
            iconChangePosX = 0;
            iconChangePosY = 0;
            document.getElementById('iconChangeSelectedPosX').value = '0';
            document.getElementById('iconChangeSelectedPosY').value = '0';
            updateIconChangePosDisplay();
            updateIconChangePreview();
        }
        
        function adjustIconChangeSize(delta) {
            iconChangeSize = Math.max(50, Math.min(150, iconChangeSize + delta));
            document.getElementById('iconChangeSizeValue').textContent = iconChangeSize;
            document.getElementById('iconChangeSelectedSize').value = iconChangeSize;
            updateIconChangePreview();
        }
        
        // サンプルアイコンのページネーション
        let iconSamplePage = 0;
        const ICONS_PER_PAGE = 5; // 1行に表示するアイコン数
        
        function loadIconChangeSamples() {
            iconSamplePage = 0;
            renderIconSamplesPage();
            updateIconSampleNav();
        }
        
        function renderIconSamplesPage() {
            const grid = document.getElementById('iconChangeSamplesGrid');
            if (!grid) return;
            
            const startIdx = iconSamplePage * ICONS_PER_PAGE;
            const endIdx = Math.min(startIdx + ICONS_PER_PAGE, sampleIcons.length);
            const pageIcons = sampleIcons.slice(startIdx, endIdx);
            
            grid.innerHTML = pageIcons.map(icon => `
                <div class="sample-icon-item" data-icon="${icon.name}" onclick="selectIconChangeSample('${icon.name}')">
                    <img src="/assets/icons/samples/${icon.name}.svg" alt="${icon.emoji || icon.name}" title="${icon.emoji || icon.name}">
                </div>
            `).join('');
            
            // 選択状態を復元
            const selectedSample = document.getElementById('iconChangeSelectedSample').value;
            if (selectedSample) {
                const selected = grid.querySelector(`.sample-icon-item[data-icon="${selectedSample}"]`);
                if (selected) selected.classList.add('selected');
            }
        }
        
        function updateIconSampleNav() {
            const totalPages = Math.ceil(sampleIcons.length / ICONS_PER_PAGE);
            const pageEl = document.getElementById('iconSamplePage');
            const totalPagesEl = document.getElementById('iconSampleTotalPages');
            const prevBtn = document.getElementById('iconSamplePrev');
            const nextBtn = document.getElementById('iconSampleNext');
            
            if (pageEl) pageEl.textContent = iconSamplePage + 1;
            if (totalPagesEl) totalPagesEl.textContent = totalPages;
            if (prevBtn) prevBtn.disabled = iconSamplePage === 0;
            if (nextBtn) nextBtn.disabled = iconSamplePage >= totalPages - 1;
        }
        
        function navigateIconSamples(direction) {
            const totalPages = Math.ceil(sampleIcons.length / ICONS_PER_PAGE);
            iconSamplePage = Math.max(0, Math.min(totalPages - 1, iconSamplePage + direction));
            renderIconSamplesPage();
            updateIconSampleNav();
        }
        
        function loadIconChangeStyles() {
            const grid = document.getElementById('iconChangeStyleGrid');
            if (!grid) return;
            
            grid.innerHTML = iconStyles.map(style => `
                <div class="icon-style-item ${style.id === 'default' ? 'selected' : ''}" 
                     data-style="${style.id}" 
                     style="background: ${style.bg}; border: ${style.border};"
                     onclick="selectIconChangeStyle('${style.id}')"
                     title="${style.name}">
                </div>
            `).join('');
        }
        
        function selectIconChangeStyle(styleId) {
            document.querySelectorAll('#iconChangeStyleGrid .icon-style-item').forEach(item => {
                item.classList.remove('selected');
            });
            const selected = document.querySelector(`#iconChangeStyleGrid .icon-style-item[data-style="${styleId}"]`);
            if (selected) selected.classList.add('selected');
            
            document.getElementById('iconChangeSelectedStyle').value = styleId;
            
            const style = iconStyles.find(s => s.id === styleId);
            if (style) {
                const preview = document.getElementById('iconChangePreview');
                if (preview) {
                    preview.style.background = style.bg;
                    preview.style.border = style.border;
                }
            }
        }
        
        function selectIconChangeSample(iconName) {
            document.querySelectorAll('#iconChangeSamplesGrid .sample-icon-item').forEach(item => {
                item.classList.remove('selected');
            });
            const selected = document.querySelector(`#iconChangeSamplesGrid .sample-icon-item[data-icon="${iconName}"]`);
            if (selected) selected.classList.add('selected');
            
            document.getElementById('iconChangeSelectedSample').value = iconName;
            document.getElementById('iconChangeFileInput').value = '';
            updateIconChangePreview();
        }
        
        function updateIconChangePreview() {
            const selectedIcon = document.getElementById('iconChangeSelectedSample').value;
            const selectedStyle = document.getElementById('iconChangeSelectedStyle').value || 'default';
            const preview = document.getElementById('iconChangePreview');
            if (!preview) return;
            
            const style = iconStyles.find(s => s.id === selectedStyle);
            if (style) {
                preview.style.background = style.bg;
                preview.style.border = style.border;
            }
            
            if (selectedIcon) {
                const size = iconChangeSize;
                const posX = iconChangePosX;
                const posY = iconChangePosY;
                preview.innerHTML = `<img src="/assets/icons/samples/${selectedIcon}.svg" alt="選択中のアイコン"
                    style="width:${size}%;height:${size}%;position:absolute;top:50%;left:50%;
                    transform:translate(calc(-50% + ${posX}%), calc(-50% + ${posY}%));object-fit:contain;">`;
            }
        }
        
        function previewIconChange(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('iconChangePreview');
                    if (preview) {
                        preview.innerHTML = `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:cover;">`;
                    }
                    // サンプル選択をクリア
                    document.getElementById('iconChangeSelectedSample').value = '';
                    document.querySelectorAll('#iconChangeSamplesGrid .sample-icon-item').forEach(item => {
                        item.classList.remove('selected');
                    });
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        async function saveIconChange() {
            const type = document.getElementById('iconChangeType').value;
            const targetId = document.getElementById('iconChangeTargetId').value;
            const input = document.getElementById('iconChangeFileInput');
            const selectedSample = document.getElementById('iconChangeSelectedSample').value;
            const selectedStyle = document.getElementById('iconChangeSelectedStyle').value || 'default';
            const selectedPosX = document.getElementById('iconChangeSelectedPosX').value || '0';
            const selectedPosY = document.getElementById('iconChangeSelectedPosY').value || '0';
            const selectedSize = document.getElementById('iconChangeSelectedSize').value || '100';
            
            // API エンドポイントとパラメータを決定
            let apiUrl, requestBody;
            
            if (type === 'group') {
                apiUrl = '/api/conversations.php';
                requestBody = {
                    action: 'update_icon',
                    conversation_id: parseInt(targetId),
                    icon_style: selectedStyle,
                    icon_pos_x: parseFloat(selectedPosX),
                    icon_pos_y: parseFloat(selectedPosY),
                    icon_size: parseInt(selectedSize)
                };
            } else {
                apiUrl = '/api/settings.php';
                requestBody = {
                    action: 'update_avatar',
                    avatar_style: selectedStyle,
                    avatar_pos_x: parseFloat(selectedPosX),
                    avatar_pos_y: parseFloat(selectedPosY),
                    avatar_size: parseInt(selectedSize)
                };
            }
            
            // サンプルアイコンが選択されている場合
            if (selectedSample) {
                const iconPath = '/assets/icons/samples/' + selectedSample + '.svg';
                if (type === 'group') {
                    requestBody.icon_path = iconPath;
                } else {
                    requestBody.avatar_path = iconPath;
                }
                
                try {
                    const response = await fetch(apiUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(requestBody)
                    });
                    const data = await response.json();
                    
                    if (data.success) {
                        closeIconChangeModal();
                        location.reload();
                    } else {
                        alert(data.message || '<?= $currentLang === "en" ? "Failed to update" : ($currentLang === "zh" ? "更新失败" : "更新に失敗しました") ?>');
                    }
                } catch (e) {
                    console.error('Error:', e);
                    alert('<?= $currentLang === "en" ? "Error occurred" : ($currentLang === "zh" ? "发生错误" : "エラーが発生しました") ?>');
                }
                return;
            }
            
            // アップロードされた画像がある場合
            if (input.files && input.files[0]) {
                const formData = new FormData();
                formData.append('file', input.files[0]);
                formData.append('type', type === 'group' ? 'group_icon' : 'avatar');
                
                try {
                    console.log('Uploading file:', input.files[0].name, input.files[0].size, input.files[0].type);
                    
                    const uploadResponse = await fetch('/api/upload.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' }
                    });
                    
                    // レスポンスのテキストを取得してデバッグ
                    const responseText = await uploadResponse.text();
                    console.log('Upload response:', responseText);
                    
                    let uploadData;
                    try {
                        uploadData = JSON.parse(responseText);
                    } catch (parseErr) {
                        console.error('JSON parse error:', parseErr, 'Response:', responseText);
                        throw new Error('サーバーからの応答が不正です');
                    }
                    
                    if (!uploadData.success) {
                        throw new Error(uploadData.message || 'Upload failed');
                    }
                    
                    // upload.php returns file_path, not path
                    const uploadedPath = uploadData.file_path || uploadData.path;
                    console.log('Uploaded path:', uploadedPath);
                    
                    if (type === 'group') {
                        requestBody.icon_path = uploadedPath;
                    } else {
                        requestBody.avatar_path = uploadedPath;
                    }
                    
                    console.log('Updating with:', requestBody);
                    
                    const response = await fetch(apiUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(requestBody)
                    });
                    
                    const updateText = await response.text();
                    console.log('Update response:', updateText);
                    
                    let data;
                    try {
                        data = JSON.parse(updateText);
                    } catch (parseErr) {
                        console.error('JSON parse error:', parseErr, 'Response:', updateText);
                        throw new Error('サーバーからの応答が不正です');
                    }
                    
                    if (data.success) {
                        closeIconChangeModal();
                        location.reload();
                    } else {
                        alert(data.message || '<?= $currentLang === "en" ? "Failed to update" : ($currentLang === "zh" ? "更新失败" : "更新に失敗しました") ?>');
                    }
                } catch (e) {
                    console.error('Error:', e);
                    alert(e.message || '<?= $currentLang === "en" ? "Error occurred" : ($currentLang === "zh" ? "发生错误" : "エラーが発生しました") ?>');
                }
                return;
            }
            
            // スタイルのみの場合（位置・サイズ・スタイルのみ更新。グループは既存の icon_path を送る）
            if (type === 'group') {
                requestBody.icon_path = (typeof iconChangeCurrentIconPath === 'string' && iconChangeCurrentIconPath) ? iconChangeCurrentIconPath : '';
            } else {
                requestBody.avatar_path = '';
            }
            
            try {
                const response = await fetch(apiUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(requestBody)
                });
                const data = await response.json();
                
                if (data.success) {
                    closeIconChangeModal();
                    location.reload();
                } else {
                    alert(data.message || '<?= $currentLang === "en" ? "Failed to update" : ($currentLang === "zh" ? "更新失败" : "更新に失敗しました") ?>');
                }
            } catch (e) {
                console.error('Error:', e);
                alert('<?= $currentLang === "en" ? "Error occurred" : ($currentLang === "zh" ? "发生错误" : "エラーが発生しました") ?>');
            }
        }
        
        // ユーザーメニューを閉じる（上パネル・左パネル・右パネルすべて）
        function closeUserMenu() {
            document.querySelectorAll('.user-dropdown.show').forEach(function(d) { d.classList.remove('show'); });
        }
        
        // 招待リンクモーダル
        async function openInviteLinkModal() {
            openModal('inviteLinkModal');
            document.getElementById('inviteLinkInput').value = '<?= $currentLang === 'en' ? 'Loading...' : ($currentLang === 'zh' ? '加载中...' : '読み込み中...') ?>';
            
            try {
                const response = await fetch('/api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'generate_invite_link',
                        conversation_id: currentConversationId
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    const baseUrl = window.location.origin + '/join_group.php?code=';
                    document.getElementById('inviteLinkInput').value = baseUrl + data.invite_code;
                } else {
                    document.getElementById('inviteLinkInput').value = '<?= $currentLang === 'en' ? 'Error' : ($currentLang === 'zh' ? '错误' : 'エラー') ?>';
                    alert(data.message);
                }
            } catch (e) {
                console.error('Error:', e);
                document.getElementById('inviteLinkInput').value = '<?= $currentLang === 'en' ? 'Error' : ($currentLang === 'zh' ? '错误' : 'エラー') ?>';
            }
        }
        
        function copyInviteLink() {
            const input = document.getElementById('inviteLinkInput');
            input.select();
            document.execCommand('copy');
            alert('<?= $currentLang === 'en' ? 'Link copied!' : ($currentLang === 'zh' ? '链接已复制！' : 'リンクをコピーしました！') ?>');
        }
        
        async function resetInviteLink() {
            if (!confirm('<?= $currentLang === 'en' ? 'Generate a new link? The old link will no longer work.' : ($currentLang === 'zh' ? '生成新链接？旧链接将失效。' : '新しいリンクを生成しますか？古いリンクは無効になります。') ?>')) {
                return;
            }
            
            try {
                const response = await fetch('/api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'reset_invite_link',
                        conversation_id: currentConversationId
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    const baseUrl = window.location.origin + '/join_group.php?code=';
                    document.getElementById('inviteLinkInput').value = baseUrl + data.invite_code;
                    alert('<?= $currentLang === 'en' ? 'New link generated!' : ($currentLang === 'zh' ? '新链接已生成！' : '新しいリンクを生成しました！') ?>');
                } else {
                    alert(data.message || '<?= $currentLang === 'en' ? 'Failed' : ($currentLang === 'zh' ? '失败' : '失敗しました') ?>');
                }
            } catch (e) {
                console.error('Error:', e);
            }
        }
        
        // メンバー管理モーダル
        async function openAddMemberModal() {
            document.getElementById('addMemberSearch').value = '';
            document.getElementById('addMemberResults').innerHTML = '';
            
            // 招待入力フィールドをクリア
            const inviteContactInput = document.getElementById('inviteContactInput');
            if (inviteContactInput) inviteContactInput.value = '';
            const inviteResult = document.getElementById('inviteResult');
            if (inviteResult) inviteResult.innerHTML = '';
            const copyUrlResult = document.getElementById('copyUrlResult');
            if (copyUrlResult) copyUrlResult.innerHTML = '';
            
            openModal('addMemberModal');
            
            // 現在のメンバーを読み込んで表示
            await loadCurrentMembersForModal();
            
            // 招待リンク・QRコードを生成
            await generateGroupInviteLink();
            
            document.getElementById('addMemberSearch').focus();
        }
        
        // モーダル用：現在のメンバー一覧を読み込む
        async function loadCurrentMembersForModal() {
            const listEl = document.getElementById('currentMembersList');
            if (!listEl) return;
            listEl.innerHTML = '<div style="padding: 12px; color: #999; text-align: center;">読み込み中...</div>';
            
            try {
                const response = await fetch('/api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'get_members',
                        conversation_id: currentConversationId
                    })
                });
                const ct = response.headers.get('content-type') || '';
                let data;
                if (ct.includes('application/json')) {
                    data = await response.json();
                } else {
                    const text = await response.text();
                    throw new Error('JSONではありません: ' + (text.substring(0, 80) || response.status));
                }
                
                if (data.success && data.members) {
                    currentGroupMembers = data.members;
                    const isAdmin = data.my_role === 'admin';
                    renderCurrentMembersList(data.members, isAdmin);
                } else {
                    listEl.innerHTML = '<div style="padding: 12px; color: #999; text-align: center;">メンバーを取得できませんでした</div>';
                }
            } catch (e) {
                console.error('Error:', e);
                listEl.innerHTML = '<div style="padding: 12px; color: #f00; text-align: center;">エラーが発生しました</div>';
            }
        }
        
        // 現在のメンバー一覧を描画（プレミアムデザイン）
        function renderCurrentMembersList(members, isAdmin) {
            const listEl = document.getElementById('currentMembersList');
            const myId = <?= $user_id ?>;
            
            if (members.length === 0) {
                listEl.innerHTML = '<div style="padding:20px;color:#94a3b8;text-align:center;font-size:14px;">メンバーがいません</div>';
                return;
            }
            
            listEl.innerHTML = members.map(m => {
                const isMe = m.id === myId;
                const isMemberAdmin = m.role === 'admin';
                
                // 役割表示
                const roleHtml = (isAdmin && !isMe) ? `
                    <select aria-label="メンバーの役割を変更" onchange="changeMemberRole(${m.id}, this.value)" style="padding:6px 28px 6px 12px;font-size:13px;border:1px solid #e2e8f0;border-radius:8px;background:#fff url('data:image/svg+xml;charset=UTF-8,%3Csvg xmlns%3D%22http://www.w3.org/2000/svg%22 width%3D%2212%22 height%3D%2212%22 viewBox%3D%220 0 12 12%22%3E%3Cpath fill%3D%22%2364748b%22 d%3D%22M2 4l4 4 4-4%22/%3E%3C/svg%3E') no-repeat right 10px center;background-size:10px;color:#334155;cursor:pointer;appearance:none;-webkit-appearance:none;min-width:100px;">
                        <option value="admin" ${isMemberAdmin ? 'selected' : ''}>👑 管理者</option>
                        <option value="member" ${!isMemberAdmin ? 'selected' : ''}>メンバー</option>
                    </select>
                ` : `
                    <span style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;font-size:12px;font-weight:500;border-radius:20px;${isMemberAdmin ? 'background:#fef3c7;color:#b45309;' : 'background:#f1f5f9;color:#64748b;'}">
                        ${isMemberAdmin ? '👑 管理者' : 'メンバー'}
                    </span>
                `;
                
                // 削除ボタン
                const removeHtml = (isAdmin && !isMe) ? `
                    <button onclick="removeMemberFromModal(${m.id}, '${escapeHtml(m.display_name)}')" style="padding:6px 14px;font-size:12px;font-weight:500;color:#ef4444;background:transparent;border:1px solid #fecaca;border-radius:8px;cursor:pointer;transition:all 0.2s;" onmouseover="this.style.background='#fef2f2';this.style.borderColor='#f87171';" onmouseout="this.style.background='transparent';this.style.borderColor='#fecaca';">
                        グループから削除
                    </button>
                ` : '';
                
                return `
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #f1f5f9;background:#fff;transition:background 0.15s;" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background='#fff'">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="font-size:14px;font-weight:500;color:#1e293b;">${escapeHtml(m.display_name)}</span>
                            ${isMe ? '<span style="font-size:11px;color:#94a3b8;font-weight:400;">(あなた)</span>' : ''}
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            ${roleHtml}
                            ${removeHtml}
                        </div>
                    </div>
                `;
            }).join('');
        }
        
        // メンバーの役割を変更
        async function changeMemberRole(userId, newRole) {
            const makeAdmin = (newRole === 'admin');
            try {
                const response = await fetch('/api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'toggle_admin',
                        conversation_id: currentConversationId,
                        user_id: userId,
                        make_admin: makeAdmin
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    // メンバーリストを再読み込み
                    await loadCurrentMembersForModal();
                } else {
                    alert(data.message || '<?= $currentLang === 'en' ? 'Failed to change role' : ($currentLang === 'zh' ? '更改角色失败' : '役割の変更に失敗しました') ?>');
                    // 失敗時はリストを再読み込みして元に戻す
                    await loadCurrentMembersForModal();
                }
            } catch (e) {
                console.error('Error:', e);
                await loadCurrentMembersForModal();
            }
        }
        
        // 管理者権限のトグル（変更後にページリロードで反映）
        async function toggleMemberAdminRole(userId, makeAdmin) {
            try {
                const response = await fetch('/api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'toggle_admin',
                        conversation_id: currentConversationId,
                        user_id: userId,
                        make_admin: makeAdmin
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    // ページをリロードして全ての変更を反映（メンバーポップアップ、システムメッセージ）
                    location.reload();
                } else {
                    alert(data.message || '<?= $currentLang === 'en' ? 'Failed' : ($currentLang === 'zh' ? '失败' : '失敗しました') ?>');
                }
            } catch (e) {
                console.error('Error:', e);
            }
        }
        
        // メンバーを削除（モーダルから）
        async function removeMemberFromModal(userId, displayName) {
            if (!confirm(`${displayName} さんをグループから削除しますか？`)) {
                return;
            }
            
            try {
                const response = await fetch('/api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'remove_member',
                        conversation_id: currentConversationId,
                        user_id: userId
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    await loadCurrentMembersForModal();
                    alert('<?= $currentLang === 'en' ? 'Member removed!' : ($currentLang === 'zh' ? '成员已移除！' : 'メンバーを削除しました！') ?>');
                } else {
                    alert(data.message || '<?= $currentLang === 'en' ? 'Failed to remove' : ($currentLang === 'zh' ? '移除失败' : '削除に失敗しました') ?>');
                }
            } catch (e) {
                console.error('Error:', e);
            }
        }
        
        let searchMemberTimeout = null;
        function searchMembersToAdd(query) {
            clearTimeout(searchMemberTimeout);
            
            if (query.length < 2) {
                document.getElementById('addMemberResults').innerHTML = '<p style="color:#999; font-size:12px; padding:8px;"><?= $currentLang === 'en' ? 'Enter at least 2 characters' : ($currentLang === 'zh' ? '请输入至少2个字符' : '2文字以上入力してください') ?></p>';
                return;
            }
            
            searchMemberTimeout = setTimeout(async () => {
                try {
                    // グループへのメンバー追加用検索（組織内検索優先: scope=org & conversation_id）
                    const convId = window.currentConversationId || (() => { const m = (window.location.search || '').match(/[?&]c=(\d+)/); return m ? m[1] : ''; })();
                    const url = '/api/users.php?action=search&q=' + encodeURIComponent(query) + '&for_group_add=1' + (convId ? '&scope=org&conversation_id=' + convId : '');
                    const response = await fetch(url);
                    const data = await response.json();
                    
                    if (data.success && data.users) {
                        const existingIds = currentGroupMembers.map(m => m.id);
                        const filtered = data.users.filter(u => !existingIds.includes(u.id) && u.id != <?= $user_id ?>);
                        
                        if (filtered.length === 0) {
                            document.getElementById('addMemberResults').innerHTML = '<p style="color:#999; font-size:12px; padding:8px;"><?= $currentLang === 'en' ? 'No users found' : ($currentLang === 'zh' ? '未找到用户' : 'ユーザーが見つかりません') ?></p>';
                        } else {
                            document.getElementById('addMemberResults').innerHTML = filtered.map(user => `
                                <div class="member-item" onclick="addMemberToGroup(${user.id})" style="cursor: pointer;">
                                    <div class="member-avatar">${(user.display_name || '?')[0].toUpperCase()}</div>
                                    <div class="member-info">
                                        <div class="member-name">${escapeHtml(user.display_name)}</div>
                                    </div>
                                    <div style="color: var(--primary);">➕</div>
                                </div>
                            `).join('');
                        }
                    }
                } catch (e) {
                    console.error('Error:', e);
                }
            }, 300);
        }
        
        async function addMemberToGroup(userId) {
            try {
                const response = await fetch('/api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'add_member',
                        conversation_id: currentConversationId,
                        user_id: userId
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    closeModal('addMemberModal');
                    
                    // DMから新規グループが作成された場合は新グループに移動
                    if (data.new_group_created && data.new_conversation_id) {
                        alert('<?= $currentLang === 'en' ? 'New group created!' : ($currentLang === 'zh' ? '已创建新群组！' : '新しいグループを作成しました！') ?>');
                        location.href = 'chat.php?c=' + data.new_conversation_id;
                    } else {
                        alert('<?= $currentLang === 'en' ? 'Member added!' : ($currentLang === 'zh' ? '成员已添加！' : 'メンバーを追加しました！') ?>');
                        // メッセージエリアを更新してシステムメッセージを表示
                        location.reload();
                    }
                } else {
                    alert(data.message || '<?= $currentLang === 'en' ? 'Failed to add' : ($currentLang === 'zh' ? '添加失败' : '追加に失敗しました') ?>');
                }
            } catch (e) {
                console.error('Error:', e);
            }
        }
        
        // 外部ユーザーをグループに招待
        async function sendGroupInvite() {
            const contactInput = document.getElementById('inviteContactInput');
            const resultDiv = document.getElementById('inviteResult');
            const contact = contactInput.value.trim();
            
            if (!contact) {
                resultDiv.innerHTML = '<span style="color: #e74c3c;"><?= $currentLang === 'en' ? 'Please enter email or phone number' : ($currentLang === 'zh' ? '请输入邮箱或电话号码' : 'メールアドレスまたは電話番号を入力してください') ?></span>';
                return;
            }
            
            // メールアドレスか電話番号かを判定
            const isEmail = contact.includes('@');
            const type = isEmail ? 'email' : 'phone';
            
            resultDiv.innerHTML = '<span style="color: #999;"><?= $currentLang === 'en' ? 'Sending invitation...' : ($currentLang === 'zh' ? '发送邀请中...' : '招待を送信中...') ?></span>';
            
            try {
                const response = await fetch('/api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'send_invite',
                        contact: contact,
                        type: type,
                        group_id: currentConversationId
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    resultDiv.innerHTML = '<span style="color: #27ae60;"><?= $currentLang === 'en' ? 'Invitation sent!' : ($currentLang === 'zh' ? '邀请已发送！' : '招待を送信しました！') ?></span>';
                    contactInput.value = '';
                    
                    // 3秒後にメッセージをクリア
                    setTimeout(() => {
                        resultDiv.innerHTML = '';
                    }, 3000);
                } else {
                    resultDiv.innerHTML = '<span style="color: #e74c3c;">' + (data.error || '<?= $currentLang === 'en' ? 'Failed to send' : ($currentLang === 'zh' ? '发送失败' : '送信に失敗しました') ?>') + '</span>';
                }
            } catch (e) {
                console.error('Error:', e);
                resultDiv.innerHTML = '<span style="color: #e74c3c;"><?= $currentLang === 'en' ? 'Error occurred' : ($currentLang === 'zh' ? '发生错误' : 'エラーが発生しました') ?></span>';
            }
        }
        
        // グループ招待URL・QRコードの生成
        async function generateGroupInviteLink() {
            if (!currentConversationId) return;
            
            const urlInput = document.getElementById('groupInviteUrl');
            const qrContainer = document.getElementById('groupInviteQrCode');
            
            if (!urlInput || !qrContainer) return;
            
            // QRCodeライブラリを遅延読み込み
            if (typeof QRCode === 'undefined') {
                if (typeof window.loadQRCode === 'function') {
                    await window.loadQRCode().catch(() => {});
                } else if (typeof Chat !== 'undefined' && Chat.lazyLoader && Chat.lazyLoader.loadModule) {
                    await Chat.lazyLoader.loadModule('qrcode').catch(() => {});
                }
            }
            
            try {
                const response = await fetch('/api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'get_invite_link',
                        conversation_id: currentConversationId
                    })
                });
                const ct = response.headers.get('content-type') || '';
                let data;
                if (ct.includes('application/json')) {
                    data = await response.json();
                } else {
                    const text = await response.text();
                    throw new Error(text.substring(0, 100) || 'サーバーエラー');
                }
                
                if (data.success && data.invite_link) {
                    // URLを表示
                    urlInput.value = data.invite_link;
                    
                    // QRコードを生成（qrcodejs API）
                    qrContainer.innerHTML = '';
                    if (typeof QRCode !== 'undefined') {
                        try {
                            new QRCode(qrContainer, {
                                text: data.invite_link,
                                width: 150,
                                height: 150,
                                colorDark: '#000000',
                                colorLight: '#ffffff',
                                correctLevel: QRCode.CorrectLevel.M
                            });
                        } catch (qrErr) {
                            console.error('QR code generation error:', qrErr);
                            qrContainer.innerHTML = '<p style="color: #999; font-size: 12px;"><?= $currentLang === 'en' ? 'Failed to generate QR code' : ($currentLang === 'zh' ? '生成二维码失败' : 'QRコード生成に失敗しました') ?></p>';
                        }
                    } else {
                        // QRCodeライブラリがない場合はGoogle Charts APIを使用
                        const qrImg = document.createElement('img');
                        qrImg.src = 'https://chart.googleapis.com/chart?chs=150x150&cht=qr&chl=' + encodeURIComponent(data.invite_link);
                        qrImg.alt = 'QR Code';
                        qrImg.style.display = 'block';
                        qrContainer.appendChild(qrImg);
                    }
                } else {
                    urlInput.value = '';
                    qrContainer.innerHTML = '<p style="color: #e74c3c; font-size: 12px;">' + (data.message || '<?= $currentLang === 'en' ? 'Failed to generate link' : ($currentLang === 'zh' ? '生成链接失败' : 'リンク生成に失敗しました') ?>') + '</p>';
                }
            } catch (e) {
                console.error('Error:', e);
                urlInput.value = '';
                qrContainer.innerHTML = '<p style="color: #e74c3c; font-size: 12px;"><?= $currentLang === 'en' ? 'Error occurred' : ($currentLang === 'zh' ? '发生错误' : 'エラーが発生しました') ?></p>';
            }
        }
        
        // 招待URLをクリップボードにコピー
        function copyGroupInviteUrl() {
            const urlInput = document.getElementById('groupInviteUrl');
            const resultDiv = document.getElementById('copyUrlResult');
            
            if (!urlInput || !urlInput.value) {
                if (resultDiv) resultDiv.innerHTML = '<span style="color: #e74c3c;"><?= $currentLang === 'en' ? 'No URL to copy' : ($currentLang === 'zh' ? '没有链接可复制' : 'コピーするURLがありません') ?></span>';
                return;
            }
            
            navigator.clipboard.writeText(urlInput.value).then(() => {
                if (resultDiv) {
                    resultDiv.innerHTML = '<?= $currentLang === 'en' ? 'Copied!' : ($currentLang === 'zh' ? '已复制！' : 'コピーしました！') ?>';
                    setTimeout(() => { resultDiv.innerHTML = ''; }, 2000);
                }
            }).catch(err => {
                console.error('Copy failed:', err);
                // フォールバック: 選択してコピー
                urlInput.select();
                document.execCommand('copy');
                if (resultDiv) {
                    resultDiv.innerHTML = '<?= $currentLang === 'en' ? 'Copied!' : ($currentLang === 'zh' ? '已复制！' : 'コピーしました！') ?>';
                    setTimeout(() => { resultDiv.innerHTML = ''; }, 2000);
                }
            });
        }
        
        // QRコードをダウンロード
        function downloadGroupQrCode() {
            const qrContainer = document.getElementById('groupInviteQrCode');
            const canvas = qrContainer.querySelector('canvas');
            
            if (canvas) {
                // Canvas形式の場合
                const link = document.createElement('a');
                link.download = 'group-invite-qr.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
            } else {
                // img形式の場合（Google Charts API）
                const img = qrContainer.querySelector('img');
                if (img) {
                    const link = document.createElement('a');
                    link.download = 'group-invite-qr.png';
                    link.href = img.src;
                    link.target = '_blank';
                    link.click();
                } else {
                    alert('<?= $currentLang === 'en' ? 'No QR code to download' : ($currentLang === 'zh' ? '没有二维码可下载' : 'ダウンロードするQRコードがありません') ?>');
                }
            }
        }
        
        // グループ削除確認（右パネルから）
        function confirmDeleteGroup() {
            contextTargetConvId = currentConversationId;
            deleteGroup();
        }
        
        // ========== グループ管理機能 終了 ==========
        
        // グループ削除
        async function deleteGroup() {
            hideConvContextMenu();
            
            // 会話IDを取得（右パネルからの削除の場合はcurrentConversationIdを使用）
            const convIdToDelete = contextTargetConvId || currentConversationId;
            
            if (!convIdToDelete) {
                console.error('削除対象の会話IDがありません');
                alert('<?= $currentLang === 'en' ? 'No conversation selected' : ($currentLang === 'zh' ? '未选择对话' : '会話が選択されていません') ?>');
                return;
            }
            
            if (!confirm('<?= $currentLang === 'en' ? 'Are you sure you want to delete this chat? This action cannot be undone.' : ($currentLang === 'zh' ? '确定要删除这个聊天吗？此操作无法撤销。' : 'このチャットを削除しますか？この操作は取り消せません。') ?>')) {
                return;
            }
            
            try {
                console.log('削除リクエスト送信:', convIdToDelete);
                const response = await fetch('api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete',
                        conversation_id: convIdToDelete
                    })
                });
                const data = await response.json();
                console.log('削除レスポンス:', data);
                if (data.success) {
                    location.href = 'chat.php';
                } else {
                    alert(data.message || '<?= $currentLang === 'en' ? 'Failed to delete' : ($currentLang === 'zh' ? '删除失败' : '削除に失敗しました') ?>');
                }
            } catch (e) {
                console.error('削除エラー:', e);
                alert('<?= $currentLang === 'en' ? 'Error occurred' : ($currentLang === 'zh' ? '发生错误' : 'エラーが発生しました') ?>');
            }
        }
        
        // ========== 友達追加機能 ==========
        function openAddFriendModal() {
            openModal('addFriendModal');
            // メンバータブをデフォルトで表示し、メンバーを読み込む
            loadAllGroupMembersForSearch();
        }
        
        function switchAddFriendTab(tab) {
            // タブボタンの状態を更新
            document.querySelectorAll('.add-friend-tabs button').forEach((btn, i) => {
                btn.classList.toggle('active', 
                    (tab === 'members' && i === 0) ||
                    (tab === 'invite' && i === 1) || 
                    (tab === 'qr' && i === 2) || 
                    (tab === 'search' && i === 3) ||
                    (tab === 'contacts' && i === 4)
                );
            });
            
            // コンテンツの表示切り替え
            document.getElementById('addFriendMembers').style.display = tab === 'members' ? 'block' : 'none';
            document.getElementById('addFriendInvite').style.display = tab === 'invite' ? 'block' : 'none';
            document.getElementById('addFriendQR').style.display = tab === 'qr' ? 'block' : 'none';
            document.getElementById('addFriendSearch').style.display = tab === 'search' ? 'block' : 'none';
            const contactsEl = document.getElementById('addFriendContacts');
            if (contactsEl) contactsEl.style.display = tab === 'contacts' ? 'block' : 'none';
            
            // メンバータブを開いたらメンバーを読み込む
            if (tab === 'members') {
                loadAllGroupMembersForSearch();
            }
        }
        
        // グループメンバー一覧（キャッシュ用）- 友達追加モーダル用
        let allGroupMembers = [];
        
        // 所属グループのメンバーを読み込む（友達追加モーダル用）
        async function loadAllGroupMembersForSearch() {
            const resultsDiv = document.getElementById('searchMemberResults');
            resultsDiv.innerHTML = '<div class="search-user-empty"><p>読み込み中...</p></div>';
            
            try {
                const res = await fetch('api/friends.php?action=group_members');
                const data = await res.json();
                
                if (data.success && data.members) {
                    allGroupMembers = data.members;
                    renderGroupMembers(allGroupMembers);
                } else {
                    resultsDiv.innerHTML = '<div class="search-user-empty"><p>メンバーが見つかりません</p></div>';
                }
            } catch (err) {
                console.error('Group members load error:', err);
                resultsDiv.innerHTML = '<div class="search-user-empty"><p>エラーが発生しました</p></div>';
            }
        }
        
        // グループメンバーを表示
        function renderGroupMembers(members) {
            const resultsDiv = document.getElementById('searchMemberResults');
            
            if (members.length === 0) {
                resultsDiv.innerHTML = '<div class="search-user-empty"><p>メンバーが見つかりません</p></div>';
                return;
            }
            
            resultsDiv.innerHTML = members.map(member => `
                <div class="search-user-item" onclick="startDmFromSearch(${member.id}, '${escapeHtml(member.display_name)}')">
                    <div class="search-user-avatar">${member.display_name[0].toUpperCase()}</div>
                    <div class="search-user-info">
                        <div class="search-user-name">${escapeHtml(member.display_name)}</div>
                        <div class="search-user-groups">${escapeHtml(member.group_names || '')}</div>
                    </div>
                    <button class="add-btn dm-btn">💬 DM</button>
                </div>
            `).join('');
        }
        
        // 検索でフィルタリング（メンバータブ用）
        let searchMemberTimeoutTab = null;
        function debounceSearchGroupMembers() {
            clearTimeout(searchMemberTimeoutTab);
            searchMemberTimeoutTab = setTimeout(filterGroupMembers, 200);
        }
        
        function filterGroupMembers() {
            const query = document.getElementById('searchMemberInput').value.trim().toLowerCase();
            
            if (!query) {
                renderGroupMembers(allGroupMembers);
                return;
            }
            
            const filtered = allGroupMembers.filter(m => 
                m.display_name.toLowerCase().includes(query) ||
                (m.group_names && m.group_names.toLowerCase().includes(query))
            );
            renderGroupMembers(filtered);
        }
        
        // 検索結果からDMを開始
        function startDmFromSearch(userId, displayName) {
            closeModal('addFriendModal');
            startDmWithUser(userId, displayName);
        }
        
        // 友達招待リンクのコピー（addFriendModal用）
        function copyFriendInviteLink() {
            const input = document.getElementById('friendInviteLinkInput');
            if (!input) return;
            input.select();
            document.execCommand('copy');
            
            const btn = input.nextElementSibling;
            if (btn) {
                const originalText = btn.textContent;
                btn.textContent = '✓ コピー完了';
                btn.style.background = '#10b981';
                
                setTimeout(() => {
                    btn.textContent = originalText;
                    btn.style.background = '';
                }, 2000);
            }
        }
        
        // ========== 友達追加モーダル：連絡先タブ ==========
        let addFriendModalContacts = [];
        
        async function loadAddFriendContacts() {
            const initialEl = document.getElementById('addFriendContactsInitial');
            const unsupportedEl = document.getElementById('addFriendContactsUnsupported');
            const loadingEl = document.getElementById('addFriendContactsLoading');
            const resultsEl = document.getElementById('addFriendContactsResults');
            const loadBtn = document.getElementById('addFriendContactsLoadBtn');
            if (!initialEl || !loadingEl || !resultsEl) return;
            
            if (!('contacts' in navigator && 'ContactsManager' in window)) {
                if (loadBtn) loadBtn.style.display = 'none';
                if (unsupportedEl) unsupportedEl.style.display = 'block';
                return;
            }
            
            initialEl.style.display = 'none';
            loadingEl.style.display = 'block';
            resultsEl.style.display = 'none';
            addFriendModalContacts = [];
            
            try {
                const props = ['email', 'name', 'tel'];
                const opts = { multiple: true };
                const contacts = await navigator.contacts.select(props, opts);
                
                if (!contacts || contacts.length === 0) {
                    loadingEl.style.display = 'none';
                    initialEl.style.display = 'block';
                    if (loadBtn) loadBtn.style.display = 'block';
                    return;
                }
                
                const loaded = [];
                for (const contact of contacts) {
                    const name = contact.name && contact.name.length > 0 ? contact.name[0] : '';
                    const emails = contact.email || [];
                    const phones = contact.tel || [];
                    for (const email of emails) {
                        if (email && email.includes('@')) {
                            loaded.push({ name: name, contact: email.toLowerCase().trim(), type: 'email' });
                        }
                    }
                    for (const phone of phones) {
                        if (phone) {
                            const normalized = phone.replace(/[^\d+]/g, '');
                            if (normalized.length >= 10) {
                                loaded.push({ name: name, contact: normalized, type: 'phone' });
                            }
                        }
                    }
                }
                
                if (loaded.length === 0) {
                    loadingEl.style.display = 'none';
                    initialEl.style.display = 'block';
                    if (loadBtn) loadBtn.style.display = 'block';
                    return;
                }
                
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'check_contacts',
                        contacts: loaded.map(c => ({ contact: c.contact, type: c.type }))
                    })
                });
                const data = await response.json().catch(() => ({}));
                
                if (!response.ok) {
                    loadingEl.style.display = 'none';
                    resultsEl.style.display = 'none';
                    initialEl.style.display = 'block';
                    if (loadBtn) loadBtn.style.display = 'block';
                    alert(data.message || data.error || '通信エラーが発生しました');
                    return;
                }
                
                if (data.success && data.matches) {
                    for (const c of loaded) {
                        const match = data.matches.find(m => m.contact === c.contact);
                        if (match) {
                            c.user_id = match.user_id;
                            c.display_name = match.display_name;
                            c.is_registered = true;
                            c.is_friend = match.is_friend;
                            c.is_pending = match.is_pending;
                        } else {
                            c.is_registered = false;
                        }
                    }
                    addFriendModalContacts = loaded;
                } else {
                    loadingEl.style.display = 'none';
                    resultsEl.style.display = 'none';
                    initialEl.style.display = 'block';
                    if (loadBtn) loadBtn.style.display = 'block';
                    alert(data.message || data.error || '連絡先の照合に失敗しました');
                    return;
                }
            } catch (e) {
                console.error('loadAddFriendContacts error:', e);
                loadingEl.style.display = 'none';
                resultsEl.style.display = 'none';
                initialEl.style.display = 'block';
                if (loadBtn) loadBtn.style.display = 'block';
                return;
            }
            
            loadingEl.style.display = 'none';
            resultsEl.style.display = 'block';
            renderAddFriendContactsList();
        }
        
        function renderAddFriendContactsList() {
            const listEl = document.getElementById('addFriendContactsList');
            if (!listEl) return;
            
            // 同一 user_id は1件のみ表示（メール・電話両方でマッチした場合の重複を防ぐ）
            const seenIds = new Set();
            const registered = addFriendModalContacts
                .filter(c => c.is_registered)
                .filter(c => {
                    if (seenIds.has(c.user_id)) return false;
                    seenIds.add(c.user_id);
                    return true;
                });
            const unregistered = addFriendModalContacts.filter(c => !c.is_registered);
            
            // onclick 内の JS 文字列用エスケープ（\ と ' をエスケープ）
            function escapeJsString(s) {
                return String(s || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\r/g, '').replace(/\n/g, ' ');
            }
            
            let html = '';
            for (const contact of registered) {
                const safeContact = (contact.contact || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
                const dmDisplayName = escapeJsString(contact.display_name || contact.name || '');
                html += `
                    <div class="search-user-item add-friend-contact-item">
                        <div class="search-user-avatar">${escapeHtml((contact.name || contact.display_name || '?').charAt(0).toUpperCase())}</div>
                        <div class="search-user-info" style="flex:1;">
                            <div class="search-user-name">${escapeHtml(contact.display_name || contact.name || '')}</div>
                            <div class="search-user-groups" style="font-size:11px;color:var(--text-muted);">${escapeHtml(contact.contact)}</div>
                        </div>
                        <div class="add-friend-contact-actions">
                            ${contact.is_friend
                                ? '<span style="color:#10b981;font-size:12px;">✓ 友だち</span>'
                                : contact.is_pending
                                    ? '<span style="color:#f59e0b;font-size:12px;">申請中</span>'
                                    : `<button type="button" class="btn btn-primary btn-sm" onclick="addFriendFromContactInModal(${contact.user_id})">友達申請</button> <button type="button" class="btn btn-secondary btn-sm" onclick="startDmFromSearch(${contact.user_id}, '${dmDisplayName}')">DM</button>`
                            }
                        </div>
                    </div>
                `;
            }
            for (const contact of unregistered) {
                const contactId = 'ac' + (contact.contact || '').replace(/[^a-zA-Z0-9]/g, '').slice(-8);
                const safeContactAttr = (contact.contact || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                html += `
                    <div class="search-user-item add-friend-contact-item">
                        <div class="search-user-avatar" style="background:#9ca3af;">${escapeHtml((contact.name || '?').charAt(0).toUpperCase())}</div>
                        <div class="search-user-info" style="flex:1;">
                            <div class="search-user-name">${escapeHtml(contact.name || '')}</div>
                            <div class="search-user-groups" style="font-size:11px;color:var(--text-muted);">${escapeHtml(contact.contact)}</div>
                        </div>
                        <div class="add-friend-contact-actions" id="addFriendInviteActions-${contactId}">
                            <button type="button" class="btn btn-secondary btn-sm btn-invite-from-modal" data-contact="${safeContactAttr}" data-type="${contact.type}" data-contact-id="${contactId}">${contact.type === 'phone' ? 'SMS招待' : 'メール招待'}</button>
                        </div>
                    </div>
                `;
            }
            listEl.innerHTML = html || '<p class="search-user-empty">マッチした連絡先はありません</p>';
            
            listEl.querySelectorAll('.btn-invite-from-modal').forEach(btn => {
                btn.addEventListener('click', function() {
                    sendInviteFromAddFriendModal(this.dataset.contact, this.dataset.type, this.dataset.contactId);
                });
            });
        }
        
        async function addFriendFromContactInModal(userId) {
            try {
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'add', friend_id: userId })
                });
                const data = await response.json();
                if (data.success) {
                    const c = addFriendModalContacts.find(x => x.user_id === userId);
                    if (c) { c.is_pending = true; renderAddFriendContactsList(); }
                } else {
                    alert(data.error || '友だち追加に失敗しました');
                }
            } catch (e) {
                alert('エラーが発生しました');
            }
        }
        
        async function sendInviteFromAddFriendModal(contact, type, contactId) {
            const actionsEl = document.getElementById('addFriendInviteActions-' + contactId);
            if (!actionsEl) return;
            const btn = actionsEl.querySelector('.btn-invite-from-modal');
            if (btn) { btn.disabled = true; btn.textContent = '送信中...'; }
            try {
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'send_invite', contact: contact, type: type })
                });
                const data = await response.json();
                if (data.success) {
                    actionsEl.innerHTML = '<span style="color:#10b981;font-size:12px;">✓ 招待済み</span>';
                } else {
                    alert(data.error || '招待の送信に失敗しました');
                    if (btn) { btn.disabled = false; btn.textContent = type === 'phone' ? 'SMS招待' : 'メール招待'; }
                }
            } catch (e) {
                if (btn) { btn.disabled = false; btn.textContent = type === 'phone' ? 'SMS招待' : 'メール招待'; }
                alert('招待の送信に失敗しました');
            }
        }
        
        // ========== QRスキャナー機能 ==========
        let qrScanner = null;
        let qrStream = null;
        let jsQRLoaded = false;
        
        // jsQR ライブラリを遅延読み込み
        function loadJsQR() {
            return new Promise((resolve, reject) => {
                if (typeof jsQR !== 'undefined') {
                    jsQRLoaded = true;
                    resolve();
                    return;
                }
                
                const script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js';
                script.async = true;
                script.onload = () => {
                    jsQRLoaded = true;
                    console.log('jsQR library loaded');
                    resolve();
                };
                script.onerror = () => {
                    reject(new Error('jsQR ライブラリの読み込みに失敗しました'));
                };
                document.head.appendChild(script);
            });
        }
        
        async function startQRScanner() {
            // jsQR がまだ読み込まれていない場合は読み込む
            if (typeof jsQR === 'undefined') {
                try {
                    const status = document.getElementById('qrScannerStatus');
                    if (status) status.textContent = 'ライブラリを読み込み中...';
                    await loadJsQR();
                } catch (error) {
                    console.error('jsQR load error:', error);
                    alert('QRスキャナーの読み込みに失敗しました。ページを再読み込みしてください。');
                    return;
                }
            }
            const preview = document.getElementById('qrScannerPreview');
            const button = document.getElementById('qrScannerButton');
            const status = document.getElementById('qrScannerStatus');
            const video = document.getElementById('qrVideo');
            
            try {
                // カメラへのアクセスを要求
                qrStream = await navigator.mediaDevices.getUserMedia({ 
                    video: { facingMode: 'environment' } 
                });
                
                video.srcObject = qrStream;
                await video.play();
                
                preview.style.display = 'block';
                button.style.display = 'none';
                status.textContent = 'QRコードをかざしてください...';
                status.innerHTML = 'QRコードをかざしてください... <button onclick="stopQRScanner()" style="margin-left:8px;padding:4px 8px;font-size:11px;border:1px solid #ddd;border-radius:4px;background:#fff;cursor:pointer;">停止</button>';
                
                // QRコードスキャン開始
                scanQRCode();
                
            } catch (err) {
                console.error('Camera error:', err);
                status.textContent = 'カメラにアクセスできません';
                alert('カメラへのアクセスが許可されていません。\nブラウザの設定でカメラを許可してください。');
            }
        }
        
        function stopQRScanner() {
            const preview = document.getElementById('qrScannerPreview');
            const button = document.getElementById('qrScannerButton');
            const status = document.getElementById('qrScannerStatus');
            const video = document.getElementById('qrVideo');
            
            if (qrStream) {
                qrStream.getTracks().forEach(track => track.stop());
                qrStream = null;
            }
            
            video.srcObject = null;
            preview.style.display = 'none';
            button.style.display = 'block';
            status.textContent = '友達のQRを読み取る';
            
            if (qrScanner) {
                cancelAnimationFrame(qrScanner);
                qrScanner = null;
            }
        }
        
        function scanQRCode() {
            const video = document.getElementById('qrVideo');
            
            if (!qrStream || video.readyState !== video.HAVE_ENOUGH_DATA) {
                qrScanner = requestAnimationFrame(scanQRCode);
                return;
            }
            
            // Canvasでビデオフレームをキャプチャ
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            
            // 簡易的なQRコード検出（jsQRライブラリを使用）
            if (typeof jsQR !== 'undefined') {
                const code = jsQR(imageData.data, imageData.width, imageData.height);
                if (code && code.data) {
                    handleQRResult(code.data);
                    return;
                }
            } else {
                // jsQRがない場合は、BarcodeDetector APIを試す（対応ブラウザのみ）
                if ('BarcodeDetector' in window) {
                    const detector = new BarcodeDetector({ formats: ['qr_code'] });
                    detector.detect(canvas).then(barcodes => {
                        if (barcodes.length > 0) {
                            handleQRResult(barcodes[0].rawValue);
                        }
                    }).catch(err => console.log('Detection error:', err));
                }
            }
            
            qrScanner = requestAnimationFrame(scanQRCode);
        }
        
        function handleQRResult(data) {
            stopQRScanner();
            
            // Social9の招待リンクかチェック
            if (data.includes('/invite.php?u=')) {
                if (confirm('友達追加ページを開きますか？\n\n' + data)) {
                    window.location.href = data;
                }
            } else if (data.startsWith('http://') || data.startsWith('https://')) {
                if (confirm('このURLを開きますか？\n\n' + data)) {
                    window.open(data, '_blank');
                }
            } else {
                alert('読み取り結果:\n' + data);
            }
        }
        
        // モーダルを閉じたときにスキャナーを停止
        const originalCloseModal = closeModal;
        closeModal = function(id) {
            if (id === 'addFriendModal') {
                stopQRScanner();
            }
            originalCloseModal(id);
        };
        
        let searchUserTimeout = null;
        function debounceSearchUser() {
            clearTimeout(searchUserTimeout);
            searchUserTimeout = setTimeout(searchUser, 300);
        }
        
        async function searchUser() {
            const query = document.getElementById('searchUserInput').value.trim();
            const resultsDiv = document.getElementById('searchUserResults');
            
            // 2文字以上で検索（Email/携帯番号のみ）
            if (query.length < 2) {
                var inviteRowEl = document.getElementById('addFriendSearchInviteRow');
                if (inviteRowEl) inviteRowEl.style.display = 'none';
                resultsDiv.innerHTML = `
                    <div class="search-user-empty">
                        <span style="font-size:40px;">🔍</span>
                        <p>メールアドレスまたは携帯番号で検索できます</p>
                    </div>
                `;
                return;
            }
            
            resultsDiv.innerHTML = '<div class="search-user-empty"><p>検索中...</p></div>';
            var inviteRow = document.getElementById('addFriendSearchInviteRow');
            var inviteBtn = document.getElementById('addFriendSearchInviteBtn');
            if (inviteRow) inviteRow.style.display = 'none';
            
            try {
                const res = await fetch('api/friends.php?action=search&query=' + encodeURIComponent(query));
                const data = await res.json();
                
                if (data.success && data.users && data.users.length > 0) {
                    if (inviteRow) inviteRow.style.display = 'none';
                    resultsDiv.innerHTML = data.users.map(user => {
                        let btnHtml = '';
                        const safeName = escapeHtml(user.display_name || 'Unknown').replace(/'/g, "\\'");
                        if (user.friendship_status === 'accepted') {
                            btnHtml = `<button class="add-btn" style="background:#10b981;cursor:pointer;" onclick="event.stopPropagation();startDmFromSearch(${user.id}, '${safeName}')">💬 DM</button>`;
                        } else if (user.friendship_status === 'pending') {
                            btnHtml = '<button class="add-btn add-btn-pending-cancel" type="button" onclick="event.stopPropagation();cancelSentFriendRequest(' + user.id + ')" title="申請を取り消す" style="background:#f59e0b;">⏳ 申請中（取り消し）</button>';
                        } else if (user.friendship_status === 'blocked') {
                            btnHtml = '<button class="add-btn" disabled style="background:#6b7280;cursor:default;">ブロック中</button>';
                        } else {
                            btnHtml = `<button class="add-btn" onclick="event.stopPropagation();openFriendRequestModal(${user.id}, '${safeName}')">👋 友達申請</button>`;
                        }
                        
                        const statusColor = user.online_status_color || '#9ca3af';
                        const statusDot = `<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${statusColor};margin-right:6px;"></span>`;
                        
                        return `
                            <div class="search-user-item">
                                <div class="user-avatar">${user.display_name ? user.display_name.substring(0, 1) : '?'}</div>
                                <div class="user-info">
                                    <div class="user-name">${escapeHtml(user.display_name || 'Unknown')}</div>
                                    <div class="user-id">${statusDot}${escapeHtml(user.online_status_label || 'オフライン')}</div>
                                </div>
                                ${btnHtml}
                            </div>
                        `;
                    }).join('');
                } else {
                    resultsDiv.innerHTML = `
                        <div class="search-user-empty">
                            <span style="font-size:40px;">😕</span>
                            <p>該当するユーザーは見つかりませんでした</p>
                        </div>
                    `;
                    if (data.invite_available && data.contact && inviteRow && inviteBtn) {
                        inviteBtn.setAttribute('data-invite-contact', data.contact);
                        inviteBtn.onclick = function() {
                            var c = inviteBtn.getAttribute('data-invite-contact');
                            if (c && typeof sendInviteFromAddFriendModal === 'function') sendInviteFromAddFriendModal(c, 'email', '');
                        };
                        inviteRow.style.display = 'block';
                    }
                }
            } catch (err) {
                resultsDiv.innerHTML = `
                    <div class="search-user-empty">
                        <span style="font-size:40px;">⚠️</span>
                        <p>検索エラーが発生しました</p>
                    </div>
                `;
            }
        }
        
        async function sendFriendRequest(userId) {
            try {
                const res = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'add', friend_id: userId })
                });
                const data = await res.json();
                
                if (data.success) {
                    // ステータスに応じてメッセージを表示
                    if (data.status === 'accepted') {
                        alert(data.message || '友達になりました！');
                    } else {
                        alert(data.message || '友達リクエストを送信しました');
                    }
                    // 検索結果を更新
                    searchUser();
                } else {
                    alert(data.error || '送信に失敗しました');
                }
            } catch (err) {
                console.error('Friend request error:', err);
                alert('エラーが発生しました');
            }
        }
        
        async function cancelSentFriendRequest(userId) {
            if (!confirm('<?= $currentLang === "en" ? "Cancel this friend request?" : ($currentLang === "zh" ? "确定要取消好友申请吗？" : "この友だち申請を取り消しますか？") ?>')) return;
            try {
                const res = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'cancel_sent', friend_id: userId })
                });
                const data = await res.json();
                if (data.success) {
                    if (typeof searchUser === 'function') searchUser();
                    if (typeof performSearch === 'function') performSearch();
                    alert(data.message || '<?= $currentLang === "en" ? "Request cancelled." : ($currentLang === "zh" ? "已取消申请。" : "申請を取り消しました。") ?>');
                } else {
                    alert(data.error || '<?= $currentLang === "en" ? "Failed to cancel." : ($currentLang === "zh" ? "取消失败。" : "取り消しに失敗しました。") ?>');
                }
            } catch (err) {
                console.error('Cancel friend request error:', err);
                alert('<?= $currentLang === "en" ? "An error occurred." : ($currentLang === "zh" ? "发生错误。" : "エラーが発生しました。") ?>');
            }
        }
        window.cancelSentFriendRequest = cancelSentFriendRequest;

        async function friendActionFromSearch(action, friendshipId, confirmMsg, successMsg, failMsg) {
            if (!confirm(confirmMsg)) return;
            try {
                const res = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: action, friendship_id: friendshipId })
                });
                const data = await res.json();
                if (data.success) {
                    if (typeof performSearch === 'function') performSearch();
                    alert(data.message || successMsg);
                } else {
                    alert(data.error || failMsg);
                }
            } catch (err) {
                console.error('Friend action error:', err);
                alert('<?= $currentLang === "en" ? "An error occurred." : ($currentLang === "zh" ? "发生错误。" : "エラーが発生しました。") ?>');
            }
        }
        window.acceptFriendFromSearch = function(fid) {
            friendActionFromSearch('accept', fid,
                '<?= $currentLang === "en" ? "Accept this friend request?" : ($currentLang === "zh" ? "确定要接受好友申请吗？" : "この友だち申請を受諾しますか？") ?>',
                '<?= $currentLang === "en" ? "Accepted." : ($currentLang === "zh" ? "已接受。" : "友だち申請を受諾しました。") ?>',
                '<?= $currentLang === "en" ? "Failed." : ($currentLang === "zh" ? "失败。" : "受諾に失敗しました。") ?>'
            );
        };
        window.rejectFriendFromSearch = function(fid) {
            friendActionFromSearch('reject', fid,
                '<?= $currentLang === "en" ? "Reject this friend request?" : ($currentLang === "zh" ? "确定要拒绝好友申请吗？" : "この友だち申請を拒否しますか？") ?>',
                '<?= $currentLang === "en" ? "Rejected." : ($currentLang === "zh" ? "已拒绝。" : "友だち申請を拒否しました。") ?>',
                '<?= $currentLang === "en" ? "Failed." : ($currentLang === "zh" ? "失败。" : "拒否に失敗しました。") ?>'
            );
        };
        window.deferFriendFromSearch = function(fid) {
            friendActionFromSearch('defer', fid,
                '<?= $currentLang === "en" ? "Defer this friend request?" : ($currentLang === "zh" ? "确定要搁置好友申请吗？" : "この友だち申請を保留しますか？") ?>',
                '<?= $currentLang === "en" ? "Deferred." : ($currentLang === "zh" ? "已搁置。" : "友だち申請を保留しました。") ?>',
                '<?= $currentLang === "en" ? "Failed." : ($currentLang === "zh" ? "失败。" : "保留に失敗しました。") ?>'
            );
        };
        
        // ========================================
        // 友達申請モーダル制御（検索結果から呼び出し）
        // ========================================
        window.openFriendRequestModal = function(userId, displayName) {
            const modal = document.getElementById('friendRequestModal');
            if (!modal) return;
            document.getElementById('frModalTargetUserId').value = userId;
            document.getElementById('frModalUserName').textContent = displayName || 'ユーザー';
            const avatarEl = document.getElementById('frModalAvatar');
            if (avatarEl) avatarEl.textContent = (displayName || '?').charAt(0).toUpperCase();
            const msgEl = document.getElementById('frModalMessage');
            if (msgEl) { msgEl.value = ''; }
            const countEl = document.getElementById('frModalCharCount');
            if (countEl) countEl.textContent = '0';
            const sendBtn = document.getElementById('frModalSendBtn');
            if (sendBtn) sendBtn.disabled = false;
            modal.classList.add('active');
            if (msgEl) msgEl.focus();
        };
        window.closeFriendRequestModal = function() {
            const modal = document.getElementById('friendRequestModal');
            if (modal) modal.classList.remove('active');
        };
        // メッセージ文字数カウント
        (function() {
            const msgEl = document.getElementById('frModalMessage');
            const countEl = document.getElementById('frModalCharCount');
            if (msgEl && countEl) {
                msgEl.addEventListener('input', function() {
                    countEl.textContent = this.value.length;
                });
            }
        })();
        // 友達申請送信（モーダルから）
        window.submitFriendRequest = async function() {
            const userId = parseInt(document.getElementById('frModalTargetUserId').value, 10);
            const message = (document.getElementById('frModalMessage').value || '').trim();
            const sendBtn = document.getElementById('frModalSendBtn');
            if (!userId) return;
            if (sendBtn) sendBtn.disabled = true;
            try {
                const res = await fetch('api/friends.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'add', friend_id: userId, message: message, source: 'search' })
                });
                const data = await res.json();
                if (data.success) {
                    closeFriendRequestModal();
                    if (data.status === 'accepted') {
                        alert(data.message || '友だちになりました！');
                    } else {
                        alert(data.message || '友だち申請を送信しました');
                    }
                    // グローバル検索の結果を再表示
                    if (typeof performSearch === 'function') performSearch();
                } else {
                    alert(data.error || '送信に失敗しました');
                    if (sendBtn) sendBtn.disabled = false;
                }
            } catch (err) {
                console.error('Friend request error:', err);
                alert('エラーが発生しました');
                if (sendBtn) sendBtn.disabled = false;
            }
        };

        // openNotifications は notifications.php へ遷移に変更
        // 右パネル折りたたみ（PC: .collapsed で収納 / 携帯: toggleMobileRightPanel でスライド）
        function toggleRightPanel() {
            var isMobile = window.innerWidth <= 768;
            if (isMobile && typeof window.toggleMobileRightPanel === 'function') {
                if (typeof window.playPanelCollapseSound === 'function') window.playPanelCollapseSound();
                window.toggleMobileRightPanel();
                return;
            }
            const rightPanel = document.getElementById('rightPanel');
            if (!rightPanel) return;
            if (typeof window.playPanelCollapseSound === 'function') window.playPanelCollapseSound();
            rightPanel.classList.toggle('collapsed');
            
            // 状態を保存
            var isCollapsed = rightPanel.classList.contains('collapsed');
            try { localStorage.setItem('rightPanelCollapsed', isCollapsed); } catch (e) {}
            
            // 右パネルボタンは常に⇒のまま（⇐は表示しない）
            var toggleBtn = document.getElementById('toggleRightBtn');
            if (toggleBtn) toggleBtn.textContent = '⇒';
        }
        
        // 左パネル折りたたみ
        function toggleLeftPanel() {
            const leftPanel = document.getElementById('leftPanel');
            leftPanel.classList.toggle('collapsed');
            
            // 状態を保存
            localStorage.setItem('leftPanelCollapsed', leftPanel.classList.contains('collapsed'));
        }
        
        // 両方のパネルを同時にトグル（≡ボタン用）
        // 会話一覧の表示切り替え（他 X 件を表示）
        function toggleConversationList() {
            const convList = document.getElementById('conversationList');
            const footer = document.getElementById('convListFooter');
            
            if (!convList || !footer) return;
            
            const isExpanded = convList.classList.toggle('show-all');
            footer.classList.toggle('expanded', isExpanded);
            
            // 表示更新
            updateConversationVisibility();
            
            // フッターテキスト更新
            if (isExpanded) {
                footer.innerHTML = LANG.showLess;
            } else {
                // 表示可能な（フィルタリングされていない）アイテムの数を計算
                let visibleCount = 0;
                document.querySelectorAll('.conv-item').forEach(item => {
                    if (item.dataset.filtered !== '0') visibleCount++;
                });
                const hiddenCount = Math.max(0, visibleCount - 10);
                footer.innerHTML = LANG.showMore.replace('%d', hiddenCount);
            }
            
            // 状態を保存
            localStorage.setItem('convListExpanded', isExpanded);
        }
        
        // ページ読み込み時に会話一覧の状態を復元
        document.addEventListener('DOMContentLoaded', () => {
            const convList = document.getElementById('conversationList');
            const footer = document.getElementById('convListFooter');
            
            // 全ての会話アイテムにdata-filtered属性を初期化
            document.querySelectorAll('.conv-item').forEach(item => {
                item.dataset.filtered = '1';
            });
            
            // 保存された状態を復元
            if (convList && footer && localStorage.getItem('convListExpanded') === 'true') {
                convList.classList.add('show-all');
                footer.classList.add('expanded');
                footer.innerHTML = LANG.showLess;
            }
            
            // 表示を更新
            updateConversationVisibility();
        });
        
        function toggleLeftMenu() {
            const leftPanel = document.getElementById('leftPanel');
            const overlay = document.getElementById('mobileOverlay');
            
            // モバイルかどうかを判定
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile) {
                // モバイル: スライドイン/アウト
                if (typeof window.playPanelCollapseSound === 'function') window.playPanelCollapseSound();
                leftPanel.classList.toggle('mobile-open');
                overlay.classList.toggle('active', leftPanel.classList.contains('mobile-open'));
            } else {
                // PC: 折りたたみ
                if (typeof window.playPanelCollapseSound === 'function') window.playPanelCollapseSound();
                leftPanel.classList.toggle('collapsed');
                const isCollapsed = leftPanel.classList.contains('collapsed');
                localStorage.setItem('leftPanelCollapsed', isCollapsed);
                
                // アイコン更新: 非表示時は⇒（開く）、表示時は⇐（閉じる）
                const toggleBtn = document.getElementById('toggleLeftBtn');
                if (toggleBtn) {
                    toggleBtn.textContent = isCollapsed ? '⇒' : '⇐';
                }
            }
        }
        
        // モバイル用: 右パネルを開く
        function toggleMobileRightPanel() {
            const rightPanel = document.getElementById('rightPanel');
            const overlay = document.getElementById('mobileOverlay');
            
            rightPanel.classList.toggle('mobile-open');
            const isOpen = rightPanel.classList.contains('mobile-open');
            overlay.classList.toggle('active', isOpen);
            overlay.classList.toggle('show', isOpen);
            document.body.classList.toggle('mobile-panel-open', isOpen);
        }
        
        // モバイル用: 右パネルを閉じる
        function closeMobileRightPanel() {
            const rightPanel = document.getElementById('rightPanel');
            const overlay = document.getElementById('mobileOverlay');
            
            rightPanel.classList.remove('mobile-open');
            overlay.classList.remove('active');
            overlay.classList.remove('show');
            document.body.classList.remove('mobile-panel-open');
        }
        
        // モバイル用: すべてのパネルを閉じる
        function closeMobilePanels() {
            const leftPanel = document.getElementById('leftPanel');
            const rightPanel = document.getElementById('rightPanel');
            const overlay = document.getElementById('mobileOverlay');
            
            leftPanel.classList.remove('mobile-open');
            rightPanel.classList.remove('mobile-open');
            overlay.classList.remove('active');
            overlay.classList.remove('show');
            document.body.classList.remove('mobile-panel-open');
        }
        
        // 初期化時にパネルの状態を復元
        document.addEventListener('DOMContentLoaded', () => {
            const isLeftCollapsed = localStorage.getItem('leftPanelCollapsed') === 'true';
            if (isLeftCollapsed) {
                document.getElementById('leftPanel').classList.add('collapsed');
            }
            // 左パネルアイコン初期化: 非表示時は⇒、表示時は⇐
            const toggleLeftBtn = document.getElementById('toggleLeftBtn');
            if (toggleLeftBtn) {
                toggleLeftBtn.textContent = isLeftCollapsed ? '⇒' : '⇐';
            }
            
            const isRightCollapsed = localStorage.getItem('rightPanelCollapsed') === 'true';
            const rightPanelEl = document.getElementById('rightPanel');
            if (rightPanelEl && isRightCollapsed) rightPanelEl.classList.add('collapsed');
            const toggleRightBtn = document.getElementById('toggleRightBtn');
            if (toggleRightBtn) toggleRightBtn.textContent = '⇒';
            
            // メディアデータを読み込み
            loadMediaFromStorage();
            
            // URLハッシュから該当メッセージにスクロール
            scrollToMessageFromHash();
        });
        
        // URLハッシュからメッセージIDを取得してスクロール
        function scrollToMessageFromHash() {
            const hash = window.location.hash;
            if (!hash || !hash.startsWith('#message-')) return;
            // 未読区切りがある場合は未読優先のためハッシュスクロールは行わない（事務局などで古い添付付きメッセージから始まる問題を防ぐ）
            if (document.getElementById('unreadDivider')) return;
            const messageId = hash.replace('#message-', '');
            console.log('Scroll to message ID:', messageId);
            setTimeout(() => {
                scrollToMessage(messageId);
            }, 1000);
        }
        
        // 指定されたメッセージIDへスクロールしてハイライト
        function scrollToMessage(messageId) {
            // data-message-id属性で検索
            let messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
            
            // 見つからない場合はid属性で検索
            if (!messageElement) {
                messageElement = document.getElementById(`message-${messageId}`);
            }
            
            console.log('Found message element:', messageElement);
            
            if (messageElement) {
                // メッセージ表示エリアにスクロール
                messageElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                
                // ハイライトアニメーション
                messageElement.style.transition = 'all 0.3s';
                messageElement.style.boxShadow = '0 0 0 3px rgba(59, 130, 246, 0.6)';
                messageElement.style.background = 'rgba(59, 130, 246, 0.15)';
                
                setTimeout(() => {
                    messageElement.style.boxShadow = '';
                    messageElement.style.background = '';
                }, 2000);
            } else {
                console.warn('Message not found with ID:', messageId);
            }
        }
        
        // ユーザーメニュー（上パネル・左パネル・右パネルのアカウントドロップダウン共通）
        function toggleUserMenu(e) {
            e.stopPropagation();
            var container = e.target.closest('.user-menu-container');
            var dropdown = container ? container.querySelector('.user-dropdown') : document.getElementById('userDropdown');
            if (!dropdown) return;
            document.querySelectorAll('.user-dropdown').forEach(function(d) {
                if (d !== dropdown) d.classList.remove('show');
            });
            dropdown.classList.toggle('show');
            document.getElementById('languageDropdown')?.classList.remove('show');
        }
        
        // 上パネル「もっと見る」メニュー
        function toggleTopbarMoreMenu(e) {
            e.stopPropagation();
            const dropdown = document.getElementById('topbarMoreDropdown');
            if (!dropdown) return;
            const isOpen = dropdown.style.display === 'block';
            dropdown.style.display = isOpen ? 'none' : 'block';
            if (!isOpen) updatePushNotificationStatus();
            closeUserMenu();
            const taskDropdown = document.getElementById('taskDropdown');
            if (taskDropdown) taskDropdown.style.display = 'none';
        }
        function closeTopbarMoreMenu() {
            const dropdown = document.getElementById('topbarMoreDropdown');
            if (dropdown) dropdown.style.display = 'none';
        }
        window.toggleTopbarMoreMenu = toggleTopbarMoreMenu;
        window.closeTopbarMoreMenu = closeTopbarMoreMenu;
        
        // 言語メニュー（もっと見る内で使用）
        function toggleLanguageMenu(e) {
            e.stopPropagation();
            const dropdown = document.getElementById('languageDropdown');
            if (dropdown) dropdown.classList.toggle('show');
            closeUserMenu();
            const taskDropdown = document.getElementById('taskDropdown');
            if (taskDropdown) taskDropdown.style.display = 'none';
            const moreDropdown = document.getElementById('topbarMoreDropdown');
            if (moreDropdown) moreDropdown.style.display = 'none';
        }
        
        // 通知メニュー
        function toggleNotificationMenu(e) {
            e.stopPropagation();
            window.location.href = 'notifications.php';
        }
        
        // プッシュ通知の状態を更新
        function updatePushNotificationStatus() {
            const icon = document.getElementById('pushStatusIcon');
            const text = document.getElementById('pushStatusText');
            const hint = document.getElementById('pushHint');
            
            if (!icon || !text) return;
            const isImgIcon = icon.tagName === 'IMG';
            if (!isImgIcon) {
                if (typeof PushNotifications === 'undefined' || !PushNotifications.isSupported) {
                    icon.textContent = '❌';
                } else if (Notification.permission === 'denied') {
                    icon.textContent = '🚫';
                } else if (PushNotifications.isSubscribed) {
                    icon.textContent = '🔔';
                } else {
                    icon.textContent = '🔕';
                }
            }
            if (typeof PushNotifications === 'undefined' || !PushNotifications.isSupported) {
                text.textContent = 'このブラウザは未対応';
                if (hint) hint.textContent = 'Chrome、Firefox、Edgeをお使いください';
                return;
            }
            if (Notification.permission === 'denied') {
                text.textContent = '通知がブロックされています';
                if (hint) hint.textContent = 'ブラウザの設定から許可してください';
                return;
            }
            if (PushNotifications.isSubscribed) {
                text.textContent = 'プッシュ通知は有効です';
                if (hint) hint.textContent = 'クリックで無効にできます';
            } else {
                text.textContent = 'プッシュ通知を有効にする';
                if (hint) hint.textContent = 'ブラウザを閉じていても通知を受け取れます';
            }
        }
        
        // プッシュ通知のトグル
        async function togglePushNotification() {
            if (typeof PushNotifications === 'undefined' || !PushNotifications.isSupported) {
                alert('このブラウザではプッシュ通知がサポートされていません');
                return;
            }
            
            if (Notification.permission === 'denied') {
                alert('通知がブロックされています。ブラウザの設定から許可してください。');
                return;
            }
            
            if (PushNotifications.isSubscribed) {
                // 無効にする
                if (confirm('プッシュ通知を無効にしますか？')) {
                    await PushNotifications.unsubscribe();
                    showToastNotification('プッシュ通知を無効にしました', 'info');
                }
            } else {
                // 有効にする
                const permission = await PushNotifications.requestPermission();
                if (permission === 'granted') {
                    showToastNotification('プッシュ通知を有効にしました！', 'success');
                } else if (permission === 'denied') {
                    showToastNotification('通知が拒否されました', 'error');
                }
            }
            
            updatePushNotificationStatus();
            
            // ドロップダウンを閉じる
            const dropdown = document.getElementById('notificationDropdown');
            if (dropdown) dropdown.style.display = 'none';
        }
        
        // グローバルに公開
        window.toggleNotificationMenu = toggleNotificationMenu;
        window.togglePushNotification = togglePushNotification;
        window.updatePushNotificationStatus = updatePushNotificationStatus;
        
        // アプリメニュー（上パネルでアプリ非表示の場合は要素なし）
        function toggleAppMenu(e) {
            e.stopPropagation();
            const dropdown = document.getElementById('appDropdown');
            if (!dropdown) return;
            if (dropdown.style.display === 'none' || dropdown.style.display === '') {
                dropdown.style.display = 'block';
            } else {
                dropdown.style.display = 'none';
            }
            // 他のメニューを閉じる
            closeUserMenu();
            document.getElementById('languageDropdown')?.classList.remove('show');
            document.getElementById('taskDropdown')?.style.setProperty('display', 'none');
        }
        
        // タスクメニュー
        function toggleTaskMenu(e) {
            e.stopPropagation();
            window.location.href = 'tasks.php';
        }
        
        // タスク・メモドロップダウンを読み込み
        async function loadTaskDropdown() {
            const list = document.getElementById('taskDropdownList');
            const memoList = document.getElementById('memoDropdownList');
            
            if (list) {
                list.innerHTML = '<div class="task-dropdown-loading">読み込み中...</div>';
                try {
                    const response = await fetch('api/tasks.php?action=list&limit=10&my_tasks_only=1&type=task');
                    const data = await response.json();
                    
                    if (!data.success || !data.tasks || data.tasks.length === 0) {
                        list.innerHTML = '<div class="task-dropdown-empty">📋 タスクがありません<br><a href="tasks.php" style="color:var(--primary);font-size:12px;">タスクを追加する</a></div>';
                    } else {
                        const currentUserId = <?= $user_id ?>;
                        let html = '';
                        data.tasks.forEach(task => {
                            const isCompleted = task.status === 'completed';
                            const isFromOther = parseInt(task.created_by) !== currentUserId;
                            const isOverdue = task.due_date && new Date(task.due_date) < new Date() && !isCompleted;
                            html += `
                                <div class="task-dropdown-item ${isCompleted ? 'completed' : ''}" data-task-id="${task.id}">
                                    <div class="task-dropdown-checkbox ${isCompleted ? 'checked' : ''}" onclick="toggleTaskFromDropdown(${task.id}, event)">${isCompleted ? '✓' : ''}</div>
                                    <div class="task-dropdown-content">
                                        <div class="task-dropdown-title">${escapeHtml(task.title)}</div>
                                        <div class="task-dropdown-meta">
                                            ${isFromOther ? `<span class="task-dropdown-requester">📩 ${escapeHtml(task.creator_name || '依頼者')}</span>` : ''}
                                            ${task.due_date ? `<span class="task-dropdown-due ${isOverdue ? 'overdue' : ''}">📅 ${formatDate(task.due_date)}${isOverdue ? ' 期限切れ' : ''}</span>` : ''}
                                        </div>
                                    </div>
                                    ${!isCompleted ? `<button class="task-dropdown-complete-btn" onclick="completeTaskFromDropdown(${task.id}, event)">完了</button>` : ''}
                                </div>`;
                        });
                        list.innerHTML = html;
                    }
                } catch (error) {
                    console.error('タスク取得エラー:', error);
                    list.innerHTML = '<div class="task-dropdown-empty">読み込みに失敗しました</div>';
                }
            }
            
            if (memoList) {
                memoList.innerHTML = '<div class="task-dropdown-loading">読み込み中...</div>';
                try {
                    const response = await fetch('api/tasks.php?action=list&limit=5&type=memo');
                    const data = await response.json();
                    
                    if (!data.success || !data.tasks || data.tasks.length === 0) {
                        memoList.innerHTML = '<div class="task-dropdown-empty">📝 メモがありません</div>';
                    } else {
                        let html = '';
                        data.tasks.forEach(memo => {
                            const title = escapeHtml(memo.title || '');
                            const preview = escapeHtml((memo.content || '').substring(0, 60));
                            const color = memo.color || '#ffffff';
                            const isPinned = (memo.is_pinned == 1 || memo.is_pinned === '1');
                            html += `
                                <a href="tasks.php?tab=memos" class="task-dropdown-item memo-item" style="border-left:3px solid ${color};text-decoration:none;color:inherit;">
                                    <div class="task-dropdown-content">
                                        <div class="task-dropdown-title">${isPinned ? '📌 ' : ''}${title}</div>
                                        ${preview ? `<div class="task-dropdown-meta">${preview}</div>` : ''}
                                    </div>
                                </a>`;
                        });
                        memoList.innerHTML = html;
                    }
                } catch (error) {
                    console.error('メモ取得エラー:', error);
                    memoList.innerHTML = '<div class="task-dropdown-empty">読み込みに失敗しました</div>';
                }
            }
        }
        
        // ドロップダウンからタスクを完了
        async function completeTaskFromDropdown(taskId, e) {
            e.stopPropagation();
            
            try {
                const response = await fetch('api/tasks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'complete', task_id: taskId })
                });
                const data = await response.json();
                
                if (data.success) {
                    // UIを即座に更新
                    const item = document.querySelector(`.task-dropdown-item[data-task-id="${taskId}"]`);
                    if (item) {
                        item.classList.add('completed');
                        const checkbox = item.querySelector('.task-dropdown-checkbox');
                        if (checkbox) {
                            checkbox.classList.add('checked');
                            checkbox.textContent = '✓';
                        }
                        const btn = item.querySelector('.task-dropdown-complete-btn');
                        if (btn) btn.remove();
                    }
                    
                    if (typeof updateTaskBadge === 'function') updateTaskBadge();
                    
                    if (data.notified) {
                        if (typeof showToastNotification === 'function') showToastNotification('タスクを完了しました（依頼者に通知しました）');
                    } else {
                        if (typeof showToastNotification === 'function') showToastNotification('タスクを完了しました');
                    }
                } else {
                    alert(data.message || 'タスクの完了に失敗しました');
                }
            } catch (error) {
                console.error('タスク完了エラー:', error);
            }
        }
        
        // ドロップダウンからタスクをトグル
        async function toggleTaskFromDropdown(taskId, e) {
            e.stopPropagation();
            
            try {
                const response = await fetch('api/tasks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'toggle', task_id: taskId })
                });
                const data = await response.json();
                
                if (data.success) {
                    loadTaskDropdown();
                    if (typeof updateTaskBadge === 'function') updateTaskBadge();
                    if (data.status === 'completed' && data.notified && typeof showToastNotification === 'function') {
                        showToastNotification('タスクを完了しました（依頼者に通知しました）');
                    }
                } else {
                    alert(data.message || 'タスクの更新に失敗しました');
                }
            } catch (error) {
                console.error('タスクトグルエラー:', error);
            }
        }
        
        // 日付フォーマット
        function formatDate(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            return `${date.getMonth() + 1}/${date.getDate()}`;
        }
        
        // トースト通知を表示
        function showToastNotification(message) {
            const existing = document.querySelector('.toast-notification');
            if (existing) existing.remove();
            
            const toast = document.createElement('div');
            toast.className = 'toast-notification';
            toast.textContent = message;
            toast.style.cssText = `
                position: fixed;
                bottom: 80px;
                left: 50%;
                transform: translateX(-50%);
                background: rgba(0, 0, 0, 0.85);
                color: white;
                padding: 12px 24px;
                border-radius: 8px;
                font-size: 14px;
                z-index: 9999;
                animation: fadeInOut 3s ease-in-out;
            `;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }
        
        // アプリ通知をチェック（Guild工事中はスキップ）
        async function checkAppNotifications() {
            try {
                const response = await fetch('Guild/api/notifications.php?action=count', {
                    credentials: 'include'
                });
                if (response.ok) {
                    const data = await response.json();
                    if (data.success && data.count > 0) {
                        const badge = document.getElementById('guildBadge');
                        const appBadge = document.getElementById('appBadge');
                        if (badge) {
                            badge.textContent = data.count > 99 ? '99+' : data.count;
                            badge.style.display = 'flex';
                        }
                        if (appBadge) {
                            appBadge.textContent = data.count > 99 ? '99+' : data.count;
                            appBadge.style.display = 'flex';
                        }
                    }
                }
            } catch (e) {
                // Guildがアクセスできない場合は無視
            }
        }
        
        // 初期化時にアプリ通知をチェック（Guild工事中はスキップ）
        // setTimeout(checkAppNotifications, 2000);
        
        // 言語変更
        async function changeLanguage(lang) {
            try {
                const response = await fetch('api/language.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ language: lang })
                });
                const raw = await response.text();
                let data = {};
                try { data = raw ? JSON.parse(raw) : {}; } catch (_) {}
                if (data.success) {
                    document.getElementById('languageDropdown')?.classList.remove('show');
                    location.reload();
                } else {
                    alert(data.error || '<?= $currentLang === 'en' ? 'Failed to change language' : ($currentLang === 'zh' ? '切换语言失败' : '言語の変更に失敗しました') ?>');
                }
            } catch (e) {
                console.error('Language change error:', e);
                alert('<?= $currentLang === 'en' ? 'Error occurred' : ($currentLang === 'zh' ? '发生错误' : 'エラーが発生しました') ?>');
            }
        }
        
        // クリックで言語メニュー・アプリメニューを閉じる
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.language-selector')) {
                document.getElementById('languageDropdown')?.classList.remove('show');
            }
            if (!e.target.closest('.app-menu-container')) {
                const appDropdown = document.getElementById('appDropdown');
                if (appDropdown) appDropdown.style.display = 'none';
            }
        });
        
        // ログアウト
        async function logout() {
            if (!confirm('ログアウトしますか？')) return;
            
            try {
                const response = await fetch('api/auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'logout' })
                });
                const data = await response.json();
                if (data.success) {
                    location.href = 'index.php';
                } else {
                    alert(data.message || 'ログアウトに失敗しました');
                }
            } catch (e) {
                location.href = 'api/auth.php?action=logout';
            }
        }
        
        // アカウント切り替え
        function switchAccount() {
            if (!confirm('現在のアカウントからログアウトして、別のアカウントでログインしますか？')) return;
            
            fetch('api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'logout' })
            }).then(() => {
                location.href = 'index.php?switch=1';
            }).catch(() => {
                location.href = 'api/auth.php?action=logout';
            });
        }
        
        // 友達リストをAPIから読み込んで表示
        const friendColors = ['avatar-grey'];
        async function loadFriendsList() {
            const container = document.getElementById('friendsListContent');
            if (!container) return;
            container.innerHTML = '<div class="friends-loading" style="padding:24px;text-align:center;color:var(--text-muted);font-size:14px;">読み込み中...</div>';
            try {
                const res = await fetch('api/friends.php?action=group_members');
                const data = await res.json();
                if (data.success && data.members && data.members.length > 0) {
                    container.innerHTML = data.members.map((member, idx) => {
                        const fc = friendColors[idx % friendColors.length];
                        const initial = (member.display_name || '?').charAt(0);
                        const nameEsc = escapeHtml(member.display_name || '');
                        const groupsEsc = escapeHtml(member.group_names || '');
                        const avatarHtml = member.avatar_path
                            ? '<img src="' + escapeHtml(member.avatar_path) + '" alt="" class="conv-icon-img">'
                            : escapeHtml(initial);
                        return '<div class="conv-item friend-item" data-type="dm" data-filter-type="dm" data-user-id="' + member.id + '" onclick="startDmWithUser(' + member.id + ',' + JSON.stringify(member.display_name || '') + ')">' +
                            '<div class="conv-avatar ' + fc + '">' + avatarHtml + '</div>' +
                            '<div class="conv-info"><div class="conv-name" title="' + groupsEsc + '"><span class="conv-name-text">' + nameEsc + '</span></div></div></div>';
                    }).join('');
                } else {
                    container.innerHTML = '<div class="friends-empty" style="padding:24px;text-align:center;color:var(--text-muted);font-size:14px;"><?= $currentLang === "en" ? "No group members found. Enable \"Allow Member DM\" in group settings." : ($currentLang === "zh" ? "未找到群组成员。请在群组设置中启用“允许成员私信”。" : "グループに所属すると、メンバーがここに表示されます。「メンバー間DMを許可」がONのグループのメンバーが表示されます。") ?></div>';
                }
            } catch (err) {
                console.error('Friends list load error:', err);
                container.innerHTML = '<div class="friends-empty" style="padding:24px;text-align:center;color:var(--text-muted);font-size:14px;">読み込みに失敗しました</div>';
            }
        }
        
        // 左パネルフィルタ（単一選択: すべて/未読/グループ/友達/組織のいずれか1つのみ）
        window.currentLeftPanelFilter = 'all';
        
        // 会話リストにフィルタを適用（filter: all | unread | group | dm | org-5）
        function applyLeftPanelFilter(filter) {
            const convList = document.getElementById('conversationList');
            const friendsList = document.getElementById('friendsList');
            const footer = document.getElementById('convListFooter');
            if (!convList) return;
            
            const isOrgFilter = typeof filter === 'string' && filter.startsWith('org-');
            const orgId = isOrgFilter ? filter.replace('org-', '') : '';
            
            if (filter === 'dm') {
                    convList.style.display = '';
                    convList.classList.remove('show-all');
                    convList.querySelectorAll('.conv-item').forEach(item => {
                        const ft = item.dataset.filterType || item.dataset.type;
                    item.dataset.filtered = (ft === 'dm' || ft === 'ai') ? '1' : '0';
                    });
                    updateConversationVisibility();
                if (friendsList) friendsList.style.display = '';
                if (footer) footer.style.display = 'none';
                return;
            }
            
            convList.style.display = '';
            if (friendsList) friendsList.style.display = 'none';
            /* 会話リストの「他○件を表示」展開状態は維持（ポーリングで上書きしない） */
            const wasExpanded = localStorage.getItem('convListExpanded') === 'true';
            if (!wasExpanded) {
                convList.classList.remove('show-all');
                if (footer) footer.classList.remove('expanded');
            }
            
            let visibleCount = 0;
            convList.querySelectorAll('.conv-item').forEach(item => {
                let show = false;
                if (filter === 'all') {
                    show = true;
                } else if (filter === 'unread') {
                    show = (parseInt(item.dataset.unread || 0, 10) > 0);
                } else if (filter === 'group') {
                    show = (item.dataset.filterType || item.dataset.type) === 'group';
                } else if (isOrgFilter && orgId) {
                    // 組織選択時: その組織のグループのみ表示（DM・AIは表示しない＝Sayoko/Bunta等は出ない）
                    const itemOrgId = (item.dataset.organizationId !== undefined && item.dataset.organizationId !== null) ? String(item.dataset.organizationId) : '';
                    const ft = item.dataset.filterType || item.dataset.type;
                    show = (ft === 'group' && itemOrgId === orgId);
                }
                item.dataset.filtered = show ? '1' : '0';
                if (show) {
                    item.classList.remove('conv-item-filtered-out');
                } else {
                    item.classList.add('conv-item-filtered-out');
                }
                if (show) visibleCount++;
            });
            
            const isMobile = window.innerWidth <= 768;
            if (footer) {
                if (isMobile) {
                    footer.style.display = 'none';
                } else {
                    const hiddenCount = Math.max(0, visibleCount - 10);
                    if (hiddenCount > 0) {
                        footer.style.display = '';
                        if (wasExpanded) {
                            convList.classList.add('show-all');
                            footer.classList.add('expanded');
                            footer.innerHTML = LANG.showLess;
                        } else {
                            footer.innerHTML = LANG.showMore.replace('%d', hiddenCount);
                        }
                    } else {
                        footer.style.display = 'none';
                    }
                }
            }
            updateConversationVisibility();
        }
        window.applyLeftPanelFilter = applyLeftPanelFilter;
        window.applyConvListTabFilter = function(tab) {
            if (tab === 'dm' || tab === 'all' || tab === 'unread' || tab === 'group') {
                window.currentLeftPanelFilter = tab;
                applyLeftPanelFilter(tab);
            }
        };
        
        // 左パネル フィルターUI（単一選択・どれか1つだけ）
        (function initLeftPanelFilter() {
            const trigger = document.getElementById('leftPanelFilterTrigger');
            const dropdown = document.getElementById('leftPanelFilterDropdown');
            const labelEl = document.getElementById('leftPanelFilterLabel');
            const tabLabels = { all: LANG.all || 'すべて', unread: LANG.unread || '未読', group: LANG.group || 'グループ', dm: (typeof LANG !== 'undefined' && LANG.filter_friends) ? LANG.filter_friends : '友達' };
            const allOptions = document.querySelectorAll('#leftPanelFilterDropdown .left-panel-filter-option');
            
            function getFilterLabel(filter) {
                if (tabLabels[filter]) return tabLabels[filter];
                const btn = document.querySelector('.left-panel-filter-option[data-filter="' + filter + '"]');
                return btn ? btn.textContent.trim() : filter;
            }
            function closeDropdown() {
                if (dropdown) { dropdown.style.display = 'none'; dropdown.setAttribute('aria-hidden', 'true'); }
                if (trigger) trigger.setAttribute('aria-expanded', 'false');
            }
            function updateSelection() {
                const filter = window.currentLeftPanelFilter || 'all';
                allOptions.forEach(btn => {
                    const isSelected = (btn.dataset.filter === filter);
                    btn.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                    btn.classList.toggle('active', !!isSelected);
                });
            }
            
            if (trigger && dropdown) {
                trigger.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isOpen = dropdown.style.display !== 'none';
                    if (isOpen) {
                        closeDropdown();
                    } else {
                        dropdown.style.display = 'block';
                        dropdown.setAttribute('aria-hidden', 'false');
                        trigger.setAttribute('aria-expanded', 'true');
                        updateSelection();
                    }
                });
                document.addEventListener('click', function(e) {
                    if (dropdown && dropdown.style.display !== 'none' && !dropdown.contains(e.target) && !trigger.contains(e.target)) {
                        closeDropdown();
                    }
                });
            }
            allOptions.forEach(btn => {
            btn.addEventListener('click', function() {
                    const filter = this.dataset.filter;
                    if (!filter) return;
                    window.currentLeftPanelFilter = filter;
                    allOptions.forEach(b => { b.classList.remove('active'); b.setAttribute('aria-selected', 'false'); });
                this.classList.add('active');
                    this.setAttribute('aria-selected', 'true');
                    if (labelEl) labelEl.textContent = this.textContent.trim();
                    if (trigger) trigger.setAttribute('aria-label', this.textContent.trim());
                    closeDropdown();
                    applyLeftPanelFilter(filter);
                    if (filter === 'dm' && typeof loadFriendsList === 'function') loadFriendsList();
            });
        });
            if (labelEl) labelEl.textContent = getFilterLabel(window.currentLeftPanelFilter || 'all');
            updateSelection();
            // 初期表示・携帯で確実にフィルタ状態を反映（dataset と conv-item-filtered-out をセット）
            applyLeftPanelFilter(window.currentLeftPanelFilter || 'all');
        })();
        
        // 会話の表示状態を更新（会話リスト内のconv-itemのみ対象）
        function updateConversationVisibility() {
            const convList = document.getElementById('conversationList');
            if (!convList) return;
            const isShowAll = convList.classList.contains('show-all');
            const isMobile = window.innerWidth <= 768;
            let visibleIndex = 0;
            
            convList.querySelectorAll('.conv-item').forEach(item => {
                const isFiltered = item.dataset.filtered !== '0';
                if (!isFiltered) {
                    item.classList.add('conv-item-filtered-out');
                    item.style.display = 'none';
                } else {
                    item.classList.remove('conv-item-filtered-out');
                    if (isMobile || isShowAll || visibleIndex < 10) {
                    item.style.display = '';
                    visibleIndex++;
                } else {
                    item.style.display = 'none';
                    }
                }
            });
            
            const footer = document.getElementById('convListFooter');
            if (footer) {
                footer.style.display = isMobile ? 'none' : '';
            }
        }
        
        // 会話作成
        function switchConversationType(type) {
            conversationType = type;
            document.querySelectorAll('#newConversationModal .conv-type-tabs__btn').forEach((b, i) => {
                const isActive = (type === 'dm' && i === 0) || (type === 'group' && i === 1);
                b.classList.toggle('active', isActive);
                b.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            document.getElementById('dmForm').style.display = type === 'dm' ? 'block' : 'none';
            document.getElementById('groupForm').style.display = type === 'group' ? 'block' : 'none';
        }
        
        function selectUser(el) {
            document.querySelectorAll('#userList .user-item').forEach(i => i.classList.remove('selected'));
            el.classList.add('selected');
            selectedUsers = [parseInt(el.dataset.userId)];
        }
        
        function toggleGroupMember(el) {
            el.classList.toggle('selected');
            const uid = parseInt(el.dataset.userId);
            if (el.classList.contains('selected')) selectedUsers.push(uid);
            else selectedUsers = selectedUsers.filter(id => id !== uid);
        }
        
        function filterUsers() {
            const kw = document.getElementById('userSearch').value.toLowerCase();
            document.querySelectorAll('#userList .user-item').forEach(item => {
                item.style.display = item.dataset.name.toLowerCase().includes(kw) ? '' : 'none';
            });
        }
        
        function filterGroupMembers() {
            const el = document.getElementById('groupMemberSearch');
            if (!el) return;
            const kw = el.value.trim().toLowerCase();
            document.querySelectorAll('#groupUserList .user-item').forEach(item => {
                const name = (item.dataset.name || '').toLowerCase();
                item.style.display = !kw || name.includes(kw) ? '' : 'none';
            });
        }
        
        async function createConversation() {
            if (selectedUsers.length === 0) { alert('<?= $currentLang === 'en' ? 'Please select a user' : ($currentLang === 'zh' ? '请选择用户' : 'ユーザーを選択してください') ?>'); return; }
            const payload = { action: 'create', type: conversationType, member_ids: selectedUsers };
            if (conversationType === 'group') {
                const name = document.getElementById('groupName').value.trim();
                const nameEn = document.getElementById('groupNameEn').value.trim();
                const nameZh = document.getElementById('groupNameZh').value.trim();
                if (!name) { alert('<?= $currentLang === 'en' ? 'Please enter a group name' : ($currentLang === 'zh' ? '请输入群组名称' : 'グループ名を入力してください') ?>'); return; }
                payload.name = name;
                payload.name_en = nameEn || null;
                payload.name_zh = nameZh || null;
                const orgEl = document.getElementById('newConversationOrganizationId');
                if (orgEl && orgEl.value !== '') payload.organization_id = parseInt(orgEl.value, 10);
            }
            try {
                const response = await fetch('api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) location.href = '?c=' + data.conversation_id;
                else alert(data.message || 'エラー');
            } catch (e) { alert('<?= $currentLang === 'en' ? 'Creation failed' : ($currentLang === 'zh' ? '创建失败' : '作成失敗') ?>'); }
        }
        
        // 検索
        let searchTimeout = null;
        let currentSearchFilter = 'all';
        
        // 検索履歴を読み込み
        function getSearchHistory() {
            const history = localStorage.getItem('searchHistory');
            return history ? JSON.parse(history) : [];
        }
        
        // 検索履歴を保存
        function saveSearchHistory(keyword) {
            let history = getSearchHistory();
            // 重複を除去して先頭に追加
            history = history.filter(h => h !== keyword);
            history.unshift(keyword);
            // 最大10件まで保存
            history = history.slice(0, 10);
            localStorage.setItem('searchHistory', JSON.stringify(history));
        }
        
        // 検索履歴を表示
        function showSearchHistory() {
            const history = getSearchHistory();
            document.getElementById('searchSectionTitle').textContent = LANG.recentSearch;
            if (history.length === 0) {
                document.getElementById('searchResults').innerHTML = `
                    <div class="search-empty">
                        <div style="font-size:40px;margin-bottom:10px;">🔍</div>
                        <div>${LANG.noSearchHistory}</div>
                        <div style="font-size:12px;margin-top:8px;">${LANG.searchHint}</div>
                    </div>
                `;
            } else {
                document.getElementById('searchResults').innerHTML = history.map(h => `
                    <div class="search-result-item" onclick="searchFromHistory('${h.replace(/'/g, "\\'")}')">
                        <div class="result-icon" style="background:#f3f4f6;">🕐</div>
                        <div class="result-content">
                            <div class="result-title">${h}</div>
                        </div>
                    </div>
                `).join('');
            }
        }
        
        // 履歴から検索
        function searchFromHistory(keyword) {
            document.getElementById('searchInput').value = keyword;
            performSearch();
        }
        
        // フィルター設定
        function setSearchFilter(filter, btn) {
            currentSearchFilter = filter;
            document.querySelectorAll('.search-filter-tabs button').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            performSearch();
        }
        
        // 検索結果の保持（絞り込み用）
        var lastSearchResults = [];
        var lastSearchUniqueGroups = [];
        
        function escapeForSearchResult(str) {
            if (str == null) return '';
            return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
        
        function renderSearchResultsList(results, opts) {
            opts = opts || {};
            var filterConvId = opts.conversationId != null ? String(opts.conversationId) : '';
            var filterWord = (opts.filterWord || '').trim().toLowerCase();
            var list = results;
            if (filterConvId) list = list.filter(function(x) { return (x.conversation_id != null && String(x.conversation_id) === filterConvId) || (x.result_type === 'group' && String(x.id) === filterConvId); });
            if (filterWord) list = list.filter(function(x) {
                var text = ((x.content || '') + ' ' + (x.sender_name || '') + ' ' + (x.name || '') + ' ' + (x.conversation_name || '')).toLowerCase();
                return text.indexOf(filterWord) !== -1;
            });
            if (list.length === 0) return '<div class="search-empty">' + (window.LANG && window.LANG.searchNoMatch ? LANG.searchNoMatch : '該当なし') + '</div>';
            return list.map(function(x) {
                var type = x.result_type || 'message';
                var icon = type === 'user' ? '👤' : type === 'group' ? '👥' : '💬';
                var iconClass = type === 'user' ? 'user' : type === 'group' ? 'group' : 'message';
                var title = x.sender_name || x.name || 'Unknown';
                var subtitle = x.content || x.description || '';
                var convName = x.conversation_name || x.name || '';
                var safeTitle = escapeForSearchResult(title);
                var convId = x.conversation_id != null ? x.conversation_id : x.id;
                var isMember = (x.is_member == 1 || x.is_member === '1');
                var groupLabel = (type === 'message' && convName) ? convName : (type === 'group' ? convName : '');
                var groupLine = groupLabel ? '<div class="result-group">' + escapeHtml(groupLabel) + '</div>' : '';

                var actionHtml = '';
                var dataAttrs = 'data-result-type="' + escapeHtml(type) + '" data-conversation-id="' + escapeHtml(String(convId)) + '"';
                if (type === 'user') {
                    dataAttrs += ' data-user-id="' + escapeHtml(String(x.id)) + '" data-user-title="' + escapeHtml(safeTitle) + '"';
                    var isFriend = (x.is_friend == 1 || x.is_friend === '1');
                    var isPending = (x.is_pending == 1 || x.is_pending === '1');
                    var sentByMe = (x.sent_by_me == 1 || x.sent_by_me === '1');
                    if (isFriend) {
                        dataAttrs += ' data-action="dm"';
                        actionHtml = '<span class="search-result-action-btn search-result-dm-btn" title="DM開始">💬</span>';
                    } else if (isPending && sentByMe) {
                        dataAttrs += ' data-action="cancel-request"';
                        actionHtml = '<span class="search-result-action-btn search-result-pending-btn" title="申請取り消し">⏳ 申請中</span>';
                    } else if (isPending && !sentByMe) {
                        var fshipId = x.friendship_id || '';
                        dataAttrs += ' data-action="none" data-friendship-id="' + fshipId + '"';
                        actionHtml = '<span class="search-result-friend-actions">'
                            + '<span class="search-result-action-label">友達申請</span>'
                            + '<button type="button" class="sr-action-btn sr-accept" onclick="event.stopPropagation();acceptFriendFromSearch(' + fshipId + ')" title="受諾">受諾</button>'
                            + '<button type="button" class="sr-action-btn sr-reject" onclick="event.stopPropagation();rejectFriendFromSearch(' + fshipId + ')" title="拒否">拒否</button>'
                            + '<button type="button" class="sr-action-btn sr-defer" onclick="event.stopPropagation();deferFriendFromSearch(' + fshipId + ')" title="保留">保留</button>'
                            + '</span>';
                    } else {
                        dataAttrs += ' data-action="friend-request"';
                        actionHtml = '<span class="search-result-action-btn search-result-add-btn" title="友達申請">👋 友達申請</span>';
                    }
                } else if (type === 'message') {
                    var msgId = (x.id != null && x.id !== '') ? String(x.id) : '';
                    if (msgId) dataAttrs += ' data-message-id="' + escapeHtml(msgId) + '"';
                    dataAttrs += ' data-action="open-message"';
                } else {
                    dataAttrs += ' data-is-member="' + (isMember ? '1' : '0') + '" data-action="open-group"';
                }
                var subShort = subtitle.substring(0, 80) + (subtitle.length > 80 ? '...' : '');
                return '<div class="search-result-item" role="button" tabindex="0" ' + dataAttrs + '>' +
                    '<div class="result-icon ' + iconClass + '">' + icon + '</div>' +
                    '<div class="result-content">' +
                    '<div class="result-title">' + escapeHtml(title) + '</div>' +
                    (groupLine ? groupLine : '') +
                    '<div class="result-subtitle">' + escapeHtml(subShort) + '</div>' +
                    '</div>' + actionHtml + '</div>';
            }).join('');
        }
        
        function applySearchRefine() {
            var groupEl = document.getElementById('searchRefineGroup');
            var wordEl = document.getElementById('searchRefineWord');
            var convId = groupEl && groupEl.value ? groupEl.value : '';
            var word = wordEl && wordEl.value ? wordEl.value.trim() : '';
            document.getElementById('searchResults').innerHTML = renderSearchResultsList(lastSearchResults, { conversationId: convId || null, filterWord: word });
        }
        window.applySearchRefine = applySearchRefine;
        // 検索結果のクリックを委譲で処理（インラインonclickの不具合を避ける）
        function setupSearchResultClick() {
            var container = document.getElementById('searchResults');
            if (!container) return;
            container.removeEventListener('click', container._searchResultClickHandler);
            container._searchResultClickHandler = function(e) {
                var item = e.target.closest('.search-result-item');
                if (!item) return;
                var action = item.getAttribute('data-action');
                if (!action || action === 'none') return;
                e.preventDefault();
                e.stopPropagation();
                var type = item.getAttribute('data-result-type');
                var convId = item.getAttribute('data-conversation-id') || '';
                if (type === 'user') {
                    var userId = item.getAttribute('data-user-id');
                    var title = (item.getAttribute('data-user-title') || '').replace(/&quot;/g, '"');
                    if (action === 'dm' && userId && typeof startDmFromSearch === 'function') startDmFromSearch(userId, title);
                    else if (action === 'cancel-request' && userId && typeof cancelSentFriendRequest === 'function') cancelSentFriendRequest(parseInt(userId, 10));
                    else if (action === 'accept-request' && userId) {
                        var fshipId = item.getAttribute('data-friendship-id');
                        if (fshipId && typeof acceptFriendFromSearch === 'function') acceptFriendFromSearch(parseInt(fshipId, 10));
                    }
                    else if (action === 'friend-request' && userId && typeof openFriendRequestModal === 'function') openFriendRequestModal(userId, title);
                } else if (type === 'message') {
                    var msgId = item.getAttribute('data-message-id') || '';
                    if (typeof openMessageFromSearch === 'function') openMessageFromSearch(convId, msgId || undefined);
                } else {
                    var isMember = item.getAttribute('data-is-member') === '1';
                    if (typeof openGroupFromSearch === 'function') openGroupFromSearch(convId, isMember);
                }
            };
            container.addEventListener('click', container._searchResultClickHandler);
            container.addEventListener('keydown', function(e) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                var item = e.target.closest('.search-result-item');
                if (!item) return;
                e.preventDefault();
                item.click();
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', setupSearchResultClick);
        } else {
            setupSearchResultClick();
        }
        
        function performSearch() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(async function() {
                var kw = document.getElementById('searchInput').value.trim();
                if (kw.length < 2) {
                    showSearchHistory();
                    document.getElementById('searchRefineBar').style.display = 'none';
                    return;
                }
                saveSearchHistory(kw);
                document.getElementById('searchSectionTitle').textContent = LANG.searchResults;
                try {
                    document.getElementById('searchResults').innerHTML = '<div class="search-loading">🔍 ' + LANG.searching + '</div>';
                    var r = await fetch('api/messages.php?action=search&keyword=' + encodeURIComponent(kw) + '&type=' + currentSearchFilter + '&limit=50');
                    var d = await r.json();
                    if (d.success && d.results && d.results.length > 0) {
                        lastSearchResults = d.results;
                        var messageResults = d.results.filter(function(x) { return (x.result_type || 'message') === 'message'; });
                        var uniqueGroups = [];
                        var seen = {};
                        messageResults.forEach(function(x) {
                            var cid = x.conversation_id;
                            var cname = x.conversation_name || '';
                            if (cid != null && cname && !seen[cid]) { seen[cid] = true; uniqueGroups.push({ id: cid, name: cname }); }
                        });
                        lastSearchUniqueGroups = uniqueGroups;
                        var refineBar = document.getElementById('searchRefineBar');
                        var groupSelect = document.getElementById('searchRefineGroup');
                        var wordInput = document.getElementById('searchRefineWord');
                        if (refineBar && groupSelect && wordInput) {
                            if (uniqueGroups.length >= 2 || d.results.length >= 8) {
                                refineBar.style.display = 'flex';
                                groupSelect.innerHTML = '<option value="">' + (LANG.searchAll || 'すべて') + '</option>' +
                                    uniqueGroups.map(function(g) { return '<option value="' + escapeHtml(String(g.id)) + '">' + escapeHtml(g.name) + '</option>'; }).join('');
                                wordInput.value = '';
                                } else {
                                refineBar.style.display = 'none';
                            }
                        }
                        document.getElementById('searchResults').innerHTML = renderSearchResultsList(lastSearchResults, {});
                    } else {
                        lastSearchResults = [];
                        document.getElementById('searchRefineBar').style.display = 'none';
                        document.getElementById('searchResults').innerHTML = '<div class="search-empty">見つかりませんでした</div>';
                    }
                } catch (e) {
                    document.getElementById('searchRefineBar').style.display = 'none';
                    document.getElementById('searchResults').innerHTML = '<div class="search-empty">検索中にエラーが発生しました</div>';
                }
            }, 300);
        }
        
        // 検索モーダルを開く時：トップバー入力値をモーダルに同期し、空なら履歴表示
        function openSearch() {
            const topBar = document.getElementById('topBarSearchInput');
            const modalInput = document.getElementById('searchInput');
            if (topBar && modalInput) {
                modalInput.value = topBar.value.trim();
            }
            openModal('searchModal');
            if (modalInput) {
                modalInput.focus();
                if (!modalInput.value.trim()) showSearchHistory();
            }
        }
        // 上パネル検索バーをクリックしたときは入力にフォーカスするだけ（ポップアップは開かない）
        function focusTopBarSearch() {
            const el = document.getElementById('topBarSearchInput');
            if (el) el.focus();
        }
        // トップバー検索でEnter押下時のみ：モーダルを開いて検索結果を表示
        (function initTopBarSearch() {
            const topBar = document.getElementById('topBarSearchInput');
            if (!topBar) return;
            topBar.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    openSearch();
                    var modalInput = document.getElementById('searchInput');
                    if (modalInput && modalInput.value.trim().length >= 2) setTimeout(performSearch, 50);
                }
            });
        })();
        
        // openDesignSettings は design.php へ遷移に変更
        
        // メンバーポップアップ切り替え
        function toggleMemberPopup(event) {
            event.stopPropagation();
            const popup = document.getElementById('memberPopup');
            const overlay = document.getElementById('memberPopupOverlay');
            
            if (popup && overlay) {
                popup.classList.add('show');
                overlay.classList.add('show');
                document.body.style.overflow = 'hidden'; // 背景スクロール防止
            }
        }
        
        // メンバーポップアップを閉じる
        function closeMemberPopup() {
            const popup = document.getElementById('memberPopup');
            const overlay = document.getElementById('memberPopupOverlay');
            
            if (popup) popup.classList.remove('show');
            if (overlay) overlay.classList.remove('show');
            document.body.style.overflow = ''; // 背景スクロール復帰
        }
        
        // ESCキーでポップアップを閉じる
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMemberPopup();
            }
        });
        
        // メンバーとチャットを開始する（既存があれば既存に移動）
        async function startDmWithUser(userId, displayName) {
            closeMemberPopup();
            
            // 確認ダイアログ
            if (!confirm(`${displayName} さんとチャットを開始しますか？`)) {
                return;
            }
            
            try {
                // チャットを作成または既存を取得
                const response = await fetch('api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'create_direct_chat',
                        user_id: userId
                    })
                });
                
                const data = await response.json();
                
                if (data.success && data.conversation_id) {
                    // 新規チャットに移動
                    window.location.href = 'chat.php?c=' + data.conversation_id;
                } else {
                    alert(data.message || '<?= $currentLang === 'en' ? 'Failed to start conversation' : ($currentLang === 'zh' ? '无法开始对话' : '会話を開始できませんでした') ?>');
                }
            } catch (e) {
                console.error('チャット開始エラー:', e);
                alert('<?= $currentLang === 'en' ? 'Error occurred' : ($currentLang === 'zh' ? '发生错误' : 'エラーが発生しました') ?>');
            }
        }
        
        // グループのDM許可設定を変更
        async function toggleAllowMemberDm(allowed) {
            if (!currentConversationId) return;
            
            try {
                const response = await fetch('api/conversations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update_settings',
                        conversation_id: currentConversationId,
                        allow_member_dm: allowed ? 1 : 0
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // ページをリロードして反映
                    location.reload();
                } else {
                    alert(data.message || '<?= $currentLang === 'en' ? 'Failed to update setting' : ($currentLang === 'zh' ? '设置更新失败' : '設定の更新に失敗しました') ?>');
                    // 失敗したらトグルを戻す
                    document.getElementById('allowMemberDmToggle').checked = !allowed;
                }
            } catch (e) {
                console.error('設定更新エラー:', e);
                alert('<?= $currentLang === 'en' ? 'Error occurred' : ($currentLang === 'zh' ? '发生错误' : 'エラーが発生しました') ?>');
                document.getElementById('allowMemberDmToggle').checked = !allowed;
            }
        }
        
        // 右パネル セクション折りたたみ
        function toggleSection(header) {
            header.classList.toggle('collapsed');
        }
        
        // ========== 概要（Overview）機能 - 複数エントリ対応 ==========
        let overviewEntries = [];
        // conversationId は既に上部で定義済み
        
        // 初期化：既存の概要データを読み込み
        function initOverviewEntries() {
            const rawDescription = <?= json_encode($selected_conversation['description'] ?? '') ?>;
            
            if (rawDescription) {
                try {
                    // JSON形式で保存されている場合
                    const parsed = JSON.parse(rawDescription);
                    if (Array.isArray(parsed)) {
                        overviewEntries = parsed;
                    } else {
                        // 単一のテキストの場合は配列に変換
                        overviewEntries = [{ id: Date.now(), text: rawDescription, saved: true }];
                    }
                } catch (e) {
                    // JSON以外の場合は単一エントリとして扱う
                    overviewEntries = [{ id: Date.now(), text: rawDescription, saved: true }];
                }
            }
            
            renderOverviewEntries();
        }
        
        // 概要エントリを描画
        function renderOverviewEntries() {
            const listContainer = document.getElementById('overviewList');
            if (!listContainer) return;
            
            if (overviewEntries.length === 0) {
                listContainer.innerHTML = '<div class="overview-empty"><?= $currentLang === 'en' ? 'No notes yet. Click + to add.' : ($currentLang === 'zh' ? '暂无备注。点击+添加。' : 'まだ概要がありません。＋をクリックして追加') ?></div>';
                return;
            }
            
            // 概要テキスト内のURLをリンク化（概要欄のリンクに overview-link クラスを付与してアクティブ表示用）
            function linkifyOverviewText(text) {
                if (!text) return '';
                const trimmed = String(text).trim().replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                const escaped = escapeHtml(trimmed);
                const withBr = escaped.replace(/\n/g, '<br>');
                return withBr.replace(/(https?:\/\/[^\s<>\[\]"]+)/g, '<a class="overview-link" href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
            }
            
            listContainer.innerHTML = overviewEntries.map((entry, index) => {
                const text = entry.text || '';
                const lineCount = text.split('\n').length;
                const isLongText = lineCount > 4 || text.length > 150;
                const isExpanded = entry.expanded === true;
                const showExpandBtn = entry.saved && isLongText;
                const textareaClass = entry.saved ? (isExpanded ? 'expanded' : 'collapsed') : '';
                
                if (entry.saved) {
                    const linkifiedHtml = linkifyOverviewText(text);
                    return `
                <div class="overview-entry saved" data-entry-id="${entry.id}">
                    <div class="overview-body-readonly overview-body-clickable ${textareaClass}" id="overview-display-${entry.id}"
                        onclick="if(!event.target.closest('a')) editOverviewEntry(${entry.id})"
                        title="<?= $currentLang === 'en' ? 'Click to edit' : ($currentLang === 'zh' ? '点击编辑' : 'クリックで編集') ?>">
                        <div class="overview-body-inner">${linkifiedHtml}</div>
                    </div>
                    <textarea id="overview-textarea-${entry.id}" style="display:none" aria-hidden="true">${escapeHtml(text)}</textarea>
                    ${showExpandBtn ? `
                        <button class="overview-expand-btn" onclick="toggleOverviewExpand(${entry.id}); event.stopPropagation();" title="<?= $currentLang === 'en' ? 'Show more' : ($currentLang === 'zh' ? '显示更多' : '続きを表示') ?>">
                            ${isExpanded ? '▲' : '▼'}
                        </button>
                    ` : ''}
                    <div class="overview-entry-actions"></div>
                </div>
            `;
                }
                
                return `
                <div class="overview-entry editing" data-entry-id="${entry.id}">
                    <textarea 
                        id="overview-textarea-${entry.id}"
                        placeholder="<?= $currentLang === 'en' ? 'Enter note...' : ($currentLang === 'zh' ? '输入备注...' : '概要を入力...') ?>"
                        onfocus="this.parentElement.classList.add('editing'); this.parentElement.classList.remove('saved');"
                    >${escapeHtml(text)}</textarea>
                    <div class="overview-entry-actions">
                        <button class="overview-entry-btn save-btn" onclick="saveOverviewEntry(${entry.id})"><?= $currentLang === 'en' ? 'Save' : ($currentLang === 'zh' ? '保存' : '保存') ?></button>
                        ${overviewEntries.length > 1 || text ? `<button class="overview-entry-btn delete-btn" onclick="deleteOverviewEntry(${entry.id})">🗑️ <?= $currentLang === 'en' ? 'Delete' : ($currentLang === 'zh' ? '删除' : '削除') ?></button>` : ''}
                    </div>
                </div>
            `}).join('');
        }
        
        // 概要の展開/折りたたみ切り替え
        function toggleOverviewExpand(entryId) {
            const entry = overviewEntries.find(e => e.id === entryId);
            if (!entry) return;
            
            entry.expanded = !entry.expanded;
            
            const displayEl = document.getElementById(`overview-display-${entryId}`);
            const textarea = document.getElementById(`overview-textarea-${entryId}`);
            const expandBtn = (displayEl || textarea) ? (displayEl || textarea).parentElement.querySelector('.overview-expand-btn') : null;
            const targetEl = displayEl || textarea;
            
            if (targetEl) {
                if (entry.expanded) {
                    targetEl.classList.remove('collapsed');
                    targetEl.classList.add('expanded');
                    targetEl.style.maxHeight = 'none';
                    const scrollHeight = targetEl.scrollHeight;
                    const expandedHeight = Math.min(scrollHeight, 400);
                    targetEl.style.height = expandedHeight + 'px';
                    targetEl.style.maxHeight = expandedHeight + 'px';
                    targetEl.style.overflowY = scrollHeight > 400 ? 'auto' : 'hidden';
                    if (expandBtn) {
                        expandBtn.innerHTML = '▲';
                        expandBtn.title = '<?= $currentLang === 'en' ? 'Show less' : ($currentLang === 'zh' ? '收起' : '閉じる') ?>';
                    }
                } else {
                    targetEl.classList.remove('expanded');
                    targetEl.classList.add('collapsed');
                    targetEl.style.height = '120px';
                    targetEl.style.maxHeight = '120px';
                    targetEl.style.overflowY = 'hidden';
                    if (expandBtn) {
                        expandBtn.innerHTML = '▼';
                        expandBtn.title = '<?= $currentLang === 'en' ? 'Show more' : ($currentLang === 'zh' ? '显示更多' : '続きを表示') ?>';
                    }
                }
            }
        }
        
        // 新しい概要エントリを追加
        function addNewOverviewEntry() {
            const newEntry = {
                id: Date.now(),
                text: '',
                saved: false
            };
            overviewEntries.push(newEntry);
            renderOverviewEntries();
            
            // 新しいテキストエリアにフォーカス
            setTimeout(() => {
                const textarea = document.getElementById(`overview-textarea-${newEntry.id}`);
                if (textarea) textarea.focus();
            }, 50);
        }
        
        // 概要エントリを編集モードに
        function editOverviewEntry(entryId) {
            const entry = overviewEntries.find(e => e.id === entryId);
            if (entry) {
                entry.saved = false;
                renderOverviewEntries();
                
                setTimeout(() => {
                    const textarea = document.getElementById(`overview-textarea-${entryId}`);
                    if (textarea) {
                        textarea.removeAttribute('readonly');
                        // 編集中は文章全体を表示（高さを内容に合わせて展開、最大400pxでスクロール）
                        textarea.style.minHeight = '60px';
                        textarea.style.maxHeight = '400px';
                        textarea.style.height = 'auto';
                        textarea.style.height = Math.min(400, Math.max(60, textarea.scrollHeight)) + 'px';
                        textarea.style.overflowY = 'auto';
                        textarea.focus();
                    }
                }, 50);
            }
        }
        
        // 概要エントリを保存
        async function saveOverviewEntry(entryId) {
            const textarea = document.getElementById(`overview-textarea-${entryId}`);
            if (!textarea) return;
            
            const entry = overviewEntries.find(e => e.id === entryId);
            if (!entry) return;
            
            const newText = textarea.value.trim();
            
            // 空の場合は削除するか確認
            if (!newText) {
                if (overviewEntries.length > 1) {
                    deleteOverviewEntry(entryId);
                    return;
                } else {
                    // 最後の1つで空なら削除
                    overviewEntries = [];
                }
            } else {
                entry.text = newText;
                entry.saved = true;
            }
            
            // サーバーに保存
            await saveAllOverviewEntries();
            renderOverviewEntries();
        }
        
        // 概要エントリを削除
        async function deleteOverviewEntry(entryId) {
            if (!confirm('<?= $currentLang === 'en' ? 'Delete this note?' : ($currentLang === 'zh' ? '删除此备注？' : 'この概要を削除しますか？') ?>')) {
                return;
            }
            
            overviewEntries = overviewEntries.filter(e => e.id !== entryId);
            await saveAllOverviewEntries();
            renderOverviewEntries();
        }
        
        // 全ての概要エントリをサーバーに保存
        async function saveAllOverviewEntries() {
            if (!conversationId) {
                console.error('会話が選択されていません');
                return;
            }
            
            try {
                // 保存済みのエントリのみをJSON形式で保存
                const savedEntries = overviewEntries.filter(e => e.saved && e.text);
                const descriptionJson = savedEntries.length > 0 ? JSON.stringify(savedEntries) : '';
                
                const response = await fetch('api/conversations.php?action=update', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        conversation_id: conversationId,
                        description: descriptionJson
                    })
                });
                
                const data = await response.json();
                if (!data.success) {
                    console.error('概要の保存に失敗:', data.message);
                    alert('<?= $currentLang === 'en' ? 'Failed to save' : ($currentLang === 'zh' ? '保存失败' : '保存に失敗しました') ?>');
                }
            } catch (error) {
                console.error('概要の保存エラー:', error);
                alert('<?= $currentLang === 'en' ? 'Failed to save' : ($currentLang === 'zh' ? '保存失败' : '保存に失敗しました') ?>');
            }
        }
        
        // ページ読み込み時に概要を初期化
        document.addEventListener('DOMContentLoaded', function() {
            initOverviewEntries();
        });
        
        // キーボードショートカット
        document.addEventListener('keydown', (e) => {
            // Ctrl + K: 検索を開く
            if (e.ctrlKey && e.key === 'k') {
                e.preventDefault();
                openSearch();
            }
            
            // Ctrl + ,: 設定を開く
            if (e.ctrlKey && e.key === ',') {
                e.preventDefault();
                window.location.href = 'settings.php';
            }
            
            // Ctrl + N: 新しい会話を開く
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                openNewConversation();
            }
            
            // Ctrl + /: ショートカット一覧を表示
            if (e.ctrlKey && e.key === '/') {
                e.preventDefault();
                showShortcutHelp();
            }
            
            // Escape: モーダルを閉じる
            if (e.key === 'Escape') {
                closeAllModals();
            }
            
            // Ctrl + Shift + M: メモページを開く
            if (e.ctrlKey && e.shiftKey && e.key === 'M') {
                e.preventDefault();
                window.location.href = 'memos.php';
            }
            
            // Ctrl + Shift + W: タスクページを開く
            if (e.ctrlKey && e.shiftKey && e.key === 'W') {
                e.preventDefault();
                window.location.href = 'tasks.php';
            }
        });
        
        // ショートカットヘルプを表示
        function showShortcutHelp() {
            const modal = document.createElement('div');
            modal.id = 'shortcutHelpModal';
            modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);display:flex;align-items:center;justify-content:center;z-index:10000;';
            modal.innerHTML = `
                <div style="background:#1a1a2e;border-radius:16px;padding:24px;max-width:400px;width:90%;color:#fff;">
                    <h3 style="margin:0 0 20px;font-size:18px;">⌨️ キーボードショートカット</h3>
                    <div style="display:flex;flex-direction:column;gap:12px;">
                        <div style="display:flex;justify-content:space-between;"><span>検索を開く</span><kbd style="background:#374151;padding:4px 8px;border-radius:4px;">Ctrl + K</kbd></div>
                        <div style="display:flex;justify-content:space-between;"><span>メッセージを送信</span><kbd style="background:#374151;padding:4px 8px;border-radius:4px;">Enter</kbd></div>
                        <div style="display:flex;justify-content:space-between;"><span>改行</span><kbd style="background:#374151;padding:4px 8px;border-radius:4px;">Shift + Enter</kbd></div>
                        <div style="display:flex;justify-content:space-between;"><span>画像を貼り付け</span><kbd style="background:#374151;padding:4px 8px;border-radius:4px;">Ctrl + V</kbd></div>
                        <div style="display:flex;justify-content:space-between;"><span>設定を開く</span><kbd style="background:#374151;padding:4px 8px;border-radius:4px;">Ctrl + ,</kbd></div>
                        <div style="display:flex;justify-content:space-between;"><span>新しい会話</span><kbd style="background:#374151;padding:4px 8px;border-radius:4px;">Ctrl + N</kbd></div>
                        <div style="display:flex;justify-content:space-between;"><span>メモを開く</span><kbd style="background:#374151;padding:4px 8px;border-radius:4px;">Ctrl + Shift + M</kbd></div>
                        <div style="display:flex;justify-content:space-between;"><span>タスクを開く</span><kbd style="background:#374151;padding:4px 8px;border-radius:4px;">Ctrl + Shift + W</kbd></div>
                        <div style="display:flex;justify-content:space-between;"><span>このヘルプを表示</span><kbd style="background:#374151;padding:4px 8px;border-radius:4px;">Ctrl + /</kbd></div>
                        <div style="display:flex;justify-content:space-between;"><span>モーダルを閉じる</span><kbd style="background:#374151;padding:4px 8px;border-radius:4px;">Escape</kbd></div>
                    </div>
                    <button onclick="this.parentElement.parentElement.remove()" style="margin-top:20px;width:100%;padding:12px;background:#10b981;border:none;border-radius:8px;color:#fff;cursor:pointer;font-size:14px;">閉じる</button>
                </div>
            `;
            document.body.appendChild(modal);
            modal.addEventListener('click', (e) => {
                if (e.target === modal) modal.remove();
            });
        }
        
        // すべてのモーダルを閉じる
        function closeAllModals() {
            document.getElementById('shortcutHelpModal')?.remove();
            document.getElementById('searchModal')?.style && (document.getElementById('searchModal').style.display = 'none');
            document.getElementById('newConvModal')?.style && (document.getElementById('newConvModal').style.display = 'none');
            document.getElementById('manualWishModal')?.style && (document.getElementById('manualWishModal').style.display = 'none');
            document.getElementById('renameGroupModal')?.style && (document.getElementById('renameGroupModal').style.display = 'none');
            document.getElementById('virtualBgModal')?.style && (document.getElementById('virtualBgModal').style.display = 'none');
        }
        
        // ========== クリップボード貼り付け＆ドラッグ＆ドロップ ==========
        
        // 貼り付けプレビュー用バックドロップ（操作を確実に受け付けるため）
        const pastePreviewBackdrop = document.createElement('div');
        pastePreviewBackdrop.className = 'paste-preview-backdrop';
        pastePreviewBackdrop.id = 'pastePreviewBackdrop';
        pastePreviewBackdrop.setAttribute('aria-hidden', 'true');
        pastePreviewBackdrop.onclick = function() { cancelPaste(); };
        document.body.appendChild(pastePreviewBackdrop);
        
        // ドロップオーバーレイ
        const dropOverlay = document.createElement('div');
        dropOverlay.className = 'drop-overlay';
        dropOverlay.innerHTML = `
            <div class="drop-overlay-content">
                <div class="drop-icon">📁</div>
                <p>ファイルをドロップして送信</p>
            </div>
        `;
        (document.querySelector('.center-panel') || document.body).appendChild(dropOverlay);
        
        // 貼り付けプレビュー（TO選択付き）
        const pastePreview = document.createElement('div');
        pastePreview.className = 'paste-preview';
        pastePreview.id = 'pastePreview';
        pastePreview.onclick = function(e) { e.stopPropagation(); };
        pastePreview.innerHTML = `
            <img id="pastePreviewImage" alt="プレビュー" style="display:none;">
            <div id="pasteFileInfo" class="paste-file-info" style="display:none;"></div>
            <div id="pasteFileNameRow" class="paste-file-name-row" style="display:none;margin:10px 0;">
                <label style="font-size:13px;color:#6b7280;margin-bottom:6px;display:block;">📝 ファイル名（任意・変更可）:</label>
                <input type="text" id="pasteFileNameInput" placeholder="表示名を入力（例: 源泉所得税R3.7-12月分.pdf）" style="width:100%;padding:8px 12px;border:1px solid #e5e7eb;border-radius:6px;font-size:14px;box-sizing:border-box;" aria-label="ファイルの表示名">
            </div>
            <div class="paste-message-input" style="margin:12px 0;">
                <label style="font-size:13px;color:#6b7280;margin-bottom:6px;display:block;">💬 メッセージ（任意）:</label>
                <textarea id="pasteMessageInput" placeholder="画像と一緒に送るメッセージを入力..." style="width:100%;min-height:60px;padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:14px;resize:vertical;box-sizing:border-box;" aria-label="画像と一緒に送るメッセージ"></textarea>
            </div>
            <div class="paste-to-selector" id="pasteToSelector">
                <label style="font-size:13px;color:#6b7280;margin-bottom:8px;display:block;">📨 宛先 (TO):</label>
                <div class="paste-to-options" id="pasteToOptions" style="display:flex;flex-wrap:wrap;gap:6px;max-height:100px;overflow-y:auto;"></div>
                <div id="pasteSelectedToDisplay" class="paste-selected-to-display" style="margin-top:8px;font-size:12px;color:var(--primary);min-height:18px;"></div>
            </div>
            <div id="pasteBulkCount" style="display:none;margin-bottom:10px;font-size:13px;color:var(--primary);"></div>
            <div class="paste-preview-actions">
                <button type="button" class="cancel-btn" onclick="cancelPaste()">キャンセル</button>
                <button type="button" class="send-btn paste-send-btn" onclick="sendPastedImage()">送信</button>
            </div>
        `;
        document.body.appendChild(pastePreview);
        // 携帯: キャンセルを1タップで即効（touchstartで先に処理）
        const pastePreviewEl = document.getElementById('pastePreview');
        if (pastePreviewEl) {
            pastePreviewEl.addEventListener('touchstart', function(e) {
                if (!e.target.closest('.cancel-btn')) return;
                e.preventDefault();
                e.stopPropagation();
                cancelPaste();
            }, { passive: false, capture: true });
        }
        
        // ペースト用TO選択の状態
        let pasteSelectedTo = [];
        
        let pendingPasteFile = null;
        
        // クリップボードからの貼り付け（Ctrl+V）- 全デザイン共通、入力欄フォーカス時
        function isPasteInChatInputArea() {
            const active = document.activeElement;
            if (!active) return false;
            const inputArea = document.getElementById('inputArea');
            const messageInput = document.getElementById('messageInput');
            const pasteModal = document.getElementById('pastePreview');
            const centerPanel = document.querySelector('.center-panel');
            if (active === messageInput) return true;
            if (inputArea && inputArea.contains(active)) return true;
            if (pasteModal && pasteModal.contains(active)) return true;
            if (centerPanel && centerPanel.contains(active)) return true;
            return false;
        }
        document.addEventListener('paste', function(e) {
            const items = e.clipboardData?.items;
            if (!items) return;
            if (!isPasteInChatInputArea()) return;
            for (let item of items) {
                if (item.type.startsWith('image/') || item.kind === 'file') {
                    const file = item.getAsFile();
                    if (file && (file.type.startsWith('image/') || file.type === 'application/pdf' || file.name)) {
                        e.preventDefault();
                        e.stopPropagation();
                        showFilePreview(file);
                        break;
                    }
                }
            }
        }, true);
        
        function togglePasteTo(value, btn) {
            // 値を適切な型に変換（'all'は文字列、それ以外は数値）
            const normalizedValue = value === 'all' ? 'all' : parseInt(value, 10);
            
            if (normalizedValue === 'all') {
                // 「全員」を選択/解除
                if (pasteSelectedTo.includes('all')) {
                    pasteSelectedTo = [];
                    btn.classList.remove('active');
                } else {
                    pasteSelectedTo = ['all'];
                    // 他のボタンの選択を解除
                    document.querySelectorAll('#pasteToOptions .paste-to-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                }
            } else {
                // 「全員」の選択を解除
                const allBtn = document.querySelector('#pasteToOptions .paste-to-btn[data-value="all"]');
                if (allBtn) allBtn.classList.remove('active');
                pasteSelectedTo = pasteSelectedTo.filter(v => v !== 'all');
                
                // 個別メンバーをトグル
                const idx = pasteSelectedTo.findIndex(v => v === normalizedValue);
                if (idx >= 0) {
                    pasteSelectedTo.splice(idx, 1);
                    btn.classList.remove('active');
                } else {
                    pasteSelectedTo.push(normalizedValue);
                    btn.classList.add('active');
                }
            }
            
            console.log('[PasteTo] Selected:', pasteSelectedTo);
            
            // 選択されたTO宛先名を表示
            updatePasteToDisplay();
        }
        
        function updatePasteToDisplay() {
            const display = document.getElementById('pasteSelectedToDisplay');
            if (!display) return;
            
            if (pasteSelectedTo.length === 0) {
                display.textContent = '';
            } else if (pasteSelectedTo.includes('all')) {
                display.textContent = '📨 To: 全員';
            } else {
                const members = window.currentConversationMembers || [];
                const names = pasteSelectedTo.map(id => {
                    const m = members.find(m => m.id == id);
                    return m ? (m.display_name || m.name) : id;
                });
                display.textContent = '📨 To: ' + names.join(', ');
            }
        }
        
        function cancelPaste() {
            pendingPasteFile = null;
            pasteSelectedTo = []; // TO選択をリセット
            const previewImg = document.getElementById('pastePreviewImage');
            if (previewImg) {
                previewImg.src = '';
                previewImg.style.display = 'none';
            }
            // ファイル情報を非表示
            const fileInfo = document.getElementById('pasteFileInfo');
            if (fileInfo) {
                fileInfo.style.display = 'none';
                fileInfo.innerHTML = '';
            }
            // ファイル名入力欄をリセット
            const fileNameRow = document.getElementById('pasteFileNameRow');
            const fileNameInput = document.getElementById('pasteFileNameInput');
            if (fileNameRow) fileNameRow.style.display = 'none';
            if (fileNameInput) fileNameInput.value = '';
            // メッセージ入力欄をリセット
            const messageInput = document.getElementById('pasteMessageInput');
            if (messageInput) {
                messageInput.value = '';
            }
            // TO選択ボタンの選択状態をリセット
            document.querySelectorAll('#pasteToOptions .paste-to-btn').forEach(b => b.classList.remove('active'));
            // TO表示をリセット
            const toDisplay = document.getElementById('pasteSelectedToDisplay');
            if (toDisplay) toDisplay.textContent = '';
            const previewEl = document.getElementById('pastePreview');
            if (previewEl) previewEl.classList.remove('active');
            const backdropEl = document.getElementById('pastePreviewBackdrop');
            if (backdropEl) backdropEl.classList.remove('active');
            document.body.style.overflow = '';
        }
        
        /**
         * 画像をLINE同様にリサイズ・圧縮して送信可能なサイズにする（大きい写真でも送信可能に）
         * @param {File} file - 画像ファイル
         * @param {Object} opts - { maxSizeBytes: 5*1024*1024, maxDimension: 1920, quality: 0.85 }
         * @returns {Promise<File>} 圧縮後ファイル（要らない場合は元のfile）
         */
        function compressImageForUpload(file, opts) {
            opts = opts || {};
            var maxSizeBytes = opts.maxSizeBytes != null ? opts.maxSizeBytes : 5 * 1024 * 1024;
            var maxDimension = opts.maxDimension != null ? opts.maxDimension : 1920;
            var quality = opts.quality != null ? opts.quality : 0.85;
            if (!file || !file.type || !file.type.startsWith('image/')) return Promise.resolve(file);
            if (file.size <= maxSizeBytes) {
                var img = new Image();
                var url = URL.createObjectURL(file);
                return new Promise(function(resolve) {
                    img.onload = function() {
                        URL.revokeObjectURL(url);
                        if (img.naturalWidth <= maxDimension && img.naturalHeight <= maxDimension) {
                            resolve(file);
                            return;
                        }
                        resizeImageToBlob(img, maxDimension, quality, file.name).then(resolve);
                    };
                    img.onerror = function() { URL.revokeObjectURL(url); resolve(file); };
                    img.src = url;
                });
            }
            var img = new Image();
            var url = URL.createObjectURL(file);
            return new Promise(function(resolve) {
                img.onload = function() {
                    URL.revokeObjectURL(url);
                    resizeImageToBlob(img, maxDimension, quality, file.name).then(resolve);
                };
                img.onerror = function() { URL.revokeObjectURL(url); resolve(file); };
                img.src = url;
            });
        }
        function resizeImageToBlob(img, maxDim, quality, baseName) {
            var w = img.naturalWidth, h = img.naturalHeight;
            if (w <= maxDim && h <= maxDim) {
                var c = document.createElement('canvas');
                c.width = w; c.height = h;
                c.getContext('2d').drawImage(img, 0, 0);
                return canvasToFile(c, quality, baseName);
            }
            if (w >= h) {
                h = Math.round(h * maxDim / w);
                w = maxDim;
            } else {
                w = Math.round(w * maxDim / h);
                h = maxDim;
            }
            var canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            canvas.getContext('2d').drawImage(img, 0, 0, w, h);
            return canvasToFile(canvas, quality, baseName);
        }
        function canvasToFile(canvas, quality, baseName) {
            var ext = (baseName && baseName.split('.').pop()) ? baseName.split('.').pop().toLowerCase() : 'jpg';
            var mime = (ext === 'png') ? 'image/png' : 'image/jpeg';
            return new Promise(function(resolve) {
                canvas.toBlob(function(blob) {
                    var name = (baseName && baseName !== 'image.png' && baseName !== 'blob') ? baseName : ('image_' + Date.now() + '.' + (mime === 'image/png' ? 'png' : 'jpg'));
                    resolve(new File([blob], name, { type: mime }));
                }, mime, quality);
            });
        }
        if (typeof window !== 'undefined') { window.compressImageForUpload = compressImageForUpload; }
        
        async function sendPastedImage() {
            if (!pendingPasteFile) {
                cancelPaste();
                return;
            }
            
            // メッセージ入力を取得
            const messageInput = document.getElementById('pasteMessageInput');
            const additionalMessage = messageInput ? messageInput.value.trim() : '';
            
            // AI秘書モード: 画像は image_path、それ以外は file_path/file_name で api/ai.php に送信
            if (typeof isAISecretaryActive === 'function' ? isAISecretaryActive() : (window.isAISecretaryActive && window.isAISecretaryActive())) {
                const isImage = pendingPasteFile.type && pendingPasteFile.type.startsWith('image/');
                const ext = (pendingPasteFile.name && pendingPasteFile.name.includes('.')) ? pendingPasteFile.name.split('.').pop().toLowerCase() : (pendingPasteFile.type ? pendingPasteFile.type.split('/')[1] : 'png');
                let fileName = (pendingPasteFile.name && pendingPasteFile.name !== 'image.png' && pendingPasteFile.name !== 'blob') ? pendingPasteFile.name : (isImage ? 'screenshot_' + Date.now() + '.' + ext : 'file_' + Date.now() + '.' + ext);

                let fileToUpload = pendingPasteFile;
                if (isImage) {
                    fileToUpload = await compressImageForUpload(pendingPasteFile, { maxSizeBytes: 5 * 1024 * 1024, maxDimension: 1920, quality: 0.85 });
                    if (fileToUpload !== pendingPasteFile) fileName = fileToUpload.name;
                }

                const uploadFormData = new FormData();
                uploadFormData.append('file', fileToUpload, fileName);
                try {
                    const uploadRes = await fetch('api/upload.php', {
                        method: 'POST',
                        body: uploadFormData,
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' }
                    });
                    const uploadText = await uploadRes.text();
                    let uploadData;
                    try { uploadData = JSON.parse(uploadText); } catch (_) {
                        alert(uploadText.trim().startsWith('<') ? 'セッションが切れました。再度ログインしてください。' : 'アップロードに失敗しました。');
                        cancelPaste();
                        return;
                    }
                    const path = (uploadData.data && uploadData.data.file_path) ? uploadData.data.file_path : (uploadData.path || uploadData.file_path);
                    if (uploadData.success && path) {
                        if (isImage) {
                            await sendAIMessage(additionalMessage || 'この画像について説明してください', path);
                        } else {
                            await sendAIMessage(additionalMessage || 'このファイルの内容を確認してください', null, { path: path, name: fileName, isImage: false });
                        }
                        cancelPaste();
                    } else {
                        alert(uploadData.error || uploadData.message || (isImage ? '画像' : 'ファイル') + 'のアップロードに失敗しました');
                        cancelPaste();
                    }
                } catch (err) {
                    console.error('[AI Paste] Upload error:', err);
                    alert('送信エラー: ' + err.message);
                    cancelPaste();
                }
                return;
            }
            
            if (!conversationId) {
                alert('会話が選択されていません');
                cancelPaste();
                return;
            }
            
            const pasteFileNameInput = document.getElementById('pasteFileNameInput');
            const displayName = pasteFileNameInput ? pasteFileNameInput.value.trim() : '';
            
            // To選択を [To:all] / [To:ID] 形式でメッセージ本文に挿入（Phase C互換）
            let toPrefix = '';
            if (pasteSelectedTo.length > 0) {
                const members = window.currentConversationMembers || [];
                if (pasteSelectedTo.includes('all')) {
                    toPrefix = '[To:all]全員\n';
                } else {
                    const lines = pasteSelectedTo.map(function(id) {
                        const m = members.find(function(mem) { return mem.id == id; });
                        const name = m ? (m.display_name || m.name || id) : id;
                        return '[To:' + id + ']' + name;
                    });
                    toPrefix = lines.join('\n') + '\n';
                }
            }
            const messageWithTo = toPrefix + additionalMessage;
            
            // 1件のみ送信（複数一括は行わない）
            window._pendingFiles = [];
            const formData = new FormData();
            formData.append('action', 'upload_file');
            formData.append('conversation_id', conversationId);
            if (displayName) formData.append('display_name', displayName);
            let fileName = pendingPasteFile.name;
            if (!fileName || fileName === 'image.png' || fileName === 'blob') {
                const ext = pendingPasteFile.type.split('/')[1] || 'png';
                fileName = 'screenshot_' + Date.now() + '.' + ext;
            }
            const fileToSend = await compressImageForUpload(pendingPasteFile, { maxSizeBytes: 5 * 1024 * 1024, maxDimension: 1920, quality: 0.85 });
            if (fileToSend !== pendingPasteFile) fileName = fileToSend.name;
            formData.append('file', fileToSend, fileName);
            if (messageWithTo) formData.append('message', messageWithTo);
            
            try {
                // 相対URLでサブディレクトリ・ルート両対応（chat.php と同じ階層から api/ を参照）
                const res = await fetch('api/messages.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('[Upload] Response status:', res.status, 'url:', res.url, 'text:', text.substring(0, 200));
                    // JSONでない応答（HTML/404/500等）は分かりやすいメッセージを表示
                    let errMsg;
                    if (res.status === 401 || res.redirected) {
                        errMsg = 'セッションが切れました。再度ログインしてください。';
                    } else if (res.status === 404) {
                        errMsg = 'APIが見つかりません（404）。サーバー管理者に問い合わせてください。';
                    } else {
                        errMsg = 'サーバーエラーが発生しました。\n\n・ファイルは10MB以下にしてください\n・ネットワーク環境を確認してください\n・サーバー管理者に問い合わせてください';
                    }
                    alert(errMsg);
                    cancelPaste();
                    return;
                }
                if (data.success) {
                        cancelPaste();
                    if (data.message && typeof window.appendMessageToUI === 'function') {
                        window.appendMessageToUI(data.message);
                        const messagesArea = document.getElementById('messagesArea');
                        if (messagesArea) messagesArea.scrollTop = messagesArea.scrollHeight;
                        if (window.updateLastMessageId) window.updateLastMessageId(data.message.id);
                    } else {
                        location.reload();
                    }
                } else {
                    alert(data.message || 'ファイルの送信に失敗しました');
                    cancelPaste();
                }
            } catch (err) {
                console.error('Upload error:', err);
                alert('送信エラー: ' + err.message);
                cancelPaste();
            }
        }
        
        // ドラッグ＆ドロップ
        let dragCounter = 0;
        
        function isStorageVaultOpen() {
            var sv = document.getElementById('storageVaultView');
            return sv && sv.style.display !== 'none';
        }

        document.addEventListener('dragenter', function(e) {
            if (isStorageVaultOpen()) return;
            e.preventDefault();
            dragCounter++;
            if (e.dataTransfer?.types?.includes('Files')) {
                dropOverlay.classList.add('active');
            }
        });
        
        document.addEventListener('dragleave', function(e) {
            if (isStorageVaultOpen()) return;
            e.preventDefault();
            dragCounter--;
            if (dragCounter === 0) {
                dropOverlay.classList.remove('active');
            }
        });
        
        document.addEventListener('dragover', function(e) {
            if (isStorageVaultOpen()) return;
            e.preventDefault();
        });
        
        document.addEventListener('drop', async function(e) {
            if (isStorageVaultOpen()) return;
            e.preventDefault();
            dragCounter = 0;
            dropOverlay.classList.remove('active');
            
            const files = e.dataTransfer?.files;
            if (!files || files.length === 0) return;
            
            const isAI = typeof isAISecretaryActive === 'function' ? isAISecretaryActive() : (window.isAISecretaryActive && window.isAISecretaryActive());
            if (!isAI && !conversationId) return;
            
            const file = files[0];

            if (isAI) {
                const isImage = file.type && file.type.startsWith('image/');
                const ext = file.type ? file.type.split('/')[1] || 'png' : 'dat';
                let fileName = (file.name && file.name !== 'image.png' && file.name !== 'blob') ? file.name : (isImage ? 'screenshot_' + Date.now() + '.' + ext : 'file_' + Date.now() + '.' + ext);

                let fileToUpload = file;
                if (isImage) {
                    fileToUpload = await compressImageForUpload(file, { maxSizeBytes: 5 * 1024 * 1024, maxDimension: 1920, quality: 0.85 });
                    if (fileToUpload !== file) fileName = fileToUpload.name;
                }

                const uploadFormData = new FormData();
                uploadFormData.append('file', fileToUpload, fileName);
                try {
                    const uploadRes = await fetch('api/upload.php', {
                        method: 'POST', body: uploadFormData,
                        credentials: 'same-origin', headers: { 'Accept': 'application/json' }
                    });
                    const uploadData = await uploadRes.json();
                    const path = (uploadData.data && uploadData.data.file_path) ? uploadData.data.file_path : (uploadData.path || uploadData.file_path);
                    if (uploadData.success && path) {
                        if (isImage) {
                            await sendAIMessage('この画像について説明してください', path);
                        } else {
                            await sendAIMessage('このファイルの内容を確認してください', null, { path: path, name: fileName, isImage: false });
                        }
                    } else {
                        alert(uploadData.error || uploadData.message || (isImage ? '画像' : 'ファイル') + 'のアップロードに失敗しました');
                    }
                } catch (err) {
                    console.error('[AI Drop] Upload error:', err);
                    alert('送信エラー: ' + err.message);
                }
                return;
            }

            let fileName = file.name;
            if (!fileName || fileName === 'image.png' || fileName === 'blob') {
                const ext = file.type ? file.type.split('/')[1] || 'png' : 'dat';
                fileName = 'file_' + Date.now() + '.' + ext;
            }
            const fileToSend = await compressImageForUpload(file, { maxSizeBytes: 5 * 1024 * 1024, maxDimension: 1920, quality: 0.85 });
            if (fileToSend !== file) fileName = fileToSend.name;
            const formData = new FormData();
            formData.append('action', 'upload_file');
            formData.append('conversation_id', conversationId);
            formData.append('file', fileToSend, fileName);
            try {
                const res = await fetch('api/messages.php', {
                    method: 'POST', body: formData,
                    credentials: 'same-origin', headers: { 'Accept': 'application/json' }
                });
                const text = await res.text();
                let data;
                try { data = JSON.parse(text); } catch (_) {
                    alert(res.status === 401 || res.redirected
                        ? 'セッションが切れました。再度ログインしてください。'
                        : 'サーバーエラーが発生しました。');
                    return;
                }
                if (data.success) {
                    if (data.message && typeof window.appendMessageToUI === 'function') {
                        window.appendMessageToUI(data.message);
                        const messagesArea = document.getElementById('messagesArea');
                        if (messagesArea) messagesArea.scrollTop = messagesArea.scrollHeight;
                        if (window.updateLastMessageId) window.updateLastMessageId(data.message.id);
                    } else {
                        location.reload();
                    }
                } else {
                    alert(data.message || 'ファイルの送信に失敗しました');
                }
            } catch (err) {
                console.error('[Drop Upload] error:', err);
                alert('送信エラー: ' + err.message);
            }
        });
        
</script>