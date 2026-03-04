<?php
/**
 * ページチェッカー（強化版）
 * 
 * 全ページの自動巡回・検査・レポート生成
 * - HTTPステータスチェック
 * - UIコンポーネント検査
 * - ボタン機能性確認
 * - テキスト可視性確認
 * - レイアウト確認
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

requireLogin();
requireSystemAdmin();

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
           . '://' . $_SERVER['HTTP_HOST'];

// ベースパスを自動検出（/nine/ または /）
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$basePath = (strpos($scriptPath, '/nine') !== false) ? '/nine' : '';

// チェック対象ページ
$pages = [
    // カテゴリ: 公開ページ
    ['category' => 'public', 'url' => $basePath . '/', 'name' => 'ログインページ', 'auth' => false, 'priority' => 'high'],
    ['category' => 'public', 'url' => $basePath . '/register.php', 'name' => '新規登録', 'auth' => false, 'priority' => 'high'],
    ['category' => 'public', 'url' => $basePath . '/forgot_password.php', 'name' => 'パスワードリセット', 'auth' => false, 'priority' => 'medium'],
    
    // カテゴリ: メイン機能
    ['category' => 'main', 'url' => $basePath . '/chat.php', 'name' => 'チャット', 'auth' => true, 'priority' => 'critical',
     'checkpoints' => ['メッセージ入力欄', 'サイドバー', 'トップバー', 'メッセージエリア']],
    ['category' => 'main', 'url' => $basePath . '/settings.php', 'name' => '設定', 'auth' => true, 'priority' => 'high',
     'checkpoints' => ['タブ切替', '保存ボタン', 'プロフィール編集']],
    ['category' => 'main', 'url' => $basePath . '/tasks.php', 'name' => 'タスク', 'auth' => true, 'priority' => 'medium',
     'checkpoints' => ['タスク追加', 'タスク一覧', '完了トグル']],
    ['category' => 'main', 'url' => $basePath . '/memos.php', 'name' => 'メモ', 'auth' => true, 'priority' => 'medium'],
    ['category' => 'main', 'url' => $basePath . '/notifications.php', 'name' => '通知', 'auth' => true, 'priority' => 'medium'],
    ['category' => 'main', 'url' => $basePath . '/design.php', 'name' => 'デザイン設定', 'auth' => true, 'priority' => 'low'],
    ['category' => 'main', 'url' => $basePath . '/call.php', 'name' => '通話', 'auth' => true, 'priority' => 'high'],
    
    // カテゴリ: 管理画面
    ['category' => 'admin', 'url' => $basePath . '/admin/index.php', 'name' => '管理ダッシュボード', 'auth' => true, 'admin' => true, 'priority' => 'high'],
    ['category' => 'admin', 'url' => $basePath . '/admin/users.php', 'name' => 'ユーザー管理', 'auth' => true, 'admin' => true, 'priority' => 'high'],
    ['category' => 'admin', 'url' => $basePath . '/admin/members.php', 'name' => '組織メンバー', 'auth' => true, 'admin' => true, 'priority' => 'high'],
    ['category' => 'admin', 'url' => $basePath . '/admin/groups.php', 'name' => 'グループ管理', 'auth' => true, 'admin' => true, 'priority' => 'medium'],
    ['category' => 'admin', 'url' => $basePath . '/admin/reports.php', 'name' => '通報管理', 'auth' => true, 'admin' => true, 'priority' => 'medium'],
    ['category' => 'admin', 'url' => $basePath . '/admin/settings.php', 'name' => 'システム設定', 'auth' => true, 'admin' => true, 'priority' => 'medium'],
    ['category' => 'admin', 'url' => $basePath . '/admin/logs.php', 'name' => 'システムログ', 'auth' => true, 'admin' => true, 'priority' => 'low'],
    ['category' => 'admin', 'url' => $basePath . '/admin/backup.php', 'name' => 'バックアップ', 'auth' => true, 'admin' => true, 'priority' => 'low'],
    ['category' => 'admin', 'url' => $basePath . '/admin/security.php', 'name' => 'セキュリティ', 'auth' => true, 'admin' => true, 'priority' => 'high'],
    ['category' => 'admin', 'url' => $basePath . '/admin/monitor.php', 'name' => 'エラーチェック', 'auth' => true, 'admin' => true, 'priority' => 'high'],
    ['category' => 'admin', 'url' => $basePath . '/admin/attackers.php', 'name' => '攻撃者情報', 'auth' => true, 'admin' => true, 'priority' => 'medium'],
    
    // カテゴリ: API
    ['category' => 'api', 'url' => $basePath . '/api/health.php', 'name' => 'ヘルスチェックAPI', 'auth' => false, 'api' => true, 'priority' => 'high'],
];

// 最近のJSエラーを取得
$recentErrors = [];
try {
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT error_message, url, occurrence_count, last_occurred_at
        FROM error_logs 
        WHERE is_resolved = 0 
        ORDER BY last_occurred_at DESC 
        LIMIT 20
    ");
    $recentErrors = $stmt->fetchAll();
} catch (Exception $e) {
    // テーブルがない場合は無視
}

// 過去のチェック結果を取得
$pastChecks = [];
try {
    $stmt = $pdo->query("
        SELECT * FROM page_check_results 
        ORDER BY checked_at DESC 
        LIMIT 1
    ");
    $pastChecks = $stmt->fetch();
} catch (Exception $e) {
    // テーブルがない場合は無視
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ページチェッカー - Social9</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <style>
        :root {
            --bg-primary: #f5f5f5;
            --bg-card: #ffffff;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --border-color: #e5e7eb;
            --success: #22c55e;
            --warning: #f59e0b;
            --error: #ef4444;
            --info: #3b82f6;
        }
        
        body { 
            background: var(--bg-primary); 
            padding: 20px; 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .container { max-width: 1400px; margin: 0 auto; }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .header-actions { display: flex; gap: 12px; }
        
        .card {
            background: var(--bg-card);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 24px;
            overflow: hidden;
        }
        
        .card-header {
            background: #f8f9fa;
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-body { padding: 24px; }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary { background: var(--info); color: white; }
        .btn-primary:hover { background: #2563eb; transform: translateY(-1px); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #16a34a; }
        .btn-warning { background: var(--warning); color: white; }
        .btn-secondary { background: #6b7280; color: white; }
        .btn-secondary:hover { background: #4b5563; }
        .btn-lg { padding: 16px 32px; font-size: 16px; }
        
        /* サマリーグリッド */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .summary-item {
            background: var(--bg-card);
            padding: 24px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .summary-item .value {
            font-size: 36px;
            font-weight: 700;
        }
        .summary-item .label {
            color: var(--text-secondary);
            font-size: 14px;
            margin-top: 4px;
        }
        .summary-item.ok .value { color: var(--success); }
        .summary-item.warning .value { color: var(--warning); }
        .summary-item.error .value { color: var(--error); }
        .summary-item.info .value { color: var(--info); }
        
        /* 進捗 */
        .progress-container {
            display: none;
            margin: 24px 0;
        }
        .progress-container.active { display: block; }
        
        .progress-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .progress-bar {
            height: 12px;
            background: var(--border-color);
            border-radius: 6px;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--info), #60a5fa);
            transition: width 0.3s ease;
            border-radius: 6px;
        }
        
        .current-page {
            margin-top: 12px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            font-family: monospace;
            font-size: 13px;
        }
        
        /* 結果テーブル */
        .results-table {
            width: 100%;
            border-collapse: collapse;
        }
        .results-table th,
        .results-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        .results-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 13px;
            text-transform: uppercase;
        }
        .results-table tr:hover { background: #f9fafb; }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-error { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-secondary { background: #f3f4f6; color: #4b5563; }
        
        .priority-critical { border-left: 4px solid var(--error); }
        .priority-high { border-left: 4px solid var(--warning); }
        .priority-medium { border-left: 4px solid var(--info); }
        .priority-low { border-left: 4px solid #9ca3af; }
        
        /* 検査フレーム */
        .inspection-frame-container {
            display: none;
            margin: 24px 0;
        }
        .inspection-frame-container.active { display: block; }
        
        .inspection-frame {
            width: 100%;
            height: 600px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
        }
        
        /* 詳細モーダル */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 900px;
            max-height: 80vh;
            overflow: auto;
        }
        
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h2 { margin: 0; font-size: 20px; }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-secondary);
        }
        
        .modal-body { padding: 24px; }
        
        /* エラーリスト */
        .error-list {
            max-height: 400px;
            overflow-y: auto;
        }
        .error-item {
            padding: 14px;
            border-bottom: 1px solid var(--border-color);
            transition: background 0.2s;
        }
        .error-item:hover { background: #f9fafb; }
        .error-item:last-child { border-bottom: none; }
        .error-message {
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 13px;
            color: #666;
            background: #f5f5f5;
            padding: 8px;
            border-radius: 4px;
            margin-top: 8px;
        }
        
        /* カテゴリータブ */
        .category-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .category-tab {
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 14px;
            border: 1px solid var(--border-color);
            background: white;
            transition: all 0.2s;
        }
        .category-tab:hover { border-color: var(--info); }
        .category-tab.active {
            background: var(--info);
            color: white;
            border-color: var(--info);
        }
        
        /* レスポンシブ */
        @media (max-width: 768px) {
            .page-header { flex-direction: column; gap: 16px; }
            .header-actions { width: 100%; justify-content: center; }
            .summary-grid { grid-template-columns: repeat(2, 1fr); }
        }
        
        /* 検査オプション */
        .inspection-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .option-group {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 12px;
        }
        .option-group h4 {
            margin-bottom: 12px;
            font-size: 14px;
            color: #374151;
        }
        .option-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .option-checkbox {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            border: 1px solid #e5e7eb;
            transition: all 0.2s;
        }
        .option-checkbox:hover {
            border-color: #3b82f6;
        }
        .option-checkbox.active {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }
        .option-checkbox input {
            display: none;
        }
        
        /* マルチビュー結果 */
        .multi-view-results {
            margin-top: 24px;
        }
        .view-result-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .view-result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            background: #f8f9fa;
            border-bottom: 1px solid #e5e7eb;
            cursor: pointer;
        }
        .view-result-header:hover {
            background: #f3f4f6;
        }
        .view-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .view-icon {
            font-size: 24px;
        }
        .view-details h4 {
            margin: 0 0 4px 0;
            font-size: 16px;
        }
        .view-details span {
            font-size: 12px;
            color: #6b7280;
        }
        .view-summary {
            display: flex;
            gap: 12px;
        }
        .view-summary .stat {
            padding: 4px 12px;
            border-radius: 16px;
            font-size: 13px;
            font-weight: 600;
        }
        .view-summary .stat.ok { background: #dcfce7; color: #166534; }
        .view-summary .stat.warn { background: #fef3c7; color: #92400e; }
        .view-summary .stat.error { background: #fee2e2; color: #991b1b; }
        .view-result-body {
            display: none;
            padding: 16px;
        }
        .view-result-card.expanded .view-result-body {
            display: block;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">🔍 ページチェッカー（拡張版 v2）</h1>
            <div class="header-actions">
                <button class="btn btn-secondary" onclick="location.href='index.php'">← 管理画面に戻る</button>
                <button class="btn btn-primary" onclick="startFullInspection()">
                    🚀 基本検査
                </button>
                <button class="btn btn-primary btn-lg" onclick="startMultiViewInspection()" style="background:#8b5cf6;">
                    🔬 全デバイス・全テーマ検査
                </button>
            </div>
        </div>
        
        <!-- 検査オプション -->
        <div class="card" id="optionsCard" style="display:none;">
            <div class="card-header">⚙️ 検査オプション</div>
            <div class="card-body">
                <div class="inspection-options">
                    <div class="option-group">
                        <h4>📱 デバイス（ビューポート）</h4>
                        <div class="option-list" id="viewportOptions">
                            <label class="option-checkbox active" data-viewport="375x667">
                                <input type="checkbox" checked value="375x667"> 📱 iPhone SE
                            </label>
                            <label class="option-checkbox active" data-viewport="390x844">
                                <input type="checkbox" checked value="390x844"> 📱 iPhone 14
                            </label>
                            <label class="option-checkbox active" data-viewport="768x1024">
                                <input type="checkbox" checked value="768x1024"> 📱 iPad
                            </label>
                            <label class="option-checkbox active" data-viewport="1280x800">
                                <input type="checkbox" checked value="1280x800"> 💻 ノートPC
                            </label>
                            <label class="option-checkbox active" data-viewport="1920x1080">
                                <input type="checkbox" checked value="1920x1080"> 🖥️ デスクトップ
                            </label>
                        </div>
                    </div>
                    <div class="option-group">
                        <h4>🎨 テーマ</h4>
                        <div class="option-list" id="themeOptions">
                            <label class="option-checkbox active" data-theme="light">
                                <input type="checkbox" checked value="light"> ☀️ ライト
                            </label>
                            <label class="option-checkbox active" data-theme="dark">
                                <input type="checkbox" checked value="dark"> 🌙 ダーク
                            </label>
                        </div>
                    </div>
                    <div class="option-group">
                        <h4>📄 対象ページ <button type="button" class="btn btn-sm" onclick="toggleAllPages()" style="margin-left:10px;padding:4px 8px;font-size:11px;">全選択/解除</button></h4>
                        <div class="option-list" id="pageOptions">
                            <!-- メイン機能 -->
                            <div style="width:100%;font-weight:600;margin:8px 0 4px;color:#374151;">📱 メイン機能</div>
                            <label class="option-checkbox active" data-page="chat.php">
                                <input type="checkbox" checked value="chat.php"> 💬 チャット
                            </label>
                            <label class="option-checkbox active" data-page="settings.php">
                                <input type="checkbox" checked value="settings.php"> ⚙️ 設定
                            </label>
                            <label class="option-checkbox active" data-page="tasks.php">
                                <input type="checkbox" checked value="tasks.php"> 📋 タスク
                            </label>
                            <label class="option-checkbox active" data-page="memos.php">
                                <input type="checkbox" checked value="memos.php"> 📝 メモ
                            </label>
                            <label class="option-checkbox active" data-page="notifications.php">
                                <input type="checkbox" checked value="notifications.php"> 🔔 通知
                            </label>
                            <label class="option-checkbox active" data-page="design.php">
                                <input type="checkbox" checked value="design.php"> 🎨 デザイン
                            </label>
                            <label class="option-checkbox active" data-page="call.php">
                                <input type="checkbox" checked value="call.php"> 📞 通話
                            </label>
                            
                            <!-- 管理画面 -->
                            <div style="width:100%;font-weight:600;margin:12px 0 4px;color:#374151;">🔧 管理画面</div>
                            <label class="option-checkbox active" data-page="admin/index.php">
                                <input type="checkbox" checked value="admin/index.php"> 📊 管理ダッシュボード
                            </label>
                            <label class="option-checkbox active" data-page="admin/users.php">
                                <input type="checkbox" checked value="admin/users.php"> 👥 ユーザー管理
                            </label>
                            <label class="option-checkbox active" data-page="admin/members.php">
                                <input type="checkbox" checked value="admin/members.php"> 👤 メンバー管理
                            </label>
                            <label class="option-checkbox active" data-page="admin/groups.php">
                                <input type="checkbox" checked value="admin/groups.php"> 🏠 グループ管理
                            </label>
                            <label class="option-checkbox active" data-page="admin/settings.php">
                                <input type="checkbox" checked value="admin/settings.php"> ⚙️ システム設定
                            </label>
                            
                            <!-- セキュリティ・監視 -->
                            <div style="width:100%;font-weight:600;margin:12px 0 4px;color:#374151;">🛡️ セキュリティ・監視</div>
                            <label class="option-checkbox active" data-page="admin/security.php">
                                <input type="checkbox" checked value="admin/security.php"> 🔒 セキュリティ
                            </label>
                            <label class="option-checkbox active" data-page="admin/monitor.php">
                                <input type="checkbox" checked value="admin/monitor.php"> 📡 エラーチェック
                            </label>
                            <label class="option-checkbox active" data-page="admin/attackers.php">
                                <input type="checkbox" checked value="admin/attackers.php"> 🚨 攻撃者情報
                            </label>
                            <label class="option-checkbox active" data-page="admin/logs.php">
                                <input type="checkbox" checked value="admin/logs.php"> 📋 システムログ
                            </label>
                            
                            <!-- その他管理 -->
                            <div style="width:100%;font-weight:600;margin:12px 0 4px;color:#374151;">📦 その他管理</div>
                            <label class="option-checkbox active" data-page="admin/reports.php">
                                <input type="checkbox" checked value="admin/reports.php"> 🚩 通報管理
                            </label>
                            <label class="option-checkbox active" data-page="admin/backup.php">
                                <input type="checkbox" checked value="admin/backup.php"> 💾 バックアップ
                            </label>
                            <label class="option-checkbox active" data-page="admin/providers.php">
                                <input type="checkbox" checked value="admin/providers.php"> 🔌 プロバイダー
                            </label>
                            <label class="option-checkbox active" data-page="admin/wishes.php">
                                <input type="checkbox" checked value="admin/wishes.php"> ⭐ Wish管理
                            </label>
                        </div>
                    </div>
                    
                    <!-- 検査オプション -->
                    <div class="option-group">
                        <h4>🔧 検査オプション</h4>
                        <div class="option-list" id="testOptions">
                            <label class="option-checkbox active" data-test="visual">
                                <input type="checkbox" checked value="visual"> 👁️ 視覚チェック
                            </label>
                            <label class="option-checkbox active" data-test="layout">
                                <input type="checkbox" checked value="layout"> 📐 レイアウトチェック
                            </label>
                            <label class="option-checkbox active" data-test="buttons">
                                <input type="checkbox" checked value="buttons"> 🔘 ボタン機能テスト
                            </label>
                            <label class="option-checkbox active" data-test="links">
                                <input type="checkbox" checked value="links"> 🔗 リンクチェック
                            </label>
                            <label class="option-checkbox active" data-test="forms">
                                <input type="checkbox" checked value="forms"> 📝 フォームチェック
                            </label>
                        </div>
                    </div>
                </div>
                <div style="text-align:center; margin-top:16px;">
                    <button class="btn btn-primary btn-lg" onclick="runMultiViewInspection()" style="background:#8b5cf6;">
                        🔬 選択した組み合わせで検査開始
                    </button>
                    <p style="margin-top:12px; font-size:13px; color:#6b7280;">
                        選択: <span id="combinationCount">20</span> 通りの組み合わせ
                    </p>
                </div>
            </div>
        </div>
        
        <!-- マルチビュー検査結果 -->
        <div class="multi-view-results" id="multiViewResults" style="display:none;">
            <div class="card">
                <div class="card-header">
                    <span>🔬 マルチビュー検査結果</span>
                    <button class="btn btn-secondary" onclick="exportMultiViewReport()">📥 レポート出力</button>
                </div>
                <div class="card-body" id="multiViewResultsBody">
                    <!-- 結果がここに表示される -->
                </div>
            </div>
        </div>
        
        <!-- サマリー -->
        <div class="summary-grid" id="summaryGrid" style="display:none;">
            <div class="summary-item ok">
                <div class="value" id="okCount">0</div>
                <div class="label">正常</div>
            </div>
            <div class="summary-item warning">
                <div class="value" id="warnCount">0</div>
                <div class="label">警告</div>
            </div>
            <div class="summary-item error">
                <div class="value" id="errCount">0</div>
                <div class="label">エラー</div>
            </div>
            <div class="summary-item info">
                <div class="value" id="avgTime">0</div>
                <div class="label">平均応答時間(ms)</div>
            </div>
            <div class="summary-item">
                <div class="value" id="totalPages">0</div>
                <div class="label">検査ページ数</div>
            </div>
        </div>
        
        <!-- 進捗 -->
        <div class="progress-container" id="progressContainer">
            <div class="card">
                <div class="card-body">
                    <div class="progress-info">
                        <span id="progressLabel">検査中...</span>
                        <span id="progressPercent">0%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-bar-fill" id="progressBar" style="width:0%"></div>
                    </div>
                    <div class="current-page" id="currentPage">準備中...</div>
                </div>
            </div>
        </div>
        
        <!-- 検査フレーム -->
        <div class="inspection-frame-container" id="frameContainer">
            <div class="card">
                <div class="card-header">
                    <span>🖥️ ページプレビュー & UI検査</span>
                    <div style="display:flex; gap:8px;">
                        <button class="btn btn-primary" onclick="runFrameInspection()" id="inspectBtn">
                            🔍 UI検査実行
                        </button>
                        <button class="btn btn-secondary" onclick="closeFrame()">閉じる</button>
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <iframe id="inspectionFrame" class="inspection-frame" sandbox="allow-same-origin allow-scripts"></iframe>
                </div>
            </div>
        </div>
        
        <!-- 検査説明 -->
        <div class="card" id="introCard">
            <div class="card-header">📋 ページチェッカー（拡張版）について</div>
            <div class="card-body">
                <p style="font-size:16px; line-height:1.8; margin-bottom:20px;">
                    このツールは、サイト内の全ページを自動的に巡回し、包括的なチェックを行います：
                </p>
                
                <h4 style="margin-bottom:12px; color:#3b82f6;">📡 基本チェック（自動実行）</h4>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:24px;">
                    <div style="padding:12px; background:#f0fdf4; border-radius:8px; border-left:4px solid #22c55e;">
                        <h5 style="margin-bottom:4px;">🌐 HTTPステータス</h5>
                        <p style="color:#666; font-size:13px;">応答コードと応答時間</p>
                    </div>
                    <div style="padding:12px; background:#f0fdf4; border-radius:8px; border-left:4px solid #22c55e;">
                        <h5 style="margin-bottom:4px;">🚨 PHPエラー検出</h5>
                        <p style="color:#666; font-size:13px;">Fatal error, Warning等</p>
                    </div>
                </div>
                
                <h4 style="margin-bottom:12px; color:#8b5cf6;">🔍 詳細UI検査（プレビュー時）</h4>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:24px;">
                    <div style="padding:12px; background:#faf5ff; border-radius:8px; border-left:4px solid #8b5cf6;">
                        <h5 style="margin-bottom:4px;">👁️ 視覚的問題</h5>
                        <p style="color:#666; font-size:13px;">コントラスト比、透明度、フォントサイズ、白背景+白文字検出</p>
                    </div>
                    <div style="padding:12px; background:#faf5ff; border-radius:8px; border-left:4px solid #8b5cf6;">
                        <h5 style="margin-bottom:4px;">📐 レイアウト</h5>
                        <p style="color:#666; font-size:13px;">画面外要素、水平スクロール、z-index競合、オーバーフロー</p>
                    </div>
                    <div style="padding:12px; background:#faf5ff; border-radius:8px; border-left:4px solid #8b5cf6;">
                        <h5 style="margin-bottom:4px;">⚙️ 機能性</h5>
                        <p style="color:#666; font-size:13px;">ボタン、リンク、画像、フォームの動作確認</p>
                    </div>
                    <div style="padding:12px; background:#faf5ff; border-radius:8px; border-left:4px solid #8b5cf6;">
                        <h5 style="margin-bottom:4px;">♿ アクセシビリティ</h5>
                        <p style="color:#666; font-size:13px;">alt属性、ラベル、見出し階層、tabindex</p>
                    </div>
                    <div style="padding:12px; background:#faf5ff; border-radius:8px; border-left:4px solid #8b5cf6;">
                        <h5 style="margin-bottom:4px;">⚡ パフォーマンス</h5>
                        <p style="color:#666; font-size:13px;">DOM要素数、HTMLサイズ、画像最適化</p>
                    </div>
                    <div style="padding:12px; background:#fef2f2; border-radius:8px; border-left:4px solid #ef4444;">
                        <h5 style="margin-bottom:4px;">📦 リソース読込</h5>
                        <p style="color:#666; font-size:13px;">画像・動画・音声の読み込みエラー検出</p>
                    </div>
                    <div style="padding:12px; background:#fef2f2; border-radius:8px; border-left:4px solid #ef4444;">
                        <h5 style="margin-bottom:4px;">🔗 データ整合性</h5>
                        <p style="color:#666; font-size:13px;">ファイルパスのテキスト表示、エラー露出検出</p>
                    </div>
                    <div style="padding:12px; background:#fefce8; border-radius:8px; border-left:4px solid #eab308;">
                        <h5 style="margin-bottom:4px;">📱 モバイル</h5>
                        <p style="color:#666; font-size:13px;">タッチターゲット間隔、表示領域、横スクロール</p>
                    </div>
                    <div style="padding:12px; background:#f0fdfa; border-radius:8px; border-left:4px solid #14b8a6;">
                        <h5 style="margin-bottom:4px;">✨ 透明デザイン</h5>
                        <p style="color:#666; font-size:13px;">半透明要素の重なり、可読性、フレーム重なり</p>
                    </div>
                    <div style="padding:12px; background:#fdf4ff; border-radius:8px; border-left:4px solid #d946ef;">
                        <h5 style="margin-bottom:4px;">📲 モバイルUI詳細</h5>
                        <p style="color:#666; font-size:13px;">パネル同時表示、フォーム重なり、固定要素カバレッジ</p>
                    </div>
                    <div style="padding:12px; background:#ecfeff; border-radius:8px; border-left:4px solid #06b6d4;">
                        <h5 style="margin-bottom:4px;">📱 PWA/アプリ</h5>
                        <p style="color:#666; font-size:13px;">manifest.json、viewport、アイコン、Service Worker</p>
                    </div>
                </div>
                
                <div style="padding:16px; background:#dbeafe; border-radius:8px; margin-bottom:16px;">
                    <strong>💡 使い方:</strong>
                    <ol style="margin:8px 0 0 20px; font-size:14px;">
                        <li>「フル検査開始」で全ページのHTTPステータスを確認</li>
                        <li>結果一覧から「🔍 プレビュー」をクリックして詳細UI検査</li>
                        <li>検査結果は自動でモーダル表示されます</li>
                    </ol>
                </div>
                
                <div style="padding:16px; background:#fef3c7; border-radius:8px;">
                    <strong>📋 手動検査:</strong> 各ページでF12を開き、コンソールで <code>PageInspector.inspect()</code> を実行すると詳細レポートが表示されます。
                </div>
            </div>
        </div>
        
        <!-- 結果 -->
        <div id="resultsContainer" style="display:none;">
            <!-- カテゴリータブ -->
            <div class="category-tabs">
                <button class="category-tab active" data-category="all" onclick="filterCategory('all')">すべて</button>
                <button class="category-tab" data-category="public" onclick="filterCategory('public')">公開ページ</button>
                <button class="category-tab" data-category="main" onclick="filterCategory('main')">メイン機能</button>
                <button class="category-tab" data-category="admin" onclick="filterCategory('admin')">管理画面</button>
                <button class="category-tab" data-category="api" onclick="filterCategory('api')">API</button>
                <button class="category-tab" data-category="issues" onclick="filterCategory('issues')">問題のみ</button>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <span>📊 検査結果</span>
                    <div>
                        <button class="btn btn-success" onclick="exportReport()">📥 レポート出力</button>
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <table class="results-table">
                        <thead>
                            <tr>
                                <th style="width:30%;">ページ</th>
                                <th>ステータス</th>
                                <th>応答時間</th>
                                <th>UI検査</th>
                                <th>警告</th>
                                <th>エラー</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="resultTable"></tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- 最近のJSエラー -->
        <?php if (!empty($recentErrors)): ?>
        <div class="card">
            <div class="card-header">
                <span>🚨 最近のJavaScriptエラー (<?= count($recentErrors) ?>件)</span>
                <button class="btn btn-secondary" onclick="resolveAllErrors()">すべて解決済みにする</button>
            </div>
            <div class="card-body error-list" style="padding:0;">
                <?php foreach ($recentErrors as $error): ?>
                <div class="error-item">
                    <div style="display:flex; justify-content:space-between; align-items:start;">
                        <div>
                            <span class="badge badge-error">エラー</span>
                            <span style="margin-left:8px; color:#666; font-size:13px;">
                                発生回数: <?= (int)$error['occurrence_count'] ?> | 
                                <?= htmlspecialchars($error['last_occurred_at']) ?>
                            </span>
                        </div>
                        <a href="<?= htmlspecialchars($error['url'] ?? '#') ?>" target="_blank" style="font-size:13px; color:#3b82f6;">
                            <?= htmlspecialchars(basename($error['url'] ?? 'N/A')) ?>
                        </a>
                    </div>
                    <div class="error-message"><?= htmlspecialchars($error['error_message']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- 詳細モーダル -->
    <div class="modal-overlay" id="detailModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">ページ詳細</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody"></div>
        </div>
    </div>
    
    <script src="../assets/js/page-inspector.js"></script>
    <script>
    const pages = <?= json_encode($pages) ?>;
    const baseUrl = '<?= $baseUrl ?>';
    
    let results = [];
    let isRunning = false;
    let currentFilter = 'all';
    
    /**
     * フル検査開始
     */
    async function startFullInspection() {
        if (isRunning) return;
        isRunning = true;
        
        results = [];
        
        // UI更新
        document.getElementById('introCard').style.display = 'none';
        document.getElementById('progressContainer').classList.add('active');
        document.getElementById('summaryGrid').style.display = 'grid';
        document.getElementById('resultsContainer').style.display = 'none';
        
        const progressBar = document.getElementById('progressBar');
        const progressLabel = document.getElementById('progressLabel');
        const progressPercent = document.getElementById('progressPercent');
        const currentPage = document.getElementById('currentPage');
        
        for (let i = 0; i < pages.length; i++) {
            const page = pages[i];
            const progress = ((i + 1) / pages.length * 100);
            
            // 進捗更新
            progressBar.style.width = progress + '%';
            progressPercent.textContent = Math.round(progress) + '%';
            progressLabel.textContent = `検査中... (${i + 1}/${pages.length})`;
            currentPage.textContent = `${page.name} - ${page.url}`;
            
            // ページを検査
            const result = await inspectPage(page);
            results.push(result);
            
            // 中間サマリー更新
            updateSummary();
        }
        
        // 完了
        document.getElementById('progressContainer').classList.remove('active');
        document.getElementById('resultsContainer').style.display = 'block';
        renderResults();
        
        isRunning = false;
    }
    
    /**
     * 単一ページを検査
     */
    async function inspectPage(page) {
        const result = {
            ...page,
            status: 0,
            time: 0,
            ok: false,
            error: null,
            uiCheck: null,
            warnings: 0,
            errors: 0
        };
        
        try {
            // HTTPチェック
            const start = performance.now();
            const response = await fetch(baseUrl + page.url, {
                method: 'GET',
                credentials: 'include',
                headers: { 'X-Page-Check': '1' }
            });
            result.time = Math.round(performance.now() - start);
            result.status = response.status;
            result.ok = response.ok;
            
            // 認証が必要なページでリダイレクトされた場合
            if (page.auth && (response.status === 302 || response.status === 301)) {
                result.status = 'AUTH';
            }
            
        } catch (error) {
            result.error = error.message;
        }
        
        // 少し待機
        await new Promise(r => setTimeout(r, 200));
        
        return result;
    }
    
    /**
     * サマリー更新
     */
    function updateSummary() {
        let ok = 0, warn = 0, err = 0, totalTime = 0;
        
        results.forEach(r => {
            if (r.ok || r.status === 200) {
                ok++;
            } else if (r.status === 302 || r.status === 301 || r.status === 'AUTH') {
                warn++;
            } else {
                err++;
            }
            totalTime += r.time;
        });
        
        document.getElementById('okCount').textContent = ok;
        document.getElementById('warnCount').textContent = warn;
        document.getElementById('errCount').textContent = err;
        document.getElementById('avgTime').textContent = results.length > 0 ? Math.round(totalTime / results.length) : 0;
        document.getElementById('totalPages').textContent = results.length;
    }
    
    /**
     * 結果をレンダリング
     */
    function renderResults() {
        const table = document.getElementById('resultTable');
        table.innerHTML = '';
        
        const filtered = currentFilter === 'all' ? results :
                        currentFilter === 'issues' ? results.filter(r => !r.ok && r.status !== 200) :
                        results.filter(r => r.category === currentFilter);
        
        filtered.forEach((r, i) => {
            const tr = document.createElement('tr');
            tr.className = `priority-${r.priority || 'medium'}`;
            tr.dataset.index = i;
            tr.dataset.category = r.category;
            
            // ステータスバッジ
            let statusBadge;
            if (r.status === 200 || r.ok) {
                statusBadge = `<span class="badge badge-success">${r.status}</span>`;
            } else if (r.status === 302 || r.status === 301 || r.status === 'AUTH') {
                statusBadge = `<span class="badge badge-warning">${r.status}</span>`;
            } else if (r.status >= 400) {
                statusBadge = `<span class="badge badge-error">${r.status}</span>`;
            } else {
                statusBadge = `<span class="badge badge-secondary">${r.status || 'ERR'}</span>`;
            }
            
            // UI検査結果
            const uiStatus = r.uiCheck ? 
                `<span class="badge ${r.uiCheck.errors > 0 ? 'badge-error' : 'badge-success'}">検査済</span>` :
                '<span class="badge badge-secondary">未検査</span>';
            
            tr.innerHTML = `
                <td>
                    <div style="font-weight:600;">${r.name}</div>
                    <div style="font-size:12px; color:#666; font-family:monospace;">${r.url}</div>
                </td>
                <td>${statusBadge}</td>
                <td>${r.time}ms</td>
                <td>${uiStatus}</td>
                <td>${r.warnings > 0 ? `<span class="badge badge-warning">${r.warnings}</span>` : '-'}</td>
                <td>${r.errors > 0 ? `<span class="badge badge-error">${r.errors}</span>` : '-'}</td>
                <td>
                    <button class="btn btn-secondary" style="padding:6px 12px; font-size:12px;" onclick="openInFrame('${r.url}')">
                        🔍 プレビュー
                    </button>
                    <a href="${baseUrl}${r.url}" target="_blank" class="btn btn-secondary" style="padding:6px 12px; font-size:12px; text-decoration:none;">
                        ↗️ 開く
                    </a>
                </td>
            `;
            table.appendChild(tr);
        });
    }
    
    /**
     * カテゴリーフィルター
     */
    function filterCategory(category) {
        currentFilter = category;
        
        document.querySelectorAll('.category-tab').forEach(tab => {
            tab.classList.toggle('active', tab.dataset.category === category);
        });
        
        renderResults();
    }
    
    /**
     * フレームで開く
     */
    function openInFrame(url) {
        document.getElementById('frameContainer').classList.add('active');
        const frame = document.getElementById('inspectionFrame');
        frame.src = baseUrl + url;
        
        // フレーム読み込み完了時に検査を試みる
        frame.onload = function() {
            try {
                // 同一オリジンの場合のみ検査可能
                if (frame.contentWindow && frame.contentWindow.PageInspector) {
                    const report = frame.contentWindow.PageInspector.inspect();
                    showInspectionResult(url, report);
                }
            } catch (e) {
                console.log('クロスオリジン制約のため自動検査不可');
            }
        };
    }
    
    /**
     * 検査結果を表示
     */
    function showInspectionResult(url, report) {
        if (!report) return;
        
        // 結果をモーダルで表示
        const modal = document.getElementById('detailModal');
        const title = document.getElementById('modalTitle');
        const body = document.getElementById('modalBody');
        
        title.textContent = `UI検査結果: ${url}`;
        
        let html = `
            <div style="margin-bottom:20px;">
                <div style="display:flex; gap:20px; margin-bottom:16px;">
                    <div style="text-align:center; padding:16px; background:#fee2e2; border-radius:8px; flex:1;">
                        <div style="font-size:24px; font-weight:bold; color:#dc2626;">${report.summary.errors}</div>
                        <div style="font-size:12px; color:#7f1d1d;">エラー</div>
                    </div>
                    <div style="text-align:center; padding:16px; background:#fef3c7; border-radius:8px; flex:1;">
                        <div style="font-size:24px; font-weight:bold; color:#d97706;">${report.summary.warnings}</div>
                        <div style="font-size:12px; color:#92400e;">警告</div>
                    </div>
                </div>
            </div>
        `;
        
        // エラー一覧
        if (report.errors && report.errors.length > 0) {
            html += '<h4 style="color:#dc2626; margin-bottom:12px;">❌ エラー</h4><ul style="margin-bottom:20px;">';
            report.errors.forEach(e => {
                html += `<li style="margin-bottom:8px;">${escapeHtml(e.message)}</li>`;
            });
            html += '</ul>';
        }
        
        // 警告一覧
        if (report.warnings && report.warnings.length > 0) {
            html += '<h4 style="color:#d97706; margin-bottom:12px;">⚠️ 警告</h4><ul style="margin-bottom:20px;">';
            report.warnings.forEach(w => {
                html += `<li style="margin-bottom:8px;">${escapeHtml(w.message)}</li>`;
            });
            html += '</ul>';
        }
        
        // カテゴリ別詳細
        const categories = [
            { key: 'visibility', name: '視覚的問題', icon: '👁️' },
            { key: 'layout', name: 'レイアウト', icon: '📐' },
            { key: 'functionality', name: '機能性', icon: '⚙️' },
            { key: 'accessibility', name: 'アクセシビリティ', icon: '♿' },
            { key: 'performance', name: 'パフォーマンス', icon: '⚡' }
        ];
        
        categories.forEach(cat => {
            const items = report.details[cat.key] || [];
            if (items.length === 0) return;
            
            html += `<h4 style="margin-bottom:12px;">${cat.icon} ${cat.name} (${items.length}件)</h4>`;
            html += '<div style="background:#f9fafb; border-radius:8px; padding:12px; margin-bottom:16px;">';
            
            items.slice(0, 10).forEach(item => {
                const color = item.severity === 'error' ? '#dc2626' : 
                             item.severity === 'warning' ? '#d97706' : '#6b7280';
                html += `
                    <div style="padding:8px 0; border-bottom:1px solid #e5e7eb;">
                        <div style="font-weight:500; color:${color};">${escapeHtml(item.title)}</div>
                        <div style="font-size:12px; color:#666; font-family:monospace;">
                            ${Object.entries(item.data || {}).map(([k, v]) => `${k}: ${escapeHtml(String(v))}`).join(', ')}
                        </div>
                    </div>
                `;
            });
            
            if (items.length > 10) {
                html += `<div style="padding:8px 0; color:#666;">...他 ${items.length - 10}件</div>`;
            }
            
            html += '</div>';
        });
        
        body.innerHTML = html;
        modal.classList.add('active');
    }
    
    /**
     * HTMLエスケープ
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * フレームを閉じる
     */
    function closeFrame() {
        document.getElementById('frameContainer').classList.remove('active');
        document.getElementById('inspectionFrame').src = 'about:blank';
    }
    
    /**
     * 詳細UI検査を実行
     */
    async function runDetailedInspection() {
        if (isRunning) return;
        
        const confirmed = confirm(
            '各ページを新しいウィンドウで開いて詳細検査を行います。\\n' +
            'ポップアップブロックを解除してください。\\n\\n' +
            '続行しますか？'
        );
        if (!confirmed) return;
        
        isRunning = true;
        const inspectionResults = [];
        
        for (let i = 0; i < Math.min(results.length, 5); i++) {
            const page = results[i];
            if (page.status !== 200) continue;
            
            try {
                // 新しいウィンドウで開く
                const popup = window.open(baseUrl + page.url, '_blank', 'width=1200,height=800');
                
                if (!popup) {
                    alert('ポップアップがブロックされました。ブラウザ設定を確認してください。');
                    break;
                }
                
                // ページ読み込みを待つ
                await new Promise(resolve => setTimeout(resolve, 3000));
                
                // 検査を実行
                if (popup.PageInspector) {
                    const report = popup.PageInspector.inspect();
                    inspectionResults.push({
                        page: page.name,
                        url: page.url,
                        ...report.summary
                    });
                }
                
                popup.close();
                
            } catch (e) {
                console.error('検査エラー:', e);
            }
        }
        
        isRunning = false;
        
        if (inspectionResults.length > 0) {
            console.table(inspectionResults);
            alert(`${inspectionResults.length}ページの詳細検査が完了しました。\\n結果はコンソールを確認してください。`);
        }
    }
    
    /**
     * レポート出力
     */
    function exportReport() {
        const report = {
            generated: new Date().toISOString(),
            summary: {
                total: results.length,
                ok: results.filter(r => r.ok || r.status === 200).length,
                warnings: results.filter(r => r.warnings > 0).length,
                errors: results.filter(r => !r.ok && r.status !== 200).length
            },
            results: results
        };
        
        const blob = new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `page-check-report-${new Date().toISOString().split('T')[0]}.json`;
        a.click();
        URL.revokeObjectURL(url);
    }
    
    /**
     * モーダルを閉じる
     */
    function closeModal() {
        document.getElementById('detailModal').classList.remove('active');
    }
    
    /**
     * すべてのエラーを解決済みにする
     */
    async function resolveAllErrors() {
        if (!confirm('すべてのエラーを解決済みにしますか？')) return;
        
        try {
            const response = await fetch('../api/error-log.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'resolve_all' })
            });
            const result = await response.json();
            if (result.success) {
                alert(`${result.resolved}件のエラーを解決済みにしました`);
                location.reload();
            }
        } catch (e) {
            alert('エラーが発生しました');
        }
    }
    
    /**
     * フレーム内でUI検査を実行
     */
    function runFrameInspection() {
        const frame = document.getElementById('inspectionFrame');
        const btn = document.getElementById('inspectBtn');
        
        btn.disabled = true;
        btn.textContent = '検査中...';
        
        try {
            if (frame.contentWindow && frame.contentWindow.PageInspector) {
                const report = frame.contentWindow.PageInspector.inspect();
                const url = new URL(frame.src).pathname;
                showInspectionResult(url, report);
                btn.textContent = '✅ 検査完了';
            } else {
                // PageInspectorがない場合、注入を試みる
                alert('このページではPageInspectorが利用できません。\\n\\n手動で検査するには：\\n1. 「↗️ 開く」でページを開く\\n2. F12でコンソールを開く\\n3. PageInspector.inspect() を実行');
                btn.textContent = '🔍 UI検査実行';
            }
        } catch (e) {
            alert('検査中にエラーが発生しました。\\n\\nセキュリティ制約により、一部のページは自動検査できません。\\n手動でF12コンソールから検査してください。');
            btn.textContent = '🔍 UI検査実行';
            console.error('検査エラー:', e);
        }
        
        btn.disabled = false;
    }
    
    // モーダル外クリックで閉じる
    document.getElementById('detailModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
    
    /**
     * 全ページ選択/解除トグル
     */
    function toggleAllPages() {
        const checkboxes = document.querySelectorAll('#pageOptions input[type="checkbox"]');
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
        
        checkboxes.forEach(cb => {
            cb.checked = !allChecked;
            cb.closest('.option-checkbox').classList.toggle('active', !allChecked);
        });
        
        updateCombinationCount();
    }
    
    // ========================================
    // マルチビュー検査機能
    // ========================================
    
    let multiViewResults = [];
    
    // オプションチェックボックスのイベント設定
    document.querySelectorAll('.option-checkbox').forEach(label => {
        label.addEventListener('click', function(e) {
            if (e.target.tagName === 'INPUT') return;
            const checkbox = this.querySelector('input');
            checkbox.checked = !checkbox.checked;
            this.classList.toggle('active', checkbox.checked);
            updateCombinationCount();
        });
    });
    
    /**
     * 組み合わせ数を更新
     */
    function updateCombinationCount() {
        const viewports = document.querySelectorAll('#viewportOptions input:checked').length;
        const themes = document.querySelectorAll('#themeOptions input:checked').length;
        const pages = document.querySelectorAll('#pageOptions input:checked').length;
        const count = viewports * themes * pages;
        document.getElementById('combinationCount').textContent = count;
    }
    
    /**
     * マルチビュー検査を開始（オプション表示）
     */
    function startMultiViewInspection() {
        document.getElementById('optionsCard').style.display = 'block';
        document.getElementById('introCard').style.display = 'none';
        updateCombinationCount();
        
        // スムーズスクロール
        document.getElementById('optionsCard').scrollIntoView({ behavior: 'smooth' });
    }
    
    /**
     * マルチビュー検査実行
     */
    async function runMultiViewInspection() {
        if (isRunning) return;
        
        // 選択されたオプションを取得
        const viewports = Array.from(document.querySelectorAll('#viewportOptions input:checked'))
            .map(input => {
                const [w, h] = input.value.split('x').map(Number);
                return { width: w, height: h, label: input.parentElement.textContent.trim() };
            });
        
        const themes = Array.from(document.querySelectorAll('#themeOptions input:checked'))
            .map(input => ({ value: input.value, label: input.parentElement.textContent.trim() }));
        
        const selectedPages = Array.from(document.querySelectorAll('#pageOptions input:checked'))
            .map(input => input.value);
        
        if (viewports.length === 0 || themes.length === 0 || selectedPages.length === 0) {
            alert('デバイス、テーマ、ページをそれぞれ1つ以上選択してください');
            return;
        }
        
        const totalCombinations = viewports.length * themes.length * selectedPages.length;
        
        if (!confirm(`${totalCombinations}通りの組み合わせで検査を実行します。\\n\\nこの処理には時間がかかる場合があります。続行しますか？`)) {
            return;
        }
        
        isRunning = true;
        multiViewResults = [];
        
        // UI更新
        document.getElementById('optionsCard').style.display = 'none';
        document.getElementById('progressContainer').classList.add('active');
        document.getElementById('multiViewResults').style.display = 'block';
        
        const progressBar = document.getElementById('progressBar');
        const progressLabel = document.getElementById('progressLabel');
        const progressPercent = document.getElementById('progressPercent');
        const currentPage = document.getElementById('currentPage');
        
        let completed = 0;
        
        for (const viewport of viewports) {
            for (const theme of themes) {
                for (const pageName of selectedPages) {
                    completed++;
                    const progress = (completed / totalCombinations) * 100;
                    
                    // 進捗更新
                    progressBar.style.width = progress + '%';
                    progressPercent.textContent = Math.round(progress) + '%';
                    progressLabel.textContent = `検査中... (${completed}/${totalCombinations})`;
                    currentPage.textContent = `${viewport.label} / ${theme.label} / ${pageName}`;
                    
                    // 検査実行
                    const result = await inspectWithViewportAndTheme(pageName, viewport, theme);
                    multiViewResults.push(result);
                    
                    // 結果をリアルタイム表示
                    updateMultiViewResultsUI();
                }
            }
        }
        
        // 完了
        document.getElementById('progressContainer').classList.remove('active');
        isRunning = false;
        
        alert(`検査完了！\\n${totalCombinations}通りの組み合わせを検査しました。`);
    }
    
    /**
     * 特定のビューポートとテーマで検査
     */
    async function inspectWithViewportAndTheme(pageName, viewport, theme) {
        const result = {
            page: pageName,
            viewport: viewport,
            theme: theme,
            timestamp: new Date().toISOString(),
            status: 'unknown',
            errors: 0,
            warnings: 0,
            details: null
        };
        
        try {
            // URLにテーマパラメータを追加
            const pageUrl = `${baseUrl}<?= $basePath ?>/${pageName}?_theme=${theme.value}&_viewport=${viewport.width}x${viewport.height}`;
            
            // ポップアップで開く（サイズ指定）
            const popup = window.open(
                pageUrl, 
                '_blank', 
                `width=${viewport.width},height=${viewport.height},menubar=no,toolbar=no,location=no,status=no`
            );
            
            if (!popup) {
                result.status = 'blocked';
                result.errors = 1;
                return result;
            }
            
            // ページ読み込みを待つ
            await new Promise(resolve => setTimeout(resolve, 2500));
            
            // テーマを適用（ページ内のJavaScriptで）
            try {
                if (popup.document && popup.document.body) {
                    popup.document.body.dataset.theme = theme.value;
                    
                    // 透明テーマの場合は追加のクラスを設定
                    if (theme.value.includes('transparent')) {
                        popup.document.body.classList.add('transparent-theme');
                    }
                }
            } catch (e) {
                console.log('テーマ適用エラー:', e);
            }
            
            // 少し待ってからスタイルを適用
            await new Promise(resolve => setTimeout(resolve, 500));
            
            // PageInspectorで検査
            if (popup.PageInspector) {
                const report = popup.PageInspector.inspect();
                result.status = 'inspected';
                result.errors = report.summary?.errors || 0;
                result.warnings = report.summary?.warnings || 0;
                result.details = report;
            } else {
                result.status = 'no-inspector';
            }
            
            popup.close();
            
        } catch (e) {
            console.error('検査エラー:', e);
            result.status = 'error';
            result.errors = 1;
        }
        
        return result;
    }
    
    /**
     * マルチビュー結果UIを更新
     */
    function updateMultiViewResultsUI() {
        const container = document.getElementById('multiViewResultsBody');
        
        // ビューポートごとにグループ化
        const grouped = {};
        multiViewResults.forEach(r => {
            const key = `${r.viewport.label}_${r.theme.label}`;
            if (!grouped[key]) {
                grouped[key] = {
                    viewport: r.viewport,
                    theme: r.theme,
                    results: []
                };
            }
            grouped[key].results.push(r);
        });
        
        let html = '';
        
        Object.entries(grouped).forEach(([key, group], index) => {
            const totalErrors = group.results.reduce((sum, r) => sum + r.errors, 0);
            const totalWarnings = group.results.reduce((sum, r) => sum + r.warnings, 0);
            const hasIssues = totalErrors > 0 || totalWarnings > 0;
            
            html += `
                <div class="view-result-card ${index === 0 ? 'expanded' : ''}" data-key="${key}">
                    <div class="view-result-header" onclick="toggleViewResult('${key}')">
                        <div class="view-info">
                            <span class="view-icon">${group.viewport.width < 500 ? '📱' : group.viewport.width < 1000 ? '📱' : '💻'}</span>
                            <div class="view-details">
                                <h4>${group.viewport.label} + ${group.theme.label}</h4>
                                <span>${group.viewport.width}x${group.viewport.height}px</span>
                            </div>
                        </div>
                        <div class="view-summary">
                            ${totalErrors > 0 ? `<span class="stat error">❌ ${totalErrors}</span>` : ''}
                            ${totalWarnings > 0 ? `<span class="stat warn">⚠️ ${totalWarnings}</span>` : ''}
                            ${!hasIssues ? `<span class="stat ok">✅ OK</span>` : ''}
                        </div>
                    </div>
                    <div class="view-result-body">
                        <table style="width:100%; border-collapse:collapse;">
                            <tr style="background:#f8f9fa;">
                                <th style="padding:8px; text-align:left;">ページ</th>
                                <th style="padding:8px; text-align:center;">ステータス</th>
                                <th style="padding:8px; text-align:center;">エラー</th>
                                <th style="padding:8px; text-align:center;">警告</th>
                            </tr>
                            ${group.results.map(r => `
                                <tr>
                                    <td style="padding:8px;">${r.page}</td>
                                    <td style="padding:8px; text-align:center;">
                                        <span class="badge ${r.status === 'inspected' ? 'badge-success' : 'badge-warning'}">${r.status}</span>
                                    </td>
                                    <td style="padding:8px; text-align:center;">
                                        ${r.errors > 0 ? `<span class="badge badge-error">${r.errors}</span>` : '-'}
                                    </td>
                                    <td style="padding:8px; text-align:center;">
                                        ${r.warnings > 0 ? `<span class="badge badge-warning">${r.warnings}</span>` : '-'}
                                    </td>
                                </tr>
                            `).join('')}
                        </table>
                        
                        ${group.results.some(r => r.details) ? `
                            <div style="margin-top:16px;">
                                <button class="btn btn-secondary" onclick="showDetailedReport('${key}')">
                                    📋 詳細レポート
                                </button>
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html || '<p style="text-align:center; color:#666;">検査中...</p>';
    }
    
    /**
     * ビュー結果の展開/折りたたみ
     */
    function toggleViewResult(key) {
        const card = document.querySelector(`.view-result-card[data-key="${key}"]`);
        if (card) {
            card.classList.toggle('expanded');
        }
    }
    
    /**
     * 詳細レポートを表示
     */
    function showDetailedReport(key) {
        const grouped = {};
        multiViewResults.forEach(r => {
            const k = `${r.viewport.label}_${r.theme.label}`;
            if (!grouped[k]) grouped[k] = { results: [] };
            grouped[k].results.push(r);
        });
        
        const group = grouped[key];
        if (!group) return;
        
        // モーダルで詳細表示
        const modal = document.getElementById('detailModal');
        const title = document.getElementById('modalTitle');
        const body = document.getElementById('modalBody');
        
        title.textContent = `詳細レポート: ${key.replace('_', ' / ')}`;
        
        let html = '';
        
        group.results.forEach(r => {
            if (!r.details) return;
            
            html += `<h3 style="margin:20px 0 12px 0; padding-top:20px; border-top:1px solid #e5e7eb;">${r.page}</h3>`;
            
            // エラー
            if (r.details.errors && r.details.errors.length > 0) {
                html += '<h4 style="color:#dc2626;">❌ エラー</h4><ul>';
                r.details.errors.forEach(e => {
                    html += `<li>${escapeHtml(e.message)}</li>`;
                });
                html += '</ul>';
            }
            
            // 警告
            if (r.details.warnings && r.details.warnings.length > 0) {
                html += '<h4 style="color:#d97706;">⚠️ 警告</h4><ul>';
                r.details.warnings.forEach(w => {
                    html += `<li>${escapeHtml(w.message)}</li>`;
                });
                html += '</ul>';
            }
            
            // カテゴリ別詳細
            if (r.details.details) {
                const allDetails = Object.entries(r.details.details).filter(([k, v]) => v && v.length > 0);
                if (allDetails.length > 0) {
                    html += '<div style="background:#f9fafb; padding:12px; border-radius:8px; margin-top:12px;">';
                    allDetails.forEach(([cat, items]) => {
                        const errorItems = items.filter(i => i.severity === 'error');
                        const warnItems = items.filter(i => i.severity === 'warning');
                        if (errorItems.length > 0 || warnItems.length > 0) {
                            html += `<div style="margin-bottom:8px;"><strong>${cat}:</strong> `;
                            if (errorItems.length > 0) html += `<span style="color:#dc2626;">${errorItems.length}エラー</span> `;
                            if (warnItems.length > 0) html += `<span style="color:#d97706;">${warnItems.length}警告</span>`;
                            html += '</div>';
                        }
                    });
                    html += '</div>';
                }
            }
        });
        
        body.innerHTML = html || '<p>詳細データがありません</p>';
        modal.classList.add('active');
    }
    
    /**
     * マルチビューレポート出力
     */
    function exportMultiViewReport() {
        const report = {
            generated: new Date().toISOString(),
            type: 'multi-view-inspection',
            summary: {
                totalCombinations: multiViewResults.length,
                withErrors: multiViewResults.filter(r => r.errors > 0).length,
                withWarnings: multiViewResults.filter(r => r.warnings > 0).length
            },
            results: multiViewResults
        };
        
        const blob = new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `multi-view-report-${new Date().toISOString().split('T')[0]}.json`;
        a.click();
        URL.revokeObjectURL(url);
    }
    </script>
</body>
</html>
