<?php
/**
 * 管理パネル - 事業者管理
 * 仕様書: 13_管理機能.md, 38_ユニバーサルマッチング.md
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

// 事業者一覧を取得
$providers = [];
try {
    $sql = "
        SELECT 
            sp.*,
            u.display_name,
            u.email
        FROM service_providers sp
        INNER JOIN users u ON sp.user_id = u.id
        WHERE 1=1
    ";
    $params = [];

    if ($status_filter) {
        $sql .= " AND sp.verification_status = ?";
        $params[] = $status_filter;
    }

    $sql .= " ORDER BY sp.created_at DESC LIMIT 100";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $providers = $stmt->fetchAll();
} catch (PDOException $e) {
    // テーブルがない場合は空のまま
}

// 審査処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $provider_id = (int)($_POST['provider_id'] ?? 0);
    
    if ($provider_id && in_array($action, ['approve', 'reject'])) {
        try {
            $stmt = $pdo->prepare("
                UPDATE service_providers SET 
                    verification_status = ?,
                    verified_at = ?,
                    verified_by = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $action === 'approve' ? 'verified' : 'rejected',
                $action === 'approve' ? date('Y-m-d H:i:s') : null,
                $_SESSION['user_id'],
                $provider_id
            ]);
            
            header('Location: providers.php?success=1');
            exit;
        } catch (PDOException $e) {
            // エラー処理
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
    <title>事業者管理 - 管理パネル | Social9</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <style>
        body { background: var(--bg-secondary); font-family: 'Hiragino Sans', 'Meiryo', sans-serif; }
        <?php adminSidebarCSS(); ?>
        
        .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .page-header h2 { font-size: 24px; }
        
        .filters { display: flex; gap: 12px; margin-bottom: 20px; }
        .filters select { padding: 8px 16px; border: 1px solid var(--border-light); border-radius: 8px; }
        
        .card { background: white; border-radius: 12px; box-shadow: var(--shadow-sm); overflow: hidden; }
        
        .provider-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            padding: 20px;
        }
        
        .provider-card {
            border: 1px solid var(--border-light);
            border-radius: 12px;
            padding: 20px;
        }
        
        .provider-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .provider-avatar {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }
        .provider-name { font-weight: 600; font-size: 16px; }
        .provider-email { font-size: 13px; color: var(--text-muted); }
        
        .provider-details { margin-bottom: 16px; }
        .detail-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border-light); font-size: 14px; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { color: var(--text-muted); }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-badge.pending { background: #fef3c7; color: #92400e; }
        .status-badge.verified { background: #dcfce7; color: #166534; }
        .status-badge.rejected { background: #fee2e2; color: #991b1b; }
        
        .provider-actions { display: flex; gap: 8px; }
        .provider-actions button { flex: 1; padding: 10px; border: none; border-radius: 8px; cursor: pointer; font-size: 13px; }
        .btn-approve { background: #22c55e; color: white; }
        .btn-reject { background: #ef4444; color: white; }
        .btn-view { background: var(--bg-secondary); color: var(--text-primary); }
        
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .alert.success { background: #dcfce7; color: #166534; }
        
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state .icon { font-size: 48px; margin-bottom: 12px; }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php adminSidebarHTML($currentPage); ?>
        
        <main class="main-content">
            <div class="page-header">
                <h2>🏢 事業者管理</h2>
            </div>
            
            <?php if ($success): ?>
            <div class="alert success">事業者を処理しました。</div>
            <?php endif; ?>
            
            <div class="filters">
                <select onchange="location.href='?status='+this.value">
                    <option value="">すべてのステータス</option>
                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>審査待ち</option>
                    <option value="verified" <?= $status_filter === 'verified' ? 'selected' : '' ?>>承認済み</option>
                    <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>却下</option>
                </select>
            </div>
            
            <div class="card">
                <?php if (empty($providers)): ?>
                <div class="empty-state">
                    <div class="icon">🏢</div>
                    <p>事業者登録はありません</p>
                    <p style="font-size: 13px; margin-top: 8px;">ユニバーサルマッチング機能で事業者が登録されると、ここに表示されます。</p>
                </div>
                <?php else: ?>
                <div class="provider-grid">
                    <?php foreach ($providers as $provider): ?>
                    <div class="provider-card">
                        <div class="provider-header">
                            <div class="provider-avatar"><?= mb_substr($provider['business_name'] ?? $provider['display_name'], 0, 1) ?></div>
                            <div>
                                <div class="provider-name"><?= htmlspecialchars($provider['business_name'] ?? $provider['display_name']) ?></div>
                                <div class="provider-email"><?= htmlspecialchars($provider['email']) ?></div>
                            </div>
                        </div>
                        
                        <div class="provider-details">
                            <div class="detail-row">
                                <span class="detail-label">ステータス</span>
                                <span class="status-badge <?= $provider['verification_status'] ?>">
                                    <?= $provider['verification_status'] === 'pending' ? '審査待ち' : ($provider['verification_status'] === 'verified' ? '承認済み' : '却下') ?>
                                </span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">業種</span>
                                <span><?= htmlspecialchars($provider['business_type'] ?? '-') ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">登録日</span>
                                <span><?= date('Y/m/d', strtotime($provider['created_at'])) ?></span>
                            </div>
                        </div>
                        
                        <div class="provider-actions">
                            <?php if ($provider['verification_status'] === 'pending'): ?>
                            <form method="POST" style="display:flex;gap:8px;flex:1;">
                                <input type="hidden" name="provider_id" value="<?= $provider['id'] ?>">
                                <button type="submit" name="action" value="approve" class="btn-approve" style="flex:1;">承認</button>
                                <button type="submit" name="action" value="reject" class="btn-reject" style="flex:1;">却下</button>
                            </form>
                            <?php else: ?>
                            <button class="btn-view" style="flex:1;">詳細を見る</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
<script src="../assets/js/admin-sidebar-sort.js"></script>
</body>
</html>








