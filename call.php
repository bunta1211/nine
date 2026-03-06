<?php
/**
 * Social9 通話画面（Jitsi Meet統合）
 * 統合計画: 通話はこのページのみ。call_id で参加。
 * 仕様書: 06_通話機能.md
 */

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/design_loader.php';
require_once __DIR__ . '/includes/lang.php';

requireLogin();

$pdo = getDB();
$user_id = $_SESSION['user_id'];
$designSettings = getDesignSettings($pdo, $user_id);
$display_name = $_SESSION['display_name'] ?? 'ユーザー';

$call_id_param = (int)($_GET['call_id'] ?? 0);
$conversation_id = (int)($_GET['c'] ?? 0);
$call_type_param = $_GET['type'] ?? '';

$call = null;
$conversation = null;
$room_name = '';
$conversation_name = '';
$call_type = 'video';
$members = [];

if ($call_id_param > 0) {
    // call_id 指定: 通話レコードから room_id 等を取得
    $stmt = $pdo->prepare("
        SELECT c.* FROM calls c
        INNER JOIN conversation_members cm ON c.conversation_id = cm.conversation_id AND cm.user_id = ? AND cm.left_at IS NULL
        WHERE c.id = ? AND c.status IN ('ringing', 'active')
    ");
    $stmt->execute([$user_id, $call_id_param]);
    $call = $stmt->fetch();
    if (!$call) {
        die('通話が見つからないか、すでに終了しています。チャットから通話を開始してください。');
    }
    $room_name = $call['room_id'];
    $call_type = ($call['call_type'] ?? 'video') === 'audio' ? 'audio' : 'video';
    $conversation_id = (int)$call['conversation_id'];
}

if ($conversation_id > 0 && !$call) {
    // 後方互換: c= のみの場合は、この会話のアクティブな通話に参加しているか確認
    $stmt = $pdo->prepare("
        SELECT c.* FROM calls c
        INNER JOIN call_participants cp ON cp.call_id = c.id AND cp.user_id = ?
        INNER JOIN conversation_members cm ON c.conversation_id = cm.conversation_id AND cm.user_id = ? AND cm.left_at IS NULL
        WHERE c.conversation_id = ? AND c.status IN ('ringing', 'active')
        ORDER BY c.id DESC LIMIT 1
    ");
    $stmt->execute([$user_id, $user_id, $conversation_id]);
    $call = $stmt->fetch();
    if ($call) {
        $call_id_param = (int)$call['id'];
        $room_name = $call['room_id'];
        $call_type = ($call['call_type'] ?? 'video') === 'audio' ? 'audio' : 'video';
    } else {
        $room_name = 'social9_' . $conversation_id;
        $call_type = in_array($call_type_param, ['audio', 'video']) ? $call_type_param : 'video';
    }
}

if ($conversation_id <= 0) {
    die('会話IDまたは通話IDが必要です。チャットから通話を開始してください。');
}

// 会話情報を取得
$stmt = $pdo->prepare("
    SELECT c.*, cm.role
    FROM conversations c
    INNER JOIN conversation_members cm ON c.id = cm.conversation_id
    WHERE c.id = ? AND cm.user_id = ? AND cm.left_at IS NULL
");
$stmt->execute([$conversation_id, $user_id]);
$conversation = $stmt->fetch();
if (!$conversation) {
    die('この会話にアクセスする権限がありません');
}

$conversation_name = $conversation['name'];
if ($conversation['type'] === 'dm') {
    $stmt = $pdo->prepare("
        SELECT u.display_name FROM conversation_members cm
        INNER JOIN users u ON cm.user_id = u.id
        WHERE cm.conversation_id = ? AND cm.user_id != ?
        LIMIT 1
    ");
    $stmt->execute([$conversation_id, $user_id]);
    $partner = $stmt->fetch();
    $conversation_name = $partner ? $partner['display_name'] : 'DM';
}

// メンバー一覧
$stmt = $pdo->prepare("
    SELECT u.id, u.display_name FROM conversation_members cm
    INNER JOIN users u ON cm.user_id = u.id
    WHERE cm.conversation_id = ? AND cm.left_at IS NULL
");
$stmt->execute([$conversation_id]);
$members = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>通話 - <?= htmlspecialchars($conversation_name) ?> | Social9</title>
    <?= generateFontLinks() ?>
    <link rel="stylesheet" href="assets/css/common.css">
    <link rel="stylesheet" href="assets/css/mobile.css?v=<?= file_exists(__DIR__.'/assets/css/mobile.css') ? filemtime(__DIR__.'/assets/css/mobile.css') : '1' ?>">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; }
        body { 
            font-family: 'Hiragino Sans', 'Meiryo', sans-serif; 
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
        }
        
        .call-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
        }
        
        .call-header {
            padding: 16px 24px;
            background: rgba(0,0,0,0.3);
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: white;
        }
        
        .call-info { display: flex; align-items: center; gap: 16px; }
        .call-info .avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 20px;
        }
        .call-info h2 { font-size: 18px; }
        .call-info .status { font-size: 13px; opacity: 0.8; display: flex; align-items: center; gap: 6px; }
        .call-info .status .dot { width: 8px; height: 8px; background: #22c55e; border-radius: 50%; animation: pulse 2s infinite; }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .call-timer {
            font-size: 24px;
            font-weight: 300;
            letter-spacing: 2px;
        }
        
        .video-area {
            flex: 1;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        #jitsiContainer {
            width: 100%;
            height: 100%;
        }
        
        /* meet.jit.si 暫定案内（通話専用ページでも同一文言を表示） */
        .call-jitsi-host-hint {
            position: absolute;
            bottom: 16px;
            left: 50%;
            transform: translateX(-50%);
            margin: 0;
            padding: 6px 12px;
            font-size: 12px;
            line-height: 1.3;
            color: rgba(255, 255, 255, 0.9);
            background: rgba(0, 0, 0, 0.4);
            border-radius: 4px;
            pointer-events: none;
            z-index: 10;
        }
        
        .call-controls {
            padding: 24px;
            background: rgba(0,0,0,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
        }
        
        .control-btn {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            font-size: 24px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .control-btn.primary {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        .control-btn.primary:hover { background: rgba(255,255,255,0.3); transform: scale(1.1); }
        .control-btn.primary.active { background: var(--primary); }
        
        .control-btn.danger {
            background: #ef4444;
            color: white;
            width: 70px;
            height: 70px;
        }
        .control-btn.danger:hover { background: #dc2626; transform: scale(1.1); }
        
        .control-btn.muted {
            background: rgba(239, 68, 68, 0.3);
        }
        
        /* 参加者サイドバー */
        .participants-sidebar {
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            width: 280px;
            background: rgba(0,0,0,0.5);
            transform: translateX(100%);
            transition: transform 0.3s;
            display: flex;
            flex-direction: column;
        }
        .participants-sidebar.open { transform: translateX(0); }
        
        .participants-header {
            padding: 16px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: white;
        }
        
        .participants-list {
            flex: 1;
            overflow-y: auto;
            padding: 8px;
        }
        
        .participant-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-radius: 8px;
            color: white;
        }
        .participant-item:hover { background: rgba(255,255,255,0.1); }
        .participant-item .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        .participant-item .name { flex: 1; }
        .participant-item .icons { display: flex; gap: 8px; font-size: 14px; opacity: 0.7; }
        
        /* チャットサイドバー */
        .chat-sidebar {
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            width: 350px;
            background: rgba(0,0,0,0.7);
            transform: translateX(100%);
            transition: transform 0.3s;
            display: flex;
            flex-direction: column;
        }
        .chat-sidebar.open { transform: translateX(0); }
        
        .chat-header {
            padding: 16px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: white;
        }
        
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
        }
        
        .chat-message {
            margin-bottom: 12px;
            color: white;
        }
        .chat-message .sender { font-size: 12px; opacity: 0.7; margin-bottom: 4px; }
        .chat-message .text { background: rgba(255,255,255,0.1); padding: 8px 12px; border-radius: 12px; }
        
        .chat-input {
            padding: 16px;
            border-top: 1px solid rgba(255,255,255,0.1);
            display: flex;
            gap: 8px;
        }
        .chat-input input {
            flex: 1;
            padding: 10px 16px;
            border: none;
            border-radius: 20px;
            background: rgba(255,255,255,0.1);
            color: white;
            font-size: 14px;
        }
        .chat-input input::placeholder { color: rgba(255,255,255,0.5); }
        .chat-input input:focus { outline: none; background: rgba(255,255,255,0.15); }
        .chat-input button {
            padding: 10px 20px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 20px;
            cursor: pointer;
        }
        
        /* 接続中オーバーレイ */
        .connecting-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0.8);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            z-index: 100;
        }
        .connecting-overlay.hidden { display: none; }
        .connecting-overlay .spinner {
            width: 60px;
            height: 60px;
            border: 4px solid rgba(255,255,255,0.2);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
    <?= generateDesignCSS($designSettings) ?>
</head>
<body class="style-<?= htmlspecialchars(function_exists('getEffectiveStyleId') ? getEffectiveStyleId($designSettings['ui_style'] ?? DESIGN_DEFAULT_STYLE) : ($designSettings['ui_style'] ?? DESIGN_DEFAULT_STYLE)) ?>" data-theme="<?= htmlspecialchars($designSettings['theme'] ?? DESIGN_DEFAULT_THEME) ?>">
    <div class="call-container">
        <header class="call-header">
            <div class="call-info">
                <div class="avatar"><?= mb_substr($conversation_name, 0, 1) ?></div>
                <div>
                    <h2><?= htmlspecialchars($conversation_name) ?></h2>
                    <div class="status">
                        <span class="dot"></span>
                        <span id="callStatus"><?= $call_type === 'video' ? 'ビデオ通話' : '音声通話' ?></span>
                    </div>
                </div>
            </div>
            <div class="call-timer" id="callTimer">00:00</div>
        </header>
        
        <div class="video-area">
            <div id="jitsiContainer"></div>
            <p class="call-jitsi-host-hint">接続しない場合は、画面内の「私はホストです」を押してください。</p>
            
            <div class="connecting-overlay" id="connectingOverlay">
                <div class="spinner"></div>
                <h3>接続中...</h3>
                <p style="opacity: 0.7; margin-top: 8px;">通話に参加しています</p>
            </div>
            
            <!-- 参加者サイドバー -->
            <aside class="participants-sidebar" id="participantsSidebar">
                <div class="participants-header">
                    <h4>参加者 (<?= count($members) ?>)</h4>
                    <button onclick="toggleParticipants()" style="background:none;border:none;color:white;cursor:pointer;font-size:20px;">×</button>
                </div>
                <div class="participants-list">
                    <?php foreach ($members as $member): ?>
                    <div class="participant-item">
                        <div class="avatar"><?= mb_substr($member['display_name'], 0, 1) ?></div>
                        <div class="name"><?= htmlspecialchars($member['display_name']) ?></div>
                        <div class="icons">
                            <span>🎤</span>
                            <span>📹</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </aside>
            
            <!-- チャットサイドバー -->
            <aside class="chat-sidebar" id="chatSidebar">
                <div class="chat-header">
                    <h4>通話中チャット</h4>
                    <button onclick="toggleChat()" style="background:none;border:none;color:white;cursor:pointer;font-size:20px;">×</button>
                </div>
                <div class="chat-messages" id="chatMessages">
                    <div class="chat-message">
                        <div class="sender">システム</div>
                        <div class="text">通話が開始されました</div>
                    </div>
                </div>
                <div class="chat-input">
                    <input type="text" id="chatInput" placeholder="メッセージを入力..." onkeydown="if(event.key==='Enter')sendChatMessage()">
                    <button onclick="sendChatMessage()">送信</button>
                </div>
            </aside>
        </div>
        
        <div class="call-controls">
            <button class="control-btn primary" id="micBtn" onclick="toggleMic()" title="マイク">🎤</button>
            <button class="control-btn primary" id="videoBtn" onclick="toggleVideo()" title="カメラ">📹</button>
            <button class="control-btn primary" onclick="shareScreen()" title="画面共有">🖥️</button>
            <button class="control-btn danger" onclick="endCall()" title="通話終了">📞</button>
            <button class="control-btn primary" onclick="toggleParticipants()" title="参加者">👥</button>
            <button class="control-btn primary" onclick="toggleChat()" title="チャット">💬</button>
        </div>
    </div>
    
    <script src="<?= htmlspecialchars(rtrim(JITSI_BASE_URL, '/') . '/external_api.js') ?>"></script>
    <script>
        const roomName = '<?= addslashes($room_name) ?>';
        const displayName = '<?= addslashes($display_name) ?>';
        const callType = '<?= addslashes($call_type) ?>';
        const conversationId = <?= (int)$conversation_id ?>;
        const callId = <?= (int)$call_id_param ?>; // leave API 用（0の場合はレガシー c= のみなので leave しない）
        const apiCallsBase = (function(){
            const a = document.createElement('a');
            a.href = window.location.href;
            return a.origin + a.pathname.replace(/\/[^/]*$/, '') + '/';
        })();
        
        let api = null;
        let startTime = null;
        let timerInterval = null;
        let isMuted = false;
        let isVideoOff = callType === 'audio';
        let leaveSent = false;
        
        function callLeaveApi() {
            if (leaveSent || !callId) return;
            leaveSent = true;
            const form = new FormData();
            form.append('action', 'leave');
            form.append('call_id', String(callId));
            fetch(apiCallsBase + 'api/calls.php', { method: 'POST', credentials: 'same-origin', body: form }).catch(function(){});
        }
        
        // Jitsi Meet初期化（自前サーバー対応: config の JITSI_DOMAIN）
        function initJitsi() {
            const domain = '<?= addslashes(JITSI_DOMAIN) ?>';
            const options = {
                roomName: roomName,
                width: '100%',
                height: '100%',
                parentNode: document.getElementById('jitsiContainer'),
                userInfo: {
                    displayName: displayName
                },
                configOverwrite: {
                    startWithAudioMuted: false,
                    startWithVideoMuted: callType === 'audio',
                    prejoinPageEnabled: false,
                    disableDeepLinking: true,
                    enableClosePage: false,
                    toolbarButtons: [],
                    hideConferenceSubject: true,
                    hideConferenceTimer: true,
                    disableInviteFunctions: true,
                    disableRemoteMute: true,
                    disableRemoteVideoMenu: true,
                    remoteVideoMenu: { disableKick: true, disableGrantModerator: true },
                    disableProfile: true,
                    startConference: true
                },
                interfaceConfigOverwrite: {
                    TOOLBAR_BUTTONS: [],
                    SHOW_JITSI_WATERMARK: false,
                    SHOW_WATERMARK_FOR_GUESTS: false,
                    SHOW_BRAND_WATERMARK: false,
                    SHOW_CHROME_EXTENSION_BANNER: false,
                    FILM_STRIP_MAX_HEIGHT: 120,
                    DISABLE_VIDEO_BACKGROUND: true,
                    TILE_VIEW_MAX_COLUMNS: 5
                }
            };
            
            api = new JitsiMeetExternalAPI(domain, options);
            
            api.addListener('videoConferenceJoined', () => {
                document.getElementById('connectingOverlay').classList.add('hidden');
                startTimer();
                document.getElementById('callStatus').textContent = '通話中';
                
                if (callType === 'audio') {
                    api.executeCommand('toggleVideo');
                    isVideoOff = true;
                    document.getElementById('videoBtn').classList.add('muted');
                }
            });
            
            api.addListener('videoConferenceLeft', () => {
                callLeaveApi();
                window.location.href = conversationId ? ('chat.php?c=' + conversationId) : 'chat.php';
            });
            
            api.addListener('participantJoined', (participant) => {
                addChatMessage('システム', `${participant.displayName || '参加者'}が参加しました`);
            });
            
            api.addListener('participantLeft', (participant) => {
                addChatMessage('システム', `${participant.displayName || '参加者'}が退出しました`);
            });
        }
        
        // タイマー
        function startTimer() {
            startTime = Date.now();
            timerInterval = setInterval(() => {
                const elapsed = Math.floor((Date.now() - startTime) / 1000);
                const mins = Math.floor(elapsed / 60).toString().padStart(2, '0');
                const secs = (elapsed % 60).toString().padStart(2, '0');
                document.getElementById('callTimer').textContent = `${mins}:${secs}`;
            }, 1000);
        }
        
        // マイク切り替え
        function toggleMic() {
            if (api) {
                api.executeCommand('toggleAudio');
                isMuted = !isMuted;
                document.getElementById('micBtn').classList.toggle('muted', isMuted);
            }
        }
        
        // カメラ切り替え
        function toggleVideo() {
            if (api) {
                api.executeCommand('toggleVideo');
                isVideoOff = !isVideoOff;
                document.getElementById('videoBtn').classList.toggle('muted', isVideoOff);
            }
        }
        
        // 画面共有
        function shareScreen() {
            if (api) {
                api.executeCommand('toggleShareScreen');
            }
        }
        
        // 通話終了
        function endCall() {
            if (confirm('通話を終了しますか？')) {
                if (api) {
                    api.executeCommand('hangup');
                }
                callLeaveApi();
                if (timerInterval) {
                    clearInterval(timerInterval);
                }
                window.location.href = conversationId ? ('chat.php?c=' + conversationId) : 'chat.php';
            }
        }
        
        // 参加者パネル
        function toggleParticipants() {
            document.getElementById('participantsSidebar').classList.toggle('open');
            document.getElementById('chatSidebar').classList.remove('open');
        }
        
        // チャットパネル
        function toggleChat() {
            document.getElementById('chatSidebar').classList.toggle('open');
            document.getElementById('participantsSidebar').classList.remove('open');
        }
        
        // チャットメッセージ追加
        function addChatMessage(sender, text) {
            const container = document.getElementById('chatMessages');
            const div = document.createElement('div');
            div.className = 'chat-message';
            div.innerHTML = `<div class="sender">${sender}</div><div class="text">${text}</div>`;
            container.appendChild(div);
            container.scrollTop = container.scrollHeight;
        }
        
        // チャット送信
        function sendChatMessage() {
            const input = document.getElementById('chatInput');
            const text = input.value.trim();
            if (text) {
                addChatMessage(displayName, text);
                input.value = '';
                // 実際のメッセージ送信はAPI経由で行う
            }
        }
        
        // ウィンドウを閉じる時
        window.onbeforeunload = function() {
            if (api) {
                api.executeCommand('hangup');
                callLeaveApi();
            }
        };
        
        // 初期化
        initJitsi();
    </script>
</body>
</html>





