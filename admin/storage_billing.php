<?php
/**
 * 管理パネル - 利用料請求 (Storage Billing Management)
 *
 * 契約一覧・請求管理・全銀データDL・口座情報管理
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/storage_s3_helper.php';
require_once __DIR__ . '/../includes/zengin_helper.php';
require_once __DIR__ . '/../includes/ai_billing_rates.php';

$currentPage = 'storage_billing';
require_once __DIR__ . '/_sidebar.php';

if (!isLoggedIn() || !isOrgAdminUser()) {
    header('Location: ../index.php');
    exit;
}

$pdo = getDB();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ============================================
// AJAX処理
// ============================================
if ($action === 'download_zengin') {
    header('Content-Type: text/plain; charset=Shift_JIS');
    header('Content-Disposition: attachment; filename="zengin_' . date('Ymd') . '.txt"');

    $billingMonth = $_GET['month'] ?? date('Y-m');
    $stmt = $pdo->prepare("
        SELECT br.*, ss.entity_type, ss.entity_id, sp.name AS plan_name
        FROM storage_billing_records br
        JOIN storage_subscriptions ss ON br.subscription_id = ss.id
        JOIN storage_plans sp ON ss.plan_id = sp.id
        WHERE br.billing_month = ? AND br.status = 'pending' AND br.amount > 0
    ");
    $stmt->execute([$billingMonth]);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $records = [];
    foreach ($bills as $bill) {
        $bankAcct = getBankAccount($pdo, $bill['entity_type'], (int) $bill['entity_id']);
        if (!$bankAcct) continue;

        $records[] = [
            'bank_code'           => $bankAcct['bank_code'],
            'bank_name_kana'      => $bankAcct['bank_name_kana'],
            'branch_code'         => $bankAcct['branch_code'],
            'branch_name_kana'    => $bankAcct['branch_name_kana'],
            'account_type'        => $bankAcct['account_type'],
            'account_number'      => $bankAcct['account_number'],
            'account_holder_kana' => $bankAcct['account_holder_kana'],
            'amount'              => (int) $bill['amount'],
            'customer_number'     => str_pad($bill['subscription_id'], 20, '0', STR_PAD_LEFT),
        ];
    }

    $data = generateZenginData($records);
    echo mb_convert_encoding($data, 'SJIS', 'UTF-8');

    $ids = array_column($bills, 'id');
    if (!empty($ids)) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("UPDATE storage_billing_records SET status = 'billed', zengin_exported_at = NOW() WHERE id IN ({$in})");
    }
    exit;
}

if ($action === 'generate_bills') {
    $month = $_POST['month'] ?? date('Y-m');
    $stmt = $pdo->prepare("
        SELECT ss.id AS sub_id, sp.monthly_price
        FROM storage_subscriptions ss
        JOIN storage_plans sp ON ss.plan_id = sp.id
        WHERE ss.status = 'active' AND sp.monthly_price > 0
    ");
    $stmt->execute();
    $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;
    foreach ($subs as $sub) {
        try {
            $pdo->prepare("
                INSERT INTO storage_billing_records (subscription_id, billing_month, amount, status)
                VALUES (?, ?, ?, 'pending')
                ON DUPLICATE KEY UPDATE amount = VALUES(amount)
            ")->execute([$sub['sub_id'], $month, $sub['monthly_price']]);
            $count++;
        } catch (Throwable $e) {}
    }
    header('Location: storage_billing.php?msg=generated&count=' . $count);
    exit;
}

if ($action === 'save_bank_account') {
    $entityType = $_POST['entity_type'] ?? '';
    $entityId   = (int) ($_POST['entity_id'] ?? 0);
    if ($entityType && $entityId) {
        $pdo->prepare("
            INSERT INTO storage_bank_accounts (entity_type, entity_id, bank_code, bank_name_kana, branch_code, branch_name_kana, account_type, account_number, account_holder_kana)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                bank_code = VALUES(bank_code),
                bank_name_kana = VALUES(bank_name_kana),
                branch_code = VALUES(branch_code),
                branch_name_kana = VALUES(branch_name_kana),
                account_type = VALUES(account_type),
                account_number = VALUES(account_number),
                account_holder_kana = VALUES(account_holder_kana)
        ")->execute([
            $entityType, $entityId,
            $_POST['bank_code'], $_POST['bank_name_kana'],
            $_POST['branch_code'], $_POST['branch_name_kana'],
            (int) ($_POST['account_type'] ?? 1),
            $_POST['account_number'], $_POST['account_holder_kana'],
        ]);
    }
    header('Location: storage_billing.php?msg=bank_saved');
    exit;
}

if ($action === 'update_subscription') {
    $subId  = (int) ($_POST['sub_id'] ?? 0);
    $planId = (int) ($_POST['plan_id'] ?? 0);
    if ($subId && $planId) {
        $pdo->prepare("UPDATE storage_subscriptions SET plan_id = ? WHERE id = ?")->execute([$planId, $subId]);
    }
    header('Location: storage_billing.php?msg=plan_updated');
    exit;
}

// ============================================
// 表示データ取得
// ============================================
$plans = $pdo->query("SELECT * FROM storage_plans WHERE is_active = 1 ORDER BY monthly_price")->fetchAll(PDO::FETCH_ASSOC);

$subscriptions = $pdo->query("
    SELECT ss.*, sp.name AS plan_name, sp.quota_bytes, sp.monthly_price
    FROM storage_subscriptions ss
    JOIN storage_plans sp ON ss.plan_id = sp.id
    ORDER BY ss.entity_type, ss.entity_id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($subscriptions as &$s) {
    $s['usage'] = getStorageUsage($pdo, $s['entity_type'], (int) $s['entity_id']);
    $s['entity_name'] = getEntityName($pdo, $s['entity_type'], (int) $s['entity_id']);
    $s['bank_account'] = getBankAccount($pdo, $s['entity_type'], (int) $s['entity_id']);
}
unset($s);

$currentMonth = date('Y-m');
$billingRecords = $pdo->prepare("
    SELECT br.*, ss.entity_type, ss.entity_id, sp.name AS plan_name
    FROM storage_billing_records br
    JOIN storage_subscriptions ss ON br.subscription_id = ss.id
    JOIN storage_plans sp ON ss.plan_id = sp.id
    WHERE br.billing_month = ?
    ORDER BY br.id
");
$billingRecords->execute([$currentMonth]);
$bills = $billingRecords->fetchAll(PDO::FETCH_ASSOC);

$msg = $_GET['msg'] ?? '';

// ============================================
// ヘルパー
// ============================================
function getEntityName(PDO $pdo, string $type, int $id): string {
    if ($type === 'organization') {
        $s = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
        $s->execute([$id]);
        return $s->fetchColumn() ?: "組織#$id";
    }
    $s = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $s->execute([$id]);
    return $s->fetchColumn() ?: "ユーザー#$id";
}

function getBankAccount(PDO $pdo, string $type, int $id): ?array {
    $s = $pdo->prepare("SELECT * FROM storage_bank_accounts WHERE entity_type = ? AND entity_id = ?");
    $s->execute([$type, $id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>利用料請求 - 管理パネル</title>
    <style>
        <?php adminSidebarCSS(); ?>
        .sb-card { background:#fff; border-radius:12px; padding:24px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
        .sb-card h2 { margin:0 0 16px; font-size:18px; color:#111827; }
        .sb-table { width:100%; border-collapse:collapse; font-size:13px; }
        .sb-table th { text-align:left; padding:10px 12px; background:#f9fafb; border-bottom:2px solid #e5e7eb; font-weight:600; color:#374151; }
        .sb-table td { padding:10px 12px; border-bottom:1px solid #f3f4f6; color:#111827; }
        .sb-table tr:hover td { background:#f9fafb; }
        .sb-btn { padding:6px 14px; border:1px solid #d1d5db; border-radius:6px; background:#fff; cursor:pointer; font-size:12px; transition:background .15s; }
        .sb-btn:hover { background:#f3f4f6; }
        .sb-btn-primary { background:#3b82f6; color:#fff; border-color:#3b82f6; }
        .sb-btn-primary:hover { background:#2563eb; }
        .sb-btn-danger { background:#fef2f2; color:#dc2626; border-color:#fecaca; }
        .sb-btn-danger:hover { background:#fee2e2; }
        .sb-usage-bar { height:6px; background:#e5e7eb; border-radius:3px; overflow:hidden; width:100px; display:inline-block; vertical-align:middle; }
        .sb-usage-fill { height:100%; border-radius:3px; background:#3b82f6; }
        .sb-usage-fill.warn { background:#f59e0b; }
        .sb-usage-fill.danger { background:#ef4444; }
        .sb-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
        .sb-badge-active { background:#dcfce7; color:#166534; }
        .sb-badge-pending { background:#fef3c7; color:#92400e; }
        .sb-badge-billed { background:#dbeafe; color:#1e40af; }
        .sb-badge-paid { background:#dcfce7; color:#166534; }
        .sb-msg { padding:12px 16px; border-radius:8px; background:#dcfce7; color:#166534; margin-bottom:16px; font-size:13px; }
        .sb-actions { display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; }
        .sb-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:1000; align-items:center; justify-content:center; }
        .sb-modal-overlay.active { display:flex; }
        .sb-modal { background:#fff; border-radius:12px; padding:24px; width:90%; max-width:500px; }
        .sb-modal h3 { margin:0 0 16px; }
        .sb-form-group { margin-bottom:12px; }
        .sb-form-group label { display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:4px; }
        .sb-form-group input, .sb-form-group select { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; box-sizing:border-box; }
        .sb-form-actions { display:flex; gap:8px; justify-content:flex-end; margin-top:16px; }
        .sb-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:20px; }
        .sb-stat { background:#fff; border-radius:10px; padding:20px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,.06); }
        .sb-stat-value { font-size:28px; font-weight:700; color:#111827; }
        .sb-stat-label { font-size:12px; color:#6b7280; margin-top:4px; }
    </style>
</head>
<body>
<div class="admin-container">
    <?php adminSidebarHTML($currentPage); ?>
    <main class="main-content">
        <h1 style="margin:0 0 20px;font-size:24px;">💰 利用料請求</h1>

        <?php if ($msg === 'generated'): ?>
        <div class="sb-msg">請求データを <?= (int)($_GET['count'] ?? 0) ?> 件生成しました。</div>
        <?php elseif ($msg === 'bank_saved'): ?>
        <div class="sb-msg">口座情報を保存しました。</div>
        <?php elseif ($msg === 'plan_updated'): ?>
        <div class="sb-msg">プランを変更しました。</div>
        <?php endif; ?>

        <!-- 統計 -->
        <div class="sb-stats">
            <div class="sb-stat">
                <div class="sb-stat-value"><?= count($subscriptions) ?></div>
                <div class="sb-stat-label">契約数</div>
            </div>
            <div class="sb-stat">
                <div class="sb-stat-value"><?= count(array_filter($subscriptions, fn($s) => ($s['monthly_price'] ?? 0) > 0)) ?></div>
                <div class="sb-stat-label">有料契約</div>
            </div>
            <div class="sb-stat">
                <div class="sb-stat-value">¥<?= number_format(array_sum(array_column($bills, 'amount'))) ?></div>
                <div class="sb-stat-label"><?= $currentMonth ?> 請求合計</div>
            </div>
        </div>

        <!-- 料金表（現在設定されている利用料） -->
        <div class="sb-card">
            <h2>📋 料金表（現在の設定）</h2>
            <p style="margin:0 0 12px;font-size:13px;color:#6b7280;">契約・請求の基準となるプランと月額料金です。</p>
            <table class="sb-table">
                <thead>
                    <tr>
                        <th>プラン名</th>
                        <th>容量</th>
                        <th>月額（税込）</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($plans as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['name']) ?></td>
                        <td><?= formatBytes((int)($p['quota_bytes'] ?? 0)) ?></td>
                        <td>¥<?= number_format((int)($p['monthly_price'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($plans)): ?>
                    <tr><td colspan="3" style="text-align:center;color:#9ca3af;padding:24px">プランが登録されていません</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- AI利用料金表（弊社コスト×1.2） -->
        <div class="sb-card">
            <h2>🤖 AI利用料金表（現在の設定）</h2>
            <p style="margin:0 0 12px;font-size:13px;color:#6b7280;">AI秘書・翻訳・タスク検索の請求単価（弊社コストに20%を乗せた金額）です。</p>
            <?php renderAiBillingTable('sb-table'); ?>
        </div>

        <!-- その他サービス料金表（SMS・メール・Places など単価設定時のみ表示） -->
        <?php $otherRates = function_exists('getOtherServiceRates') ? getOtherServiceRates() : []; if (!empty($otherRates)): ?>
        <div class="sb-card">
            <h2>📡 その他サービス料金表</h2>
            <p style="margin:0 0 12px;font-size:13px;color:#6b7280;">SMS・メール・Places API など、単価を設定した項目です。</p>
            <?php renderOtherServiceTable('sb-table'); ?>
        </div>
        <?php endif; ?>

        <!-- 契約一覧 -->
        <div class="sb-card">
            <h2>契約一覧</h2>
            <table class="sb-table">
                <thead>
                    <tr>
                        <th>種別</th>
                        <th>名前</th>
                        <th>プラン</th>
                        <th>使用量</th>
                        <th>月額</th>
                        <th>口座</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($subscriptions as $sub): ?>
                    <?php
                        $isUnlimited = ($sub['entity_type'] === 'organization' && defined('STORAGE_UNLIMITED_ORGANIZATION_IDS') && is_array(STORAGE_UNLIMITED_ORGANIZATION_IDS) && in_array((int)$sub['entity_id'], array_map('intval', STORAGE_UNLIMITED_ORGANIZATION_IDS), true));
                        $pct = $isUnlimited ? 0 : ($sub['quota_bytes'] > 0 ? round($sub['usage'] / $sub['quota_bytes'] * 100) : 0);
                        $barCls = $pct >= 90 ? 'danger' : ($pct >= 80 ? 'warn' : '');
                        $usageDisplay = $isUnlimited ? (formatBytes($sub['usage']) . ' / 無制限') : (formatBytes($sub['usage']) . ' / ' . formatBytes($sub['quota_bytes']));
                        $planDisplay = $isUnlimited ? '無制限' : $sub['plan_name'];
                    ?>
                    <tr>
                        <td><?= $sub['entity_type'] === 'organization' ? '法人' : '個人' ?></td>
                        <td><?= htmlspecialchars($sub['entity_name']) ?></td>
                        <td>
                            <span class="sb-badge sb-badge-active"><?= htmlspecialchars($planDisplay) ?></span>
                        </td>
                        <td>
                            <?php if ($isUnlimited): ?>
                            <span style="font-size:11px"><?= $usageDisplay ?></span>
                            <?php else: ?>
                            <div class="sb-usage-bar"><div class="sb-usage-fill <?= $barCls ?>" style="width:<?= min($pct, 100) ?>%"></div></div>
                            <span style="font-size:11px;margin-left:4px"><?= $usageDisplay ?></span>
                            <?php endif; ?>
                        </td>
                        <td>¥<?= number_format($sub['monthly_price']) ?></td>
                        <td><?= $sub['bank_account'] ? '✓ 登録済' : '<span style="color:#dc2626">未登録</span>' ?></td>
                        <td>
                            <button class="sb-btn" onclick="openBankModal('<?= $sub['entity_type'] ?>',<?= $sub['entity_id'] ?>)">口座編集</button>
                            <button class="sb-btn" onclick="openPlanModal(<?= $sub['id'] ?>,<?= $sub['plan_id'] ?>)">プラン変更</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($subscriptions)): ?>
                    <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:40px">契約データがありません</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 請求管理 -->
        <div class="sb-card">
            <h2><?= $currentMonth ?> 請求一覧</h2>
            <div class="sb-actions">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="generate_bills">
                    <input type="hidden" name="month" value="<?= $currentMonth ?>">
                    <button type="submit" class="sb-btn sb-btn-primary" onclick="return confirm('<?= $currentMonth ?>の請求データを生成しますか？')">請求データ生成</button>
                </form>
                <a href="?action=download_zengin&month=<?= $currentMonth ?>" class="sb-btn sb-btn-primary">全銀データDL</a>
            </div>
            <table class="sb-table">
                <thead>
                    <tr>
                        <th>契約ID</th>
                        <th>プラン</th>
                        <th>金額</th>
                        <th>ステータス</th>
                        <th>全銀出力日</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($bills as $bill): ?>
                    <tr>
                        <td>#<?= $bill['subscription_id'] ?></td>
                        <td><?= htmlspecialchars($bill['plan_name']) ?></td>
                        <td>¥<?= number_format($bill['amount']) ?></td>
                        <td>
                            <span class="sb-badge sb-badge-<?= $bill['status'] ?>"><?= $bill['status'] ?></span>
                        </td>
                        <td><?= $bill['zengin_exported_at'] ?: '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($bills)): ?>
                    <tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:20px">この月の請求データはありません</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>
</div>

<!-- 口座編集モーダル -->
<div class="sb-modal-overlay" id="bankModal">
    <div class="sb-modal">
        <h3>🏦 口座情報編集</h3>
        <form method="POST">
            <input type="hidden" name="action" value="save_bank_account">
            <input type="hidden" name="entity_type" id="bankEntityType">
            <input type="hidden" name="entity_id" id="bankEntityId">
            <div class="sb-form-group">
                <label>金融機関コード (4桁)</label>
                <input type="text" name="bank_code" id="bankCode" maxlength="4" pattern="\d{4}" required placeholder="9900">
            </div>
            <div class="sb-form-group">
                <label>金融機関名 (カナ)</label>
                <input type="text" name="bank_name_kana" id="bankNameKana" maxlength="15" required placeholder="ﾕｳﾁﾖ">
            </div>
            <div class="sb-form-group">
                <label>支店コード (3桁)</label>
                <input type="text" name="branch_code" id="branchCode" maxlength="3" pattern="\d{3}" required placeholder="018">
            </div>
            <div class="sb-form-group">
                <label>支店名 (カナ)</label>
                <input type="text" name="branch_name_kana" id="branchNameKana" maxlength="15" required>
            </div>
            <div class="sb-form-group">
                <label>口座種別</label>
                <select name="account_type" id="bankAccountType">
                    <option value="1">普通</option>
                    <option value="2">当座</option>
                </select>
            </div>
            <div class="sb-form-group">
                <label>口座番号 (7桁)</label>
                <input type="text" name="account_number" id="bankAccountNumber" maxlength="7" pattern="\d{7}" required>
            </div>
            <div class="sb-form-group">
                <label>口座名義 (カナ)</label>
                <input type="text" name="account_holder_kana" id="bankHolderKana" maxlength="30" required>
            </div>
            <div class="sb-form-actions">
                <button type="button" class="sb-btn" onclick="closeBankModal()">キャンセル</button>
                <button type="submit" class="sb-btn sb-btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>

<!-- プラン変更モーダル -->
<div class="sb-modal-overlay" id="planModal">
    <div class="sb-modal">
        <h3>📋 プラン変更</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update_subscription">
            <input type="hidden" name="sub_id" id="planSubId">
            <div class="sb-form-group">
                <label>プラン</label>
                <select name="plan_id" id="planSelect">
                    <?php foreach ($plans as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (¥<?= number_format($p['monthly_price']) ?>/月 - <?= formatBytes((int)$p['quota_bytes']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sb-form-actions">
                <button type="button" class="sb-btn" onclick="closePlanModal()">キャンセル</button>
                <button type="submit" class="sb-btn sb-btn-primary">変更</button>
            </div>
        </form>
    </div>
</div>

<script>
function openBankModal(type, id) {
    document.getElementById('bankEntityType').value = type;
    document.getElementById('bankEntityId').value = id;
    document.getElementById('bankModal').classList.add('active');
}
function closeBankModal() {
    document.getElementById('bankModal').classList.remove('active');
}
function openPlanModal(subId, currentPlanId) {
    document.getElementById('planSubId').value = subId;
    document.getElementById('planSelect').value = currentPlanId;
    document.getElementById('planModal').classList.add('active');
}
function closePlanModal() {
    document.getElementById('planModal').classList.remove('active');
}
</script>
</body>
</html>
