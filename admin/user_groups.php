<?php
/**
 * 管理パネル - ユーザー別 所属グループ一覧・再入室
 * 引っ越し等でグループから外れたユーザーの確認・再入室用
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

$currentPage = basename(__FILE__, '.php');
require_once __DIR__ . '/_sidebar.php';

requireLogin();
requireSystemAdmin();

$pdo = getDB();

// 再入室処理（POST）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rejoin') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $conversation_id = (int)($_POST['conversation_id'] ?? 0);
    if ($user_id && $conversation_id) {
        $stmt = $pdo->prepare("SELECT id FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
        $stmt->execute([$conversation_id, $user_id]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo->prepare("UPDATE conversation_members SET left_at = NULL, role = 'member', joined_at = NOW() WHERE id = ?")
                ->execute([$row['id']]);
            $message = '再入室させました。';
        } else {
            $pdo->prepare("INSERT INTO conversation_members (conversation_id, user_id, role) VALUES (?, ?, 'member')")
                ->execute([$conversation_id, $user_id]);
            $message = 'グループに追加しました。';
        }
        header('Location: user_groups.php?user_id=' . $user_id . '&msg=' . urlencode($message));
        exit;
    }
}

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$search = trim($_GET['search'] ?? '');
$search_users = [];

// ユーザー取得（user_id または 表示名・メール検索）
$user = null;
if ($user_id) {
    $stmt = $pdo->prepare("SELECT id, display_name, email, created_at FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($search !== '') {
    $stmt = $pdo->prepare("SELECT id, display_name, email, created_at FROM users WHERE display_name LIKE ? OR email LIKE ? ORDER BY id LIMIT 20");
    $stmt->execute(["%{$search}%", "%{$search}%"]);
    $search_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 対象ユーザーのグループ所属一覧（現在所属 + 退室済み）
$current_groups = [];
$left_groups = [];
if ($user) {
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.type, cm.role, cm.joined_at, cm.left_at
        FROM conversation_members cm
        INNER JOIN conversations c ON c.id = cm.conversation_id
        WHERE cm.user_id = ? AND c.type = 'group'
        ORDER BY cm.left_at IS NULL DESC, c.name ASC
    ");
    $stmt->execute([$user['id']]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['left_at'] === null || $row['left_at'] === '') {
            $current_groups[] = $row;
        } else {
            $left_groups[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>所属グループ - 管理パネル</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <style>
        body { background: var(--bg-secondary); }
        <?php adminSidebarCSS(); ?>
        .page-header { margin-bottom: 20px; }
        .breadcrumb { margin-bottom: 8px; font-size: 13px; color: var(--text-muted); }
        .breadcrumb a { color: var(--primary); }
        .user-card { background: white; padding: 20px; border-radius: 12px; margin-bottom: 20px; }
        .user-card h2 { margin: 0 0 8px; }
        .user-card .meta { color: var(--text-muted); font-size: 14px; }
        .section-title { font-size: 18px; margin: 24px 0 12px; }
        .group-list { list-style: none; padding: 0; margin: 0; }
        .group-list li { background: white; padding: 12px 16px; margin-bottom: 8px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; }
        .group-list li.lefted { opacity: 0.85; background: var(--bg-secondary); }
        .group-list .role-badge { font-size: 12px; padding: 2px 8px; border-radius: 4px; background: #e0e7ff; color: #3730a3; }
        .group-list .rejoin-btn { padding: 6px 12px; border-radius: 6px; background: var(--primary); color: white; border: none; cursor: pointer; font-size: 13px; }
        .group-list .rejoin-btn:hover { opacity: 0.9; }
        .search-box { background: white; padding: 16px; border-radius: 12px; margin-bottom: 20px; }
        .search-box form { display: flex; gap: 8px; }
        .search-box input { flex: 1; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; }
        .msg { padding: 12px; border-radius: 8px; margin-bottom: 16px; background: #d1fae5; color: #065f46; }
        .search-results { background: white; padding: 16px; border-radius: 12px; margin-bottom: 20px; }
        .search-results table { width: 100%; }
        .search-results td { padding: 8px 0; }
        .search-results a { color: var(--primary); }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php adminSidebarHTML($currentPage); ?>

        <main class="main-content">
            <div class="page-header">
                <div class="breadcrumb">
                    <a href="users.php">ユーザー管理</a> &gt; 所属グループ確認
                </div>
                <h2>📁 ユーザー別 所属グループ一覧</h2>
                <p style="color: var(--text-muted); margin-top: 4px;">引っ越し等でグループから外れた方の確認・再入室ができます。</p>
            </div>

            <?php if (!empty($_GET['msg'])): ?>
            <div class="msg"><?= htmlspecialchars($_GET['msg']) ?></div>
            <?php endif; ?>

            <div class="search-box">
                <form method="GET" action="">
                    <input type="hidden" name="user_id" value="">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="表示名またはメールで検索（例: kyoko）">
                    <button type="submit" class="btn btn-primary">検索</button>
                </form>
            </div>

            <?php if ($search !== '' && !$user_id): ?>
            <div class="search-results">
                <h3>検索結果</h3>
                <?php if (empty($search_users)): ?>
                <p>該当するユーザーがいません。</p>
                <?php else: ?>
                <table>
                    <thead><tr><th>ID</th><th>表示名</th><th>メール</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($search_users as $u): ?>
                    <tr>
                        <td><?= (int)$u['id'] ?></td>
                        <td><?= htmlspecialchars($u['display_name']) ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><a href="?user_id=<?= (int)$u['id'] ?>">所属グループを表示</a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($user): ?>
            <div class="user-card">
                <h2><?= htmlspecialchars($user['display_name']) ?></h2>
                <div class="meta">ID: <?= (int)$user['id'] ?> / <?= htmlspecialchars($user['email']) ?> / 登録: <?= date('Y-m-d', strtotime($user['created_at'])) ?></div>
            </div>

            <h3 class="section-title">現在所属しているグループ（<?= count($current_groups) ?>件）</h3>
            <?php if (empty($current_groups)): ?>
            <p style="color: var(--text-muted);">現在、グループには所属していません。</p>
            <?php else: ?>
            <ul class="group-list">
                <?php foreach ($current_groups as $g): ?>
                <li>
                    <span><strong><?= htmlspecialchars($g['name']) ?></strong> <span class="role-badge"><?= htmlspecialchars($g['role']) ?></span> （入室: <?= date('Y-m-d', strtotime($g['joined_at'])) ?>）</span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <h3 class="section-title">退室済みのグループ（再入室可能）（<?= count($left_groups) ?>件）</h3>
            <?php if (empty($left_groups)): ?>
            <p style="color: var(--text-muted);">退室済みのグループはありません。</p>
            <?php else: ?>
            <ul class="group-list">
                <?php foreach ($left_groups as $g): ?>
                <li class="lefted">
                    <span><?= htmlspecialchars($g['name']) ?> （退室: <?= date('Y-m-d', strtotime($g['left_at'])) ?>）</span>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('このグループに再入室させますか？');">
                        <input type="hidden" name="action" value="rejoin">
                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                        <input type="hidden" name="conversation_id" value="<?= (int)$g['id'] ?>">
                        <button type="submit" class="rejoin-btn">再入室</button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <?php elseif ($user_id && !$user): ?>
            <p>指定されたユーザーが見つかりません。</p>
            <?php endif; ?>

            <?php if (!$user && !$search): ?>
            <p style="color: var(--text-muted);">上で表示名またはメールアドレスで検索するか、<a href="users.php">ユーザー管理</a>から「所属グループ」をクリックしてユーザーを指定してください。</p>
            <?php endif; ?>
        </main>
    </div>
<script src="../assets/js/admin-sidebar-sort.js"></script>
</body>
</html>
