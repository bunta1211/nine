<?php
/**
 * Guild ホーム画面
 */

// デバッグ用エラー表示
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 共通ファイルを先に読み込む
require_once __DIR__ . '/includes/common.php';

// ログイン確認（SSO対応）
requireGuildLogin();

$pageTitle = __('home');
$extraCss = ['home.css'];
$extraJs = ['home.js'];

require_once __DIR__ . '/templates/header.php';

$pdo = getDB();
$userId = getGuildUserId();
$fiscalYear = getCurrentFiscalYear();

// 新着依頼を取得
$stmt = $pdo->prepare("
    SELECT r.*, g.name as guild_name,
           u.display_name as requester_name,
           (SELECT COUNT(*) FROM guild_request_applications WHERE request_id = r.id AND status = 'pending') as applicant_count
    FROM guild_requests r
    LEFT JOIN guild_guilds g ON r.guild_id = g.id
    LEFT JOIN users u ON r.requester_id = u.id
    WHERE r.status = 'open' 
    AND (
        r.request_type = 'request'
        OR r.id IN (SELECT request_id FROM guild_request_targets WHERE user_id = ?)
    )
    ORDER BY r.created_at DESC
    LIMIT 10
");
$stmt->execute([$userId]);
$newRequests = $stmt->fetchAll();

// 自分が引き受けている依頼
$stmt = $pdo->prepare("
    SELECT r.*, g.name as guild_name,
           u.display_name as requester_name,
           ra.status as assignee_status, ra.earth_amount as my_earth
    FROM guild_request_assignees ra
    INNER JOIN guild_requests r ON ra.request_id = r.id
    LEFT JOIN guild_guilds g ON r.guild_id = g.id
    LEFT JOIN users u ON r.requester_id = u.id
    WHERE ra.user_id = ? AND ra.status IN ('assigned', 'in_progress')
    ORDER BY r.deadline ASC, r.created_at DESC
    LIMIT 5
");
$stmt->execute([$userId]);
$myAssignments = $stmt->fetchAll();

// 最近の取引履歴
$stmt = $pdo->prepare("
    SELECT t.*, 
           fu.display_name as from_user_name
    FROM guild_earth_transactions t
    LEFT JOIN users fu ON t.related_user_id = fu.id
    WHERE t.user_id = ?
    ORDER BY t.created_at DESC
    LIMIT 5
");
$stmt->execute([$userId]);
$recentTransactions = $stmt->fetchAll();

// 所属ギルドの予算状況
$stmt = $pdo->prepare("
    SELECT g.*, gm.role
    FROM guild_guilds g
    INNER JOIN guild_members gm ON g.id = gm.guild_id
    WHERE gm.user_id = ?
");
$stmt->execute([$userId]);
$myGuildStats = $stmt->fetchAll();
?>

<!-- 余力セクション -->
<section class="availability-section">
    <div class="availability-header">
        <h2 class="availability-title">📊 <?= __('availability') ?></h2>
        <a href="settings.php#availability" class="btn btn-sm btn-secondary"><?= __('edit') ?></a>
    </div>
    <div class="availability-grid">
        <?php
        $periods = [
            'today' => ['label' => __('today'), 'status' => $currentUser['availability_today'] ?? 'available', 'percent' => (int)($currentUser['availability_today_percent'] ?? 100)],
            'week' => ['label' => __('this_week'), 'status' => $currentUser['availability_week'] ?? 'available', 'percent' => (int)($currentUser['availability_week_percent'] ?? 100)],
            'month' => ['label' => __('this_month'), 'status' => $currentUser['availability_month'] ?? 'available', 'percent' => (int)($currentUser['availability_month_percent'] ?? 100)],
            'next' => ['label' => __('next_month'), 'status' => $currentUser['availability_next'] ?? 'available', 'percent' => (int)($currentUser['availability_next_percent'] ?? 100)],
        ];
        foreach ($periods as $key => $period):
        ?>
        <div class="availability-item <?= h($period['status']) ?>">
            <div class="availability-label"><?= $period['label'] ?></div>
            <div class="availability-status">
                <span class="status-dot <?= h($period['status']) ?>"></span>
                <span><?= $period['percent'] ?>%</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ダッシュボードグリッド -->
<div class="dashboard-grid">
    <!-- 新着依頼 -->
    <div class="dashboard-card">
        <div class="dashboard-card-header">
            <h3 class="dashboard-card-title">📋 <?= __('requests') ?></h3>
            <a href="requests.php" class="view-all-link">すべて見る →</a>
        </div>
        <div class="dashboard-card-body">
            <?php if (empty($newRequests)): ?>
            <div class="empty-state">
                <div class="empty-icon">📭</div>
                <p><?= __('no_requests') ?></p>
            </div>
            <?php else: ?>
            <?php foreach ($newRequests as $request): ?>
            <a href="request.php?id=<?= (int)$request['id'] ?>" class="request-mini">
                <div class="request-mini-icon <?= h($request['request_type']) ?>">
                    <?php
                    $icons = ['public' => '📋', 'designated' => '👤', 'order' => '⚡', 'personal' => '💬', 'thanks' => '💝', 'special_reward' => '🏆'];
                    echo $icons[$request['request_type']] ?? '📝';
                    ?>
                </div>
                <div class="request-mini-content">
                    <div class="request-mini-title"><?= h($request['title']) ?></div>
                    <div class="request-mini-meta"><?= h($request['guild_name'] ?? __('personal_request')) ?></div>
                </div>
                <div class="request-mini-earth">🌍 <?= number_format($request['earth_amount']) ?></div>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 引き受けた依頼 -->
    <div class="dashboard-card">
        <div class="dashboard-card-header">
            <h3 class="dashboard-card-title">✅ <?= __('assigned_requests') ?></h3>
            <a href="my-requests.php" class="view-all-link">すべて見る →</a>
        </div>
        <div class="dashboard-card-body">
            <?php if (empty($myAssignments)): ?>
            <div class="empty-state">
                <div class="empty-icon">🎯</div>
                <p><?= __('no_data') ?></p>
            </div>
            <?php else: ?>
            <?php foreach ($myAssignments as $assignment): ?>
            <a href="request.php?id=<?= (int)$assignment['id'] ?>" class="request-mini">
                <div class="request-mini-icon <?= h($assignment['assignee_status']) ?>">
                    <?= $assignment['assignee_status'] === 'in_progress' ? '🔄' : '📌' ?>
                </div>
                <div class="request-mini-content">
                    <div class="request-mini-title"><?= h($assignment['title']) ?></div>
                    <div class="request-mini-meta">
                        <?= $assignment['assignee_status'] === 'in_progress' ? __('status_in_progress') : __('status_assigned') ?>
                        <?php if ($assignment['deadline']): ?>
                        ・〆 <?= date('n/j', strtotime($assignment['deadline'])) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="request-mini-earth">🌍 <?= number_format($assignment['my_earth']) ?></div>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 最近の取引 -->
    <div class="dashboard-card">
        <div class="dashboard-card-header">
            <h3 class="dashboard-card-title">💰 <?= __('payment_history') ?></h3>
            <a href="payments.php" class="view-all-link">すべて見る →</a>
        </div>
        <div class="dashboard-card-body">
            <?php if (empty($recentTransactions)): ?>
            <div class="empty-state">
                <div class="empty-icon">📊</div>
                <p><?= __('no_data') ?></p>
            </div>
            <?php else: ?>
            <?php foreach ($recentTransactions as $tx): ?>
            <div class="transaction-item income">
                <div class="transaction-icon">
                    <?php
                    $icons = ['earned' => '🌍', 'initial' => '🎁', 'tenure' => '⭐', 'role_bonus' => '👑', 'spent' => '💸', 'paid' => '💰'];
                    echo $icons[$tx['type']] ?? '💰';
                    ?>
                </div>
                <div class="transaction-content">
                    <div class="transaction-title"><?= h($tx['description'] ?? $tx['type']) ?></div>
                    <div class="transaction-date"><?= date('n/j H:i', strtotime($tx['created_at'])) ?></div>
                </div>
                <div class="transaction-amount positive">+<?= number_format($tx['amount']) ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 所属ギルド -->
    <div class="dashboard-card">
        <div class="dashboard-card-header">
            <h3 class="dashboard-card-title">🍀 <?= __('my_guilds') ?></h3>
            <a href="guilds.php" class="view-all-link">すべて見る →</a>
        </div>
        <div class="dashboard-card-body">
            <?php if (empty($myGuildStats)): ?>
            <div class="empty-state">
                <div class="empty-icon">🏰</div>
                <p><?= __('no_data') ?></p>
            </div>
            <?php else: ?>
            <?php foreach ($myGuildStats as $guild): ?>
            <div class="guild-stat-card">
                <div class="guild-stat-icon">🍀</div>
                <div class="guild-stat-content">
                    <div class="guild-stat-name"><?= h($guild['name']) ?></div>
                    <div class="guild-stat-role"><?= __('role_' . $guild['role']) ?></div>
                </div>
                <div class="guild-stat-budget">
                    <div class="guild-stat-value"><?= number_format($guild['annual_budget']) ?></div>
                    <div class="guild-stat-label">年間予算</div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
