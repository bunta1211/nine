<?php
/**
 * Guild 自分の依頼ページ
 */

require_once __DIR__ . '/includes/common.php';

$pageTitle = __('my_requests');
$extraCss = ['requests.css'];

require_once __DIR__ . '/templates/header.php';

$pdo = getDB();
$userId = getGuildUserId();
$fiscalYear = getCurrentFiscalYear();

// 自分が作成した依頼
$stmt = $pdo->prepare("
    SELECT r.*, g.name as guild_name,
           (SELECT COUNT(*) FROM guild_request_applications WHERE request_id = r.id AND status = 'pending') as applicant_count
    FROM guild_requests r
    LEFT JOIN guild_guilds g ON r.guild_id = g.id
    WHERE r.requester_id = ? AND r.fiscal_year = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$userId, $fiscalYear]);
$myCreatedRequests = $stmt->fetchAll();

// 自分が担当している依頼
$stmt = $pdo->prepare("
    SELECT r.*, g.name as guild_name, u.display_name as requester_name,
           ra.status as assignee_status, ra.earth_amount as my_earth
    FROM guild_request_assignees ra
    INNER JOIN guild_requests r ON ra.request_id = r.id
    LEFT JOIN guild_guilds g ON r.guild_id = g.id
    LEFT JOIN users u ON r.requester_id = u.id
    WHERE ra.user_id = ? AND r.fiscal_year = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$userId, $fiscalYear]);
$myAssignedRequests = $stmt->fetchAll();

// 自分が応募中の依頼
$stmt = $pdo->prepare("
    SELECT r.*, g.name as guild_name, u.display_name as requester_name,
           rap.status as application_status, rap.applied_at
    FROM guild_request_applications rap
    INNER JOIN guild_requests r ON rap.request_id = r.id
    LEFT JOIN guild_guilds g ON r.guild_id = g.id
    LEFT JOIN users u ON r.requester_id = u.id
    WHERE rap.user_id = ? AND r.fiscal_year = ?
    ORDER BY rap.applied_at DESC
");
$stmt->execute([$userId, $fiscalYear]);
$myApplications = $stmt->fetchAll();
?>

<div class="page-header">
    <h1 class="page-title"><?= __('my_requests') ?></h1>
    <a href="request-new.php" class="btn btn-primary">
        <span class="btn-icon">+</span>
        <?= __('new_request') ?>
    </a>
</div>

<!-- タブ -->
<div class="tabs">
    <button class="tab-btn active" data-tab="created">作成した依頼 (<?= count($myCreatedRequests) ?>)</button>
    <button class="tab-btn" data-tab="assigned">担当中の依頼 (<?= count($myAssignedRequests) ?>)</button>
    <button class="tab-btn" data-tab="applied">応募中 (<?= count($myApplications) ?>)</button>
</div>

<!-- 作成した依頼 -->
<div class="tab-content active" id="tab-created">
    <?php if (empty($myCreatedRequests)): ?>
    <div class="empty-state">
        <div class="empty-icon">📝</div>
        <p>作成した依頼はありません</p>
    </div>
    <?php else: ?>
    <div class="request-list">
        <?php foreach ($myCreatedRequests as $request): ?>
        <a href="request.php?id=<?= $request['id'] ?>" class="request-card">
            <div class="request-header">
                <span class="request-type badge badge-<?= $request['request_type'] ?>">
                    <?= __('request_type_' . $request['request_type']) ?>
                </span>
                <span class="request-status badge badge-status-<?= $request['status'] ?>">
                    <?= __('status_' . $request['status']) ?>
                </span>
            </div>
            <h3 class="request-title"><?= h($request['title']) ?></h3>
            <div class="request-footer">
                <span class="request-earth">🌍 <?= number_format($request['earth_amount']) ?> Earth</span>
                <?php if ($request['applicant_count'] > 0): ?>
                <span class="request-applicants"><?= $request['applicant_count'] ?>人応募</span>
                <?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- 担当中の依頼 -->
<div class="tab-content" id="tab-assigned">
    <?php if (empty($myAssignedRequests)): ?>
    <div class="empty-state">
        <div class="empty-icon">📋</div>
        <p>担当中の依頼はありません</p>
    </div>
    <?php else: ?>
    <div class="request-list">
        <?php foreach ($myAssignedRequests as $request): ?>
        <a href="request.php?id=<?= $request['id'] ?>" class="request-card">
            <div class="request-header">
                <span class="request-type badge badge-<?= $request['request_type'] ?>">
                    <?= __('request_type_' . $request['request_type']) ?>
                </span>
                <span class="request-status badge badge-status-<?= $request['assignee_status'] ?>">
                    <?= __('status_' . $request['assignee_status']) ?>
                </span>
            </div>
            <h3 class="request-title"><?= h($request['title']) ?></h3>
            <div class="request-meta">
                <span class="request-requester">依頼者: <?= h($request['requester_name']) ?></span>
            </div>
            <div class="request-footer">
                <span class="request-earth">🌍 <?= number_format($request['my_earth']) ?> Earth</span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- 応募中 -->
<div class="tab-content" id="tab-applied">
    <?php if (empty($myApplications)): ?>
    <div class="empty-state">
        <div class="empty-icon">✋</div>
        <p>応募中の依頼はありません</p>
    </div>
    <?php else: ?>
    <div class="request-list">
        <?php foreach ($myApplications as $request): ?>
        <a href="request.php?id=<?= $request['id'] ?>" class="request-card">
            <div class="request-header">
                <span class="request-type badge badge-<?= $request['request_type'] ?>">
                    <?= __('request_type_' . $request['request_type']) ?>
                </span>
                <span class="request-status badge badge-status-<?= $request['application_status'] ?>">
                    <?= $request['application_status'] === 'pending' ? '選考中' : __('status_' . $request['application_status']) ?>
                </span>
            </div>
            <h3 class="request-title"><?= h($request['title']) ?></h3>
            <div class="request-meta">
                <span class="request-requester">依頼者: <?= h($request['requester_name']) ?></span>
            </div>
            <div class="request-footer">
                <span class="request-earth">🌍 <?= number_format($request['earth_amount']) ?> Earth</span>
                <span class="request-date">応募: <?= date('n/j', strtotime($request['applied_at'])) ?></span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
    });
});
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
