<?php
/**
 * AI安全通報管理ページ
 * 
 * 運営責任者（KEN）向け。通報の確認・ステータス変更・追加質問。
 * 計画書 6.1 に基づき、運営責任者のみアクセス可能。
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$admin_current_page = 'ai_safety';
require_once __DIR__ . '/includes/sidebar.php';

if (!isLoggedIn() || !isOrgAdminUser()) {
    header('Location: ../index.php');
    exit;
}

$pdo = getDB();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI安全通報 - 管理パネル</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <link rel="stylesheet" href="../assets/css/admin-ai-safety.css">
</head>
<body>
<main class="admin-main">
    <div class="admin-header">
        <h2>AI安全通報</h2>
        <p>秘書が検知した社会通念上看過しえない事象・生命の危機・いじめ等の通報を管理します</p>
    </div>

    <div class="aisf-stats" id="aisfStats"></div>

    <div class="aisf-controls">
        <select id="aisfStatusFilter">
            <option value="">全ステータス</option>
            <option value="new" selected>未対応</option>
            <option value="reviewing">確認中</option>
            <option value="resolved">対応済み</option>
            <option value="dismissed">却下</option>
        </select>
        <button id="aisfRefreshBtn" class="btn-primary">更新</button>
    </div>

    <div class="aisf-list" id="aisfList">
        <p class="aisf-empty">読み込み中...</p>
    </div>

    <!-- 通報詳細モーダル -->
    <div class="aisf-modal" id="aisfModal" style="display:none">
        <div class="aisf-modal-content">
            <div class="aisf-modal-header">
                <h3>通報詳細</h3>
                <button class="aisf-modal-close" id="aisfModalClose">&times;</button>
            </div>
            <div class="aisf-modal-body" id="aisfModalBody"></div>
            <div class="aisf-modal-footer">
                <select id="aisfStatusSelect">
                    <option value="new">未対応</option>
                    <option value="reviewing">確認中</option>
                    <option value="resolved">対応済み</option>
                    <option value="dismissed">却下</option>
                </select>
                <textarea id="aisfNotes" rows="2" placeholder="メモ（任意）"></textarea>
                <button id="aisfUpdateStatusBtn" class="btn-primary">ステータス更新</button>
            </div>
            <div class="aisf-questions-section">
                <h4>秘書への追加質問</h4>
                <div id="aisfQuestionsList"></div>
                <div class="aisf-ask-form">
                    <textarea id="aisfNewQuestion" rows="2" placeholder="秘書に質問..."></textarea>
                    <button id="aisfAskBtn" class="btn-primary">質問する</button>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="../assets/js/admin-ai-safety.js"></script>
</body>
</html>
