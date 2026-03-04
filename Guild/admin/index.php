<?php
/**
 * Guild システム管理ページ
 */

require_once __DIR__ . '/../includes/common.php';
requireGuildSystemAdmin();

$pageTitle = __('system_admin');

require_once __DIR__ . '/../templates/header.php';

$pdo = getDB();
$fiscalYear = getCurrentFiscalYear();

// 統計情報
$stats = [];

// ユーザー数
$stmt = $pdo->query("SELECT COUNT(*) FROM users");
$stats['user_count'] = $stmt->fetchColumn();

// ギルド数
$stmt = $pdo->prepare("SELECT COUNT(*) FROM guild_guilds WHERE fiscal_year = ?");
$stmt->execute([$fiscalYear]);
$stats['guild_count'] = $stmt->fetchColumn();

// 依頼数
$stmt = $pdo->prepare("SELECT COUNT(*) FROM guild_requests WHERE fiscal_year = ?");
$stmt->execute([$fiscalYear]);
$stats['request_count'] = $stmt->fetchColumn();

// 完了依頼数
$stmt = $pdo->prepare("SELECT COUNT(*) FROM guild_requests WHERE fiscal_year = ? AND status = 'completed'");
$stmt->execute([$fiscalYear]);
$stats['completed_count'] = $stmt->fetchColumn();

// Earth総発行額
$stmt = $pdo->prepare("SELECT COALESCE(SUM(earth_amount), 0) FROM guild_requests WHERE fiscal_year = ? AND status IN ('completed', 'in_progress')");
$stmt->execute([$fiscalYear]);
$stats['total_earth'] = $stmt->fetchColumn();

// 承認待ち依頼（1万Earth以上）
$stmt = $pdo->prepare("
    SELECT r.*, u.display_name as requester_name, g.name as guild_name
    FROM guild_requests r
    LEFT JOIN users u ON r.requester_id = u.id
    LEFT JOIN guild_guilds g ON r.guild_id = g.id
    WHERE r.status = 'pending_approval' AND r.fiscal_year = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$fiscalYear]);
$pendingApprovals = $stmt->fetchAll();

// 最近のアクティビティ
$stmt = $pdo->prepare("
    SELECT l.*, u.display_name as user_name
    FROM guild_activity_logs l
    LEFT JOIN users u ON l.user_id = u.id
    ORDER BY l.created_at DESC
    LIMIT 20
");
$stmt->execute();
$recentLogs = $stmt->fetchAll();
?>

<div class="page-header">
    <h1 class="page-title"><?= __('system_admin') ?></h1>
</div>

<!-- 統計カード -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">👤</div>
        <div class="stat-value"><?= number_format($stats['user_count']) ?></div>
        <div class="stat-label">登録ユーザー</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">🍀</div>
        <div class="stat-value"><?= number_format($stats['guild_count']) ?></div>
        <div class="stat-label">ギルド数</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📋</div>
        <div class="stat-value"><?= number_format($stats['request_count']) ?></div>
        <div class="stat-label">依頼総数</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">✅</div>
        <div class="stat-value"><?= number_format($stats['completed_count']) ?></div>
        <div class="stat-label">完了依頼</div>
    </div>
    <div class="stat-card highlight">
        <div class="stat-icon">🌍</div>
        <div class="stat-value"><?= number_format($stats['total_earth']) ?></div>
        <div class="stat-label">Earth総額</div>
    </div>
</div>

<!-- 管理メニュー -->
<div class="admin-menu">
    <a href="users.php" class="menu-card">
        <div class="menu-icon">👥</div>
        <div class="menu-title">ユーザー管理</div>
        <div class="menu-desc">ユーザーの権限設定、入社日設定など</div>
    </a>
    <a href="guilds.php" class="menu-card">
        <div class="menu-icon">🍀</div>
        <div class="menu-title">ギルド管理</div>
        <div class="menu-desc">ギルドの作成、予算設定、メンバー管理</div>
    </a>
    <a href="fiscal.php" class="menu-card">
        <div class="menu-icon">📅</div>
        <div class="menu-title">年度管理</div>
        <div class="menu-desc">年度設定、Earth分配、年度切替</div>
    </a>
    <a href="reports.php" class="menu-card">
        <div class="menu-icon">📊</div>
        <div class="menu-title">レポート</div>
        <div class="menu-desc">分析、ランキング、統計データ</div>
    </a>
    <a href="templates.php" class="menu-card">
        <div class="menu-icon">📝</div>
        <div class="menu-title">テンプレート管理</div>
        <div class="menu-desc">依頼テンプレートの管理</div>
    </a>
    <a href="export.php" class="menu-card">
        <div class="menu-icon">📥</div>
        <div class="menu-title">CSV出力</div>
        <div class="menu-desc">データのエクスポート</div>
    </a>
</div>

<!-- 承認待ち依頼 -->
<?php if (!empty($pendingApprovals)): ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">⚠️ 承認待ち依頼（1万Earth以上）</h2>
    </div>
    <div class="card-body">
        <div class="approval-list">
            <?php foreach ($pendingApprovals as $request): ?>
            <div class="approval-item">
                <div class="approval-info">
                    <div class="approval-title"><?= h($request['title']) ?></div>
                    <div class="approval-meta">
                        <span><?= h($request['guild_name']) ?></span>
                        <span><?= h($request['requester_name']) ?></span>
                        <span><?= date('n/j H:i', strtotime($request['created_at'])) ?></span>
                    </div>
                </div>
                <div class="approval-earth">🌍 <?= number_format($request['earth_amount']) ?></div>
                <div class="approval-actions">
                    <button class="btn btn-success btn-sm" onclick="approveRequest(<?= $request['id'] ?>)">承認</button>
                    <button class="btn btn-danger btn-sm" onclick="rejectRequest(<?= $request['id'] ?>)">却下</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 最近のアクティビティ -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">最近のアクティビティ</h2>
    </div>
    <div class="card-body">
        <?php if (empty($recentLogs)): ?>
        <p class="text-muted">アクティビティはありません</p>
        <?php else: ?>
        <div class="activity-list">
            <?php foreach ($recentLogs as $log): ?>
            <div class="activity-item">
                <div class="activity-user"><?= h($log['user_name'] ?? 'システム') ?></div>
                <div class="activity-action"><?= h($log['action_type']) ?></div>
                <div class="activity-time"><?= date('n/j H:i', strtotime($log['created_at'])) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-xl);
}

.stat-card {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    text-align: center;
    box-shadow: var(--shadow-sm);
}

.stat-card.highlight {
    background: linear-gradient(135deg, var(--color-primary) 0%, #8b5cf6 100%);
    color: white;
}

.stat-icon {
    font-size: 2rem;
    margin-bottom: var(--spacing-sm);
}

.stat-value {
    font-size: var(--font-size-2xl);
    font-weight: 700;
}

.stat-label {
    font-size: var(--font-size-sm);
    color: var(--color-text-secondary);
}

.stat-card.highlight .stat-label {
    color: rgba(255,255,255,0.8);
}

.admin-menu {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-xl);
}

.menu-card {
    display: block;
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    text-decoration: none;
    color: inherit;
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-fast);
}

.menu-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.menu-icon {
    font-size: 2rem;
    margin-bottom: var(--spacing-sm);
}

.menu-title {
    font-size: var(--font-size-lg);
    font-weight: 600;
    margin-bottom: var(--spacing-xs);
}

.menu-desc {
    font-size: var(--font-size-sm);
    color: var(--color-text-secondary);
}

.approval-list {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
}

.approval-item {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    padding: var(--spacing-md);
    background: #fef3c7;
    border-radius: var(--radius-md);
}

.dark .approval-item {
    background: rgba(251, 191, 36, 0.1);
}

.approval-info {
    flex: 1;
}

.approval-title {
    font-weight: 500;
}

.approval-meta {
    font-size: var(--font-size-sm);
    color: var(--color-text-secondary);
    display: flex;
    gap: var(--spacing-md);
}

.approval-earth {
    font-size: var(--font-size-lg);
    font-weight: 600;
}

.approval-actions {
    display: flex;
    gap: var(--spacing-xs);
}

.activity-list {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
}

.activity-item {
    display: flex;
    gap: var(--spacing-md);
    padding: var(--spacing-sm);
    border-bottom: 1px solid var(--color-border);
}

.activity-user {
    font-weight: 500;
    min-width: 100px;
}

.activity-action {
    flex: 1;
    color: var(--color-text-secondary);
}

.activity-time {
    font-size: var(--font-size-sm);
    color: var(--color-text-muted);
}
</style>

<script>
async function approveRequest(id) {
    if (!await Guild.confirm('この依頼を承認しますか？')) return;
    
    try {
        await Guild.api('admin/requests.php?action=approve', {
            method: 'POST',
            body: { id }
        });
        Guild.toast('承認しました', 'success');
        location.reload();
    } catch (error) {
        Guild.toast('エラーが発生しました', 'error');
    }
}

async function rejectRequest(id) {
    if (!await Guild.confirm('この依頼を却下しますか？')) return;
    
    try {
        await Guild.api('admin/requests.php?action=reject', {
            method: 'POST',
            body: { id }
        });
        Guild.toast('却下しました', 'success');
        location.reload();
    } catch (error) {
        Guild.toast('エラーが発生しました', 'error');
    }
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
