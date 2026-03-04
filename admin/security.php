<?php
/**
 * セキュリティ管理ダッシュボード
 * 
 * 侵入者の検出、詳細情報の表示、IPブロック管理
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/security.php';

$currentPage = 'security';
require_once __DIR__ . '/_sidebar.php';

// 管理者チェック（developer, admin, system_admin, super_admin）
$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['developer', 'admin', 'system_admin', 'super_admin'])) {
    header('Location: ../index.php');
    exit;
}

$security = getSecurity();
$summary = $security->getSummary(24);
$currentLang = $_SESSION['lang'] ?? 'ja';
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>セキュリティ監視 - Social9</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        <?php adminSidebarCSS(); ?>
        .security-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .security-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .security-title {
            font-size: 28px;
            font-weight: bold;
            color: #1f2937;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        
        .btn-secondary {
            background: white;
            border: 1px solid #e5e7eb;
            color: #374151;
        }
        
        /* ステータスカード */
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .status-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
        }
        
        .status-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }
        
        .status-card.critical::before { background: #ef4444; }
        .status-card.high::before { background: #f97316; }
        .status-card.medium::before { background: #f59e0b; }
        .status-card.low::before { background: #22c55e; }
        .status-card.info::before { background: #3b82f6; }
        
        .status-card-title {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-card-value {
            font-size: 32px;
            font-weight: bold;
            color: #1f2937;
        }
        
        .status-card.critical .status-card-value { color: #ef4444; }
        .status-card.high .status-card-value { color: #f97316; }
        
        /* タブ */
        .tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 10px;
        }
        
        .tab {
            padding: 10px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            color: #6b7280;
            border-radius: 8px 8px 0 0;
        }
        
        .tab.active {
            background: #3b82f6;
            color: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* セクション */
        .section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .section-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 20px;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* テーブル */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .data-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            font-size: 13px;
        }
        
        .data-table tr:hover {
            background: #f9fafb;
        }
        
        /* バッジ */
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge.critical { background: #fee2e2; color: #dc2626; }
        .badge.high { background: #ffedd5; color: #ea580c; }
        .badge.medium { background: #fef3c7; color: #d97706; }
        .badge.low { background: #dcfce7; color: #16a34a; }
        
        .badge.js { background: #fef3c7; color: #92400e; }
        .badge.api { background: #dbeafe; color: #1e40af; }
        
        /* イベントタイプ */
        .event-type {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }
        
        .event-type-icon {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .event-type-icon.login_failed { background: #f59e0b; }
        .event-type-icon.brute_force { background: #ef4444; }
        .event-type-icon.sql_injection { background: #dc2626; }
        .event-type-icon.xss_attempt { background: #ea580c; }
        .event-type-icon.session_hijack { background: #dc2626; }
        .event-type-icon.unauthorized_access { background: #f97316; }
        .event-type-icon.ip_blocked { background: #6b7280; }
        .event-type-icon.login_success { background: #22c55e; }
        
        /* IP情報 */
        .ip-cell {
            font-family: monospace;
            font-size: 13px;
        }
        
        .ip-actions {
            display: flex;
            gap: 5px;
        }
        
        .ip-action-btn {
            padding: 4px 8px;
            font-size: 11px;
            border-radius: 4px;
            cursor: pointer;
            border: none;
        }
        
        .ip-action-btn.block {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .ip-action-btn.lookup {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .ip-action-btn.view {
            background: #f3f4f6;
            color: #374151;
        }
        
        /* UA情報 */
        .ua-info {
            font-size: 12px;
            color: #6b7280;
        }
        
        .ua-suspicious {
            color: #dc2626;
            font-weight: 500;
        }
        
        /* 詳細モーダル */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            max-width: 900px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            font-size: 18px;
            font-weight: bold;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #6b7280;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        /* 詳細情報グリッド */
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        .detail-section {
            background: #f9fafb;
            border-radius: 8px;
            padding: 15px;
        }
        
        .detail-section-title {
            font-weight: 600;
            color: #374151;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #e5e7eb;
            font-size: 13px;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            color: #6b7280;
        }
        
        .detail-value {
            color: #1f2937;
            font-weight: 500;
            text-align: right;
            word-break: break-all;
            max-width: 60%;
        }
        
        /* JSONビューア */
        .json-viewer {
            background: #1f2937;
            color: #e5e7eb;
            padding: 15px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 12px;
            overflow-x: auto;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
        }
        
        /* フィルター */
        .filters {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-label {
            font-size: 12px;
            color: #6b7280;
        }
        
        .filter-select,
        .filter-input {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 13px;
        }
        
        /* ページネーション */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }
        
        .pagination-btn {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            background: white;
            border-radius: 6px;
            cursor: pointer;
        }
        
        .pagination-btn.active {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }
        
        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* ブロックIP */
        .blocked-ip-card {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #fef2f2;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .blocked-ip-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .blocked-ip {
            font-family: monospace;
            font-weight: 600;
            color: #dc2626;
        }
        
        .blocked-reason {
            font-size: 13px;
            color: #6b7280;
        }
        
        .blocked-meta {
            font-size: 12px;
            color: #9ca3af;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #9ca3af;
        }
        
        /* レスポンシブ */
        @media (max-width: 768px) {
            .status-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
            
            .data-table {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php adminSidebarHTML($currentPage); ?>
        <main class="main-content">
            <div class="security-container">
        
        <div class="security-header">
            <h1 class="security-title">🛡️ セキュリティ監視</h1>
            <div class="header-actions">
                <button class="btn btn-secondary" onclick="location.reload()">更新</button>
                <button class="btn btn-primary" onclick="openBlockIPModal()">IPをブロック</button>
            </div>
        </div>
        
        <!-- ステータスカード -->
        <div class="status-grid">
            <div class="status-card <?= $summary['critical'] > 0 ? 'critical' : 'low' ?>">
                <div class="status-card-title">重大イベント (24h)</div>
                <div class="status-card-value"><?= $summary['critical'] ?></div>
            </div>
            <div class="status-card <?= $summary['high'] > 0 ? 'high' : 'low' ?>">
                <div class="status-card-title">高リスク (24h)</div>
                <div class="status-card-value"><?= $summary['high'] ?></div>
            </div>
            <div class="status-card medium">
                <div class="status-card-title">中リスク (24h)</div>
                <div class="status-card-value"><?= $summary['medium'] ?></div>
            </div>
            <div class="status-card info">
                <div class="status-card-title">ブロック中のIP</div>
                <div class="status-card-value"><?= $summary['blocked_ips'] ?></div>
            </div>
            <div class="status-card <?= $summary['login_failures'] > 20 ? 'high' : 'info' ?>">
                <div class="status-card-title">ログイン失敗 (24h)</div>
                <div class="status-card-value"><?= $summary['login_failures'] ?></div>
            </div>
        </div>
        
        <!-- タブ -->
        <div class="tabs">
            <button class="tab active" onclick="showTab('logs')">セキュリティログ</button>
            <button class="tab" onclick="showTab('blocked')">ブロックIP</button>
            <button class="tab" onclick="showTab('logins')">ログイン履歴</button>
            <button class="tab" onclick="showTab('settings')">設定</button>
        </div>
        
        <!-- セキュリティログタブ -->
        <div id="tab-logs" class="tab-content active">
            <div class="section">
                <div class="filters">
                    <div class="filter-group">
                        <span class="filter-label">イベントタイプ</span>
                        <select class="filter-select" id="filter-event-type" onchange="loadLogs()">
                            <option value="">全て</option>
                            <option value="login_failed">ログイン失敗</option>
                            <option value="brute_force">ブルートフォース</option>
                            <option value="sql_injection">SQLインジェクション</option>
                            <option value="xss_attempt">XSS攻撃</option>
                            <option value="session_hijack">セッションハイジャック</option>
                            <option value="unauthorized_access">不正アクセス</option>
                            <option value="ip_blocked">IPブロック</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <span class="filter-label">重要度</span>
                        <select class="filter-select" id="filter-severity" onchange="loadLogs()">
                            <option value="">全て</option>
                            <option value="critical">重大</option>
                            <option value="high">高</option>
                            <option value="medium">中</option>
                            <option value="low">低</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <span class="filter-label">IPアドレス</span>
                        <input type="text" class="filter-input" id="filter-ip" placeholder="IPで検索" onkeyup="debounce(loadLogs, 500)()">
                    </div>
                </div>
                
                <table class="data-table" id="logs-table">
                    <thead>
                        <tr>
                            <th>日時</th>
                            <th>イベント</th>
                            <th>重要度</th>
                            <th>IPアドレス</th>
                            <th>ブラウザ/OS</th>
                            <th>対象</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="logs-body">
                        <tr><td colspan="7" class="no-data">読み込み中...</td></tr>
                    </tbody>
                </table>
                
                <div class="pagination" id="logs-pagination"></div>
            </div>
        </div>
        
        <!-- ブロックIPタブ -->
        <div id="tab-blocked" class="tab-content">
            <div class="section">
                <div class="section-title">ブロック中のIPアドレス</div>
                <div id="blocked-ips-list">
                    <div class="no-data">読み込み中...</div>
                </div>
            </div>
        </div>
        
        <!-- ログイン履歴タブ -->
        <div id="tab-logins" class="tab-content">
            <div class="section">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>日時</th>
                            <th>ユーザー名</th>
                            <th>結果</th>
                            <th>IPアドレス</th>
                            <th>理由</th>
                        </tr>
                    </thead>
                    <tbody id="logins-body">
                        <tr><td colspan="5" class="no-data">読み込み中...</td></tr>
                    </tbody>
                </table>
                <div class="pagination" id="logins-pagination"></div>
            </div>
        </div>
        
        <!-- 設定タブ -->
        <div id="tab-settings" class="tab-content">
            <div class="section">
                <div class="section-title">セキュリティ設定</div>
                <div id="settings-form">
                    <div class="no-data">読み込み中...</div>
                </div>
            </div>
        </div>
            </div>
        </main>
    </div>
    
    <!-- 詳細モーダル -->
    <div class="modal" id="detail-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">イベント詳細</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="detail-content">
                読み込み中...
            </div>
        </div>
    </div>
    
    <!-- IPブロックモーダル -->
    <div class="modal" id="block-ip-modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3 class="modal-title">IPをブロック</h3>
                <button class="modal-close" onclick="closeBlockIPModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">IPアドレス</label>
                    <input type="text" id="block-ip-address" class="filter-input" style="width: 100%;" placeholder="例: 192.168.1.1">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">理由</label>
                    <input type="text" id="block-ip-reason" class="filter-input" style="width: 100%;" placeholder="ブロック理由">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="block-ip-permanent">
                        永久ブロック
                    </label>
                </div>
                <div id="block-ip-duration-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">ブロック期間（分）</label>
                    <input type="number" id="block-ip-duration" class="filter-input" style="width: 100%;" value="60" min="1">
                </div>
                <button class="btn btn-danger" style="width: 100%;" onclick="blockIP()">ブロックする</button>
            </div>
        </div>
    </div>
    
    <script>
        let currentLogsPage = 1;
        let currentLoginsPage = 1;
        
        // 初期化
        document.addEventListener('DOMContentLoaded', function() {
            loadLogs();
            loadBlockedIPs();
            loadLogins();
            loadSettings();
        });
        
        // タブ切り替え
        function showTab(tabId) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            event.target.classList.add('active');
            document.getElementById('tab-' + tabId).classList.add('active');
        }
        
        // デバウンス
        function debounce(func, wait) {
            let timeout;
            return function() {
                clearTimeout(timeout);
                timeout = setTimeout(func, wait);
            };
        }
        
        // ログ読み込み
        async function loadLogs(page = 1) {
            currentLogsPage = page;
            const eventType = document.getElementById('filter-event-type').value;
            const severity = document.getElementById('filter-severity').value;
            const ip = document.getElementById('filter-ip').value;
            
            const params = new URLSearchParams({
                action: 'logs',
                page: page,
                event_type: eventType,
                severity: severity,
                ip: ip
            });
            
            try {
                const res = await fetch('../api/security.php?' + params);
                const data = await res.json();
                
                if (!data.success) {
                    document.getElementById('logs-body').innerHTML = 
                        '<tr><td colspan="7" class="no-data">' + (data.error || 'エラー') + '</td></tr>';
                    return;
                }
                
                if (data.logs.length === 0) {
                    document.getElementById('logs-body').innerHTML = 
                        '<tr><td colspan="7" class="no-data">データがありません</td></tr>';
                    document.getElementById('logs-pagination').innerHTML = '';
                    return;
                }
                
                let html = '';
                data.logs.forEach(log => {
                    const ua = log.user_agent_parsed || {};
                    const uaText = ua.browser ? `${ua.browser} / ${ua.os}` : '-';
                    const suspiciousClass = ua.is_suspicious ? 'ua-suspicious' : '';
                    
                    html += `
                        <tr>
                            <td>${formatDate(log.created_at)}</td>
                            <td>
                                <span class="event-type">
                                    <span class="event-type-icon ${log.event_type}"></span>
                                    ${formatEventType(log.event_type)}
                                </span>
                            </td>
                            <td><span class="badge ${log.severity}">${log.severity.toUpperCase()}</span></td>
                            <td class="ip-cell">
                                ${log.ip_address}
                                <div class="ip-actions">
                                    <button class="ip-action-btn lookup" onclick="lookupIP('${log.ip_address}')">詳細</button>
                                    <button class="ip-action-btn block" onclick="quickBlockIP('${log.ip_address}')">ブロック</button>
                                </div>
                            </td>
                            <td class="ua-info ${suspiciousClass}">${uaText}${ua.is_suspicious ? ' ⚠️' : ''}</td>
                            <td>${log.target_username || log.target_resource || '-'}</td>
                            <td>
                                <button class="ip-action-btn view" onclick="showDetail(${log.id})">詳細</button>
                            </td>
                        </tr>
                    `;
                });
                
                document.getElementById('logs-body').innerHTML = html;
                
                // ページネーション
                renderPagination('logs-pagination', data.page, data.pages, 'loadLogs');
                
            } catch (e) {
                document.getElementById('logs-body').innerHTML = 
                    '<tr><td colspan="7" class="no-data">読み込みエラー</td></tr>';
            }
        }
        
        // ブロックIP読み込み
        async function loadBlockedIPs() {
            try {
                const res = await fetch('../api/security.php?action=blocked_ips');
                const data = await res.json();
                
                if (!data.success || data.blocked_ips.length === 0) {
                    document.getElementById('blocked-ips-list').innerHTML = 
                        '<div class="no-data">ブロック中のIPはありません</div>';
                    return;
                }
                
                let html = '';
                data.blocked_ips.forEach(ip => {
                    const expiry = ip.is_permanent == 1 ? '永久' : 
                        (ip.expires_at ? formatDate(ip.expires_at) + 'まで' : '-');
                    
                    html += `
                        <div class="blocked-ip-card">
                            <div class="blocked-ip-info">
                                <div class="blocked-ip">${ip.ip_address}</div>
                                <div class="blocked-reason">${ip.reason || '-'}</div>
                                <div class="blocked-meta">
                                    ${expiry} | 試行回数: ${ip.block_count}
                                </div>
                            </div>
                            <button class="btn btn-secondary" onclick="unblockIP('${ip.ip_address}')">解除</button>
                        </div>
                    `;
                });
                
                document.getElementById('blocked-ips-list').innerHTML = html;
                
            } catch (e) {
                document.getElementById('blocked-ips-list').innerHTML = 
                    '<div class="no-data">読み込みエラー</div>';
            }
        }
        
        // ログイン履歴読み込み
        async function loadLogins(page = 1) {
            currentLoginsPage = page;
            
            try {
                const res = await fetch('../api/security.php?action=login_attempts&page=' + page);
                const data = await res.json();
                
                if (!data.success || data.attempts.length === 0) {
                    document.getElementById('logins-body').innerHTML = 
                        '<tr><td colspan="5" class="no-data">データがありません</td></tr>';
                    return;
                }
                
                let html = '';
                data.attempts.forEach(a => {
                    const resultClass = a.success == 1 ? 'low' : 'high';
                    const resultText = a.success == 1 ? '成功' : '失敗';
                    
                    html += `
                        <tr>
                            <td>${formatDate(a.created_at)}</td>
                            <td>${a.username}</td>
                            <td><span class="badge ${resultClass}">${resultText}</span></td>
                            <td class="ip-cell">${a.ip_address}</td>
                            <td>${a.failure_reason || '-'}</td>
                        </tr>
                    `;
                });
                
                document.getElementById('logins-body').innerHTML = html;
                
            } catch (e) {
                document.getElementById('logins-body').innerHTML = 
                    '<tr><td colspan="5" class="no-data">読み込みエラー</td></tr>';
            }
        }
        
        // 設定読み込み
        async function loadSettings() {
            try {
                const res = await fetch('../api/security.php?action=settings');
                const data = await res.json();
                
                if (!data.success) {
                    document.getElementById('settings-form').innerHTML = 
                        '<div class="no-data">設定を読み込めません</div>';
                    return;
                }
                
                let html = '<div style="display: grid; gap: 15px; max-width: 600px;">';
                data.settings.forEach(s => {
                    const inputType = s.setting_value === 'true' || s.setting_value === 'false' ? 'checkbox' : 'text';
                    const checked = s.setting_value === 'true' ? 'checked' : '';
                    
                    if (inputType === 'checkbox') {
                        html += `
                            <label style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" ${checked} onchange="updateSetting('${s.setting_key}', this.checked ? 'true' : 'false')">
                                <span>${s.description || s.setting_key}</span>
                            </label>
                        `;
                    } else {
                        html += `
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: 500;">${s.description || s.setting_key}</label>
                                <input type="text" class="filter-input" style="width: 100%;" value="${s.setting_value}" 
                                    onchange="updateSetting('${s.setting_key}', this.value)">
                            </div>
                        `;
                    }
                });
                html += '</div>';
                
                document.getElementById('settings-form').innerHTML = html;
                
            } catch (e) {
                document.getElementById('settings-form').innerHTML = 
                    '<div class="no-data">読み込みエラー</div>';
            }
        }
        
        // 設定更新
        async function updateSetting(key, value) {
            try {
                const res = await fetch('../api/security.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=update_setting&key=${key}&value=${value}`
                });
                const data = await res.json();
                
                if (data.success) {
                    // 成功時は静かに更新
                } else {
                    alert('設定の更新に失敗しました');
                }
            } catch (e) {
                alert('エラー: ' + e.message);
            }
        }
        
        // 詳細表示
        async function showDetail(id) {
            document.getElementById('detail-modal').classList.add('active');
            document.getElementById('detail-content').innerHTML = '読み込み中...';
            
            try {
                const res = await fetch('../api/security.php?action=log_detail&id=' + id);
                const data = await res.json();
                
                if (!data.success) {
                    document.getElementById('detail-content').innerHTML = 'エラー: ' + data.error;
                    return;
                }
                
                const log = data.log;
                const ua = log.user_agent_parsed || {};
                
                let html = `
                    <div class="detail-grid">
                        <div class="detail-section">
                            <div class="detail-section-title">イベント情報</div>
                            <div class="detail-row"><span class="detail-label">種類</span><span class="detail-value">${formatEventType(log.event_type)}</span></div>
                            <div class="detail-row"><span class="detail-label">重要度</span><span class="detail-value"><span class="badge ${log.severity}">${log.severity}</span></span></div>
                            <div class="detail-row"><span class="detail-label">日時</span><span class="detail-value">${log.created_at}</span></div>
                            <div class="detail-row"><span class="detail-label">説明</span><span class="detail-value">${log.description || '-'}</span></div>
                        </div>
                        
                        <div class="detail-section">
                            <div class="detail-section-title">攻撃者情報</div>
                            <div class="detail-row"><span class="detail-label">IPアドレス</span><span class="detail-value">${log.ip_address}</span></div>
                            <div class="detail-row"><span class="detail-label">ブラウザ</span><span class="detail-value">${ua.browser || '-'} ${ua.browser_version || ''}</span></div>
                            <div class="detail-row"><span class="detail-label">OS</span><span class="detail-value">${ua.os || '-'} ${ua.os_version || ''}</span></div>
                            <div class="detail-row"><span class="detail-label">デバイス</span><span class="detail-value">${ua.device || '-'}</span></div>
                            <div class="detail-row"><span class="detail-label">ボット</span><span class="detail-value">${ua.is_bot ? 'はい' : 'いいえ'}</span></div>
                            <div class="detail-row"><span class="detail-label">不審</span><span class="detail-value">${ua.is_suspicious ? '⚠️ はい' : 'いいえ'}</span></div>
                        </div>
                        
                        <div class="detail-section">
                            <div class="detail-section-title">リクエスト情報</div>
                            <div class="detail-row"><span class="detail-label">メソッド</span><span class="detail-value">${log.request_method || '-'}</span></div>
                            <div class="detail-row"><span class="detail-label">URI</span><span class="detail-value">${log.request_uri || '-'}</span></div>
                            <div class="detail-row"><span class="detail-label">リファラー</span><span class="detail-value">${log.referer || '-'}</span></div>
                            <div class="detail-row"><span class="detail-label">フィンガープリント</span><span class="detail-value" style="font-size: 10px;">${log.fingerprint_hash || '-'}</span></div>
                        </div>
                        
                        <div class="detail-section">
                            <div class="detail-section-title">対象情報</div>
                            <div class="detail-row"><span class="detail-label">ユーザーID</span><span class="detail-value">${log.target_user_id || '-'}</span></div>
                            <div class="detail-row"><span class="detail-label">ユーザー名</span><span class="detail-value">${log.target_username || '-'}</span></div>
                            <div class="detail-row"><span class="detail-label">リソース</span><span class="detail-value">${log.target_resource || '-'}</span></div>
                        </div>
                    </div>
                `;
                
                // ユーザーエージェント全文
                if (log.user_agent) {
                    html += `
                        <div class="detail-section" style="margin-top: 20px;">
                            <div class="detail-section-title">User-Agent</div>
                            <div style="font-family: monospace; font-size: 12px; word-break: break-all;">${log.user_agent}</div>
                        </div>
                    `;
                }
                
                // リクエストヘッダー
                if (log.request_headers) {
                    html += `
                        <div class="detail-section" style="margin-top: 20px;">
                            <div class="detail-section-title">リクエストヘッダー</div>
                            <div class="json-viewer">${JSON.stringify(log.request_headers, null, 2)}</div>
                        </div>
                    `;
                }
                
                // 関連イベント
                if (data.related_by_ip && data.related_by_ip.length > 0) {
                    html += `
                        <div class="detail-section" style="margin-top: 20px;">
                            <div class="detail-section-title">同一IPからの他のイベント (${data.related_by_ip.length}件)</div>
                            <div style="max-height: 200px; overflow-y: auto;">
                    `;
                    data.related_by_ip.forEach(e => {
                        html += `<div class="detail-row"><span>${e.created_at}</span><span class="badge ${e.severity}">${e.event_type}</span></div>`;
                    });
                    html += '</div></div>';
                }
                
                // アクションボタン
                html += `
                    <div style="margin-top: 20px; display: flex; gap: 10px;">
                        <button class="btn btn-danger" onclick="quickBlockIP('${log.ip_address}'); closeModal();">このIPをブロック</button>
                        <button class="btn btn-primary" onclick="lookupIP('${log.ip_address}')">IP詳細を調査</button>
                    </div>
                `;
                
                document.getElementById('detail-content').innerHTML = html;
                
            } catch (e) {
                document.getElementById('detail-content').innerHTML = 'エラー: ' + e.message;
            }
        }
        
        // モーダルを閉じる
        function closeModal() {
            document.getElementById('detail-modal').classList.remove('active');
        }
        
        // IPブロックモーダル
        function openBlockIPModal() {
            document.getElementById('block-ip-modal').classList.add('active');
        }
        
        function closeBlockIPModal() {
            document.getElementById('block-ip-modal').classList.remove('active');
        }
        
        // クイックブロック
        function quickBlockIP(ip) {
            if (!confirm(`${ip} をブロックしますか？`)) return;
            
            document.getElementById('block-ip-address').value = ip;
            document.getElementById('block-ip-reason').value = 'セキュリティログから手動ブロック';
            document.getElementById('block-ip-permanent').checked = false;
            document.getElementById('block-ip-duration').value = 60;
            
            blockIP();
        }
        
        // IPブロック実行
        async function blockIP() {
            const ip = document.getElementById('block-ip-address').value;
            const reason = document.getElementById('block-ip-reason').value;
            const permanent = document.getElementById('block-ip-permanent').checked;
            const duration = document.getElementById('block-ip-duration').value;
            
            if (!ip) {
                alert('IPアドレスを入力してください');
                return;
            }
            
            try {
                const res = await fetch('../api/security.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=block_ip&ip=${ip}&reason=${encodeURIComponent(reason)}&permanent=${permanent ? 1 : 0}&duration=${duration}`
                });
                const data = await res.json();
                
                if (data.success) {
                    alert('IPをブロックしました');
                    closeBlockIPModal();
                    loadBlockedIPs();
                } else {
                    alert('ブロックに失敗: ' + data.error);
                }
            } catch (e) {
                alert('エラー: ' + e.message);
            }
        }
        
        // IPブロック解除
        async function unblockIP(ip) {
            if (!confirm(`${ip} のブロックを解除しますか？`)) return;
            
            try {
                const res = await fetch('../api/security.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=unblock_ip&ip=${ip}`
                });
                const data = await res.json();
                
                if (data.success) {
                    loadBlockedIPs();
                } else {
                    alert('解除に失敗: ' + data.error);
                }
            } catch (e) {
                alert('エラー: ' + e.message);
            }
        }
        
        // IP詳細調査
        async function lookupIP(ip) {
            try {
                const res = await fetch('../api/security.php?action=ip_lookup&ip=' + ip);
                const data = await res.json();
                
                if (data.success && data.info) {
                    const info = data.info;
                    let message = `IP: ${ip}\n\n`;
                    message += `国: ${info.country || '-'}\n`;
                    message += `地域: ${info.regionName || '-'}\n`;
                    message += `都市: ${info.city || '-'}\n`;
                    message += `ISP: ${info.isp || '-'}\n`;
                    message += `組織: ${info.org || '-'}\n`;
                    message += `AS: ${info.as || '-'}\n`;
                    message += `モバイル: ${info.mobile ? 'はい' : 'いいえ'}\n`;
                    message += `プロキシ/VPN: ${info.proxy ? 'はい' : 'いいえ'}\n`;
                    message += `ホスティング: ${info.hosting ? 'はい' : 'いいえ'}\n`;
                    
                    alert(message);
                } else {
                    alert('IP情報を取得できませんでした');
                }
            } catch (e) {
                alert('エラー: ' + e.message);
            }
        }
        
        // ページネーション描画
        function renderPagination(containerId, currentPage, totalPages, loadFunc) {
            if (totalPages <= 1) {
                document.getElementById(containerId).innerHTML = '';
                return;
            }
            
            let html = '';
            html += `<button class="pagination-btn" onclick="${loadFunc}(${currentPage - 1})" ${currentPage <= 1 ? 'disabled' : ''}>&lt;</button>`;
            
            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
                    html += `<button class="pagination-btn ${i === currentPage ? 'active' : ''}" onclick="${loadFunc}(${i})">${i}</button>`;
                } else if (i === currentPage - 3 || i === currentPage + 3) {
                    html += '<span style="padding: 0 5px;">...</span>';
                }
            }
            
            html += `<button class="pagination-btn" onclick="${loadFunc}(${currentPage + 1})" ${currentPage >= totalPages ? 'disabled' : ''}>&gt;</button>`;
            
            document.getElementById(containerId).innerHTML = html;
        }
        
        // ユーティリティ
        function formatDate(dateStr) {
            if (!dateStr) return '-';
            const d = new Date(dateStr);
            return d.toLocaleDateString('ja-JP') + ' ' + d.toLocaleTimeString('ja-JP', {hour: '2-digit', minute: '2-digit'});
        }
        
        function formatEventType(type) {
            const types = {
                'login_failed': 'ログイン失敗',
                'login_success': 'ログイン成功',
                'brute_force': 'ブルートフォース',
                'session_hijack': 'セッションハイジャック',
                'unauthorized_access': '不正アクセス',
                'sql_injection': 'SQLインジェクション',
                'xss_attempt': 'XSS攻撃',
                'csrf_violation': 'CSRF違反',
                'rate_limit': 'レート制限',
                'suspicious_activity': '不審な活動',
                'admin_access': '管理画面アクセス',
                'password_reset': 'パスワードリセット',
                'account_locked': 'アカウントロック',
                'ip_blocked': 'IPブロック',
                'file_upload_suspicious': '不審なアップロード',
                'api_abuse': 'API乱用'
            };
            return types[type] || type;
        }
        
        // 永久ブロックチェックボックス
        document.getElementById('block-ip-permanent').addEventListener('change', function() {
            document.getElementById('block-ip-duration-group').style.display = this.checked ? 'none' : 'block';
        });
    </script>
    <script src="../assets/js/admin-sidebar-sort.js"></script>
</body>
</html>
