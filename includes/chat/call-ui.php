<?php
/**
 * チャット画面 - 通話関連UI
 *
 * 含まれる要素（通話は call.php に統合済み）:
 * - メディア右クリックメニュー
 * - 通話メニュー（ビデオ/音声 → call.php へ遷移）
 * - 着信モーダル（拒否/出る → call.php へ遷移）
 * - 通話状態インジケーター（非表示・call.php に遷移済みのため未使用）
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

<!-- 通話は call.php で実施。チャットでは通話メニュー・着信モーダルのみ。通話中は call.php に遷移済みのためインジケーターは非表示でよい -->
<div class="call-status-indicator" id="callStatusIndicator" style="display:none;">
    <span class="pulse"></span>
    <span id="callStatusText">通話中</span>
    <span id="callDuration">00:00</span>
</div>
