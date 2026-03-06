/**
 * 通話機能モジュール
 * 
 * 音声/ビデオ通話の管理（Jitsi Meet連携）
 * 
 * 使用例:
 * Chat.call.start(conversationId, { video: true });
 * Chat.call.join(roomName);
 * Chat.call.leave();
 */

(function(global) {
    'use strict';
    
    // チャット名前空間を確認
    global.Chat = global.Chat || {};
    
    // 内部状態（自前 Jitsi 対応: window.__JITSI_DOMAIN を優先）
    let api = null;
    let currentRoom = null;
    let isInCall = false;
    let domain = (typeof window.__JITSI_DOMAIN !== 'undefined' && window.__JITSI_DOMAIN) ? window.__JITSI_DOMAIN : 'meet.jit.si';
    
    /**
     * 初期化
     * @param {Object} options - オプション
     */
    function init(options = {}) {
        if (options.domain) {
            domain = options.domain;
        } else if (typeof window.__JITSI_DOMAIN !== 'undefined' && window.__JITSI_DOMAIN) {
            domain = window.__JITSI_DOMAIN;
        }
        
        console.log('[Call] Initialized');
    }
    
    /**
     * 通話を開始
     * @param {number} conversationId - 会話ID
     * @param {Object} options - オプション
     */
    async function start(conversationId, options = {}) {
        if (isInCall) {
            console.warn('[Call] Already in a call');
            return;
        }
        
        try {
            // 通話ルームを作成
            const response = await fetch('api/calls.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=create&conversation_id=${conversationId}&video=${options.video ? 1 : 0}`
            });
            
            const data = await response.json();
            
            if (data.success && data.room_name) {
                join(data.room_name, options);
            } else {
                console.error('[Call] Failed to create call:', data.error);
                alert('通話の開始に失敗しました');
            }
        } catch (error) {
            console.error('[Call] Start error:', error);
            alert('通話の開始に失敗しました');
        }
    }
    
    /**
     * 通話に参加
     * @param {string} roomName - ルーム名
     * @param {Object} options - オプション
     */
    function join(roomName, options = {}) {
        if (isInCall) {
            leave();
        }
        
        currentRoom = roomName;
        isInCall = true;
        
        // 通話UIを表示
        showCallUI(roomName, options);
        
        // ポーリングを一時停止
        if (Chat.polling) {
            Chat.polling.pause();
        }
    }
    
    /**
     * 通話を終了
     */
    function leave() {
        if (api) {
            api.dispose();
            api = null;
        }
        
        currentRoom = null;
        isInCall = false;
        
        // 通話UIを非表示
        hideCallUI();
        
        // ポーリングを再開
        if (Chat.polling) {
            Chat.polling.resume();
        }
        
        console.log('[Call] Left call');
    }
    
    /**
     * 通話UIを表示
     * @param {string} roomName - ルーム名
     * @param {Object} options - オプション
     */
    function showCallUI(roomName, options = {}) {
        let container = document.getElementById('callContainer');
        
        if (!container) {
            container = document.createElement('div');
            container.id = 'callContainer';
            container.className = 'call-container';
            container.innerHTML = `
                <div class="call-header">
                    <span class="call-title">通話中</span>
                    <button class="call-leave-btn" onclick="Chat.call.leave()">通話を終了</button>
                </div>
                <div id="jitsiContainer" class="jitsi-container"></div>
            `;
            document.body.appendChild(container);
        }
        
        container.style.display = 'flex';
        
        // Jitsi APIをロード
        loadJitsiAPI(() => {
            initJitsi(roomName, options);
        });
    }
    
    /**
     * 通話UIを非表示
     */
    function hideCallUI() {
        const container = document.getElementById('callContainer');
        if (container) {
            container.style.display = 'none';
        }
    }
    
    /**
     * Jitsi APIをロード
     * @param {Function} callback - コールバック
     */
    function loadJitsiAPI(callback) {
        if (window.JitsiMeetExternalAPI) {
            callback();
            return;
        }
        
        const script = document.createElement('script');
        const baseUrl = (typeof window.__JITSI_BASE_URL !== 'undefined' && window.__JITSI_BASE_URL) ? window.__JITSI_BASE_URL : 'https://meet.jit.si';
        script.src = baseUrl.replace(/\/$/, '') + '/external_api.js';
        script.onload = callback;
        script.onerror = () => {
            console.error('[Call] Failed to load Jitsi API');
            alert('通話機能の読み込みに失敗しました');
            leave();
        };
        document.head.appendChild(script);
    }
    
    /**
     * Jitsiを初期化
     * @param {string} roomName - ルーム名
     * @param {Object} options - オプション
     */
    function initJitsi(roomName, options = {}) {
        const container = document.getElementById('jitsiContainer');
        if (!container) return;
        
        const userName = Chat.config ? Chat.config.displayName : (window.displayName || 'ゲスト');
        
        api = new JitsiMeetExternalAPI(domain, {
            roomName: roomName,
            parentNode: container,
            userInfo: {
                displayName: userName
            },
            configOverwrite: {
                startWithAudioMuted: !options.audio,
                startWithVideoMuted: !options.video,
                prejoinPageEnabled: false,
                disableDeepLinking: true,
                hideConferenceTimer: true,
                subject: ' ',
                disableModeratorIndicator: true,
                disableReactions: true,
                disablePolls: true,
                hideLobbyButton: true,
                requireDisplayName: false
            },
            interfaceConfigOverwrite: {
                TOOLBAR_BUTTONS: [
                    'microphone', 'camera', 'desktop', 'chat',
                    'raisehand', 'tileview', 'hangup'
                ],
                SHOW_JITSI_WATERMARK: false,
                SHOW_WATERMARK_FOR_GUESTS: false,
                SHOW_BRAND_WATERMARK: false,
                BRAND_WATERMARK_LINK: '',
                MOBILE_APP_PROMO: false,
                DISABLE_JOIN_LEAVE_NOTIFICATIONS: true,
                HIDE_INVITE_MORE_HEADER: true
            }
        });
        
        // イベントリスナー
        api.addListener('readyToClose', leave);
        api.addListener('participantLeft', handleParticipantLeft);
        
        console.log('[Call] Jitsi initialized for room:', roomName);
    }
    
    /**
     * 参加者退出ハンドラ
     */
    function handleParticipantLeft(event) {
        // 全員退出したら通話を終了
        const participants = api.getParticipantsInfo();
        if (participants.length === 0) {
            leave();
        }
    }
    
    /**
     * マイクをトグル
     */
    function toggleMute() {
        if (api) {
            api.executeCommand('toggleAudio');
        }
    }
    
    /**
     * ビデオをトグル
     */
    function toggleVideo() {
        if (api) {
            api.executeCommand('toggleVideo');
        }
    }
    
    /**
     * 画面共有をトグル
     */
    function toggleScreenShare() {
        if (api) {
            api.executeCommand('toggleShareScreen');
        }
    }
    
    /**
     * 通話中かどうか
     * @returns {boolean}
     */
    function isInCallNow() {
        return isInCall;
    }
    
    /**
     * 現在のルーム名を取得
     * @returns {string|null}
     */
    function getCurrentRoom() {
        return currentRoom;
    }
    
    // 公開API
    Chat.call = {
        init,
        start,
        join,
        leave,
        toggleMute,
        toggleVideo,
        toggleScreenShare,
        isInCall: isInCallNow,
        getCurrentRoom
    };
    
    // グローバル関数との互換性
    global.startCall = start;
    global.joinCall = join;
    global.leaveCall = leave;
    global.toggleCallMute = toggleMute;
    global.toggleCallVideo = toggleVideo;
    
})(typeof window !== 'undefined' ? window : this);
