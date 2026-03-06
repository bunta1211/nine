/**
 * Social9 チャット - 通話機能
 * 分離: 2026-01
 * 
 * 依存: Jitsi Meet API (遅延読み込み)
 * 使用: chat.php, includes/chat/scripts.php
 */

// ============================================
// 通話機能
// ============================================
let jitsiApi = null;
let callStartTime = null;
let callDurationInterval = null;
let isCallActive = false;
let isMicMuted = false;
let isVideoMuted = false;
let isScreenSharing = false;
let localStream = null;
let jitsiApiLoaded = false;

// Jitsi API を遅延読み込み（自前 Jitsi 対応: window.__JITSI_BASE_URL を優先）
function loadJitsiApi() {
    return new Promise((resolve, reject) => {
        if (window.JitsiMeetExternalAPI) {
            jitsiApiLoaded = true;
            resolve();
            return;
        }
        
        const script = document.createElement('script');
        const baseUrl = (typeof window.__JITSI_BASE_URL !== 'undefined' && window.__JITSI_BASE_URL) ? window.__JITSI_BASE_URL : 'https://meet.jit.si';
        script.src = baseUrl.replace(/\/$/, '') + '/external_api.js';
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

function openCallMenu(e) {
    e.stopPropagation();
    
    if (typeof conversationId === 'undefined' || !conversationId) {
        alert('会話を選択してください');
        return;
    }
    
    const menu = document.getElementById('callMenu');
    const rect = e.target.getBoundingClientRect();
    menu.style.top = (rect.bottom + 5) + 'px';
    menu.style.left = rect.left + 'px';
    menu.classList.add('show');
    
    document.addEventListener('click', hideCallMenu, { once: true });
}

function hideCallMenu() {
    document.getElementById('callMenu').classList.remove('show');
}

function startCall(type) {
    hideCallMenu();
    
    if (typeof conversationId === 'undefined' || !conversationId) {
        alert('会話を選択してください');
        return;
    }
    
    if (isCallActive) {
        alert('すでに通話中です');
        return;
    }
    
    // 通話ルーム名を生成（会話IDベース）
    const roomName = `social9_${conversationId}_${Date.now()}`;
    
    // 通話UIを表示
    const videoContainer = document.getElementById('callVideoContainer');
    const controlsContainer = document.getElementById('callControlsContainer');
    const callIndicator = document.getElementById('callStatusIndicator');
    const participantsDiv = document.getElementById('callParticipants');
    
    videoContainer.classList.add('active');
    controlsContainer.classList.add('active');
    callIndicator.classList.add('active');
    isCallActive = true;
    
    // 通話中はページ離脱時に警告
    window.addEventListener('beforeunload', handleBeforeUnload);
    
    // 自分のビデオバブルを追加
    const selfBubble = document.createElement('div');
    selfBubble.className = 'call-video-bubble self';
    selfBubble.id = 'selfVideoBubble';
    const displayName = document.body.dataset.displayName || 'あなた';
    selfBubble.innerHTML = `
        <video id="selfVideo" autoplay muted playsinline></video>
        <div class="user-name-label">${escapeHtml(displayName)}</div>
        <div class="resize-handle">↘</div>
    `;
    
    // 隠しJitsiコンテナ（通話処理用）
    const jitsiHidden = document.createElement('div');
    jitsiHidden.id = 'jitsiContainer';
    jitsiHidden.style.cssText = 'position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;overflow:hidden;';
    document.body.appendChild(jitsiHidden);
    participantsDiv.appendChild(selfBubble);
    
    // 通話時間カウント開始
    callStartTime = new Date();
    callDurationInterval = setInterval(updateCallDuration, 1000);
    
    // カメラ映像を直接表示
    initLocalCamera(type === 'video');
    
    // Jitsi Meet を初期化（通話処理用）
    initJitsiMeet(roomName, type === 'video');
}

// ローカルカメラ初期化
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
        
    } catch (error) {
        console.error('カメラ/マイクアクセスエラー:', error);
        if (error.name === 'NotAllowedError') {
            alert('カメラとマイクへのアクセスを許可してください。');
        } else if (error.name === 'NotFoundError') {
            alert('カメラまたはマイクが見つかりません。');
        } else {
            alert('カメラ/マイクの初期化に失敗しました: ' + error.message);
        }
    }
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
    
    const displayName = document.body.dataset.displayName || 'ユーザー';
    
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
            subject: ' ',
            disableModeratorIndicator: true,
            disableReactions: true,
            disablePolls: true,
            hideLobbyButton: true,
            requireDisplayName: false,
            disableSelfView: false,
            disableSelfViewSettings: true,
            filmstrip: { disabled: true },
            notifications: [],
            disableNotifications: true,
            hideParticipantsStats: true,
            disableRemoteMute: true,
            remoteVideoMenu: { disabled: true },
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
            displayName: displayName
        }
    };
    
    try {
        const jitsiDomain = (typeof window.__JITSI_DOMAIN !== 'undefined' && window.__JITSI_DOMAIN) ? window.__JITSI_DOMAIN : 'meet.jit.si';
        jitsiApi = new JitsiMeetExternalAPI(jitsiDomain, options);
        
        // イベントリスナー
        jitsiApi.addListener('participantJoined', (participant) => {
            console.log('参加者が入室:', participant);
            addParticipantBubble(participant);
        });
        
        jitsiApi.addListener('participantLeft', (participant) => {
            console.log('参加者が退出:', participant);
            removeParticipantBubble(participant.id);
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
    
    if (document.getElementById(`participant-${participant.id}`)) {
        return;
    }
    
    const bubble = document.createElement('div');
    bubble.className = 'call-video-bubble';
    bubble.id = `participant-${participant.id}`;
    bubble.innerHTML = `
        <div class="participant-avatar">${(participant.displayName || '?').charAt(0)}</div>
        <div class="user-name-label">${escapeHtml(participant.displayName || '参加者')}</div>
    `;
    participantsDiv.appendChild(bubble);
}

function removeParticipantBubble(participantId) {
    const bubble = document.getElementById(`participant-${participantId}`);
    if (bubble) {
        bubble.remove();
    }
}

function updateCallDuration() {
    if (!callStartTime) return;
    
    const now = new Date();
    const diff = Math.floor((now - callStartTime) / 1000);
    const minutes = Math.floor(diff / 60).toString().padStart(2, '0');
    const seconds = (diff % 60).toString().padStart(2, '0');
    
    document.getElementById('callDuration').textContent = `${minutes}:${seconds}`;
}

function toggleMic() {
    if (!jitsiApi) return;
    
    jitsiApi.executeCommand('toggleAudio');
    
    // ローカルストリームのマイクも切り替え
    if (localStream) {
        const audioTracks = localStream.getAudioTracks();
        audioTracks.forEach(track => {
            track.enabled = isMicMuted;
        });
    }
}

function updateMicButton() {
    const btn = document.getElementById('micToggleBtn');
    if (btn) {
        btn.innerHTML = isMicMuted ? '🔇' : '🎤';
        btn.classList.toggle('muted', isMicMuted);
    }
}

function toggleVideo() {
    if (!jitsiApi) return;
    
    jitsiApi.executeCommand('toggleVideo');
    
    // ローカルストリームのビデオも切り替え
    if (localStream) {
        const videoTracks = localStream.getVideoTracks();
        videoTracks.forEach(track => {
            track.enabled = isVideoMuted;
        });
    }
}

function updateVideoButton() {
    const btn = document.getElementById('videoToggleBtn');
    if (btn) {
        btn.innerHTML = isVideoMuted ? '📷' : '📹';
        btn.classList.toggle('muted', isVideoMuted);
    }
}

function toggleScreenShare() {
    if (!jitsiApi) return;
    jitsiApi.executeCommand('toggleShareScreen');
}

function toggleBackgroundBlur() {
    if (!jitsiApi) return;
    
    const btn = document.getElementById('blurToggleBtn');
    const isBlurred = btn.classList.contains('active');
    
    if (isBlurred) {
        jitsiApi.executeCommand('setVideoQuality', 720);
        btn.classList.remove('active');
    } else {
        btn.classList.add('active');
    }
}

function openVirtualBackgroundSelector() {
    // バーチャル背景モーダルを開く（実装は別途）
    console.log('Virtual background selector - not implemented');
}

function handleBeforeUnload(e) {
    if (isCallActive) {
        e.preventDefault();
        e.returnValue = '通話中です。本当にページを離れますか？';
        return e.returnValue;
    }
}

function endCall() {
    window.removeEventListener('beforeunload', handleBeforeUnload);
    
    // ローカルストリームを停止
    if (localStream) {
        localStream.getTracks().forEach(track => track.stop());
        localStream = null;
    }
    
    // Jitsiを終了
    if (jitsiApi) {
        jitsiApi.dispose();
        jitsiApi = null;
    }
    
    // Jitsiコンテナを削除
    const jitsiContainer = document.getElementById('jitsiContainer');
    if (jitsiContainer) {
        jitsiContainer.remove();
    }
    
    // 通話UIを非表示
    const videoContainer = document.getElementById('callVideoContainer');
    const controlsContainer = document.getElementById('callControlsContainer');
    const callIndicator = document.getElementById('callStatusIndicator');
    const participantsDiv = document.getElementById('callParticipants');
    
    if (videoContainer) videoContainer.classList.remove('active');
    if (controlsContainer) controlsContainer.classList.remove('active');
    if (callIndicator) callIndicator.classList.remove('active');
    if (participantsDiv) participantsDiv.innerHTML = '';
    
    // タイマー停止
    if (callDurationInterval) {
        clearInterval(callDurationInterval);
        callDurationInterval = null;
    }
    
    callStartTime = null;
    isCallActive = false;
    isMicMuted = false;
    isVideoMuted = false;
    
    updateMicButton();
    updateVideoButton();
}

// エスケープ関数（依存）
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
