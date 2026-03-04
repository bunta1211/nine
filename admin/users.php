<?php
/**
 * 管理パネル - ユーザー管理
 * 仕様書: 13_管理機能.md
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

$currentPage = basename(__FILE__, '.php');
require_once __DIR__ . '/_sidebar.php';

requireLogin();
requireSystemAdmin();

$pdo = getDB();

// 検索・フィルター
$search = $_GET['search'] ?? '';
$role = $_GET['role'] ?? '';
$status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// クエリ構築
$where = ['1=1'];
$params = [];

if ($search) {
    $where[] = "(display_name LIKE ? OR email LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($role) {
    $where[] = "role = ?";
    $params[] = $role;
}

if ($status === 'online') {
    $where[] = "online_status = 'online'";
} elseif ($status === 'minor') {
    $where[] = "is_minor = 1";
}
// アカウント状態（active/suspended/deleted）
$accountStatus = $_GET['account_status'] ?? '';
if ($accountStatus !== '' && in_array($accountStatus, ['active', 'suspended', 'deleted'], true)) {
    $where[] = "status = ?";
    $params[] = $accountStatus;
}

$whereClause = implode(' AND ', $where);

// 総数を取得（WHERE のカラムは users のためテーブル指定なしでOK）
$countStmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE $whereClause");
$countStmt->execute($params);
$total = $countStmt->fetch()['count'];
$totalPages = ceil($total / $limit);

// ユーザー一覧を取得
// reportsテーブルが存在するかチェック
$reportsTableExists = false;
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'reports'");
    $reportsTableExists = $checkTable->rowCount() > 0;
} catch (PDOException $e) {}

$reportCountSql = $reportsTableExists 
    ? "(SELECT COUNT(*) FROM reports WHERE reported_user_id = u.id)" 
    : "0";

$sql = "
    SELECT u.*, 
        (SELECT COUNT(*) FROM messages WHERE sender_id = u.id) as message_count,
        $reportCountSql as report_count
    FROM users u
    WHERE $whereClause
    ORDER BY u.created_at DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ユーザー管理 - 管理パネル</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <style>
        body { background: var(--bg-secondary); }
        <?php adminSidebarCSS(); ?>
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .page-header h2 { font-size: 24px; }
        
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
        }
        
        table { width: 100%; border-collapse: collapse; }
        
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border-light); }
        th { background: var(--bg-secondary); font-weight: 600; font-size: 13px; color: var(--text-muted); }
        
        .user-cell { display: flex; align-items: center; gap: 12px; }
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: var(--primary);
        }
        
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
        }
        .badge-admin { background: #fee2e2; color: #991b1b; }
        .badge-user { background: #e0e7ff; color: #3730a3; }
        .badge-minor { background: #fef3c7; color: #92400e; }
        .badge-online { background: #d1fae5; color: #065f46; }
        
        .actions a, .actions button {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            text-decoration: none;
            border: none;
            cursor: pointer;
        }
        .actions .view { background: var(--bg-secondary); color: var(--text-primary); }
        .actions .edit { background: #e0e7ff; color: #3730a3; margin-left: 4px; }
        .actions .delete { background: #fee2e2; color: #991b1b; margin-left: 4px; }
        .actions .view:hover, .actions .edit:hover, .actions .delete:hover { opacity: 0.9; }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            padding: 20px;
        }
        .pagination a {
            padding: 8px 12px;
            border-radius: 6px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            text-decoration: none;
        }
        .pagination a.active { background: var(--primary); color: white; }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php adminSidebarHTML($currentPage); ?>
        
        <main class="main-content">
            <div class="page-header">
                <h2>👥 ユーザー管理</h2>
                <span><?= number_format($total) ?> 件</span>
            </div>
            
            <form class="filters" method="GET">
                <input type="text" name="search" placeholder="名前・メールで検索..." value="<?= htmlspecialchars($search) ?>">
                <select name="role">
                    <option value="">すべての役割</option>
                    <option value="system_admin" <?= $role === 'system_admin' ? 'selected' : '' ?>>システム管理者</option>
                    <option value="org_admin" <?= $role === 'org_admin' ? 'selected' : '' ?>>組織管理者</option>
                    <option value="user" <?= $role === 'user' ? 'selected' : '' ?>>一般ユーザー</option>
                </select>
                <select name="status">
                    <option value="">すべてのステータス</option>
                    <option value="online" <?= $status === 'online' ? 'selected' : '' ?>>オンライン</option>
                    <option value="minor" <?= $status === 'minor' ? 'selected' : '' ?>>未成年</option>
                </select>
                <select name="account_status">
                    <option value="">アカウント状態: すべて</option>
                    <option value="active" <?= $accountStatus === 'active' ? 'selected' : '' ?>>有効</option>
                    <option value="suspended" <?= $accountStatus === 'suspended' ? 'selected' : '' ?>>停止</option>
                    <option value="deleted" <?= $accountStatus === 'deleted' ? 'selected' : '' ?>>削除済</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">検索</button>
            </form>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ユーザー</th>
                            <th>メールアドレス</th>
                            <th>役割</th>
                            <th>アカウント状態</th>
                            <th>ステータス</th>
                            <th>メッセージ数</th>
                            <th>通報数</th>
                            <th>登録日</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <div class="user-avatar">
                                        <?= mb_substr($user['display_name'], 0, 1) ?>
                                    </div>
                                    <div>
                                        <strong><?= htmlspecialchars($user['display_name']) ?></strong>
                                        <?php if ($user['is_minor']): ?>
                                        <span class="badge badge-minor">未成年</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($user['email']) ?></td>
                            <td>
                                <span class="badge badge-<?= $user['role'] === 'system_admin' ? 'admin' : 'user' ?>">
                                    <?= $user['role'] === 'system_admin' ? '管理者' : ($user['role'] === 'org_admin' ? '組織管理者' : 'ユーザー') ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $ust = $user['status'] ?? 'active';
                                if ($ust === 'deleted'): ?>
                                <span class="badge" style="background:#fecaca;color:#991b1b">削除済</span>
                                <?php elseif ($ust === 'suspended'): ?>
                                <span class="badge" style="background:#fed7aa;color:#9a3412">停止</span>
                                <?php else: ?>
                                <span class="badge badge-online">有効</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (($user['online_status'] ?? '') === 'online'): ?>
                                <span class="badge badge-online">🟢 オンライン</span>
                                <?php else: ?>
                                <span style="color: var(--text-muted)">オフライン</span>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($user['message_count']) ?></td>
                            <td>
                                <?php if ($user['report_count'] > 0): ?>
                                <span style="color: var(--error)"><?= $user['report_count'] ?></span>
                                <?php else: ?>
                                0
                                <?php endif; ?>
                            </td>
                            <td><?= date('Y/m/d', strtotime($user['created_at'])) ?></td>
                            <td class="actions">
                                <a href="user_groups.php?user_id=<?= (int)$user['id'] ?>" class="view">所属グループ</a>
                                <button type="button" class="edit js-user-edit" data-user-id="<?= (int)$user['id'] ?>">編集</button>
                                <?php if (($user['status'] ?? 'active') !== 'deleted' && (int)$user['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
                                <button type="button" class="delete js-user-delete" data-user-id="<?= (int)$user['id'] ?>" data-display-name="<?= htmlspecialchars($user['display_name']) ?>">削除</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($role) ?>&status=<?= urlencode($status) ?>&account_status=<?= urlencode($accountStatus) ?>" 
                       class="<?= $i === $page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- ユーザー編集モーダル -->
            <div id="userEditModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
                <div style="background:white; border-radius:12px; padding:24px; max-width:480px; width:90%; box-shadow:0 8px 32px rgba(0,0,0,0.2);">
                    <h3 style="margin-bottom:16px;">ユーザーを編集</h3>
                    <form id="userEditForm">
                        <input type="hidden" name="id" id="editUserId">
                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-weight:600; margin-bottom:4px;">表示名</label>
                            <input type="text" name="display_name" id="editDisplayName" required style="width:100%; padding:8px 12px; border:1px solid #e0e0e0; border-radius:8px;">
                        </div>
                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-weight:600; margin-bottom:4px;">メールアドレス</label>
                            <input type="email" name="email" id="editEmail" required style="width:100%; padding:8px 12px; border:1px solid #e0e0e0; border-radius:8px;">
                        </div>
                        <div style="margin-bottom:12px;" id="editFullNameWrap">
                            <label style="display:block; font-weight:600; margin-bottom:4px;">氏名（本名）</label>
                            <input type="text" name="full_name" id="editFullName" style="width:100%; padding:8px 12px; border:1px solid #e0e0e0; border-radius:8px;">
                        </div>
                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-weight:600; margin-bottom:4px;">アカウント状態</label>
                            <select name="status" id="editStatus" style="width:100%; padding:8px 12px; border:1px solid #e0e0e0; border-radius:8px;">
                                <option value="active">有効</option>
                                <option value="suspended">停止</option>
                                <option value="deleted">削除済</option>
                            </select>
                        </div>
                        <div style="margin-bottom:16px;">
                            <label style="display:block; font-weight:600; margin-bottom:4px;">役割</label>
                            <select name="role" id="editRole" style="width:100%; padding:8px 12px; border:1px solid #e0e0e0; border-radius:8px;">
                                <option value="user">一般ユーザー</option>
                                <option value="org_admin">組織管理者</option>
                                <option value="system_admin">システム管理者</option>
                            </select>
                        </div>
                        <div style="display:flex; gap:8px; justify-content:flex-end;">
                            <button type="button" id="userEditModalClose" class="btn btn-secondary">キャンセル</button>
                            <button type="submit" class="btn btn-primary">保存</button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
    <script>
(function() {
    var modal = document.getElementById('userEditModal');
    var form = document.getElementById('userEditForm');
    var closeBtn = document.getElementById('userEditModalClose');

    function openModal() {
        modal.style.display = 'flex';
    }
    function closeModal() {
        modal.style.display = 'none';
    }

    document.querySelectorAll('.js-user-edit').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-user-id');
            fetch('api/users.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success || !data.user) {
                        alert(data.message || '取得に失敗しました');
                        return;
                    }
                    var u = data.user;
                    document.getElementById('editUserId').value = u.id;
                    document.getElementById('editDisplayName').value = u.display_name || '';
                    document.getElementById('editEmail').value = u.email || '';
                    var fnWrap = document.getElementById('editFullNameWrap');
                    var fnInput = document.getElementById('editFullName');
                    if (fnInput) {
                        fnInput.value = u.full_name != null ? u.full_name : '';
                        fnWrap.style.display = u.has_full_name !== false ? 'block' : 'none';
                    }
                    document.getElementById('editStatus').value = u.status || 'active';
                    var roleSel = document.getElementById('editRole');
                    if (roleSel) {
                        roleSel.value = (u.role && roleSel.querySelector('option[value="' + u.role + '"]')) ? u.role : 'user';
                    }
                    openModal();
                })
                .catch(function() { alert('通信エラー'); });
        });
    });

    document.querySelectorAll('.js-user-delete').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-user-id');
            var name = this.getAttribute('data-display-name') || id;
            if (!confirm('「' + name + '」を削除（無効化）しますか？\nログインできなくなります。')) return;
            fetch('api/users.php', {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        alert(data.message || '削除しました');
                        location.reload();
                    } else {
                        alert(data.message || '削除に失敗しました');
                    }
                })
                .catch(function() { alert('通信エラー'); });
        });
    });

    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeModal();
    });
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var id = document.getElementById('editUserId').value;
        var payload = {
            id: parseInt(id, 10),
            display_name: document.getElementById('editDisplayName').value.trim(),
            email: document.getElementById('editEmail').value.trim(),
            status: document.getElementById('editStatus').value,
            role: document.getElementById('editRole').value
        };
        var fn = document.getElementById('editFullName');
        if (fn) payload.full_name = fn.value.trim();
        fetch('api/users.php', {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    alert(data.message || '保存しました');
                    closeModal();
                    location.reload();
                } else {
                    alert(data.message || '保存に失敗しました');
                }
            })
            .catch(function() { alert('通信エラー'); });
    });
})();
    </script>
<script src="../assets/js/admin-sidebar-sort.js"></script>
</body>
</html>








