<?php
/**
 * Social9 タスク管理画面
 * タスク依頼・管理機能
 */

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/design_loader.php';
require_once __DIR__ . '/includes/asset_helper.php';
require_once __DIR__ . '/includes/lang.php';

requireLogin();

// 言語設定を初期化
$currentLang = getCurrentLanguage();

$pdo = getDB();
$user_id = $_SESSION['user_id'];
$display_name = $_SESSION['display_name'] ?? 'ユーザー';

// トップバー用：ユーザー情報・所属組織
$user = [];
$userOrganizations = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT o.id, o.name, o.type, om.role as relationship
        FROM organizations o
        INNER JOIN organization_members om ON o.id = om.organization_id
        WHERE om.user_id = ? AND om.left_at IS NULL
        ORDER BY CASE om.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END, o.name
    ");
    $stmt->execute([$user_id]);
    $userOrganizations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// デザイン設定を取得
$designSettings = getDesignSettings($pdo, $user_id);

// sourceカラムとdeleted_atカラムの存在を確認
$hasSourceColumn = false;
$hasDeletedAtColumn = false;
try {
    $checkCol = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'source'");
    $hasSourceColumn = $checkCol->fetch() !== false;
    $checkDelCol = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'deleted_at'");
    $hasDeletedAtColumn = $checkDelCol->fetch() !== false;
} catch (Exception $e) {}

$deletedFilter = $hasDeletedAtColumn ? " AND t.deleted_at IS NULL" : "";

$myTasks = [];
$requestedTasks = [];

try {
    // 自分のタスク（自分が担当 or 自分で作成して担当者なし）
    $myTasksQuery = "
        SELECT 
            t.*,
            " . ($hasSourceColumn ? "COALESCE(t.source, 'manual') as source, t.original_text, t.category, t.source_message_id," : "'manual' as source, NULL as original_text, NULL as category, NULL as source_message_id,") . "
            m.conversation_id as message_conversation_id,
            creator.display_name as creator_name,
            creator.id as creator_id
        FROM tasks t
        LEFT JOIN messages m ON t.source_message_id = m.id
        LEFT JOIN users creator ON t.created_by = creator.id
        WHERE (t.assigned_to = ? OR (t.created_by = ? AND (t.assigned_to IS NULL OR t.assigned_to = ?))) AND t.status != 'completed'" . $deletedFilter . "
        ORDER BY 
            CASE t.status WHEN 'pending' THEN 1 WHEN 'in_progress' THEN 2 ELSE 3 END,
            t.due_date ASC,
            t.created_at DESC
    ";
    $stmt = $pdo->prepare($myTasksQuery);
    $stmt->execute([$user_id, $user_id, $user_id]);
    $myTasks = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('tasks.php: myTasks query failed: ' . $e->getMessage());
}

try {
    // 依頼したタスク（自分が作成して他人に割り当て）
    $requestedTasksQuery = "
        SELECT 
            t.*,
            " . ($hasSourceColumn ? "COALESCE(t.source, 'manual') as source, t.original_text, t.category, t.source_message_id," : "'manual' as source, NULL as original_text, NULL as category, NULL as source_message_id,") . "
            m.conversation_id as message_conversation_id,
            assignee.display_name as assignee_name,
            assignee.id as assignee_id
        FROM tasks t
        LEFT JOIN messages m ON t.source_message_id = m.id
        LEFT JOIN users assignee ON t.assigned_to = assignee.id
        WHERE t.created_by = ? AND t.assigned_to IS NOT NULL AND t.assigned_to != ? AND t.status != 'completed'" . $deletedFilter . "
        ORDER BY 
            CASE t.status WHEN 'pending' THEN 1 WHEN 'in_progress' THEN 2 ELSE 3 END,
            t.due_date ASC,
            t.created_at DESC
    ";
    $stmt = $pdo->prepare($requestedTasksQuery);
    $stmt->execute([$user_id, $user_id]);
    $requestedTasks = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('tasks.php: requestedTasks query failed: ' . $e->getMessage());
}

// 担当者候補（友達・グループメンバー）- テーブル名は friendships（friends は存在しない）
$availableUsers = [];
try {
    $usersQuery = "
        SELECT DISTINCT u.id, u.display_name 
        FROM users u
        WHERE u.id != ?
        AND (
            u.id IN (SELECT friend_id FROM friendships WHERE user_id = ? AND status = 'accepted')
            OR u.id IN (SELECT user_id FROM friendships WHERE friend_id = ? AND status = 'accepted')
            OR u.id IN (
                SELECT cm2.user_id FROM conversation_members cm1
                JOIN conversation_members cm2 ON cm1.conversation_id = cm2.conversation_id
                WHERE cm1.user_id = ? AND cm2.user_id != ?
            )
        )
        ORDER BY u.display_name
        LIMIT 100
    ";
    $stmt = $pdo->prepare($usersQuery);
    $stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id]);
    $availableUsers = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('tasks.php: availableUsers query failed: ' . $e->getMessage());
}

// 優先度ラベル
$priorityLabels = [
    0 => '低',
    1 => '中',
    2 => '高',
    3 => '緊急'
];

// メモ一覧取得（tasks テーブルの type='memo' から取得。フォールバック: memos テーブル）
$memos = [];
try {
    $hasTypeCol = false;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'type'");
        $hasTypeCol = $chk && $chk->rowCount() > 0;
    } catch (Exception $e) {}
    
    if ($hasTypeCol) {
        $memoSql = "SELECT * FROM tasks WHERE created_by = ? AND type = 'memo'";
        $hasDeleted = false;
        try {
            $chk2 = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'deleted_at'");
            $hasDeleted = $chk2 && $chk2->rowCount() > 0;
        } catch (Exception $e) {}
        if ($hasDeleted) $memoSql .= " AND deleted_at IS NULL";
        $memoSql .= " ORDER BY is_pinned DESC, updated_at DESC";
        $stmt = $pdo->prepare($memoSql);
        $stmt->execute([$user_id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM memos WHERE created_by = ? ORDER BY is_pinned DESC, updated_at DESC");
        $stmt->execute([$user_id]);
    }
    $memos = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('tasks.php: memos query failed: ' . $e->getMessage());
}

$active_tab = $_GET['tab'] ?? 'tasks';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>タスク管理 | <?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social100' ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <?= generateFontLinks() ?>
    <link rel="stylesheet" href="assets/css/common.css?v=<?= assetVersion('assets/css/common.css', __DIR__) ?>">
    <link rel="stylesheet" href="assets/css/layout/header.css?v=<?= assetVersion('assets/css/layout/header.css', __DIR__) ?>">
    <link rel="stylesheet" href="assets/css/panel-panels-unified.css?v=<?= assetVersion('assets/css/panel-panels-unified.css', __DIR__) ?>">
    <?= generateDesignCSS($designSettings) ?>
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/pages-mobile.css?v=<?= assetVersion('assets/css/pages-mobile.css', __DIR__) ?>">
    <style>
        :root {
            --header-height: 70px;
            --left-panel-width: 260px;
            --right-panel-width: 280px;
        }
        
        .topbar-back-link { text-decoration: none; color: inherit; display: inline-flex; align-items: center; justify-content: center; }
        .tasks-page .top-panel .user-info .user-info-mobile-gear { display: none !important; }
        .tasks-page .top-panel .toggle-right-btn { display: none !important; }

        .main-container { display: flex; }
        
        /* 左パネル（空白） - chat.phpの左パネルと同じ幅 */
        .left-spacer {
            width: var(--left-panel-width);
            background: rgba(255,255,255,0.95);
            flex-shrink: 0;
            border-radius: 16px;
        }
        
        /* 中央パネル（コンテンツ） - デザイントークン対応 */
        .center-panel {
            flex: 1;
            background: var(--dt-center-bg, rgba(255,255,255,0.98));
            min-width: 0;
            padding: 24px;
            border-radius: 16px;
            overflow-y: auto;
            max-height: calc(100vh - var(--header-height) - 40px);
            backdrop-filter: blur(12px);
        }
        
        /* スクロールバー - デザイントークン対応 */
        .center-panel::-webkit-scrollbar {
            width: 6px;
        }
        .center-panel::-webkit-scrollbar-track {
            background: transparent;
        }
        .center-panel::-webkit-scrollbar-thumb {
            background: var(--dt-scroll-thumb, rgba(100,116,139,0.15));
            border-radius: 10px;
        }
        .center-panel::-webkit-scrollbar-thumb:hover {
            background: var(--dt-scroll-thumb-hover, rgba(100,116,139,0.25));
        }
        
        /* 右パネル（空白） */
        .right-spacer {
            width: var(--right-panel-width);
            background: rgba(255,255,255,0.95);
            flex-shrink: 0;
            border-radius: 16px;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        @media (max-width: 1200px) {
            .right-spacer { display: none; }
        }
        @media (max-width: 900px) {
            .left-spacer { display: none; }
        }
        
        .content-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .content-header h2 { font-size: 18px; }
        
        .add-btn {
            padding: 10px 20px;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .add-btn:hover { opacity: 0.9; }
        
        /* タスクカード */
        .task-list { display: flex; flex-direction: column; gap: 12px; }
        
        .task-card {
            background: var(--dt-card-bg, white);
            border: 1px solid var(--dt-card-border, transparent);
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.2s;
            color: var(--dt-text-primary, inherit);
        }
        .task-card:hover { box-shadow: var(--shadow-md); }
        .task-card.completed { opacity: 0.6; }
        
        .task-checkbox {
            width: 24px;
            height: 24px;
            border: 2px solid var(--border-light);
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s;
        }
        .task-checkbox:hover { border-color: var(--primary); }
        .task-checkbox.checked { background: var(--primary); border-color: var(--primary); color: white; }
        
        .task-content { flex: 1; min-width: 0; }
        .task-title { font-weight: 500; margin-bottom: 4px; }
        .task-card.completed .task-title { text-decoration: line-through; }
        .task-meta { font-size: 13px; color: var(--text-muted); display: flex; gap: 12px; flex-wrap: wrap; }
        
        .priority-badge {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: 500;
        }
        .priority-badge.high { background: #fef2f2; color: #dc2626; }
        .priority-badge.medium { background: #fffbeb; color: #d97706; }
        .priority-badge.low { background: #f0fdf4; color: #16a34a; }
        
        .due-date { display: flex; align-items: center; gap: 4px; }
        .due-date.overdue { color: #dc2626; }
        
        /* カテゴリ・抽出元バッジ */
        .category-badge {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 4px;
            background: #e0f2fe;
            color: #0369a1;
        }
        .source-badge {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #92400e;
        }
        .source-badge.manual { background: #f3f4f6; color: #6b7280; }
        
        .requester-badge {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 4px;
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            color: #1e40af;
            font-weight: 500;
        }
        
        .original-text {
            font-size: 12px;
            color: var(--text-muted);
            font-style: italic;
            margin-top: 4px;
            padding-left: 12px;
            border-left: 2px solid var(--border-light);
        }
        
        .message-link {
            font-size: 12px;
            color: var(--primary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            background: rgba(5, 150, 105, 0.1);
            border-radius: 4px;
            transition: all 0.2s;
        }
        .message-link:hover {
            background: rgba(5, 150, 105, 0.2);
            text-decoration: none;
        }
        
        /* 翻訳機能 */
        .task-title-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .translate-btn {
            width: 28px;
            height: 28px;
            border: none;
            background: rgba(99, 102, 241, 0.1);
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            opacity: 0.6;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .translate-btn:hover {
            opacity: 1;
            background: rgba(99, 102, 241, 0.2);
        }
        .translate-btn.loading {
            opacity: 0.5;
            cursor: wait;
        }
        .task-translated {
            font-size: 13px;
            color: var(--primary);
            background: rgba(99, 102, 241, 0.08);
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 6px;
            border-left: 3px solid var(--primary);
        }
        
        .task-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .task-actions button {
            min-width: 32px;
            height: 32px;
            border: none;
            background: var(--bg-secondary);
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            padding: 0 8px;
        }
        .task-actions button:hover { background: var(--border-light); }
        
        /* 完了ボタン */
        .complete-btn {
            background: linear-gradient(135deg, #10b981, #059669) !important;
            color: white !important;
            font-weight: 500;
            min-width: 60px !important;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            transition: all 0.2s;
        }
        .complete-btn:hover {
            background: linear-gradient(135deg, #059669, #047857) !important;
            transform: scale(1.02);
        }
        .complete-btn.completed {
            background: linear-gradient(135deg, #6b7280, #4b5563) !important;
        }
        
        /* モバイル用完了ボタン */
        @media (max-width: 768px) {
            .complete-btn {
                min-width: 80px !important;
                height: 40px;
                font-size: 13px;
            }
            .task-actions {
                gap: 6px;
            }
            .task-actions button {
                min-width: 40px;
                height: 40px;
            }
        }
        
        /* メモカード */
        .memo-list {
            display: flex;
            flex-direction: column;
            gap: 0;
        }
        
        .memo-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-bottom: 1px solid var(--dt-card-border, #eee);
            cursor: pointer;
            transition: background 0.15s;
            color: var(--dt-text-primary, inherit);
        }
        .memo-row:hover { background: var(--dt-hover-bg, rgba(0,0,0,0.03)); }
        .memo-row.pinned { background: var(--dt-pinned-bg, rgba(var(--primary-rgb, 108,99,255), 0.04)); }
        .memo-row:last-child { border-bottom: none; }
        
        .memo-row-num {
            flex-shrink: 0;
            width: 28px;
            text-align: right;
            font-size: 12px;
            color: var(--text-muted, #999);
            font-variant-numeric: tabular-nums;
        }
        .memo-row-pin {
            flex-shrink: 0;
            width: 20px;
            font-size: 14px;
            text-align: center;
        }
        .memo-row-color {
            flex-shrink: 0;
            width: 4px;
            height: 28px;
            border-radius: 2px;
        }
        .memo-row-body {
            flex: 1;
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .memo-row-title {
            font-weight: 600;
            font-size: 14px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 300px;
        }
        .memo-row-preview {
            font-size: 13px;
            color: var(--text-secondary, #666);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex: 1;
            min-width: 0;
        }
        .memo-row-date {
            flex-shrink: 0;
            font-size: 12px;
            color: var(--text-muted, #999);
            white-space: nowrap;
        }
        .memo-row-actions {
            flex-shrink: 0;
            display: flex;
            gap: 2px;
            opacity: 0;
            transition: opacity 0.15s;
        }
        .memo-row:hover .memo-row-actions { opacity: 1; }
        .memo-row-actions button {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            padding: 2px 5px;
            border-radius: 4px;
            color: var(--text-secondary, #666);
        }
        .memo-row-actions button:hover { background: rgba(0,0,0,0.08); }
        .memo-row-actions .memo-act-delete:hover { color: #e53e3e; }
        @media (max-width: 600px) {
            .memo-row { padding: 8px 10px; gap: 6px; }
            .memo-row-title { max-width: 140px; font-size: 13px; }
            .memo-row-preview { display: none; }
            .memo-row-num { width: 22px; font-size: 11px; }
            .memo-row-actions { opacity: 1; }
        }
        
        /* モーダル */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal-overlay.active { display: flex; }
        
        .modal {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-header h3 { font-size: 18px; }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted);
        }
        
        .modal-body { padding: 20px; max-height: 60vh; overflow-y: auto; }
        
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 6px; }
        .form-group input, .form-group textarea, .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }
        .form-group input:focus, .form-group textarea:focus, .form-group select:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .form-row { display: flex; gap: 12px; }
        .form-row .form-group { flex: 1; }
        
        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--border-light);
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        .empty-state .icon { font-size: 64px; margin-bottom: 16px; }
        .empty-state h3 { color: var(--text-primary); margin-bottom: 8px; }
        
        /* タブボタン */
        .tab-container {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .tab-btn {
            padding: 10px 20px;
            border: 1px solid var(--border-light);
            background: white;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            color: var(--text-secondary);
        }
        .tab-btn:hover { background: var(--bg-secondary); }
        .tab-btn.active {
            background: var(--gradient-primary);
            color: white;
            border-color: transparent;
        }
        
        /* ステータスバッジ */
        .status-badge {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: 500;
        }
        .status-badge.pending { background: #fef3c7; color: #92400e; }
        .status-badge.in_progress { background: #dbeafe; color: #1e40af; }
        .status-badge.completed { background: #d1fae5; color: #065f46; }
        .status-badge.cancelled { background: #f3f4f6; color: #6b7280; }
        
        /* 担当者選択 */
        .assignee-options {
            display: flex;
            gap: 16px;
            margin-bottom: 8px;
        }
        .radio-option {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            font-size: 14px;
        }
        .radio-option input[type="radio"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        /* 作業者チェックボックスリスト（一流UI） */
        #taskAssigneeCheckboxList {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        #taskAssigneeCheckboxList .task-assignee-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            margin: 0 -4px;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        #taskAssigneeCheckboxList .task-assignee-item:hover {
            background: rgba(0, 0, 0, 0.04);
        }
        #taskAssigneeCheckboxList .task-assignee-item input[type="checkbox"] {
            flex-shrink: 0;
            width: 18px;
            height: 18px;
            margin: 0;
            cursor: pointer;
            accent-color: var(--primary, #059669);
        }
        #taskAssigneeCheckboxList .task-assignee-item .task-assignee-name {
            flex: 1;
            font-size: 14px;
            line-height: 1.4;
            user-select: none;
        }
        
        /* タスク説明 */
        .task-description {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 6px;
            line-height: 1.5;
        }
    </style>
</head>
<body class="tasks-page style-<?= htmlspecialchars($designSettings['ui_style'] ?? DESIGN_DEFAULT_STYLE) ?> has-tabs" data-theme="<?= htmlspecialchars($designSettings['theme'] ?? DESIGN_DEFAULT_THEME) ?>">
    <?php
    $topbar_back_url = 'chat.php';
    include __DIR__ . '/includes/chat/topbar.php';
    ?>
    
    <!-- メインコンテナ -->
    <div class="main-container">
        <!-- 左パネル（空白スペーサー） -->
        <aside class="left-spacer"></aside>
        
        <!-- 中央パネル（コンテンツ） -->
        <main class="center-panel">
            <div class="container">
        <!-- タブ切り替え -->
        <div class="tab-container" style="display: flex; gap: 8px; margin-bottom: 24px;">
            <button class="tab-btn <?= $active_tab === 'my' ? 'active' : '' ?>" onclick="switchTab('my')">
                📥 自分のタスク (<?= count($myTasks) ?>)
            </button>
            <button class="tab-btn <?= $active_tab === 'requested' ? 'active' : '' ?>" onclick="switchTab('requested')">
                📤 依頼したタスク (<?= count($requestedTasks) ?>)
            </button>
            <button class="tab-btn <?= $active_tab === 'memos' ? 'active' : '' ?>" onclick="switchTab('memos')">
                📝 メモ (<?= count($memos) ?>)
            </button>
        </div>

        <!-- 自分のタスクタブ -->
        <div id="myTab" style="display: <?= $active_tab === 'my' || $active_tab === 'tasks' ? 'block' : 'none' ?>;">
            <div class="content-header">
                <h2>自分のタスク</h2>
                <button class="add-btn" onclick="openTaskModal()">➕ 新規タスク</button>
            </div>
            
            <?php if (empty($myTasks)): ?>
            <div class="empty-state">
                <div class="icon">📋</div>
                <h3>タスクがありません</h3>
                <p>「新規タスク」ボタンでタスクを追加しましょう</p>
            </div>
            <?php else: ?>
            <div class="task-list">
                <?php foreach ($myTasks as $task): ?>
                <?php
                    $is_completed = $task['status'] === 'completed';
                    $is_overdue = !empty($task['due_date']) && strtotime($task['due_date']) < strtotime('today') && !$is_completed;
                    $is_from_other = (int)$task['created_by'] !== $user_id;
                ?>
                <div class="task-card <?= $is_completed ? 'completed' : '' ?>" data-task-id="<?= $task['id'] ?>">
                    <div class="task-checkbox <?= $is_completed ? 'checked' : '' ?>" onclick="toggleTask(<?= $task['id'] ?>)">
                        <?= $is_completed ? '✓' : '' ?>
                    </div>
                    <div class="task-content">
                        <div class="task-title"><?= htmlspecialchars($task['title']) ?></div>
                        <div class="task-roles" style="display: flex; gap: 8px; margin-bottom: 4px; flex-wrap: wrap;">
                            <?php if ($is_from_other): ?>
                            <span class="role-badge requester-role" style="background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #1e40af; padding: 2px 8px; border-radius: 12px; font-size: 11px;">
                                📤 依頼者: <?= htmlspecialchars($task['creator_name'] ?? '不明') ?>
                            </span>
                            <span class="role-badge worker-role" style="background: linear-gradient(135deg, #dcfce7, #bbf7d0); color: #166534; padding: 2px 8px; border-radius: 12px; font-size: 11px;">
                                📥 作業者: あなた
                            </span>
                            <?php else: ?>
                            <span class="role-badge self-task" style="background: linear-gradient(135deg, #f3e8ff, #e9d5ff); color: #6b21a8; padding: 2px 8px; border-radius: 12px; font-size: 11px;">
                                📝 自分のタスク
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="task-meta">
                            <span class="status-badge <?= $task['status'] ?>" style="font-size: 11px; padding: 2px 8px; border-radius: 4px; <?php
                                $statusStyles = [
                                    'pending' => 'background: #fef3c7; color: #92400e;',
                                    'in_progress' => 'background: #dbeafe; color: #1e40af;',
                                    'completed' => 'background: #dcfce7; color: #166534;',
                                    'cancelled' => 'background: #f3f4f6; color: #6b7280;'
                                ];
                                echo $statusStyles[$task['status']] ?? '';
                            ?>">
                                <?php
                                $statusLabels = ['pending' => '未着手', 'in_progress' => '進行中', 'completed' => '完了', 'cancelled' => 'キャンセル'];
                                echo $statusLabels[$task['status']] ?? $task['status'];
                                ?>
                            </span>
                            <?php if (!empty($task['due_date'])): ?>
                            <span class="due-date <?= $is_overdue ? 'overdue' : '' ?>">
                                📅 <?= date('n/j', strtotime($task['due_date'])) ?>
                                <?= $is_overdue ? '（期限切れ）' : '' ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($task['priority']) && $task['priority'] > 0): ?>
                            <span class="priority-badge <?= $task['priority'] >= 2 ? 'high' : 'medium' ?>">
                                <?= $priorityLabels[$task['priority']] ?? '' ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($task['description'])): ?>
                        <div class="task-description"><?= nl2br(htmlspecialchars(mb_substr($task['description'], 0, 100))) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="task-actions">
                        <?php if (!$is_completed): ?>
                        <button class="complete-btn" onclick="completeTask(<?= $task['id'] ?>)" title="完了にする">✓ 完了</button>
                        <?php else: ?>
                        <button class="complete-btn completed" onclick="reopenTask(<?= $task['id'] ?>)" title="未完了に戻す">↩ 戻す</button>
                        <?php endif; ?>
                        <button onclick="editTask(<?= $task['id'] ?>)" title="編集">✏️</button>
                        <button onclick="deleteTask(<?= $task['id'] ?>)" title="削除">🗑️</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- 依頼したタスクタブ -->
        <div id="requestedTab" style="display: <?= $active_tab === 'requested' ? 'block' : 'none' ?>;">
            <div class="content-header">
                <h2>依頼したタスク</h2>
                <button class="add-btn" onclick="openTaskModal(null, true)">➕ タスクを依頼</button>
            </div>
            
            <?php if (empty($requestedTasks)): ?>
            <div class="empty-state">
                <div class="icon">📤</div>
                <h3>依頼したタスクがありません</h3>
                <p>「タスクを依頼」ボタンで他の人にタスクを依頼しましょう</p>
            </div>
            <?php else: ?>
            <div class="task-list">
                <?php foreach ($requestedTasks as $task): ?>
                <?php
                    $is_completed = $task['status'] === 'completed';
                    $is_overdue = !empty($task['due_date']) && strtotime($task['due_date']) < strtotime('today') && !$is_completed;
                ?>
                <div class="task-card <?= $is_completed ? 'completed' : '' ?>" data-task-id="<?= $task['id'] ?>">
                    <div class="task-checkbox <?= $is_completed ? 'checked' : '' ?>" style="pointer-events: none;">
                        <?= $is_completed ? '✓' : '' ?>
                    </div>
                    <div class="task-content">
                        <div class="task-title"><?= htmlspecialchars($task['title']) ?></div>
                        <div class="task-roles" style="display: flex; gap: 8px; margin-bottom: 4px; flex-wrap: wrap;">
                            <span class="role-badge requester-role" style="background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #1e40af; padding: 2px 8px; border-radius: 12px; font-size: 11px;">
                                📤 依頼者: あなた
                            </span>
                            <span class="role-badge worker-role" style="background: linear-gradient(135deg, #dcfce7, #bbf7d0); color: #166534; padding: 2px 8px; border-radius: 12px; font-size: 11px;">
                                📥 作業者: <?= htmlspecialchars($task['assignee_name'] ?? '不明') ?>
                            </span>
                        </div>
                        <div class="task-meta">
                            <span class="status-badge <?= $task['status'] ?>" style="font-size: 11px; padding: 2px 8px; border-radius: 4px; <?php
                                $statusStyles = [
                                    'pending' => 'background: #fef3c7; color: #92400e;',
                                    'in_progress' => 'background: #dbeafe; color: #1e40af;',
                                    'completed' => 'background: #dcfce7; color: #166534;',
                                    'cancelled' => 'background: #f3f4f6; color: #6b7280;'
                                ];
                                echo $statusStyles[$task['status']] ?? '';
                            ?>">
                                <?php
                                $statusLabels = ['pending' => '未着手', 'in_progress' => '進行中', 'completed' => '完了', 'cancelled' => 'キャンセル'];
                                echo $statusLabels[$task['status']] ?? $task['status'];
                                ?>
                            </span>
                            <?php if (!empty($task['due_date'])): ?>
                            <span class="due-date <?= $is_overdue ? 'overdue' : '' ?>">
                                📅 <?= date('n/j', strtotime($task['due_date'])) ?>
                                <?= $is_overdue ? '（期限切れ）' : '' ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($task['description'])): ?>
                        <div class="task-description"><?= nl2br(htmlspecialchars(mb_substr($task['description'], 0, 100))) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="task-actions">
                        <button onclick="editTask(<?= $task['id'] ?>)" title="編集">✏️</button>
                        <button onclick="deleteTask(<?= $task['id'] ?>)" title="削除">🗑️</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- メモタブ -->
        <div id="memosTab" style="display: <?= $active_tab === 'memos' ? 'block' : 'none' ?>;">
            <div class="content-header">
                <h2><?= __('my_memo') ?> (<?= count($memos) ?>)</h2>
                <button class="add-btn" onclick="openMemoModal()">➕ <?= __('add_memo') ?></button>
            </div>
            
            <?php if (empty($memos)): ?>
            <div class="empty-state">
                <div class="icon">📝</div>
                <h3><?= __('no_memos') ?></h3>
                <p><?= $currentLang === 'en' ? 'Create a memo with the "Add Memo" button' : ($currentLang === 'zh' ? '点击"添加备忘录"按钮创建备忘录' : '「メモ追加」ボタンで新しいメモを作成しましょう') ?></p>
            </div>
            <?php else: ?>
            <div class="memo-list">
                <?php foreach ($memos as $i => $memo):
                    $color = htmlspecialchars($memo['color'] ?? '#ffffff');
                    $isPinned = !empty($memo['is_pinned']);
                    $title = htmlspecialchars($memo['title'] ?: '無題のメモ');
                    $preview = htmlspecialchars(mb_substr(str_replace(["\r\n","\r","\n"], ' ', $memo['content'] ?? ''), 0, 80));
                    $date = date('n/j H:i', strtotime($memo['updated_at']));
                ?>
                <div class="memo-row <?= $isPinned ? 'pinned' : '' ?>" onclick="openMemoModal(<?= (int)$memo['id'] ?>)">
                    <span class="memo-row-num"><?= $i + 1 ?></span>
                    <span class="memo-row-pin"><?= $isPinned ? '📌' : '' ?></span>
                    <span class="memo-row-color" style="background:<?= $color ?>"></span>
                    <div class="memo-row-body">
                        <span class="memo-row-title"><?= $title ?></span>
                        <span class="memo-row-preview"><?= $preview ?></span>
                    </div>
                    <span class="memo-row-date"><?= $date ?></span>
                    <span class="memo-row-actions">
                        <button onclick="event.stopPropagation(); toggleMemoPin(<?= (int)$memo['id'] ?>, <?= $isPinned ? 0 : 1 ?>)" title="<?= $isPinned ? 'ピン解除' : 'ピン留め' ?>"><?= $isPinned ? '📌' : '📍' ?></button>
                        <button onclick="event.stopPropagation(); openMemoModal(<?= (int)$memo['id'] ?>)" title="編集">✏️</button>
                        <button class="memo-act-delete" onclick="event.stopPropagation(); quickDeleteMemo(<?= (int)$memo['id'] ?>)" title="削除">🗑️</button>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
            </div><!-- /.container -->
        </main><!-- /.center-panel -->
        
        <!-- 右パネル（空白スペーサー） -->
        <aside class="right-spacer"></aside>
    </div><!-- /.main-container -->
    
    <!-- モバイル用フローティングアクションボタン -->
    <button class="fab" onclick="openTaskModal()" aria-label="新規タスク">➕</button>
    
    <!-- タスクモーダル -->
    <div class="modal-overlay" id="taskModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="taskModalTitle">新規タスク</h3>
                <button class="modal-close" onclick="closeModal('taskModal')">×</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="taskId">
                <div class="form-group">
                    <label>タスク内容 *</label>
                    <textarea id="taskDescription" rows="3" placeholder="タスクの内容を入力"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>期限</label>
                        <input type="date" id="taskDueDate">
                    </div>
                    <div class="form-group">
                        <label>優先度</label>
                        <select id="taskPriority">
                            <option value="0">低</option>
                            <option value="1" selected>中</option>
                            <option value="2">高</option>
                            <option value="3">緊急</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>担当者</label>
                    <div class="assignee-options">
                        <label class="radio-option">
                            <input type="radio" name="assigneeType" value="self" checked onchange="toggleAssigneeSelect()">
                            <span>自分</span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="assigneeType" value="other" onchange="toggleAssigneeSelect()">
                            <span>他の人に依頼</span>
                        </label>
                    </div>
                    <div id="taskGroupAssigneeArea" style="display: none; margin-top: 12px;">
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label style="font-size: 13px; font-weight: 500;">グループを選択 *</label>
                            <select id="taskConversationSelect" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                                <option value="">-- グループ・DMを選択 --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label style="font-size: 13px; font-weight: 500;">作業者（担当者）* 複数選択可</label>
                            <div id="taskAssigneeCheckboxList" style="max-height: 140px; overflow-y: auto; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fafafa; font-size: 14px;">
                                <div class="assignee-placeholder" style="color: #6b7280;">グループを選択するとメンバーが表示されます</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('taskModal')">キャンセル</button>
                <button class="btn btn-primary" onclick="saveTask()">保存</button>
            </div>
        </div>
    </div>
    
    <!-- メモモーダル -->
    <div class="modal-overlay" id="memoModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="memoModalTitle">新規メモ</h3>
                <button class="modal-close" onclick="closeModal('memoModal')">×</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="memoId">
                <div class="form-group">
                    <label>タイトル</label>
                    <input type="text" id="memoTitle" placeholder="メモのタイトル（任意）">
                </div>
                <div class="form-group">
                    <label>内容</label>
                    <textarea id="memoContent" placeholder="メモの内容を入力..." rows="8"></textarea>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="memoPinned"> 📌 ピン留め
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="deleteMemoBtn" onclick="deleteMemo()" style="margin-right: auto; display: none;">削除</button>
                <button class="btn btn-secondary" onclick="closeModal('memoModal')">キャンセル</button>
                <button class="btn btn-primary" onclick="saveMemo()">保存</button>
            </div>
        </div>
    </div>
    
    <script src="assets/js/topbar-standalone.js"></script>
    <script>
        function switchTab(tab) {
            document.getElementById('myTab').style.display = (tab === 'my' || tab === 'tasks') ? 'block' : 'none';
            document.getElementById('requestedTab').style.display = tab === 'requested' ? 'block' : 'none';
            document.getElementById('memosTab').style.display = tab === 'memos' ? 'block' : 'none';
            document.querySelectorAll('.tab-btn').forEach(btn => {
                const btnTab = btn.onclick.toString().match(/'(\w+)'/)?.[1];
                btn.classList.toggle('active', btnTab === tab || (btnTab === 'my' && tab === 'tasks'));
            });
            history.replaceState(null, '', '?tab=' + tab);
        }
        
        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }
        
        function toggleAssigneeSelect() {
            const isOther = document.querySelector('input[name="assigneeType"][value="other"]').checked;
            const area = document.getElementById('taskGroupAssigneeArea');
            if (area) area.style.display = isOther ? 'block' : 'none';
            if (isOther) loadConversationsForTask();
        }
        
        async function loadConversationsForTask() {
            const sel = document.getElementById('taskConversationSelect');
            if (!sel) return;
            sel.innerHTML = '<option value="">読み込み中...</option>';
            try {
                const r = await fetch('api/conversations.php?action=list&limit=100');
                const data = await r.json();
                sel.innerHTML = '<option value="">-- グループ・DMを選択 --</option>';
                if (data.success && data.conversations) {
                    data.conversations.forEach(c => {
                        const opt = document.createElement('option');
                        opt.value = c.id;
                        opt.textContent = c.name || c.display_name || ('会話 ' + c.id);
                        sel.appendChild(opt);
                    });
                }
            } catch (e) {
                sel.innerHTML = '<option value="">読み込みエラー</option>';
            }
            document.getElementById('taskAssigneeCheckboxList').innerHTML = '<div class="assignee-placeholder" style="color: #6b7280;">グループを選択するとメンバーが表示されます</div>';
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const convSel = document.getElementById('taskConversationSelect');
            if (convSel) {
                convSel.addEventListener('change', async function() {
                    const convId = this.value;
                    const listEl = document.getElementById('taskAssigneeCheckboxList');
                    if (!listEl) return;
                    if (!convId) {
                        listEl.innerHTML = '<div class="assignee-placeholder" style="color: #6b7280;">グループを選択するとメンバーが表示されます</div>';
                        return;
                    }
                    listEl.innerHTML = '<div class="assignee-placeholder" style="color: #6b7280;">読み込み中...</div>';
                    try {
                        const r = await fetch('api/conversations.php?action=members&conversation_id=' + convId);
                        const data = await r.json();
                        listEl.innerHTML = '';
                        const currentUserId = <?= (int)$user_id ?>;
                        if (data.success && data.members) {
                            const others = data.members.filter(m => m.id != currentUserId);
                            if (others.length === 0) {
                                listEl.innerHTML = '<div style="color: #6b7280;">他のメンバーがいません</div>';
                                return;
                            }
                            others.forEach(m => {
                                const label = document.createElement('label');
                                label.className = 'task-assignee-item';
                                const cb = document.createElement('input');
                                cb.type = 'checkbox';
                                cb.name = 'taskAssigneeCb';
                                cb.value = m.id;
                                cb.className = 'task-assignee-cb';
                                const span = document.createElement('span');
                                span.className = 'task-assignee-name';
                                span.textContent = m.display_name || m.name || 'ユーザー';
                                label.appendChild(cb);
                                label.appendChild(span);
                                listEl.appendChild(label);
                            });
                        } else {
                            listEl.innerHTML = '<div style="color: #dc2626;">メンバーを取得できませんでした</div>';
                        }
                    } catch (e) {
                        listEl.innerHTML = '<div style="color: #dc2626;">読み込みエラー</div>';
                    }
                });
            }
        });
        
        // タスク関連
        function openTaskModal(taskId = null, forRequest = false) {
            document.getElementById('taskId').value = taskId || '';
            document.getElementById('taskDescription').value = '';
            document.getElementById('taskDueDate').value = '';
            document.getElementById('taskPriority').value = '1';
            
            const area = document.getElementById('taskGroupAssigneeArea');
            if (area) area.style.display = 'none';
            const convSel = document.getElementById('taskConversationSelect');
            if (convSel) convSel.innerHTML = '<option value="">-- グループ・DMを選択 --</option>';
            const listEl = document.getElementById('taskAssigneeCheckboxList');
            if (listEl) listEl.innerHTML = '<div class="assignee-placeholder" style="color: #6b7280;">グループを選択するとメンバーが表示されます</div>';
            
            if (forRequest) {
                document.querySelector('input[name="assigneeType"][value="other"]').checked = true;
                if (area) area.style.display = 'block';
                loadConversationsForTask();
                document.getElementById('taskModalTitle').textContent = 'タスクを依頼';
            } else {
                document.querySelector('input[name="assigneeType"][value="self"]').checked = true;
                document.getElementById('taskModalTitle').textContent = taskId ? 'タスク編集' : '新規タスク';
            }
            
            document.getElementById('taskModal').classList.add('active');
        }
        
        async function loadTaskForEdit(taskId) {
            try {
                const response = await fetch(`api/tasks.php?action=get&id=${taskId}`);
                const data = await response.json();
                if (data.success && data.task) {
                    document.getElementById('taskId').value = taskId;
                    // 説明があれば説明を、なければタイトルを表示
                    const content = data.task.description || data.task.title || '';
                    document.getElementById('taskDescription').value = content;
                    document.getElementById('taskDueDate').value = data.task.due_date || '';
                    document.getElementById('taskPriority').value = data.task.priority || '1';
                    
                    if (data.task.assigned_to && data.task.assigned_to != <?= $user_id ?>) {
                        document.querySelector('input[name="assigneeType"][value="other"]').checked = true;
                        const area = document.getElementById('taskGroupAssigneeArea');
                        if (area) area.style.display = 'block';
                        loadConversationsForTask().then(() => {
                            const convId = data.task.conversation_id;
                            const convSel = document.getElementById('taskConversationSelect');
                            if (convId && convSel) {
                                convSel.value = convId;
                                convSel.dispatchEvent(new Event('change'));
                                setTimeout(() => {
                                    const cb = document.querySelector('.task-assignee-cb[value="' + data.task.assigned_to + '"]');
                                    if (cb) cb.checked = true;
                                }, 500);
                            }
                        });
                    } else {
                        document.querySelector('input[name="assigneeType"][value="self"]').checked = true;
                        document.getElementById('taskGroupAssigneeArea').style.display = 'none';
                    }
                    
                    document.getElementById('taskModalTitle').textContent = 'タスク編集';
                    document.getElementById('taskModal').classList.add('active');
                }
            } catch (e) {
                console.error('タスク取得エラー:', e);
            }
        }
        
        async function saveTask() {
            const taskId = document.getElementById('taskId').value;
            const description = document.getElementById('taskDescription').value.trim();
            
            if (!description) {
                alert('タスク内容を入力してください');
                return;
            }
            
            const isOther = document.querySelector('input[name="assigneeType"][value="other"]').checked;
            const conversationId = isOther ? (document.getElementById('taskConversationSelect')?.value || '') : null;
            const assigneeIds = isOther ? Array.from(document.querySelectorAll('.task-assignee-cb:checked')).map(cb => parseInt(cb.value, 10)).filter(id => id) : [];
            
            if (isOther) {
                if (!conversationId) {
                    alert('グループを選択してください');
                    return;
                }
                if (assigneeIds.length === 0) {
                    alert('作業者を1人以上選択してください');
                    return;
                }
            }
            
            const payload = {
                action: taskId ? 'update' : 'create',
                description: description,
                due_date: document.getElementById('taskDueDate').value || null,
                priority: parseInt(document.getElementById('taskPriority').value) || 1
            };
            
            if (taskId) {
                payload.task_id = parseInt(taskId);
                payload.assigned_to = isOther && assigneeIds.length > 0 ? assigneeIds[0] : null;
                if (isOther && conversationId) payload.conversation_id = parseInt(conversationId, 10);
            } else {
                if (isOther) {
                    payload.assignee_ids = assigneeIds;
                    payload.conversation_id = parseInt(conversationId, 10);
                    payload.post_to_chat = true;
                } else {
                    payload.assigned_to = null;
                }
            }
            
            try {
                const response = await fetch('api/tasks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'エラーが発生しました');
                }
            } catch (e) {
                alert('保存に失敗しました');
            }
        }
        
        async function toggleTask(taskId) {
            try {
                const response = await fetch('api/tasks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'toggle', task_id: taskId })
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                }
            } catch (e) {}
        }
        
        // タスクを完了にする
        async function completeTask(taskId) {
            try {
                const response = await fetch('api/tasks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'complete', task_id: taskId })
                });
                
                const text = await response.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseError) {
                    console.error('JSON parse error:', text);
                    alert('サーバーエラー: ' + text.substring(0, 200));
                    return;
                }
                
                if (data.success) {
                    // 完了メッセージを表示
                    showToast('タスクを完了しました' + (data.notified ? '（依頼者に通知しました）' : ''));
                    location.reload();
                } else {
                    alert(data.message || 'エラーが発生しました');
                }
            } catch (e) {
                console.error('Complete task error:', e);
                alert('エラーが発生しました: ' + e.message);
            }
        }
        
        // タスクを未完了に戻す
        async function reopenTask(taskId) {
            try {
                const response = await fetch('api/tasks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reopen', task_id: taskId })
                });
                
                const text = await response.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseError) {
                    console.error('JSON parse error:', text);
                    alert('サーバーエラー: ' + text.substring(0, 200));
                    return;
                }
                
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'エラーが発生しました');
                }
            } catch (e) {
                console.error('Reopen task error:', e);
                alert('エラーが発生しました: ' + e.message);
            }
        }
        
        // トースト通知を表示
        function showToast(message) {
            const toast = document.createElement('div');
            toast.className = 'toast-notification';
            toast.textContent = message;
            toast.style.cssText = `
                position: fixed;
                bottom: 80px;
                left: 50%;
                transform: translateX(-50%);
                background: rgba(0, 0, 0, 0.85);
                color: white;
                padding: 12px 24px;
                border-radius: 8px;
                font-size: 14px;
                z-index: 9999;
                animation: fadeInOut 3s ease-in-out;
            `;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }
        
        async function editTask(taskId) {
            loadTaskForEdit(taskId);
        }
        
        async function deleteTask(taskId) {
            try {
                const response = await fetch('api/tasks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', task_id: taskId })
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'エラーが発生しました');
                }
            } catch (e) {
                alert('削除に失敗しました');
            }
        }
        
        // 翻訳機能
        const currentLang = '<?= $currentLang ?>';
        
        async function translateWish(taskId) {
            const card = document.querySelector(`[data-task-id="${taskId}"]`);
            const titleEl = document.getElementById(`task-title-${taskId}`);
            const translatedEl = document.getElementById(`task-translated-${taskId}`);
            const btn = card.querySelector('.translate-btn');
            const originalText = card.dataset.title;
            
            // 既に翻訳が表示されている場合はトグル
            if (translatedEl.style.display !== 'none') {
                translatedEl.style.display = 'none';
                return;
            }
            
            // ローディング状態
            btn.classList.add('loading');
            btn.textContent = '⏳';
            
            try {
                const response = await fetch('api/translate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        text: originalText,
                        source_lang: 'auto',
                        target_lang: currentLang
                    })
                });
                
                const data = await response.json();
                console.log('Translation response:', data);
                
                if (data.success && data.translated_text) {
                    // 翻訳結果が元のテキストと同じ場合はメッセージを表示
                    if (data.translated_text === originalText) {
                        translatedEl.textContent = '🌐 ' + (currentLang === 'en' ? '(Already in target language)' : (currentLang === 'zh' ? '（已经是目标语言）' : '（既に対象言語です）'));
                    } else {
                        translatedEl.textContent = '🌐 ' + data.translated_text;
                    }
                    translatedEl.style.display = 'block';
                } else {
                    alert(data.error || '<?= $currentLang === "en" ? "Translation failed" : ($currentLang === "zh" ? "翻译失败" : "翻訳に失敗しました") ?>');
                }
            } catch (error) {
                console.error('Translation error:', error);
                alert('<?= $currentLang === "en" ? "Translation failed" : ($currentLang === "zh" ? "翻译失败" : "翻訳に失敗しました") ?>');
            } finally {
                btn.classList.remove('loading');
                btn.textContent = '🌐';
            }
        }
        
        // メモ関連
        async function openMemoModal(memoId = null) {
            document.getElementById('memoId').value = memoId || '';
            document.getElementById('memoTitle').value = '';
            document.getElementById('memoContent').value = '';
            document.getElementById('memoPinned').checked = false;
            document.getElementById('memoModalTitle').textContent = memoId ? 'メモ編集' : '新規メモ';
            document.getElementById('deleteMemoBtn').style.display = memoId ? 'block' : 'none';
            document.getElementById('memoModal').classList.add('active');
            
            if (memoId) {
                try {
                    const response = await fetch('api/tasks.php?action=get&id=' + memoId);
                    const data = await response.json();
                    if (data.success && data.task) {
                        document.getElementById('memoTitle').value = data.task.title || '';
                        document.getElementById('memoContent').value = data.task.content || data.task.description || '';
                        document.getElementById('memoPinned').checked = (data.task.is_pinned == 1 || data.task.is_pinned === '1');
                    }
                } catch (e) {
                    console.error('メモ読み込みエラー:', e);
                }
            }
        }
        
        async function saveMemo() {
            const memoId = document.getElementById('memoId').value;
            const content = document.getElementById('memoContent').value.trim();
            
            if (!content) {
                alert('内容を入力してください');
                return;
            }
            
            const payload = {
                action: memoId ? 'update' : 'create',
                type: 'memo',
                title: document.getElementById('memoTitle').value.trim(),
                content: content,
                is_pinned: document.getElementById('memoPinned').checked ? 1 : 0
            };
            
            if (memoId) payload.task_id = parseInt(memoId);
            
            try {
                const response = await fetch('api/tasks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'エラーが発生しました');
                }
            } catch (e) {
                alert('保存に失敗しました');
            }
        }
        
        async function deleteMemo() {
            const memoId = document.getElementById('memoId').value;
            if (!memoId || !confirm('このメモを削除しますか？')) return;
            
            try {
                const response = await fetch('api/tasks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', task_id: parseInt(memoId) })
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'エラーが発生しました');
                }
            } catch (e) {
                alert('削除に失敗しました');
            }
        }
        
        async function toggleMemoPin(memoId, pinState) {
            try {
                const response = await fetch('api/tasks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'pin', task_id: memoId, is_pinned: pinState })
                });
                const data = await response.json();
                if (data.success) location.reload();
            } catch (e) {
                alert('ピン留め変更に失敗しました');
            }
        }
        
        async function quickDeleteMemo(memoId) {
            if (!confirm('このメモを削除しますか？')) return;
            try {
                const response = await fetch('api/tasks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', task_id: memoId })
                });
                const data = await response.json();
                if (data.success) location.reload();
                else alert(data.message || 'エラーが発生しました');
            } catch (e) {
                alert('削除に失敗しました');
            }
        }
    </script>
</body>
</html>

