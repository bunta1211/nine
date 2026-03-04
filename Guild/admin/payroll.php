<?php
/**
 * Guild 支払い管理ページ
 */

require_once __DIR__ . '/../includes/common.php';

if (!isGuildSystemAdmin() && !isGuildPayrollAdmin()) {
    header('Location: ../home.php');
    exit;
}

$pageTitle = '支払い管理';

require_once __DIR__ . '/../templates/header.php';

$pdo = getDB();
$fiscalYear = getCurrentFiscalYear();

// 未支給Earth一覧
$stmt = $pdo->prepare("
    SELECT u.id, u.display_name, u.email,
           b.current_balance, b.total_paid,
           (b.current_balance - b.total_paid) as unpaid
    FROM guild_earth_balances b
    INNER JOIN users u ON b.user_id = u.id
    WHERE b.fiscal_year = ? AND (b.current_balance - b.total_paid) > 0
    ORDER BY unpaid DESC
");
$stmt->execute([$fiscalYear]);
$unpaidUsers = $stmt->fetchAll();

// 前借り申請一覧（テーブルがあれば）
$advanceRequests = [];
try {
    $stmt = $pdo->prepare("
        SELECT ar.*, u.display_name as user_name
        FROM guild_advance_requests ar
        INNER JOIN users u ON ar.user_id = u.id
        WHERE ar.fiscal_year = ? AND ar.status = 'pending'
        ORDER BY ar.created_at DESC
    ");
    $stmt->execute([$fiscalYear]);
    $advanceRequests = $stmt->fetchAll();
} catch (PDOException $e) {
    // テーブルがない場合は無視
}

// 統計
$totalUnpaid = array_sum(array_column($unpaidUsers, 'unpaid'));
$totalUnpaidYen = $totalUnpaid * EARTH_TO_YEN;
?>

<div class="page-header">
    <h1 class="page-title">支払い管理</h1>
</div>

<!-- サマリー -->
<div class="summary-cards">
    <div class="summary-card">
        <div class="summary-label">未支給Earth総額</div>
        <div class="summary-value">🌍 <?= number_format($totalUnpaid) ?></div>
    </div>
    <div class="summary-card highlight">
        <div class="summary-label">未支給金額</div>
        <div class="summary-value">¥<?= number_format($totalUnpaidYen) ?></div>
    </div>
    <div class="summary-card">
        <div class="summary-label">対象者数</div>
        <div class="summary-value"><?= count($unpaidUsers) ?>人</div>
    </div>
</div>

<!-- 前借り申請 -->
<?php if (!empty($advanceRequests)): ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">前借り申請</h2>
    </div>
    <div class="card-body">
        <table class="table">
            <thead>
                <tr>
                    <th>申請者</th>
                    <th>申請額</th>
                    <th>申請日</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($advanceRequests as $ar): ?>
                <tr>
                    <td><?= h($ar['user_name']) ?></td>
                    <td><?= number_format($ar['amount']) ?> Earth (¥<?= number_format($ar['amount'] * EARTH_TO_YEN) ?>)</td>
                    <td><?= date('Y/n/j', strtotime($ar['created_at'])) ?></td>
                    <td>
                        <button class="btn btn-success btn-sm" onclick="approveAdvance(<?= $ar['id'] ?>)">承認</button>
                        <button class="btn btn-danger btn-sm" onclick="rejectAdvance(<?= $ar['id'] ?>)">却下</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- 未支給者一覧 -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">未支給Earth一覧</h2>
        <button class="btn btn-primary" onclick="exportPayroll()">CSV出力</button>
    </div>
    <div class="card-body">
        <?php if (empty($unpaidUsers)): ?>
        <p class="text-muted">未支給者はいません</p>
        <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>氏名</th>
                    <th>メールアドレス</th>
                    <th>保有Earth</th>
                    <th>支払済み</th>
                    <th>未支給</th>
                    <th>金額</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($unpaidUsers as $user): ?>
                <tr>
                    <td><?= h($user['display_name']) ?></td>
                    <td><?= h($user['email']) ?></td>
                    <td><?= number_format($user['current_balance']) ?></td>
                    <td><?= number_format($user['total_paid']) ?></td>
                    <td><strong><?= number_format($user['unpaid']) ?></strong></td>
                    <td>¥<?= number_format($user['unpaid'] * EARTH_TO_YEN) ?></td>
                    <td>
                        <button class="btn btn-primary btn-sm" onclick="markPaid(<?= $user['id'] ?>, <?= $user['unpaid'] ?>)">支払済み</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<style>
.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-xl);
}

.summary-card {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    text-align: center;
    box-shadow: var(--shadow-sm);
}

.summary-card.highlight {
    background: var(--color-secondary);
    color: white;
}

.summary-label {
    font-size: var(--font-size-sm);
    margin-bottom: var(--spacing-xs);
    opacity: 0.8;
}

.summary-value {
    font-size: var(--font-size-2xl);
    font-weight: 700;
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th, .table td {
    padding: var(--spacing-sm) var(--spacing-md);
    text-align: left;
    border-bottom: 1px solid var(--color-border);
}

.table th {
    background: var(--color-bg-hover);
    font-weight: 600;
}

.table tr:hover {
    background: var(--color-bg-hover);
}
</style>

<script>
async function markPaid(userId, amount) {
    if (!await Guild.confirm(`${amount} Earthを支払済みにしますか？`)) return;
    
    try {
        await Guild.api('admin/payroll.php?action=mark_paid', {
            method: 'POST',
            body: { user_id: userId, amount }
        });
        Guild.toast('支払済みにしました', 'success');
        location.reload();
    } catch (error) {
        Guild.toast('エラーが発生しました', 'error');
    }
}

function exportPayroll() {
    window.location.href = 'export.php?type=payroll';
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
