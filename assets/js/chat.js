/**
 * Social9 チャット機能JavaScript
 */

// ============================================
// 初期化
// ============================================

document.addEventListener('DOMContentLoaded', () => {
    initializeChat();
    initializeMessageForm();
    startPolling();
    OnlineStatus.start();
    NotificationChecker.start('#notificationBadge');
});

function initializeChat() {
    // チャットを最下部にスクロール
    scrollToBottom();
    
    // テキストエリアの自動リサイズ
    const messageInput = $('#messageInput');
    if (messageInput) {
        messageInput.addEventListener('input', autoResize);
    }
}

function scrollToBottom() {
    const chatMessages = $('#chatMessages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
}

function autoResize() {
    var cap = 180;
    this.style.setProperty('min-height', '0px', 'important');
    this.style.setProperty('max-height', 'none', 'important');
    this.style.setProperty('height', 'auto', 'important');
    var sh = this.scrollHeight;
    var h = Math.min(sh, cap);
    this.style.setProperty('height', h + 'px', 'important');
    this.style.setProperty('max-height', cap + 'px', 'important');
}

// ============================================
// メッセージ送信
// ============================================

function initializeMessageForm() {
    const form = $('#messageForm');
    const input = $('#messageInput');
    
    if (!form || !input) return;
    
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const content = input.value.trim();
        if (!content || !CONVERSATION_ID) return;
        
        // 送信ボタンを無効化
        const submitBtn = form.querySelector('.send-btn');
        submitBtn.disabled = true;
        
        try {
            const response = await api('messages.php', {
                method: 'POST',
                body: {
                    action: 'send',
                    conversation_id: CONVERSATION_ID,
                    content: content
                }
            });
            
            if (response.success) {
                input.value = '';
                input.style.height = 'auto';
                
                // 新しいメッセージを即座に表示
                const newMessage = {
                    id: response.message_id,
                    sender_id: USER_ID,
                    content: content,
                    display_name: USER_NAME || 'あなた',
                    created_at: new Date().toISOString()
                };
                appendMessage(newMessage);
                scrollToBottom();
                
                // lastMessageId を更新
                lastMessageId = response.message_id;
            } else {
                Toast.error(response.message || 'メッセージの送信に失敗しました');
            }
        } catch (error) {
            Toast.error('メッセージの送信に失敗しました');
        } finally {
            submitBtn.disabled = false;
            input.focus();
        }
    });
    
    // Enterで送信（Shift+Enterで改行）
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.dispatchEvent(new Event('submit'));
        }
    });
}

function appendMessage(msg) {
    const chatMessages = $('#chatMessages');
    if (!chatMessages) return;
    
    // 「メッセージがありません」を削除
    const noMessages = chatMessages.querySelector('.no-messages');
    if (noMessages) {
        noMessages.remove();
    }
    
    const isOwn = parseInt(msg.sender_id) === USER_ID;
    const senderName = msg.sender_name || msg.display_name || 'Unknown';
    const time = new Date(msg.created_at).toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
    
    // メンションをハイライト
    let contentHtml = escapeHtml(msg.content).replace(/\n/g, '<br>');
    contentHtml = contentHtml.replace(/@([a-zA-Z0-9_\u3040-\u309F\u30A0-\u30FF\u4E00-\u9FAF]+)/g, 
        '<span class="mention">@$1</span>');
    
    // ピン留めマーク
    const pinnedMark = (msg.is_pinned == 1 || msg.is_pinned === '1') ? '<span class="pinned-mark">📌</span>' : '';
    
    const messageHtml = `
        <div class="message ${isOwn ? 'own' : ''}" data-message-id="${msg.id}">
            ${!isOwn ? `<div class="avatar avatar-sm">${senderName.charAt(0)}</div>` : ''}
            <div class="message-content">
                ${pinnedMark}
                ${!isOwn ? `<div class="message-sender">${escapeHtml(senderName)}</div>` : ''}
                <div class="message-text">${contentHtml}</div>
                <div class="message-time">${time}</div>
                ${msg.reactions ? `<div class="message-reactions">${msg.reactions}</div>` : ''}
            </div>
        </div>
    `;
    
    chatMessages.insertAdjacentHTML('beforeend', messageHtml);
}

// ============================================
// ポーリング（新着メッセージ確認）
// ============================================

let pollingInterval = null;
let lastMessageId = null;

function startPolling() {
    if (!CONVERSATION_ID) return;
    
    // 最後のメッセージIDを取得
    const messages = $$('.message[data-message-id]');
    if (messages.length > 0) {
        lastMessageId = messages[messages.length - 1].dataset.messageId;
    }
    
    // 5秒ごとにポーリング
    pollingInterval = setInterval(checkNewMessages, 5000);
}

async function checkNewMessages() {
    if (!CONVERSATION_ID) return;
    
    try {
        let url = `messages.php?action=get&conversation_id=${CONVERSATION_ID}`;
        if (lastMessageId) {
            url += `&after_id=${lastMessageId}`;
        }
        const response = await api(url);
        
        if (response.success && response.messages && response.messages.length > 0) {
            response.messages.forEach(msg => {
                // 自分のメッセージは既に表示されている（送信時に追加済み）
                const existingMsg = $(`.message[data-message-id="${msg.id}"]`);
                if (!existingMsg && parseInt(msg.sender_id) !== USER_ID) {
                    appendMessage(msg);
                }
                lastMessageId = msg.id;
            });
            scrollToBottom();
        }
    } catch (error) {
        console.error('Polling error:', error);
    }
}

// ============================================
// グループ作成
// ============================================

async function createGroup() {
    const form = $('#createGroupForm');
    if (!form) return;
    
    const name = form.querySelector('[name="name"]').value.trim();
    const description = form.querySelector('[name="description"]').value.trim();
    
    if (!name) {
        Toast.error('グループ名を入力してください');
        return;
    }
    
    try {
        const response = await api('conversations.php', {
            method: 'POST',
            body: {
                action: 'create',
                type: 'group',
                name: name,
                description: description
            }
        });
        
        if (response.success) {
            Toast.success('グループを作成しました');
            closeModal('createGroupModal');
            location.href = `?c=${response.conversation_id}`;
        } else {
            Toast.error(response.message || 'グループの作成に失敗しました');
        }
    } catch (error) {
        Toast.error('グループの作成に失敗しました');
    }
}

// ============================================
// 通話
// ============================================

async function startCall(type) {
    if (!CONVERSATION_ID) return;
    
    if (AUTH_LEVEL < 2) {
        showUpgradePrompt('call');
        return;
    }
    
    try {
        const response = await api('calls.php', {
            method: 'POST',
            body: {
                action: 'create',
                conversation_id: CONVERSATION_ID,
                call_type: type
            }
        });
        
        if (response.success) {
            // Jitsi Meetを開く
            window.open(response.join_url, '_blank', 'width=800,height=600');
        } else {
            Toast.error(response.message || '通話の開始に失敗しました');
        }
    } catch (error) {
        Toast.error('通話の開始に失敗しました');
    }
}

// ============================================
// UI操作
// ============================================

function toggleSidebar() {
    const sidebar = $('#sidebar');
    if (sidebar) {
        sidebar.classList.toggle('active');
    }
}

function toggleUserMenu() {
    const dropdown = $('#userDropdown');
    if (dropdown) {
        dropdown.classList.toggle('active');
    }
}

function toggleDetailPanel() {
    const panel = $('#detailPanel');
    if (panel) {
        panel.classList.toggle('hidden');
    }
}

function toggleNotifications() {
    // TODO: 通知パネルの実装
    Toast.info('通知機能は準備中です');
}

function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('active');
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('active');
    }
}

function openConversationSettings() {
    // TODO: 会話設定の実装
    Toast.info('設定機能は準備中です');
}

// ============================================
// リアクション
// ============================================

async function addReaction(messageId, reactionType) {
    try {
        const response = await api('messages.php', {
            method: 'POST',
            body: {
                action: 'react',
                message_id: messageId,
                reaction_type: reactionType
            }
        });
        
        if (response.success) {
            // UIを更新
            updateMessageReaction(messageId, reactionType);
        } else {
            Toast.error(response.message || 'リアクションに失敗しました');
        }
    } catch (error) {
        Toast.error('リアクションに失敗しました');
    }
}

async function removeReaction(messageId) {
    try {
        const response = await api('messages.php', {
            method: 'POST',
            body: {
                action: 'unreact',
                message_id: messageId
            }
        });
        
        if (response.success) {
            // UIを更新
            const msgEl = $(`.message[data-message-id="${messageId}"]`);
            if (msgEl) {
                const reactions = msgEl.querySelector('.message-reactions');
                if (reactions) reactions.remove();
            }
        }
    } catch (error) {
        Toast.error('リアクションの解除に失敗しました');
    }
}

function updateMessageReaction(messageId, reactionType) {
    const msgEl = $(`.message[data-message-id="${messageId}"]`);
    if (!msgEl) return;
    
    let reactions = msgEl.querySelector('.message-reactions');
    if (!reactions) {
        const content = msgEl.querySelector('.message-content');
        content.insertAdjacentHTML('beforeend', '<div class="message-reactions"></div>');
        reactions = msgEl.querySelector('.message-reactions');
    }
    reactions.textContent = reactionType;
}

// ============================================
// メンション検索
// ============================================

async function loadMentions() {
    try {
        const response = await api('messages.php?action=mentions');
        
        if (response.success && response.mentions) {
            displayMentions(response.mentions);
        }
    } catch (error) {
        console.error('Failed to load mentions:', error);
    }
}

function displayMentions(mentions) {
    const container = $('#mentionsContainer');
    if (!container) return;
    
    if (mentions.length === 0) {
        container.innerHTML = '<p class="no-data">メンションはありません</p>';
        return;
    }
    
    container.innerHTML = mentions.map(m => `
        <div class="mention-item" onclick="goToMessage(${m.conversation_id}, ${m.id})">
            <div class="mention-sender">${escapeHtml(m.sender_name)}</div>
            <div class="mention-content">${escapeHtml(m.content.substring(0, 50))}...</div>
            <div class="mention-time">${new Date(m.created_at).toLocaleDateString('ja-JP')}</div>
        </div>
    `).join('');
}

function goToMessage(conversationId, messageId) {
    location.href = `chat.php?c=${conversationId}&m=${messageId}`;
}

// ============================================
// メッセージ編集
// ============================================

async function editMessage(messageId) {
    const msgEl = $(`.message[data-message-id="${messageId}"]`);
    if (!msgEl) return;
    
    const textEl = msgEl.querySelector('.message-text');
    const currentContent = textEl.textContent.trim();
    
    const newContent = prompt('メッセージを編集:', currentContent);
    if (newContent === null || newContent === currentContent) return;
    
    try {
        const response = await api('messages.php', {
            method: 'POST',
            body: {
                action: 'edit',
                message_id: messageId,
                content: newContent
            }
        });
        
        if (response.success) {
            textEl.innerHTML = escapeHtml(newContent).replace(/\n/g, '<br>');
            Toast.success('メッセージを編集しました');
        } else {
            Toast.error(response.message || '編集に失敗しました');
        }
    } catch (error) {
        Toast.error('編集に失敗しました');
    }
}

async function deleteMessage(messageId) {
    if (!confirm('このメッセージを削除しますか？')) return;
    
    try {
        const response = await api('messages.php', {
            method: 'POST',
            body: {
                action: 'delete',
                message_id: messageId
            }
        });
        
        if (response.success) {
            const msgEl = $(`.message[data-message-id="${messageId}"]`);
            if (msgEl) {
                msgEl.style.opacity = '0.5';
                msgEl.querySelector('.message-text').textContent = 'このメッセージは削除されました';
            }
            Toast.success('メッセージを削除しました');
        } else {
            Toast.error(response.message || '削除に失敗しました');
        }
    } catch (error) {
        Toast.error('削除に失敗しました');
    }
}

// ============================================
// 認証レベルアップ促進
// ============================================

function showUpgradePrompt(feature) {
    const messages = {
        'create_group': '電話認証をするとグループが作成できるようになります',
        'send_dm': '電話認証をするとDMが送れるようになります',
        'send_image': '電話認証をすると画像が送れるようになります',
        'call': '電話認証をすると通話ができるようになります'
    };
    
    const message = messages[feature] || '電話認証をすると機能が使えるようになります';
    
    if (confirm(message + '\n\n電話認証画面に移動しますか？')) {
        location.href = 'verify_phone.php';
    }
}

// ============================================
// チュートリアル
// ============================================

let tutorialStep = 1;

function nextTutorialStep() {
    tutorialStep++;
    
    const overlay = $('#tutorialOverlay');
    if (!overlay) return;
    
    if (tutorialStep > 3) {
        completeTutorial();
        return;
    }
    
    // 次のステップを表示（簡略化版）
    const steps = overlay.querySelectorAll('.tutorial-step');
    steps.forEach(step => {
        step.style.display = 'none';
    });
    
    const nextStep = overlay.querySelector(`[data-step="${tutorialStep}"]`);
    if (nextStep) {
        nextStep.style.display = 'block';
    } else {
        completeTutorial();
    }
}

function skipTutorial() {
    completeTutorial(true);
}

async function completeTutorial(skipped = false) {
    const overlay = $('#tutorialOverlay');
    if (overlay) {
        overlay.remove();
    }
    
    // サーバーに完了を記録
    try {
        await api('users.php', {
            method: 'POST',
            body: {
                action: 'complete_tutorial',
                skipped: skipped
            }
        });
    } catch (error) {
        console.error('Tutorial completion error:', error);
    }
    
    // URLからtutorialパラメータを削除
    history.replaceState(null, '', location.pathname + location.search.replace(/[?&]tutorial=1/, '').replace(/[?&]welcome=1/, ''));
}

// ============================================
// クリック外で閉じる
// ============================================

document.addEventListener('click', (e) => {
    // ユーザーメニュー
    if (!e.target.closest('.user-menu')) {
        const dropdown = $('#userDropdown');
        if (dropdown) dropdown.classList.remove('active');
    }
    
    // サイドバー（モバイル）
    if (window.innerWidth <= 768) {
        if (!e.target.closest('.sidebar') && !e.target.closest('.menu-toggle')) {
            const sidebar = $('#sidebar');
            if (sidebar) sidebar.classList.remove('active');
        }
    }
});





