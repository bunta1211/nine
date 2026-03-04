<?php
/**
 * チャット画面 - 通話関連UI
 * 
 * 含まれる要素:
 * - メディア右クリックメニュー
 * - 通話メニュー
 * - 通話状態インジケーター
 * - 通話ビデオウィンドウ
 * - 通話コントロールバー
 */
?>
<!-- メディア右クリックメニュー -->
<div class="media-context-menu" id="mediaContextMenu">
    <div class="media-context-menu-item" onclick="editMediaTitle()">
        <span>✏️</span>
        <span>タイトル編集</span>
    </div>
    <div class="media-context-menu-divider"></div>
    <div class="media-context-menu-item danger" onclick="deleteMediaFromContext()">
        <span>🗑️</span>
        <span>削除</span>
    </div>
</div>

<!-- 着信モーダル（相手の通話着信時に表示・着信音・バイブ） -->
<div class="incoming-call-overlay" id="incomingCallOverlay" style="display:none;">
    <div class="incoming-call-modal">
        <div class="incoming-call-icon">📞</div>
        <p class="incoming-call-title" id="incomingCallTitle">着信</p>
        <p class="incoming-call-from" id="incomingCallFrom"></p>
        <div class="incoming-call-actions">
            <button type="button" class="incoming-call-btn decline" id="incomingCallDecline">拒否</button>
            <button type="button" class="incoming-call-btn answer" id="incomingCallAnswer">出る</button>
        </div>
    </div>
</div>

<!-- 通話メニュー（ビデオを上側に表示） -->
<div class="call-menu" id="callMenu">
    <div class="call-menu-item" onclick="startCall('video')">
        <span class="call-menu-icon">📹</span>
        <span>ビデオ通話</span>
    </div>
    <div class="call-menu-item" onclick="startCall('audio')">
        <span class="call-menu-icon">🎤</span>
        <span>音声通話</span>
    </div>
</div>

<!-- 通話状態インジケーター -->
<div class="call-status-indicator" id="callStatusIndicator">
    <span class="pulse"></span>
    <span id="callStatusText">通話中</span>
    <span id="callDuration">00:00</span>
</div>

<!-- 通話ビデオウィンドウ（独立して移動可能） -->
<div class="call-video-container" id="callVideoContainer">
    <div class="call-participants" id="callParticipants">
        <!-- 参加者のビデオバブルがここに追加される -->
    </div>
</div>

<!-- 通話コントロールバー（独立して移動可能） -->
<div class="call-controls-container" id="callControlsContainer">
    <div class="call-controls-bar" id="callControlsBar">
        <div class="controls-drag-handle" title="ドラッグで移動">⋮⋮</div>
        <button class="call-control-btn primary" id="micToggleBtn" onclick="toggleMic()" title="マイク">
            🎤
        </button>
        <button class="call-control-btn primary" id="videoToggleBtn" onclick="toggleVideo()" title="カメラ">
            📹
        </button>
        <button class="call-control-btn primary" id="blurToggleBtn" onclick="toggleBackgroundBlur()" title="背景ぼかし">
            🌫️
        </button>
        <button class="call-control-btn primary" id="virtualBgBtn" onclick="openVirtualBackgroundSelector()" title="バーチャル背景">
            🖼️
        </button>
        <button class="call-control-btn primary" id="screenShareBtn" onclick="toggleScreenShare()" title="画面共有">
            🖥️
        </button>
        <button class="call-control-btn danger" onclick="endCall()" title="通話終了">
            📞
        </button>
    </div>
</div>
