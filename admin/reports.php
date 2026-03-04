<?php
/**
 * 管理パネル - 通報管理
 * 仕様書: 13_管理機能.md, 39_誹謗中傷対策システム.md
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

$currentPage = basename(__FILE__, '.php');
require_once __DIR__ . '/_sidebar.php';

requireLogin();
requireSystemAdmin();

$pdo = getDB();

// フィルター
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';

// reportsテーブルが存在するかチェック
$reportsTableExists = false;
$reports = [];
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'reports'");
    $reportsTableExists = $checkTable->rowCount() > 0;
} catch (PDOException $e) {}

if ($reportsTableExists) {
    // 通報一覧を取得
    $sql = "
        SELECT 
            r.*,
            reporter.display_name as reporter_name,
            reported.display_name as reported_name
        FROM reports r
        LEFT JOIN users reporter ON r.reporter_id = reporter.id
        LEFT JOIN users reported ON r.reported_user_id = reported.id
        WHERE 1=1
    ";
    $params = [];
    
    if ($status_filter) {
        $sql .= " AND r.status = ?";
        $params[] = $status_filter;
    }
    if ($type_filter) {
        $sql .= " AND r.report_type = ?";
        $params[] = $type_filter;
    }
    
    $sql .= " ORDER BY r.created_at DESC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();
}

// 通報処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reportsTableExists) {
    $action = $_POST['action'] ?? '';
    $report_id = (int)($_POST['report_id'] ?? 0);
    
    if ($report_id && in_array($action, ['resolve', 'dismiss', 'warn', 'ban'])) {
        $pdo->beginTransaction();
        try {
            // 通報ステータスを更新
            $new_status = in_array($action, ['resolve', 'warn', 'ban']) ? 'resolved' : 'dismissed';
            $stmt = $pdo->prepare("
                UPDATE reports SET 
                    status = ?,
                    resolved_by = ?,
                    resolved_at = NOW(),
                    resolution_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $new_status,
                $_SESSION['user_id'],
                $_POST['notes'] ?? '',
                $report_id
            ]);
            
            // BANの場合はユーザーを凍結
            if ($action === 'ban') {
                $stmt = $pdo->prepare("SELECT reported_user_id FROM reports WHERE id = ?");
                $stmt->execute([$report_id]);
                $report = $stmt->fetch();
                
                if ($report['reported_user_id']) {
                    $stmt = $pdo->prepare("UPDATE users SET role = 'banned' WHERE id = ?");
                    $stmt->execute([$report['reported_user_id']]);
                }
            }
            
            $pdo->commit();
            header('Location: reports.php?success=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
        }
    }
}

$success = isset($_GET['success']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>通報管理 - 管理パネル | Social9</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <style>
        body { background: var(--bg-secondary); font-family: 'Hiragino Sans', 'Meiryo', sans-serif; }
        <?php adminSidebarCSS(); ?>
        
        .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .page-header h2 { font-size: 24px; }
        
        .filters { display: flex; gap: 12px; margin-bottom: 20px; }
        .filters select { padding: 8px 16px; border: 1px solid var(--border-light); border-radius: 8px; }
        
        .card { background: white; border-radius: 12px; box-shadow: var(--shadow-sm); overflow: hidden; }
        
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 14px 16px; text-align: left; border-bottom: 1px solid var(--border-light); }
        .table th { background: var(--bg-secondary); font-weight: 600; font-size: 13px; color: var(--text-muted); }
        .table tr:hover { background: var(--bg-secondary); }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-badge.pending { background: #fef3c7; color: #92400e; }
        .status-badge.resolved { background: #dcfce7; color: #166534; }
        .status-badge.dismissed { background: #e5e7eb; color: #374151; }
        
        .type-badge { background: #e0e7ff; color: #3730a3; padding: 4px 8px; border-radius: 6px; font-size: 11px; }
        
        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            margin-right: 4px;
        }
        .action-btn.primary { background: var(--primary); color: white; }
        .action-btn.warning { background: #f59e0b; color: white; }
        .action-btn.danger { background: #ef4444; color: white; }
        .action-btn.secondary { background: var(--bg-secondary); color: var(--text-secondary); }
        
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .alert.success { background: #dcfce7; color: #166534; }
        
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state .icon { font-size: 48px; margin-bottom: 12px; }
        
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
        .modal { background: white; border-radius: 12px; padding: 24px; max-width: 500px; width: 90%; }
        .modal h3 { margin-bottom: 16px; }
        .modal textarea { width: 100%; padding: 12px; border: 1px solid var(--border-light); border-radius: 8px; margin-bottom: 16px; }
        .modal-actions { display: flex; gap: 12px; justify-content: flex-end; }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php adminSidebarHTML($currentPage); ?>
        
        <main class="main-content">
            <div class="page-header">
                <h2>🚨 通報管理</h2>
            </div>
            
            <?php if ($success): ?>
            <div class="alert success">通報を処理しました。</div>
            <?php endif; ?>
            
            <div class="filters">
                <select onchange="location.href='?status='+this.value+'&type=<?= htmlspecialchars($type_filter) ?>'">
                    <option value="">すべてのステータス</option>
                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>未処理</option>
                    <option value="resolved" <?= $status_filter === 'resolved' ? 'selected' : '' ?>>解決済み</option>
                    <option value="dismissed" <?= $status_filter === 'dismissed' ? 'selected' : '' ?>>却下</option>
                </select>
                <select onchange="location.href='?status=<?= htmlspecialchars($status_filter) ?>&type='+this.value">
                    <option value="">すべてのタイプ</option>
                    <option value="spam" <?= $type_filter === 'spam' ? 'selected' : '' ?>>スパム</option>
                    <option value="harassment" <?= $type_filter === 'harassment' ? 'selected' : '' ?>>嫌がらせ</option>
                    <option value="inappropriate" <?= $type_filter === 'inappropriate' ? 'selected' : '' ?>>不適切なコンテンツ</option>
                    <option value="other" <?= $type_filter === 'other' ? 'selected' : '' ?>>その他</option>
                </select>
            </div>
            
            <div class="card">
                <?php if (empty($reports)): ?>
                <div class="empty-state">
                    <div class="icon">✅</div>
                    <p>通報はありません</p>
                </div>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>タイプ</th>
                            <th>通報者</th>
                            <th>対象者</th>
                            <th>理由</th>
                            <th>ステータス</th>
                            <th>日時</th>
                            <th>アクション</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $report): ?>
                        <tr>
                            <td>#<?= $report['id'] ?></td>
                            <td><span class="type-badge"><?= htmlspecialchars($report['report_type'] ?? 'other') ?></span></td>
                            <td><?= htmlspecialchars($report['reporter_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($report['reported_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars(mb_substr($report['reason'] ?? '', 0, 50)) ?></td>
                            <td><span class="status-badge <?= $report['status'] ?>"><?= $report['status'] === 'pending' ? '未処理' : ($report['status'] === 'resolved' ? '解決済み' : '却下') ?></span></td>
                            <td><?= date('m/d H:i', strtotime($report['created_at'])) ?></td>
                            <td>
                                <?php if ($report['status'] === 'pending'): ?>
                                <button class="action-btn primary" onclick="openResolveModal(<?= $report['id'] ?>, 'resolve')">解決</button>
                                <button class="action-btn warning" onclick="openResolveModal(<?= $report['id'] ?>, 'warn')">警告</button>
                                <button class="action-btn danger" onclick="openResolveModal(<?= $report['id'] ?>, 'ban')">BAN</button>
                                <button class="action-btn secondary" onclick="openResolveModal(<?= $report['id'] ?>, 'dismiss')">却下</button>
                                <?php else: ?>
                                <span style="color:var(--text-muted);font-size:12px;">処理済み</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- 処理モーダル -->
    <div class="modal-overlay" id="resolveModal">
        <div class="modal">
            <h3 id="modalTitle">通報を処理</h3>
            <form method="POST" id="resolveForm">
                <input type="hidden" name="report_id" id="reportId">
                <input type="hidden" name="action" id="reportAction">
                <textarea name="notes" placeholder="メモ（任意）" rows="3"></textarea>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">キャンセル</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">確定</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openResolveModal(id, action) {
            document.getElementById('reportId').value = id;
            document.getElementById('reportAction').value = action;
            
            const titles = {
                resolve: '通報を解決済みにする',
                warn: 'ユーザーに警告を送信',
                ban: 'ユーザーをBANする',
                dismiss: '通報を却下する'
            };
            document.getElementById('modalTitle').textContent = titles[action] || '通報を処理';
            
            const btn = document.getElementById('submitBtn');
            btn.className = 'btn ' + (action === 'ban' ? 'btn-danger' : 'btn-primary');
            btn.style.background = action === 'ban' ? '#ef4444' : '';
            
            document.getElementById('resolveModal').classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('resolveModal').classList.remove('active');
        }
    </script>
<script src="../assets/js/admin-sidebar-sort.js"></script>
</body>
</html>








