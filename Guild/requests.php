<?php
/**
 * Guild 依頼一覧ページ
 */

require_once __DIR__ . '/includes/common.php';

$pageTitle = __('requests');
$extraCss = ['requests.css'];
$extraJs = ['requests.js'];

require_once __DIR__ . '/templates/header.php';

$pdo = getDB();
$userId = getGuildUserId();
$fiscalYear = getCurrentFiscalYear();

// フィルター
$filterType = $_GET['type'] ?? '';
$filterGuild = $_GET['guild'] ?? '';
$filterStatus = $_GET['status'] ?? 'open';

// 依頼一覧を取得
$sql = "
    SELECT r.*, g.name as guild_name,
           u.display_name as requester_name, u.avatar_path as requester_avatar,
           (SELECT COUNT(*) FROM guild_request_applications WHERE request_id = r.id AND status = 'pending') as applicant_count
    FROM guild_requests r
    LEFT JOIN guild_guilds g ON r.guild_id = g.id
    LEFT JOIN users u ON r.requester_id = u.id
    WHERE r.fiscal_year = ?
";
$params = [$fiscalYear];

if ($filterStatus) {
    $sql .= " AND r.status = ?";
    $params[] = $filterStatus;
}

if ($filterType) {
    $sql .= " AND r.request_type = ?";
    $params[] = $filterType;
}

if ($filterGuild) {
    $sql .= " AND r.guild_id = ?";
    $params[] = $filterGuild;
}

$sql .= " ORDER BY r.created_at DESC LIMIT 50";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// 所属ギルド一覧
$userGuilds = getUserGuilds();
?>

<div class="page-header">
    <h1 class="page-title"><?= __('requests') ?></h1>
    <a href="request-new.php" class="btn btn-primary">
        <span class="btn-icon">+</span>
        <?= __('new_request') ?>
    </a>
</div>

<!-- フィルター -->
<div class="filter-bar">
    <select id="filter-type" class="form-select" onchange="applyFilters()">
        <option value=""><?= __('all_types') ?></option>
        <option value="public" <?= $filterType === 'public' ? 'selected' : '' ?>><?= __('request_type_public') ?></option>
        <option value="designated" <?= $filterType === 'designated' ? 'selected' : '' ?>><?= __('request_type_designated') ?></option>
        <option value="order" <?= $filterType === 'order' ? 'selected' : '' ?>><?= __('request_type_order') ?></option>
        <option value="shift_swap" <?= $filterType === 'shift_swap' ? 'selected' : '' ?>><?= __('request_type_shift_swap') ?></option>
        <option value="personal" <?= $filterType === 'personal' ? 'selected' : '' ?>><?= __('request_type_personal') ?></option>
    </select>
    
    <select id="filter-guild" class="form-select" onchange="applyFilters()">
        <option value=""><?= __('all_guilds') ?></option>
        <?php foreach ($userGuilds as $guild): ?>
        <option value="<?= $guild['id'] ?>" <?= $filterGuild == $guild['id'] ? 'selected' : '' ?>><?= h($guild['name']) ?></option>
        <?php endforeach; ?>
    </select>
    
    <select id="filter-status" class="form-select" onchange="applyFilters()">
        <option value="open" <?= $filterStatus === 'open' ? 'selected' : '' ?>><?= __('status_open') ?></option>
        <option value="in_progress" <?= $filterStatus === 'in_progress' ? 'selected' : '' ?>><?= __('status_in_progress') ?></option>
        <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>><?= __('status_completed') ?></option>
        <option value=""><?= __('all_status') ?></option>
    </select>
</div>

<!-- 依頼リスト -->
<div class="request-list">
    <?php if (empty($requests)): ?>
    <div class="empty-state">
        <div class="empty-icon">📋</div>
        <p><?= __('no_requests') ?></p>
    </div>
    <?php else: ?>
    <?php foreach ($requests as $request): ?>
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
        <div class="request-meta">
            <span class="request-guild"><?= h($request['guild_name'] ?? __('personal')) ?></span>
            <span class="request-requester"><?= h($request['requester_name']) ?></span>
        </div>
        <div class="request-footer">
            <span class="request-earth">🌍 <?= number_format($request['earth_amount']) ?> Earth</span>
            <?php if ($request['deadline']): ?>
            <span class="request-deadline">〆 <?= date('n/j', strtotime($request['deadline'])) ?></span>
            <?php endif; ?>
            <?php if ($request['applicant_count'] > 0): ?>
            <span class="request-applicants"><?= $request['applicant_count'] ?>人応募</span>
            <?php endif; ?>
        </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function applyFilters() {
    const type = document.getElementById('filter-type').value;
    const guild = document.getElementById('filter-guild').value;
    const status = document.getElementById('filter-status').value;
    
    const params = new URLSearchParams();
    if (type) params.set('type', type);
    if (guild) params.set('guild', guild);
    if (status) params.set('status', status);
    
    window.location.href = 'requests.php' + (params.toString() ? '?' + params.toString() : '');
}
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
