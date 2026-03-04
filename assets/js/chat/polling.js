/**
 * ポーリングモジュール
 * 
 * メッセージ、会話リスト、通知の定期取得
 * 
 * 使用例:
 * Chat.polling.start();
 * Chat.polling.stop();
 */

(function(global) {
    'use strict';
    
    // チャット名前空間を確認
    global.Chat = global.Chat || {};
    
    // デフォルト設定
    const DEFAULT_INTERVALS = {
        messages: 3000,       // 3秒
        conversations: 10000, // 10秒
        heartbeat: 120000,    // 2分
        notifications: 30000  // 30秒
    };
    
    // 内部状態
    let intervals = { ...DEFAULT_INTERVALS };
    let timers = {
        messages: null,
        conversations: null,
        heartbeat: null,
        notifications: null
    };
    let isActive = true;
    let previousNotificationCount = -1;
    let isPaused = false;
    let inactiveMultiplier = 3;
    
    /**
     * 初期化
     * @param {Object} options - オプション
     */
    function init(options = {}) {
        if (options.intervals) {
            intervals = { ...intervals, ...options.intervals };
        }
        if (options.inactiveMultiplier) {
            inactiveMultiplier = options.inactiveMultiplier;
        }
        
        // ページの可視性変更を監視
        document.addEventListener('visibilitychange', handleVisibilityChange);
        
        // ユーザーアクティビティを監視
        ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(event => {
            document.addEventListener(event, handleUserActivity, { passive: true });
        });
        
        console.log('[Polling] Initialized');
    }
    
    /**
     * ポーリングを開始
     */
    function start() {
        if (isPaused) return;
        
        console.log('[Polling] Starting');
        
        // メッセージポーリング
        startMessagePolling();
        
        // 会話リストポーリング
        startConversationPolling();
        
        // ハートビート
        startHeartbeat();
        
        // 通知ポーリング
        startNotificationPolling();
    }
    
    /**
     * ポーリングを停止
     */
    function stop() {
        console.log('[Polling] Stopping');
        
        Object.keys(timers).forEach(key => {
            if (timers[key]) {
                clearTimeout(timers[key]);
                timers[key] = null;
            }
        });
    }
    
    /**
     * ポーリングを一時停止
     */
    function pause() {
        isPaused = true;
        stop();
    }
    
    /**
     * ポーリングを再開
     */
    function resume() {
        isPaused = false;
        start();
    }
    
    /**
     * メッセージポーリングを開始
     */
    function startMessagePolling() {
        if (timers.messages) clearTimeout(timers.messages);
        
        async function poll() {
            if (isPaused) return;
            
            const conversationId = Chat.config ? Chat.config.conversationId : window.currentConversationId;
            if (!conversationId) {
                timers.messages = setTimeout(poll, getInterval('messages'));
                return;
            }
            
            try {
                const lastId = Chat.messages ? Chat.messages.getLastMessageId() : (window.lastMessageId || 0);
                const response = await fetch(`api/messages.php?action=poll&conversation_id=${conversationId}&last_id=${lastId}`);
                const data = await response.json();
                
                if (data.success && data.messages && data.messages.length > 0) {
                    // 新しいメッセージを処理
                    data.messages.forEach(msg => {
                        if (typeof window.appendMessageToUI === 'function') {
                            window.appendMessageToUI(msg);
                        }
                        if (Chat.messages) {
                            Chat.messages.updateLastMessageId(msg.id);
                        }
                    });
                }
            } catch (error) {
                console.error('[Polling] Message poll error:', error);
            }
            
            timers.messages = setTimeout(poll, getInterval('messages'));
        }
        
        timers.messages = setTimeout(poll, getInterval('messages'));
    }
    
    /**
     * 会話リストポーリングを開始
     */
    function startConversationPolling() {
        if (timers.conversations) clearTimeout(timers.conversations);
        
        async function poll() {
            if (isPaused) return;
            
            try {
                const response = await fetch('api/conversations.php?action=list');
                const data = await response.json();
                
                if (data.success && data.conversations) {
                    // 会話リストを更新
                    if (typeof window.updateConversationList === 'function') {
                        window.updateConversationList(data.conversations);
                    }
                }
            } catch (error) {
                console.error('[Polling] Conversation poll error:', error);
            }
            
            timers.conversations = setTimeout(poll, getInterval('conversations'));
        }
        
        timers.conversations = setTimeout(poll, getInterval('conversations'));
    }
    
    /**
     * ハートビートを開始
     */
    function startHeartbeat() {
        if (timers.heartbeat) clearTimeout(timers.heartbeat);
        
        async function beat() {
            if (isPaused) return;
            
            try {
                await fetch('api/status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=heartbeat'
                });
            } catch (error) {
                console.error('[Polling] Heartbeat error:', error);
            }
            
            timers.heartbeat = setTimeout(beat, getInterval('heartbeat'));
        }
        
        // 初回は即座に実行
        beat();
    }
    
    /**
     * 通知ポーリングを開始
     */
    function startNotificationPolling() {
        if (timers.notifications) clearTimeout(timers.notifications);
        
        async function poll() {
            if (isPaused) return;
            
            try {
                const response = await fetch('api/notifications.php?action=count');
                const data = await response.json();
                
                if (data.success) {
                    const count = data.total ?? data.count ?? 0;
                    updateNotificationBadge(count);
                    // 通知数が増えた場合、ブラウザの通知許可ホップを表示
                    if (count > 0 && count > previousNotificationCount && previousNotificationCount >= 0) {
                        if (typeof window.PushNotifications !== 'undefined' && typeof window.PushNotifications.showNotificationPermissionHop === 'function') {
                            window.PushNotifications.showNotificationPermissionHop();
                        }
                    }
                    previousNotificationCount = count;
                }
            } catch (error) {
                console.error('[Polling] Notification poll error:', error);
            }
            
            timers.notifications = setTimeout(poll, getInterval('notifications'));
        }
        
        timers.notifications = setTimeout(poll, getInterval('notifications'));
    }
    
    /**
     * 通知バッジを更新
     * @param {number} count - 通知数
     */
    function updateNotificationBadge(count) {
        const badge = document.querySelector('.notification-badge');
        if (badge) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = count > 0 ? 'inline-block' : 'none';
        }
    }
    
    /**
     * 間隔を取得（アクティブ/非アクティブで調整）
     * @param {string} type - ポーリングタイプ
     * @returns {number} 間隔（ms）
     */
    function getInterval(type) {
        const base = intervals[type] || 5000;
        return isActive ? base : base * inactiveMultiplier;
    }
    
    /**
     * ページの可視性変更ハンドラ
     */
    function handleVisibilityChange() {
        if (document.hidden) {
            isActive = false;
            console.log('[Polling] Page hidden, slowing down');
        } else {
            isActive = true;
            console.log('[Polling] Page visible, resuming normal speed');
            // 即座にポーリングを再開
            startMessagePolling();
        }
    }
    
    /**
     * ユーザーアクティビティハンドラ
     */
    let activityTimeout = null;
    function handleUserActivity() {
        isActive = true;
        
        // 30秒間操作がなければ非アクティブとみなす
        clearTimeout(activityTimeout);
        activityTimeout = setTimeout(() => {
            isActive = false;
        }, 30000);
    }
    
    /**
     * 間隔を設定
     * @param {string} type - ポーリングタイプ
     * @param {number} ms - 間隔（ms）
     */
    function setInterval(type, ms) {
        if (intervals.hasOwnProperty(type)) {
            intervals[type] = ms;
        }
    }
    
    /**
     * アクティブ状態を取得
     * @returns {boolean}
     */
    function isUserActive() {
        return isActive;
    }
    
    // 公開API
    Chat.polling = {
        init,
        start,
        stop,
        pause,
        resume,
        setInterval,
        isUserActive,
        startMessagePolling,
        startConversationPolling,
        startHeartbeat,
        startNotificationPolling
    };
    
    // グローバル関数との互換性
    global.startPolling = start;
    global.stopPolling = stop;
    global.pausePolling = pause;
    global.resumePolling = resume;
    
})(typeof window !== 'undefined' ? window : this);
