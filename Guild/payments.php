<?php
/**
 * Guild 支払いページ
 */

require_once __DIR__ . '/includes/common.php';

$pageTitle = __('payments');

require_once __DIR__ . '/templates/header.php';

$pdo = getDB();
$userId = getGuildUserId();
$fiscalYear = getCurrentFiscalYear();

// Earth残高
$earthBalance = getUserEarthBalance();

// 取引履歴
$stmt = $pdo->prepare("
    SELECT t.*, fu.display_name as from_user_name
    FROM guild_earth_transactions t
    LEFT JOIN users fu ON t.from_user_id = fu.id
    WHERE t.user_id = ? AND t.fiscal_year = ?
    ORDER BY t.created_at DESC
    LIMIT 50
");
$stmt->execute([$userId, $fiscalYear]);
$transactions = $stmt->fetchAll();

// 前借り申請の取得（テーブルがあれば）
$advanceRequests = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM guild_advance_requests
        WHERE user_id = ? AND fiscal_year = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$userId, $fiscalYear]);
    $advanceRequests = $stmt->fetchAll();
} catch (PDOException $e) {
    // テーブルがない場合は無視
}

// 前借り可能額を計算
$unpaidEarth = $earthBalance['current_balance'] - $earthBalance['total_paid'];
$maxAdvance = floor($unpaidEarth * 0.8); // 80%まで
?>

<div class="page-header">
    <h1 class="page-title"><?= __('payments') ?></h1>
</div>

<!-- Earth残高サマリー -->
<div class="balance-summary">
    <div class="balance-card primary">
        <div class="balance-label">保有Earth</div>
        <div class="balance-value">🌍 <?= number_format($earthBalance['current_balance']) ?></div>
    </div>
    <div class="balance-card">
        <div class="balance-label">獲得総額</div>
        <div class="balance-value"><?= number_format($earthBalance['total_earned']) ?></div>
    </div>
    <div class="balance-card">
        <div class="balance-label">使用総額</div>
        <div class="balance-value"><?= number_format($earthBalance['total_spent']) ?></div>
    </div>
    <div class="balance-card">
        <div class="balance-label">支払済み</div>
        <div class="balance-value"><?= number_format($earthBalance['total_paid']) ?></div>
    </div>
    <div class="balance-card highlight">
        <div class="balance-label">未支給Earth</div>
        <div class="balance-value"><?= number_format($unpaidEarth) ?></div>
        <div class="balance-sub">(<?= number_format($unpaidEarth * EARTH_TO_YEN) ?>円相当)</div>
    </div>
</div>

<!-- 前借り申請 -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">前借り申請</h2>
    </div>
    <div class="card-body">
        <p>前借り可能額: <strong><?= number_format($maxAdvance) ?> Earth</strong> (<?= number_format($maxAdvance * EARTH_TO_YEN) ?>円)</p>
        <p class="text-muted">※ 未支給Earthの80%まで前借り可能です</p>
        
        <?php if ($maxAdvance > 0): ?>
        <button class="btn btn-primary" onclick="openAdvanceModal()">前借りを申請する</button>
        <?php else: ?>
        <p class="text-warning">現在、前借り可能なEarthがありません</p>
        <?php endif; ?>
    </div>
</div>

<!-- 取引履歴 -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">取引履歴</h2>
    </div>
    <div class="card-body">
        <?php if (empty($transactions)): ?>
        <div class="empty-state">
            <p>取引履歴がありません</p>
        </div>
        <?php else: ?>
        <div class="transaction-list">
            <?php foreach ($transactions as $tx): ?>
            <div class="transaction-item <?= in_array($tx['type'], ['earned', 'initial', 'tenure', 'role_bonus']) ? 'income' : 'expense' ?>">
                <div class="tx-icon">
                    <?php
                    $icons = [
                        'earned' => '🌍',
                        'spent' => '💸',
                        'paid' => '💰',
                        'initial' => '🎁',
                        'tenure' => '⭐',
                        'role_bonus' => '👑'
                    ];
                    echo $icons[$tx['type']] ?? '📝';
                    ?>
                </div>
                <div class="tx-details">
                    <div class="tx-description"><?= h($tx['description'] ?? $tx['type']) ?></div>
                    <div class="tx-date"><?= date('Y/n/j H:i', strtotime($tx['created_at'])) ?></div>
                </div>
                <div class="tx-amount <?= in_array($tx['type'], ['earned', 'initial', 'tenure', 'role_bonus']) ? 'positive' : 'negative' ?>">
                    <?= in_array($tx['type'], ['earned', 'initial', 'tenure', 'role_bonus']) ? '+' : '-' ?><?= number_format($tx['amount']) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.balance-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-xl);
}

.balance-card {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    text-align: center;
    box-shadow: var(--shadow-sm);
}

.balance-card.primary {
    background: linear-gradient(135deg, var(--color-primary) 0%, #8b5cf6 100%);
    color: white;
}

.balance-card.highlight {
    background: var(--color-secondary);
    color: white;
}

.balance-label {
    font-size: var(--font-size-sm);
    opacity: 0.8;
    margin-bottom: var(--spacing-xs);
}

.balance-value {
    font-size: var(--font-size-xl);
    font-weight: 600;
}

.balance-sub {
    font-size: var(--font-size-sm);
    opacity: 0.8;
    margin-top: var(--spacing-xs);
}

.transaction-list {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
}

.transaction-item {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    padding: var(--spacing-md);
    border-radius: var(--radius-md);
    background: var(--color-bg-hover);
}

.tx-icon {
    font-size: 1.5rem;
}

.tx-details {
    flex: 1;
}

.tx-description {
    font-weight: 500;
}

.tx-date {
    font-size: var(--font-size-sm);
    color: var(--color-text-muted);
}

.tx-amount {
    font-weight: 600;
    font-size: var(--font-size-lg);
}

.tx-amount.positive {
    color: var(--color-success);
}

.tx-amount.negative {
    color: var(--color-danger);
}
</style>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
