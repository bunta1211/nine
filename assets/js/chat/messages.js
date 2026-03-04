/**
 * メッセージ送信・編集モジュール
 * 
 * メッセージの送信、編集、削除、返信機能
 * 
 * 使用例:
 * Chat.messages.send();
 * Chat.messages.edit(messageId);
 * Chat.messages.reply(messageId);
 */

(function(global) {
    'use strict';
    
    // チャット名前空間を確認
    global.Chat = global.Chat || {};
    
    // 内部状態
    let editingMessageId = null;
    let replyingToMessageId = null;
    let lastMessageId = 0;
    
    /**
     * 初期化
     * @param {Object} options - オプション
     */
    function init(options = {}) {
        if (options.lastMessageId) {
            lastMessageId = options.lastMessageId;
        }
        
        console.log('[Messages] Initialized');
    }
    
    /**
     * メッセージを送信
     */
    async function send() {
        const input = document.getElementById('messageInput');
        const content = input ? input.value.trim() : '';
        
        if (!content) return;
        
        const conversationId = Chat.config ? Chat.config.conversationId : (window.currentConversationId || window.conversationId);
        if (!conversationId) {
            console.error('[Messages] No conversation selected');
            return;
        }
        
        // 送信データを準備
        const formData = new FormData();
        formData.append('action', 'send');
        formData.append('conversation_id', conversationId);
        formData.append('content', content);
        
        // 返信先
        if (replyingToMessageId) {
            formData.append('reply_to', replyingToMessageId);
        }
        
        // TO（宛先）— API は mention_ids で受け取る
        const mentionIds = Chat.toSelector ? Chat.toSelector.getSelected() : (window.getSelectedToMembers ? window.getSelectedToMembers() : []);
        if (mentionIds.length > 0) {
            formData.append('mention_ids', JSON.stringify(mentionIds));
        }
        
        // ファイル添付
        const fileInput = document.getElementById('fileAttachment');
        if (fileInput && fileInput.files.length > 0) {
            formData.append('file', fileInput.files[0]);
        }
        
        try {
            // 入力欄をクリア
            input.value = '';
            if (Chat.utils && Chat.utils.autoResizeInput) {
                Chat.utils.autoResizeInput(input);
            }
            
            // 返信バーを非表示
            cancelReply();
            
            // TO選択をクリア
            if (Chat.toSelector) {
                Chat.toSelector.clear();
            } else if (window.clearToSelection) {
                window.clearToSelection();
            }
            
            const response = await fetch('api/messages.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                // 複数メッセージ（長文分割PDF）の場合は順に追加、それ以外は1件追加
                if (data.messages && Array.isArray(data.messages) && data.messages.length > 0) {
                    data.messages.forEach(function(m) {
                        if (typeof window.appendMessageToUI === 'function') {
                            window.appendMessageToUI(m);
                        }
                    });
                    if (data.messages.length > 0 && data.messages[data.messages.length - 1].id) {
                        updateLastMessageId(data.messages[data.messages.length - 1].id);
                    }
                } else if (data.message && typeof window.appendMessageToUI === 'function') {
                    window.appendMessageToUI(data.message);
                    if (data.message.id) {
                        updateLastMessageId(data.message.id);
                    }
                }
                
                // ファイル入力をクリア
                if (fileInput) {
                    fileInput.value = '';
                }
            } else {
                console.error('[Messages] Send failed:', data.error);
                // 入力内容を復元
                input.value = content;
            }
        } catch (error) {
            console.error('[Messages] Send error:', error);
            input.value = content;
        }
    }
    
    /**
     * メッセージを編集モードにする
     * @param {number} messageId - メッセージID
     */
    function edit(messageId) {
        const card = document.querySelector(`[data-message-id="${messageId}"]`);
        if (!card) {
            console.error('[Messages] Message card not found');
            return;
        }
        
        // 他の編集をキャンセル
        cancelEdit();
        
        editingMessageId = messageId;
        
        const contentEl = card.querySelector('.content');
        const originalContent = card.dataset.content || (contentEl ? contentEl.textContent : '');
        
        // 編集UI を表示
        const editBar = document.getElementById('editBar');
        if (editBar) {
            editBar.style.display = 'flex';
            const editingMsg = editBar.querySelector('.editing-message');
            if (editingMsg) {
                editingMsg.textContent = originalContent.substring(0, 50) + (originalContent.length > 50 ? '...' : '');
            }
        }
        
        // 入力欄に内容を設定
        const input = document.getElementById('messageInput');
        if (input) {
            input.value = originalContent;
            input.focus();
            
            if (Chat.utils && Chat.utils.autoResizeInput) {
                Chat.utils.autoResizeInput(input);
            }
        }
        
        // カードにクラスを追加
        card.classList.add('editing');
    }
    
    /**
     * 編集を保存
     */
    async function saveEdit() {
        if (!editingMessageId) return;
        
        const input = document.getElementById('messageInput');
        const newContent = input ? input.value.trim() : '';
        
        if (!newContent) {
            alert('メッセージを入力してください');
            return;
        }
        
        try {
            const response = await fetch('api/messages.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=edit&message_id=${editingMessageId}&content=${encodeURIComponent(newContent)}`
            });
            
            const data = await response.json();
            
            if (data.success) {
                // UIを更新
                const card = document.querySelector(`[data-message-id="${editingMessageId}"]`);
                if (card) {
                    const contentEl = card.querySelector('.content');
                    if (contentEl) {
                        contentEl.textContent = newContent;
                    }
                    card.dataset.content = newContent;
                    
                    // 編集済みマークを追加
                    const timestamp = card.querySelector('.timestamp');
                    if (timestamp && !timestamp.textContent.includes('編集済み')) {
                        timestamp.textContent += ' (編集済み)';
                    }
                }
                
                cancelEdit();
            } else {
                alert(data.error || '編集に失敗しました');
            }
        } catch (error) {
            console.error('[Messages] Edit error:', error);
            alert('編集に失敗しました');
        }
    }
    
    /**
     * 編集をキャンセル
     */
    function cancelEdit() {
        if (editingMessageId) {
            const card = document.querySelector(`[data-message-id="${editingMessageId}"]`);
            if (card) {
                card.classList.remove('editing');
            }
        }
        
        editingMessageId = null;
        
        const editBar = document.getElementById('editBar');
        if (editBar) {
            editBar.style.display = 'none';
        }
        
        const input = document.getElementById('messageInput');
        if (input) {
            input.value = '';
            if (Chat.utils && Chat.utils.autoResizeInput) {
                Chat.utils.autoResizeInput(input);
            }
        }
    }
    
    /**
     * メッセージに返信
     * @param {number} messageId - メッセージID
     */
    function reply(messageId) {
        const msgCard = document.querySelector(`[data-message-id="${messageId}"]`);
        if (!msgCard) return;
        
        const content = msgCard.dataset.content || '';
        const sender = msgCard.querySelector('.sender')?.textContent || '';
        
        replyingToMessageId = messageId;
        
        // 返信バーを表示
        const replyBar = document.getElementById('replyBar');
        if (replyBar) {
            replyBar.style.display = 'flex';
            const replyPreview = replyBar.querySelector('.reply-preview');
            if (replyPreview) {
                replyPreview.innerHTML = `<strong>${Chat.utils ? Chat.utils.escapeHtml(sender) : sender}:</strong> ${Chat.utils ? Chat.utils.escapeHtml(content.substring(0, 50)) : content.substring(0, 50)}${content.length > 50 ? '...' : ''}`;
            }
        }
        
        // 入力欄にフォーカス
        const input = document.getElementById('messageInput');
        if (input) {
            input.focus();
        }
    }
    
    /**
     * 返信をキャンセル
     */
    function cancelReply() {
        replyingToMessageId = null;
        
        const replyBar = document.getElementById('replyBar');
        if (replyBar) {
            replyBar.style.display = 'none';
        }
    }
    
    /**
     * メッセージを削除
     * @param {number} messageId - メッセージID
     */
    async function deleteMessage(messageId) {
        if (!confirm('このメッセージを削除しますか？')) return;
        
        try {
            const response = await fetch('api/messages.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=delete&message_id=${messageId}`
            });
            
            const data = await response.json();
            
            if (data.success) {
                const card = document.querySelector(`[data-message-id="${messageId}"]`);
                if (card) {
                    card.remove();
                }
            } else {
                alert(data.error || '削除に失敗しました');
            }
        } catch (error) {
            console.error('[Messages] Delete error:', error);
            alert('削除に失敗しました');
        }
    }
    
    /**
     * lastMessageIdを更新
     * @param {number} id - メッセージID
     */
    function updateLastMessageId(id) {
        if (id > lastMessageId) {
            lastMessageId = id;
        }
    }
    
    /**
     * lastMessageIdを取得
     * @returns {number}
     */
    function getLastMessageId() {
        return lastMessageId;
    }
    
    /**
     * 編集中のメッセージIDを取得
     * @returns {number|null}
     */
    function getEditingMessageId() {
        return editingMessageId;
    }
    
    /**
     * 返信中のメッセージIDを取得
     * @returns {number|null}
     */
    function getReplyingToMessageId() {
        return replyingToMessageId;
    }
    
    // 公開API
    Chat.messages = {
        init,
        send,
        edit,
        saveEdit,
        cancelEdit,
        reply,
        cancelReply,
        delete: deleteMessage,
        updateLastMessageId,
        getLastMessageId,
        getEditingMessageId,
        getReplyingToMessageId
    };
    
    // グローバル関数との互換性
    global.sendMessage = send;
    global.editMessage = edit;
    global.saveEdit = saveEdit;
    global.cancelEdit = cancelEdit;
    global.replyToMessage = reply;
    global.cancelReply = cancelReply;
    global.deleteMessage = deleteMessage;
    global.updateLastMessageId = updateLastMessageId;
    
})(typeof window !== 'undefined' ? window : this);
