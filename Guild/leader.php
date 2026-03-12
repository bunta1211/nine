<?php
/**
 * ギルド長ページ
 * リーダー・サブリーダーが担当するギルドの管理入口
 */

require_once __DIR__ . '/includes/common.php';
requireGuildLogin();

if (!isGuildLeaderOrSubLeader()) {
    header('Location: home.php');
    exit;
}

$pageTitle = 'ギルド長ページ';
$extraCss = ['home.css'];

require_once __DIR__ . '/templates/header.php';

$pdo = getDB();
$fiscalYear = getCurrentFiscalYear();
$leaderGuilds = getGuildsWhereLeaderOrSubLeader();

$guildDetails = [];
foreach ($leaderGuilds as $guild) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM guild_members WHERE guild_id = ?");
    $stmt->execute([$guild['id']]);
    $memberCount = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM guild_requests WHERE guild_id = ? AND fiscal_year = ?");
    $stmt->execute([$guild['id'], $fiscalYear]);
    $requestCount = $stmt->fetchColumn();

    $guildDetails[$guild['id']] = [
        'member_count' => $memberCount,
        'request_count' => $requestCount,
    ];
}
?>

<div class="page-header">
    <h1 class="page-title">ギルド長ページ</h1>
    <p class="page-description" style="color:var(--color-text-secondary);font-size:14px;margin-top:8px;">担当ギルドの依頼確認・管理を行います。</p>
</div>

<?php if (empty($leaderGuilds)): ?>
<div class="empty-state">
    <div class="empty-icon">👑</div>
    <p>リーダー・サブリーダーとして担当しているギルドはありません</p>
</div>
<?php else: ?>
<div class="guild-list leader-guild-list">
    <?php foreach ($leaderGuilds as $guild):
        $details = $guildDetails[$guild['id']];
    ?>
    <div class="guild-card">
        <div class="guild-header">
            <?php if (!empty($guild['logo_path'])): ?>
            <img src="<?= h($guild['logo_path']) ?>" alt="" class="guild-logo">
            <?php else: ?>
            <div class="guild-logo-placeholder">🍀</div>
            <?php endif; ?>
            <div class="guild-info">
                <h3 class="guild-name"><?= h($guild['name']) ?></h3>
                <span class="guild-role badge badge-<?= h($guild['role']) ?>">
                    <?= __('role_' . $guild['role']) ?>
                </span>
            </div>
        </div>
        <?php if (!empty($guild['description'])): ?>
        <p class="guild-description"><?= h($guild['description']) ?></p>
        <?php endif; ?>
        <div class="guild-stats">
            <div class="stat">
                <span class="stat-value"><?= (int)$details['member_count'] ?></span>
                <span class="stat-label">メンバー</span>
            </div>
            <div class="stat">
                <span class="stat-value"><?= (int)$details['request_count'] ?></span>
                <span class="stat-label">依頼</span>
            </div>
            <div class="stat">
                <span class="stat-value"><?= number_format((int)($guild['annual_budget'] ?? 0)) ?></span>
                <span class="stat-label">年間予算</span>
            </div>
        </div>
        <div class="guild-actions">
            <a href="requests.php?guild=<?= (int)$guild['id'] ?>" class="btn btn-secondary">依頼を見る</a>
            <a href="guilds.php" class="btn btn-primary">ギルド詳細</a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.leader-guild-list { display: grid; gap: var(--spacing-lg, 20px); }
.guild-card {
    background: var(--color-bg-card, #fff);
    border-radius: var(--radius-lg, 12px);
    padding: var(--spacing-lg, 20px);
    box-shadow: var(--shadow-sm, 0 1px 3px rgba(0,0,0,0.1));
}
.guild-header { display: flex; align-items: center; gap: var(--spacing-md, 16px); margin-bottom: var(--spacing-md, 16px); }
.guild-logo, .guild-logo-placeholder { width: 60px; height: 60px; border-radius: var(--radius-lg, 12px); object-fit: cover; }
.guild-logo-placeholder { display: flex; align-items: center; justify-content: center; background: #e0e7ff; font-size: 1.5rem; }
.guild-info { flex: 1; }
.guild-name { font-size: 1.125rem; font-weight: 600; margin-bottom: 4px; }
.guild-description { color: #64748b; margin-bottom: 12px; font-size: 14px; }
.guild-stats { display: flex; gap: 24px; padding: 12px 0; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; margin-bottom: 12px; }
.stat { text-align: center; }
.stat-value { display: block; font-size: 1.25rem; font-weight: 600; color: #6366f1; }
.stat-label { font-size: 12px; color: #94a3b8; }
.guild-actions { display: flex; gap: 10px; }
.badge-leader { background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 6px; font-size: 12px; }
.badge-sub_leader { background: #dbeafe; color: #1e40af; padding: 2px 8px; border-radius: 6px; font-size: 12px; }
</style>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
