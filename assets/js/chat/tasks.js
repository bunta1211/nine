/**
 * タスク・メモ機能モジュール
 * 
 * メッセージからタスクやメモを作成する機能
 * 
 * 使用例:
 * Chat.tasks.addFromMessage(messageId);
 * Chat.memos.addFromMessage(messageId);
 */

(function(global) {
    'use strict';
    
    // チャット名前空間を確認
    global.Chat = global.Chat || {};
    
    // ========================================
    // タスク機能
    // ========================================
    
    const tasks = {
        /**
         * 初期化
         */
        init: function() {
            console.log('[Tasks] Initialized');
        },
        
        /**
         * メッセージからタスクを追加
         * @param {number} messageId - メッセージID
         */
        addFromMessage: async function(messageId) {
            const card = document.querySelector(`[data-message-id="${messageId}"]`);
            if (!card) {
                alert('メッセージが見つかりません');
                return;
            }
            
            const contentEl = card.querySelector('.content');
            const content = contentEl ? contentEl.textContent.trim() : '';
            
            // タスクモーダルを開く
            this.openModal(messageId, content);
        },
        
        /**
         * タスクモーダルを開く
         * @param {number} messageId - メッセージID
         * @param {string} content - コンテンツ
         */
        openModal: async function(messageId, content) {
            const modal = document.getElementById('manualTaskModal') || document.getElementById('manualWishModal');
            if (!modal) {
                console.error('[Tasks] Modal not found');
                return;
            }
            
            // タイトルを設定
            const titleInput = modal.querySelector('#taskTitleModal, #wishContentModal');
            if (titleInput) {
                titleInput.value = content.substring(0, 200);
            }
            
            // メッセージIDを保存
            modal.dataset.messageId = messageId;
            
            // 担当者リストを読み込み
            await this.populateAssignees();
            
            // モーダルを表示
            modal.style.display = 'flex';
        },
        
        /**
         * 担当者リストを取得
         */
        populateAssignees: async function() {
            const select = document.getElementById('taskAssignToModal');
            if (!select) return;
            
            try {
                const response = await fetch('api/users.php?action=list');
                const data = await response.json();
                
                if (data.success && data.users) {
                    select.innerHTML = '<option value="">自分</option>';
                    data.users.forEach(user => {
                        select.innerHTML += `<option value="${user.id}">${Chat.utils ? Chat.utils.escapeHtml(user.display_name) : user.display_name}</option>`;
                    });
                }
            } catch (error) {
                console.error('[Tasks] Failed to load users:', error);
            }
        },
        
        /**
         * タスクを保存
         */
        save: async function() {
            const modal = document.getElementById('manualTaskModal') || document.getElementById('manualWishModal');
            if (!modal) return;
            
            const titleInput = modal.querySelector('#taskTitleModal, #wishContentModal');
            const dueDateInput = document.getElementById('taskDueDateModal');
            const prioritySelect = document.getElementById('taskPriorityModal');
            const assignToSelect = document.getElementById('taskAssignToModal');
            
            const title = titleInput ? titleInput.value.trim() : '';
            if (!title) {
                alert('タイトルを入力してください');
                return;
            }
            
            const data = {
                action: 'create',
                title: title,
                message_id: modal.dataset.messageId || null,
                due_date: dueDateInput ? dueDateInput.value : null,
                priority: prioritySelect ? prioritySelect.value : 'medium',
                assigned_to: assignToSelect && assignToSelect.value ? assignToSelect.value : null
            };
            
            try {
                const response = await fetch('api/tasks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    modal.style.display = 'none';
                    this.updateBadge();
                    
                    // 通知
                    if (typeof showToast === 'function') {
                        showToast('タスクを追加しました');
                    }
                } else {
                    alert(result.error || '保存に失敗しました');
                }
            } catch (error) {
                console.error('[Tasks] Save error:', error);
                alert('保存に失敗しました');
            }
        },
        
        /**
         * バッジを更新
         */
        updateBadge: async function() {
            try {
                const response = await fetch('api/tasks.php?action=count');
                const data = await response.json();
                
                if (data.success) {
                    const badge = document.querySelector('.task-badge, .wish-badge');
                    if (badge) {
                        const count = data.count || 0;
                        badge.textContent = count;
                        badge.style.display = count > 0 ? 'inline-block' : 'none';
                    }
                }
            } catch (error) {
                console.error('[Tasks] Badge update error:', error);
            }
        },
        
        /**
         * モーダルを閉じる
         */
        closeModal: function() {
            const modal = document.getElementById('manualTaskModal') || document.getElementById('manualWishModal');
            if (modal) {
                modal.style.display = 'none';
            }
        }
    };
    
    // ========================================
    // メモ機能
    // ========================================
    
    const memos = {
        /**
         * 初期化
         */
        init: function() {
            console.log('[Memos] Initialized');
        },
        
        /**
         * メッセージからメモを追加
         * @param {number} messageId - メッセージID
         */
        addFromMessage: async function(messageId) {
            try {
                const response = await fetch('api/tasks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'create', type: 'memo', message_id: messageId })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    this.updateBadge();
                    
                    if (typeof showToast === 'function') {
                        showToast('メモに追加しました');
                    }
                } else {
                    alert(data.message || 'メモの追加に失敗しました');
                }
            } catch (error) {
                console.error('[Memos] Add error:', error);
                alert('メモの追加に失敗しました');
            }
        },
        
        /**
         * バッジを更新
         */
        updateBadge: async function() {
            try {
                const response = await fetch('api/tasks.php?action=count&type=memo');
                const data = await response.json();
                
                if (data.success) {
                    const badge = document.querySelector('.memo-badge');
                    if (badge) {
                        const count = data.count || 0;
                        badge.textContent = count;
                        badge.style.display = count > 0 ? 'inline-block' : 'none';
                    }
                }
            } catch (error) {
                console.error('[Memos] Badge update error:', error);
            }
        }
    };
    
    // 公開API
    Chat.tasks = tasks;
    Chat.memos = memos;
    
    // グローバル関数との互換性
    global.addToTask = tasks.addFromMessage.bind(tasks);
    global.addToWish = tasks.addFromMessage.bind(tasks);  // 互換性
    global.addToMemo = memos.addFromMessage.bind(memos);
    global.updateTaskBadge = tasks.updateBadge.bind(tasks);
    global.updateWishBadge = tasks.updateBadge.bind(tasks);  // 互換性
    global.updateMemoBadge = memos.updateBadge.bind(memos);
    global.openManualTaskModal = tasks.openModal.bind(tasks);
    global.openManualWishModal = tasks.openModal.bind(tasks);  // 互換性
    global.saveTaskFromMessage = tasks.save.bind(tasks);
    global.saveManualWish = tasks.save.bind(tasks);  // 互換性
    global.populateTaskAssignTo = tasks.populateAssignees.bind(tasks);
    
})(typeof window !== 'undefined' ? window : this);
