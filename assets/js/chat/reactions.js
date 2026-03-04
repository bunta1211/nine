/**
 * リアクション機能モジュール
 * 
 * メッセージへのリアクション（絵文字）機能
 * 
 * 使用例:
 * Chat.reactions.toggle(messageId, reaction);
 * Chat.reactions.showPicker(messageId, event);
 */

(function(global) {
    'use strict';
    
    // チャット名前空間を確認
    global.Chat = global.Chat || {};
    
    // デフォルト絵文字リスト（🙇＝ありがとう）
    const DEFAULT_EMOJIS = ['👍', '❤️', '😊', '😂', '😮', '😢', '🎉', '👏', '🙏', '🙇', '🔥', '💯'];
    
    // 内部状態
    let targetMessageId = null;
    let emojis = DEFAULT_EMOJIS;
    
    /**
     * 初期化
     * @param {Object} options - オプション
     */
    function init(options = {}) {
        if (options.emojis) {
            emojis = options.emojis;
        }
        
        // ピッカー外クリックで閉じる
        document.addEventListener('click', function(e) {
            const picker = document.getElementById('reactionPicker');
            if (picker && picker.style.display !== 'none') {
                if (!picker.contains(e.target) && !e.target.closest('.reaction-trigger')) {
                    hidePicker();
                }
            }
        });
        
        console.log('[Reactions] Initialized');
    }
    
    /**
     * リアクションピッカーを表示
     * @param {number} messageId - メッセージID
     * @param {Event} event - イベント
     */
    function showPicker(messageId, event) {
        if (event) {
            event.stopPropagation();
        }
        
        const picker = document.getElementById('reactionPicker');
        const msgCard = document.querySelector(`[data-message-id="${messageId}"]`);
        
        if (!picker || !msgCard) {
            console.error('[Reactions] Picker or message card not found');
            return;
        }
        
        targetMessageId = messageId;
        
        // ピッカーの内容を生成
        picker.innerHTML = emojis.map(emoji => 
            `<button class="reaction-emoji" onclick="Chat.reactions.add(${messageId}, '${emoji}')">${emoji}</button>`
        ).join('');
        
        // 位置を計算
        const cardRect = msgCard.getBoundingClientRect();
        const isOwn = msgCard.classList.contains('own');
        
        picker.style.display = 'flex';
        picker.style.position = 'fixed';
        picker.style.zIndex = '10000';
        
        // 横位置
        if (isOwn) {
            picker.style.right = (window.innerWidth - cardRect.right) + 'px';
            picker.style.left = 'auto';
        } else {
            picker.style.left = cardRect.left + 'px';
            picker.style.right = 'auto';
        }
        
        // 縦位置
        picker.style.top = (cardRect.top - picker.offsetHeight - 10) + 'px';
        
        // 画面外に出ないよう調整
        const pickerRect = picker.getBoundingClientRect();
        if (pickerRect.top < 0) {
            picker.style.top = (cardRect.bottom + 10) + 'px';
        }
    }
    
    /**
     * リアクションピッカーを非表示
     */
    function hidePicker() {
        const picker = document.getElementById('reactionPicker');
        if (picker) {
            picker.style.display = 'none';
        }
        targetMessageId = null;
    }
    
    /**
     * リアクションを追加/トグル
     * @param {number} messageId - メッセージID
     * @param {string} reaction - リアクション絵文字
     */
    async function add(messageId, reaction) {
        messageId = messageId || targetMessageId;
        if (!messageId) return;
        
        hidePicker();
        
        try {
            const response = await fetch('api/messages.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=add_reaction&message_id=${messageId}&reaction=${encodeURIComponent(reaction)}`
            });
            
            const data = await response.json();
            
            if (data.success) {
                // UIを更新
                updateMessageReactions(messageId, data.reactions || []);
            } else {
                console.error('[Reactions] Add failed:', data.error);
                if (typeof Toast !== 'undefined' && Toast.error) {
                    Toast.error(data.error || 'リアクションの保存に失敗しました');
                }
            }
        } catch (error) {
            console.error('[Reactions] Add error:', error);
            if (typeof Toast !== 'undefined' && Toast.error) {
                Toast.error('リアクションの保存に失敗しました');
            }
        }
    }
    
    /**
     * リアクションをトグル（既存の場合は削除）
     * @param {number} messageId - メッセージID
     * @param {string} reaction - リアクション絵文字
     */
    async function toggle(messageId, reaction) {
        await add(messageId, reaction);
    }
    
    /**
     * リアクションを削除
     * @param {number} messageId - メッセージID
     * @param {string} reaction - リアクション絵文字
     */
    async function remove(messageId, reaction) {
        try {
            const response = await fetch('api/messages.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=remove_reaction&message_id=${messageId}&reaction=${encodeURIComponent(reaction)}`
            });
            
            const data = await response.json();
            
            if (data.success) {
                updateMessageReactions(messageId, data.reactions || []);
            } else {
                console.error('[Reactions] Remove failed:', data.error);
                if (typeof Toast !== 'undefined' && Toast.error) {
                    Toast.error(data.error || 'リアクションの削除に失敗しました');
                }
            }
        } catch (error) {
            console.error('[Reactions] Remove error:', error);
            if (typeof Toast !== 'undefined' && Toast.error) {
                Toast.error('リアクションの削除に失敗しました');
            }
        }
    }
    
    /**
     * メッセージのリアクション表示を更新
     * @param {number} messageId - メッセージID
     * @param {Array} reactions - リアクション配列
     */
    function updateMessageReactions(messageId, reactions) {
        const msgCard = document.querySelector(`[data-message-id="${messageId}"]`);
        if (!msgCard) return;
        
        let reactionsContainer = msgCard.querySelector('.message-reactions');
        
        if (reactions.length === 0) {
            if (reactionsContainer) {
                reactionsContainer.remove();
            }
            return;
        }
        
        // コンテナがなければ作成
        if (!reactionsContainer) {
            reactionsContainer = document.createElement('div');
            reactionsContainer.className = 'message-reactions';
            
            const content = msgCard.querySelector('.content');
            if (content) {
                content.parentNode.insertBefore(reactionsContainer, content.nextSibling);
            } else {
                msgCard.appendChild(reactionsContainer);
            }
        }
        
        // リアクションを描画（絵文字のみ表示、ホバーで誰がしたか表示）
        reactionsContainer.innerHTML = reactions.map(r => {
            const type = r.reaction_type || r.type || '';
            const names = (r.users && r.users.length) ? r.users.map(u => u.name).join(', ') : '';
            const title = names ? names : 'クリックでリアクション';
            const typeEsc = type.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
            return `<span class="reaction-badge ${r.is_mine ? 'my-reaction' : ''}" 
                   onclick="Chat.reactions.toggle(${messageId}, '${typeEsc}')" title="${title.replace(/"/g, '&quot;')}">${type}</span>`;
        }).join('');
    }
    
    /**
     * リアクションHTMLを生成
     * @param {number} messageId - メッセージID
     * @param {Array} reactions - リアクション配列
     * @returns {string} HTML文字列
     */
    function renderReactionsHtml(messageId, reactions) {
        if (!reactions || reactions.length === 0) return '';
        
        let html = '<div class="message-reactions">';
        reactions.forEach(r => {
            const type = r.reaction_type || r.type || '';
            const names = (r.users && r.users.length) ? r.users.map(u => u.name).join(', ') : '';
            const title = names ? names : 'クリックでリアクション';
            const typeEsc = type.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
            html += `<span class="reaction-badge ${r.is_mine ? 'my-reaction' : ''}" 
                          onclick="Chat.reactions.toggle(${messageId}, '${typeEsc}')" title="${title.replace(/"/g, '&quot;')}">${type}</span>`;
        });
        html += '</div>';
        
        return html;
    }
    
    // 公開API
    Chat.reactions = {
        init,
        showPicker,
        hidePicker,
        add,
        toggle,
        remove,
        updateMessageReactions,
        renderReactionsHtml
    };
    
    // グローバル関数との互換性
    global.toggleReactionPicker = showPicker;
    global.addReaction = add;
    global.toggleReaction = toggle;
    
})(typeof window !== 'undefined' ? window : this);
