<?php
/**
 * 管理パネル - タスク管理
 * 全ユーザーのタスクを一覧表示
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

requireLogin();
requireSystemAdmin();

$pdo = getDB();

// 検索・フィルター
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$user_id = $_GET['user_id'] ?? '';
$source = $_GET['source'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// クエリ構築
$where = ['1=1'];
$params = [];

if ($search) {
    $where[] = "(t.title LIKE ? OR t.description LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($status) {
    $where[] = "t.status = ?";
    $params[] = $status;
}

if ($user_id) {
    $where[] = "t.created_by = ?";
    $params[] = $user_id;
}

if ($source) {
    $where[] = "COALESCE(t.source, 'manual') = ?";
    $params[] = $source;
}

$whereClause = implode(' AND ', $where);

// 総数を取得
$countStmt = $pdo->prepare("SELECT COUNT(*) as count FROM tasks t WHERE $whereClause");
$countStmt->execute($params);
$total = $countStmt->fetch()['count'];
$totalPages = ceil($total / $limit);

// Wish一覧を取得
$sql = "
    SELECT 
        t.*,
        u.display_name as creator_name,
        u.email as creator_email,
        au.display_name as assignee_name,
        COALESCE(t.source, 'manual') as source
    FROM tasks t
    INNER JOIN users u ON t.created_by = u.id
    LEFT JOIN users au ON t.assigned_to = au.id
    WHERE $whereClause
    ORDER BY t.created_at DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$wishes = $stmt->fetchAll();

// 統計情報
$statsQuery = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN source = 'ai_extracted' THEN 1 ELSE 0 END) as ai_extracted,
        SUM(CASE WHEN source = 'manual' OR source IS NULL THEN 1 ELSE 0 END) as manual
    FROM tasks
");
$stats = $statsQuery->fetch();

// ユーザー一覧（フィルター用）
$usersStmt = $pdo->query("
    SELECT DISTINCT u.id, u.display_name 
    FROM users u 
    INNER JOIN tasks t ON u.id = t.created_by 
    ORDER BY u.display_name
");
$users = $usersStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>タスク管理 - 管理パネル</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <style>
        body { background: var(--bg-secondary); }
        .admin-container { display: flex; min-height: 100vh; }
        
        .sidebar {
            width: 250px;
            background: var(--bg-dark);
            color: white;
            padding: 20px 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        
        .sidebar-header { padding: 0 20px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 20px; }
        .sidebar-header h1 { font-size: 20px; color: white; display: flex; align-items: center; gap: 8px; }
        
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            transition: all 0.2s;
        }
        .sidebar-nav a:hover, .sidebar-nav a.active { background: rgba(255,255,255,0.1); color: white; }
        .sidebar-nav a .icon { font-size: 18px; width: 24px; text-align: center; }
        
        .main-content { flex: 1; margin-left: 250px; padding: 30px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .page-header h2 { font-size: 24px; display: flex; align-items: center; gap: 10px; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            box-shadow: var(--shadow-sm);
        }
        
        .stat-card .value { font-size: 28px; font-weight: 700; color: var(--text-primary); }
        .stat-card .label { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
        .stat-card.pending .value { color: #f59e0b; }
        .stat-card.in-progress .value { color: #3b82f6; }
        .stat-card.completed .value { color: #10b981; }
        
        .filters {
            background: white;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filters input, .filters select {
            padding: 8px 12px;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            font-size: 14px;
        }
        
        .filters input[type="text"] { width: 250px; }
        
        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        
        table { width: 100%; border-collapse: collapse; }
        
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border-light); }
        th { background: var(--bg-secondary); font-weight: 600; font-size: 13px; color: var(--text-muted); }
        
        .wish-title {
            font-weight: 500;
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .user-cell { display: flex; align-items: center; gap: 8px; }
        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 12px;
            color: var(--primary);
        }
        
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge.pending { background: #fef3c7; color: #92400e; }
        .badge.in-progress { background: #dbeafe; color: #1e40af; }
        .badge.completed { background: #d1fae5; color: #065f46; }
        .badge.cancelled { background: #f3f4f6; color: #6b7280; }
        
        .source-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
        }
        .source-badge.ai { background: #ede9fe; color: #5b21b6; }
        .source-badge.manual { background: #e0e7ff; color: #3730a3; }
        
        .priority-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 6px;
        }
        .priority-1 { background: #10b981; }
        .priority-2 { background: #f59e0b; }
        .priority-3 { background: #ef4444; }
        
        .action-btn {
            padding: 4px 8px;
            border: 1px solid var(--border-light);
            border-radius: 4px;
            background: white;
            cursor: pointer;
            font-size: 12px;
            color: var(--text-secondary);
        }
        .action-btn:hover { background: var(--bg-secondary); }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
        }
        
        .pagination a, .pagination span {
            padding: 8px 12px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
        }
        .pagination a { background: white; color: var(--text-primary); border: 1px solid var(--border-light); }
        .pagination a:hover { background: var(--bg-secondary); }
        .pagination .current { background: var(--primary); color: white; }
        
        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: var(--text-muted);
        }
        .empty-state .icon { font-size: 48px; margin-bottom: 16px; }
        
        .task-description-cell {
            font-size: 12px;
            color: var(--text-muted);
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        @media (max-width: 1024px) {
            .sidebar { width: 60px; }
            .sidebar-header h1 span, .sidebar-nav a span { display: none; }
            .main-content { margin-left: 60px; }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1>⚙️ <span>管理パネル</span></h1>
            </div>
            <nav class="sidebar-nav">
                <a href="index.php">
                    <span class="icon">📊</span>
                    <span>ダッシュボード</span>
                </a>
                <a href="users.php">
                    <span class="icon">👥</span>
                    <span>ユーザー管理</span>
                </a>
                <a href="reports.php">
                    <span class="icon">🚨</span>
                    <span>通報管理</span>
                </a>
                <a href="providers.php">
                    <span class="icon">🏢</span>
                    <span>事業者管理</span>
                </a>
                <a href="specs.php">
                    <span class="icon">📋</span>
                    <span>仕様書ビューア</span>
                </a>
                <a href="logs.php">
                    <span class="icon">📝</span>
                    <span>システムログ</span>
                </a>
                <a href="wishes.php" class="active">
                    <span class="icon">📋</span>
                    <span>タスク管理</span>
                </a>
                <a href="backup.php">
                    <span class="icon">🗄️</span>
                    <span>バックアップ</span>
                </a>
                <a href="settings.php">
                    <span class="icon">⚙️</span>
                    <span>システム設定</span>
                </a>
                <a href="../chat.php">
                    <span class="icon">←</span>
                    <span>チャットに戻る</span>
                </a>
            </nav>
        </aside>
        
        <main class="main-content">
            <div class="page-header">
                <h2>📋 タスク管理</h2>
                <span style="color: var(--text-muted);">全ユーザーのタスク一覧</span>
            </div>
            
            <!-- 統計カード -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="value"><?= number_format($stats['total'] ?? 0) ?></div>
                    <div class="label">総タスク数</div>
                </div>
                <div class="stat-card pending">
                    <div class="value"><?= number_format($stats['pending'] ?? 0) ?></div>
                    <div class="label">未着手</div>
                </div>
                <div class="stat-card in-progress">
                    <div class="value"><?= number_format($stats['in_progress'] ?? 0) ?></div>
                    <div class="label">進行中</div>
                </div>
                <div class="stat-card completed">
                    <div class="value"><?= number_format($stats['completed'] ?? 0) ?></div>
                    <div class="label">完了</div>
                </div>
                <div class="stat-card">
                    <div class="value"><?= number_format($stats['ai_extracted'] ?? 0) ?></div>
                    <div class="label">AI抽出</div>
                </div>
            </div>
            
            <!-- フィルター -->
            <form class="filters" method="get">
                <input type="text" name="search" placeholder="タイトル・説明で検索..." value="<?= htmlspecialchars($search) ?>">
                
                <select name="status">
                    <option value="">全ステータス</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>未着手</option>
                    <option value="in_progress" <?= $status === 'in_progress' ? 'selected' : '' ?>>進行中</option>
                    <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>完了</option>
                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>キャンセル</option>
                </select>
                
                <select name="user_id">
                    <option value="">全ユーザー</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $user_id == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                
                <select name="source">
                    <option value="">全ソース</option>
                    <option value="manual" <?= $source === 'manual' ? 'selected' : '' ?>>手動入力</option>
                    <option value="ai_extracted" <?= $source === 'ai_extracted' ? 'selected' : '' ?>>AI抽出</option>
                </select>
                
                <button type="submit" class="action-btn">🔍 検索</button>
                <a href="wishes.php" class="action-btn">クリア</a>
            </form>
            
            <!-- テーブル -->
            <div class="table-container">
                <?php if (empty($wishes)): ?>
                    <div class="empty-state">
                        <div class="icon">📋</div>
                        <p>タスクがありません</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>タイトル</th>
                                <th>作成者</th>
                                <th>ステータス</th>
                                <th>優先度</th>
                                <th>ソース</th>
                                <th>作成日</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($wishes as $wish): ?>
                                <tr>
                                    <td>#<?= $wish['id'] ?></td>
                                    <td>
                                        <div class="wish-title"><?= htmlspecialchars($wish['title']) ?></div>
                                        <?php if ($wish['description']): ?>
                                            <div class="wish-description"><?= htmlspecialchars($wish['description']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="user-cell">
                                            <div class="user-avatar"><?= mb_substr($wish['creator_name'], 0, 1) ?></div>
                                            <div>
                                                <div style="font-weight: 500;"><?= htmlspecialchars($wish['creator_name']) ?></div>
                                                <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($wish['creator_email'] ?? '') ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?= $wish['status'] ?>">
                                            <?php
                                            $statusLabels = [
                                                'pending' => '未着手',
                                                'in_progress' => '進行中',
                                                'completed' => '完了',
                                                'cancelled' => 'キャンセル'
                                            ];
                                            echo $statusLabels[$wish['status']] ?? $wish['status'];
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="priority-indicator priority-<?= $wish['priority'] ?? 1 ?>"></span>
                                        <?php
                                        $priorityLabels = [1 => '低', 2 => '中', 3 => '高'];
                                        echo $priorityLabels[$wish['priority'] ?? 1] ?? '低';
                                        ?>
                                    </td>
                                    <td>
                                        <span class="source-badge <?= $wish['source'] === 'ai_extracted' ? 'ai' : 'manual' ?>">
                                            <?= $wish['source'] === 'ai_extracted' ? 'AI' : '手動' ?>
                                        </span>
                                    </td>
                                    <td style="font-size: 13px; color: var(--text-muted);">
                                        <?= date('Y/m/d H:i', strtotime($wish['created_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <!-- ページネーション -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">← 前へ</a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">次へ →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
