<?php
/**
 * Guild 所属ギルドページ
 */

require_once __DIR__ . '/includes/common.php';

$pageTitle = __('my_guilds');

require_once __DIR__ . '/templates/header.php';

$pdo = getDB();
$userId = getGuildUserId();
$fiscalYear = getCurrentFiscalYear();

// 所属ギルド一覧
$userGuilds = getUserGuilds();

// 各ギルドの詳細情報を取得
$guildDetails = [];
foreach ($userGuilds as $guild) {
    // メンバー数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM guild_members WHERE guild_id = ?");
    $stmt->execute([$guild['id']]);
    $memberCount = $stmt->fetchColumn();
    
    // 依頼数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM guild_requests WHERE guild_id = ? AND fiscal_year = ?");
    $stmt->execute([$guild['id'], $fiscalYear]);
    $requestCount = $stmt->fetchColumn();
    
    $guildDetails[$guild['id']] = [
        'member_count' => $memberCount,
        'request_count' => $requestCount
    ];
}
?>

<div class="page-header">
    <h1 class="page-title"><?= __('my_guilds') ?></h1>
</div>

<?php if (empty($userGuilds)): ?>
<div class="empty-state">
    <div class="empty-icon">🍀</div>
    <p>所属ギルドがありません</p>
    <p class="text-muted">管理者にギルドへの招待を依頼してください</p>
</div>
<?php else: ?>
<div class="guild-list">
    <?php foreach ($userGuilds as $guild): 
        $details = $guildDetails[$guild['id']];
    ?>
    <div class="guild-card">
        <div class="guild-header">
            <?php if ($guild['logo_path']): ?>
            <img src="<?= h($guild['logo_path']) ?>" alt="" class="guild-logo">
            <?php else: ?>
            <div class="guild-logo-placeholder">🍀</div>
            <?php endif; ?>
            <div class="guild-info">
                <h3 class="guild-name"><?= h($guild['name']) ?></h3>
                <span class="guild-role badge badge-<?= $guild['role'] ?>">
                    <?= __('role_' . $guild['role']) ?>
                </span>
            </div>
        </div>
        
        <?php if ($guild['description']): ?>
        <p class="guild-description"><?= h($guild['description']) ?></p>
        <?php endif; ?>
        
        <div class="guild-stats">
            <div class="stat">
                <span class="stat-value"><?= $details['member_count'] ?></span>
                <span class="stat-label">メンバー</span>
            </div>
            <div class="stat">
                <span class="stat-value"><?= $details['request_count'] ?></span>
                <span class="stat-label">依頼</span>
            </div>
            <div class="stat">
                <span class="stat-value"><?= number_format($guild['annual_budget']) ?></span>
                <span class="stat-label">年間予算</span>
            </div>
        </div>
        
        <div class="guild-actions">
            <a href="requests.php?guild=<?= $guild['id'] ?>" class="btn btn-secondary">依頼を見る</a>
            <?php if (in_array($guild['role'], ['leader', 'sub_leader', 'coordinator'])): ?>
            <a href="guild-manage.php?id=<?= $guild['id'] ?>" class="btn btn-secondary">管理</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.guild-list {
    display: grid;
    gap: var(--spacing-lg);
}

.guild-card {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    box-shadow: var(--shadow-sm);
}

.guild-header {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-md);
}

.guild-logo, .guild-logo-placeholder {
    width: 60px;
    height: 60px;
    border-radius: var(--radius-lg);
    object-fit: cover;
}

.guild-logo-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-primary-light);
    font-size: 1.5rem;
}

.guild-info {
    flex: 1;
}

.guild-name {
    font-size: var(--font-size-lg);
    font-weight: 600;
    margin-bottom: var(--spacing-xs);
}

.guild-description {
    color: var(--color-text-secondary);
    margin-bottom: var(--spacing-md);
}

.guild-stats {
    display: flex;
    gap: var(--spacing-lg);
    padding: var(--spacing-md) 0;
    border-top: 1px solid var(--color-border);
    border-bottom: 1px solid var(--color-border);
    margin-bottom: var(--spacing-md);
}

.stat {
    text-align: center;
}

.stat-value {
    display: block;
    font-size: var(--font-size-xl);
    font-weight: 600;
    color: var(--color-primary);
}

.stat-label {
    font-size: var(--font-size-sm);
    color: var(--color-text-muted);
}

.guild-actions {
    display: flex;
    gap: var(--spacing-sm);
}

.badge-leader { background: #fef3c7; color: #92400e; }
.badge-sub_leader { background: #dbeafe; color: #1e40af; }
.badge-coordinator { background: #d1fae5; color: #065f46; }
.badge-member { background: var(--color-bg-hover); color: var(--color-text-secondary); }
</style>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
