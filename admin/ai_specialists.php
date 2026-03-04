<?php
/**
 * 専門AI管理ページ
 * 
 * 組織の専門AI設定・プロンプト編集・機能フラグ管理。
 * 計画書 2.3 に基づく。
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ai_specialist_router.php';

$admin_current_page = 'ai_specialists';
require_once __DIR__ . '/includes/sidebar.php';

if (!isLoggedIn() || !isOrgAdminUser()) {
    header('Location: ../index.php');
    exit;
}

$pdo = getDB();
$userId = $_SESSION['user_id'];

$orgStmt = $pdo->prepare("
    SELECT o.id, o.name FROM organizations o
    JOIN organization_members om ON om.organization_id = o.id
    WHERE om.user_id = ? AND om.left_at IS NULL
    ORDER BY o.name
");
$orgStmt->execute([$userId]);
$orgs = $orgStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>専門AI管理 - 管理パネル</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <link rel="stylesheet" href="../assets/css/admin-ai-specialists.css">
</head>
<body>
<main class="admin-main">
    <div class="admin-header">
        <h2>専門AI管理</h2>
        <p>組織ごとの専門AI設定・プロンプト編集・利用統計の管理</p>
    </div>

    <div class="aisp-controls">
        <select id="aispOrgSelect">
            <option value="">組織を選択</option>
            <?php foreach ($orgs as $org): ?>
            <option value="<?= (int)$org['id'] ?>"><?= htmlspecialchars($org['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button id="aispLoadBtn" class="btn-primary">読み込み</button>
        <button id="aispProvisionBtn" class="btn-success">専門AI一式を初期設定</button>
    </div>

    <div class="aisp-grid" id="aispGrid">
        <p class="aisp-empty">組織を選択してください</p>
    </div>

    <!-- 専門AI編集モーダル -->
    <div class="aisp-modal" id="aispModal" style="display:none">
        <div class="aisp-modal-content">
            <div class="aisp-modal-header">
                <h3 id="aispModalTitle">専門AI設定</h3>
                <button class="aisp-modal-close" id="aispModalClose">&times;</button>
            </div>
            <div class="aisp-modal-body">
                <input type="hidden" id="aispEditId">
                <input type="hidden" id="aispEditType">
                <div class="aisp-form-group">
                    <label>表示名</label>
                    <input type="text" id="aispEditName" class="aisp-input-full">
                </div>
                <div class="aisp-form-group">
                    <label>システムプロンプト（空欄でデフォルト使用）</label>
                    <textarea id="aispEditPrompt" rows="10" class="aisp-textarea-full"></textarea>
                </div>
                <div class="aisp-form-group">
                    <label>組織固有ルール・ポリシー</label>
                    <textarea id="aispEditRules" rows="5" class="aisp-textarea-full"></textarea>
                </div>
                <div class="aisp-form-group">
                    <label>
                        <input type="checkbox" id="aispEditEnabled"> 有効
                    </label>
                </div>
            </div>
            <div class="aisp-modal-footer">
                <button id="aispSaveBtn" class="btn-primary">保存</button>
                <button id="aispCancelBtn" class="btn-secondary">キャンセル</button>
            </div>
        </div>
    </div>

    <hr style="margin: 40px 0 30px;">

    <div class="admin-header">
        <h2>機能フラグ管理（システム全体）</h2>
        <p>機能番号 1〜33 の有効化・無効化を管理</p>
    </div>
    <div class="aisp-flags" id="aispFlags">
        <p class="aisp-empty">読み込み中...</p>
    </div>
</main>

<script src="../assets/js/admin-ai-specialists.js"></script>
</body>
</html>
