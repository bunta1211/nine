<?php
/**
 * 管理パネル - AI使用量
 *
 * AI種別ごとのユーザー別使用量を閲覧できるページ
 * - AI秘書チャット（ai_usage_logs, feature='chat'）
 * - タスク・メモ検索（ai_usage_logs, feature='task_memo_search'）
 * - 翻訳（translation_usage）
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ai_billing_rates.php';

$currentPage = basename(__FILE__, '.php');
require_once __DIR__ . '/_sidebar.php';

if (!isLoggedIn() || !isOrgAdminUser()) {
    header('Location: ../index.php');
    exit;
}

$pdo = getDB();

// テーブル存在チェック（無ければ自動作成して使用量を可視化できるようにする）
$aiUsageLogsExists = false;
$translationUsageExists = false;
try {
    $t = $pdo->query("SHOW TABLES LIKE 'ai_usage_logs'");
    $aiUsageLogsExists = $t && $t->rowCount() > 0;
    if (!$aiUsageLogsExists) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ai_usage_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                provider VARCHAR(20) DEFAULT 'gemini',
                feature VARCHAR(50),
                input_chars INT UNSIGNED DEFAULT 0,
                output_chars INT UNSIGNED DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_created (created_at),
                INDEX idx_feature (feature)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $t = $pdo->query("SHOW TABLES LIKE 'ai_usage_logs'");
        $aiUsageLogsExists = $t && $t->rowCount() > 0;
    }
    $t = $pdo->query("SHOW TABLES LIKE 'translation_usage'");
    $translationUsageExists = $t && $t->rowCount() > 0;
} catch (PDOException $e) {}

$createTableError = '';

// AI使用量テーブルを作成して機能を有効化（再起動）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_ai_usage_table') {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ai_usage_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                provider VARCHAR(20) DEFAULT 'gemini',
                feature VARCHAR(50),
                input_chars INT UNSIGNED DEFAULT 0,
                output_chars INT UNSIGNED DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        header('Location: ai_usage.php?created=1');
        exit;
    } catch (PDOException $e) {
        $createTableError = $e->getMessage();
    }
}

// 期間フィルタ
$period = $_GET['period'] ?? 'this_month';
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;

$dateFrom = null;
$dateTo = null;

switch ($period) {
    case 'this_month':
        $dateFrom = date('Y-m-01');
        $dateTo = date('Y-m-d');
        break;
    case 'last_month':
        $dateFrom = date('Y-m-01', strtotime('first day of last month'));
        $dateTo = date('Y-m-t', strtotime('last day of last month'));
        break;
    case 'this_week':
        $dateFrom = date('Y-m-d', strtotime('monday this week'));
        $dateTo = date('Y-m-d');
        break;
    case 'last_7_days':
        $dateTo = date('Y-m-d');
        $dateFrom = date('Y-m-d', strtotime('-6 days'));
        break;
    case 'custom':
        if ($startDate && $endDate) {
            $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ? $startDate : null;
            $dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) ? $endDate : null;
        }
        break;
}

if (!$dateFrom || !$dateTo) {
    $dateFrom = date('Y-m-01');
    $dateTo = date('Y-m-d');
}

$dateFrom .= ' 00:00:00';
$dateTo .= ' 23:59:59';

// ユーザー検索
$search = trim($_GET['search'] ?? '');
$sort = $_GET['sort'] ?? 'total_chars';
$order = strtoupper($_GET['order'] ?? 'DESC');
if (!in_array($order, ['ASC', 'DESC'])) {
    $order = 'DESC';
}

$usdToJpy = defined('USD_TO_JPY_RATE') ? USD_TO_JPY_RATE : 154;

// サマリー取得
$summary = [
    'chat_count' => 0,
    'chat_chars' => 0,
    'task_search_count' => 0,
    'translate_count' => 0,
    'translate_chars' => 0,
    'translate_cost_usd' => 0
];

if ($aiUsageLogsExists) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN feature = 'chat' THEN 1 ELSE 0 END) as chat_count,
                SUM(CASE WHEN feature = 'chat' THEN input_chars + output_chars ELSE 0 END) as chat_chars,
                SUM(CASE WHEN feature = 'task_memo_search' THEN 1 ELSE 0 END) as task_search_count
            FROM ai_usage_logs
            WHERE created_at >= ? AND created_at <= ?
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $summary['chat_count'] = (int)($row['chat_count'] ?? 0);
            $summary['chat_chars'] = (int)($row['chat_chars'] ?? 0);
            $summary['task_search_count'] = (int)($row['task_search_count'] ?? 0);
        }
    } catch (PDOException $e) {}
}

if ($translationUsageExists) {
    try {
        $costColumn = '';
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM translation_usage LIKE 'cost_usd'") as $c) {
            $costColumn = 'cost_usd';
            break;
        }
        $selectCost = $costColumn ? ", COALESCE(SUM(cost_usd), 0) as cost_usd" : "";
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as cnt, COALESCE(SUM(character_count), 0) as chars {$selectCost}
            FROM translation_usage
            WHERE created_at >= ? AND created_at <= ?
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $summary['translate_count'] = (int)($row['cnt'] ?? 0);
            $summary['translate_chars'] = (int)($row['chars'] ?? 0);
            $summary['translate_cost_usd'] = isset($row['cost_usd']) ? (float)$row['cost_usd'] : 0;
        }
    } catch (PDOException $e) {}
}

// ユーザー別集計
$userStats = [];

if ($aiUsageLogsExists || $translationUsageExists) {
    $userIds = [];

    if ($aiUsageLogsExists) {
        $stmt = $pdo->prepare("SELECT DISTINCT user_id FROM ai_usage_logs WHERE created_at >= ? AND created_at <= ?");
        $stmt->execute([$dateFrom, $dateTo]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $userIds[(int)$uid] = true;
        }
    }
    if ($translationUsageExists) {
        $stmt = $pdo->prepare("SELECT DISTINCT user_id FROM translation_usage WHERE created_at >= ? AND created_at <= ?");
        $stmt->execute([$dateFrom, $dateTo]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $userIds[(int)$uid] = true;
        }
    }

    $userIds = array_keys($userIds);
    if (!empty($userIds)) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $userWhere = " AND u.id IN ($placeholders)";

        if ($search) {
            $userWhere .= " AND (u.display_name LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)";
        }

        $params = array_merge($userIds, $search ? ["%{$search}%", "%{$search}%", "%{$search}%"] : []);

        $stmt = $pdo->prepare("
            SELECT u.id, u.display_name, u.email, u.full_name
            FROM users u
            WHERE u.id IN ($placeholders) " . ($search ? "AND (u.display_name LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)" : "") . "
            ORDER BY u.display_name
        ");
        $stmt->execute($search ? array_merge($userIds, ["%{$search}%", "%{$search}%", "%{$search}%"]) : $userIds);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($users as $u) {
            $uid = (int)$u['id'];
            $userStats[$uid] = [
                'id' => $uid,
                'display_name' => $u['display_name'] ?: $u['full_name'] ?: 'ID:' . $uid,
                'email' => $u['email'] ?? '',
                'chat_count' => 0,
                'chat_chars' => 0,
                'task_search_count' => 0,
                'translate_count' => 0,
                'translate_chars' => 0,
                'translate_cost_usd' => 0
            ];
        }

        if ($aiUsageLogsExists && !empty($userStats)) {
            $uids = array_keys($userStats);
            $ph = implode(',', array_fill(0, count($uids), '?'));
            $stmt = $pdo->prepare("
                SELECT user_id,
                    SUM(CASE WHEN feature = 'chat' THEN 1 ELSE 0 END) as chat_count,
                    SUM(CASE WHEN feature = 'chat' THEN input_chars + output_chars ELSE 0 END) as chat_chars,
                    SUM(CASE WHEN feature = 'task_memo_search' THEN 1 ELSE 0 END) as task_search_count
                FROM ai_usage_logs
                WHERE created_at >= ? AND created_at <= ? AND user_id IN ($ph)
                GROUP BY user_id
            ");
            $stmt->execute(array_merge([$dateFrom, $dateTo], $uids));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $uid = (int)$row['user_id'];
                if (isset($userStats[$uid])) {
                    $userStats[$uid]['chat_count'] = (int)$row['chat_count'];
                    $userStats[$uid]['chat_chars'] = (int)$row['chat_chars'];
                    $userStats[$uid]['task_search_count'] = (int)$row['task_search_count'];
                }
            }
        }

        if ($translationUsageExists && !empty($userStats)) {
            $uids = array_keys($userStats);
            $ph = implode(',', array_fill(0, count($uids), '?'));
            $costCol = '';
            foreach ($pdo->query("SHOW COLUMNS FROM translation_usage LIKE 'cost_usd'") as $c) {
                $costCol = ', COALESCE(SUM(cost_usd), 0) as cost_usd';
                break;
            }
            $stmt = $pdo->prepare("
                SELECT user_id, COUNT(*) as cnt, COALESCE(SUM(character_count), 0) as chars {$costCol}
                FROM translation_usage
                WHERE created_at >= ? AND created_at <= ? AND user_id IN ($ph)
                GROUP BY user_id
            ");
            $stmt->execute(array_merge([$dateFrom, $dateTo], $uids));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $uid = (int)$row['user_id'];
                if (isset($userStats[$uid])) {
                    $userStats[$uid]['translate_count'] = (int)$row['cnt'];
                    $userStats[$uid]['translate_chars'] = (int)$row['chars'];
                    $userStats[$uid]['translate_cost_usd'] = isset($row['cost_usd']) ? (float)$row['cost_usd'] : 0;
                }
            }
        }

        foreach ($userStats as &$s) {
            $s['total_chars'] = $s['chat_chars'] + $s['translate_chars'];
        }
        unset($s);

        // ソート
        $validSort = ['total_chars', 'chat_count', 'chat_chars', 'translate_count', 'translate_chars', 'task_search_count', 'display_name'];
        if (!in_array($sort, $validSort)) {
            $sort = 'total_chars';
        }
        uasort($userStats, function ($a, $b) use ($sort, $order) {
            $va = $a[$sort] ?? 0;
            $vb = $b[$sort] ?? 0;
            if (is_string($va)) {
                $c = strcmp($va, $vb);
                return $order === 'ASC' ? $c : -$c;
            }
            if ($order === 'ASC') {
                return $va <=> $vb;
            }
            return $vb <=> $va;
        });
    }
}

// CSVエクスポート
$export = $_GET['export'] ?? '';
if ($export === 'csv' && !empty($userStats)) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ai_usage_' . date('Y-m-d') . '.csv"');
    $fp = fopen('php://output', 'w');
    fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($fp, ['ユーザー名', 'メール', 'AI秘書回数', 'AI秘書文字数', 'タスク検索回数', '翻訳回数', '翻訳文字数', '翻訳コストUSD', '合計文字数']);
    foreach ($userStats as $s) {
        fputcsv($fp, [
            $s['display_name'],
            $s['email'],
            $s['chat_count'],
            $s['chat_chars'],
            $s['task_search_count'],
            $s['translate_count'],
            $s['translate_chars'],
            round($s['translate_cost_usd'], 4),
            $s['total_chars']
        ]);
    }
    fclose($fp);
    exit;
}

$translateCostJpy = round($summary['translate_cost_usd'] * $usdToJpy);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI使用量 - 管理パネル | Social9</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <style>
        body { background: var(--bg-secondary); }
        <?php adminSidebarCSS(); ?>
        .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
        .page-header h2 { font-size: 24px; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
        }
        .stat-card .label { font-size: 12px; color: var(--text-muted); margin-bottom: 4px; }
        .stat-card .value { font-size: 24px; font-weight: 700; color: var(--text-primary); }
        .stat-card .sub { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
        .filters {
            background: white;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filters input, .filters select {
            padding: 8px 12px;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            font-size: 14px;
        }
        .filters input[type="text"] { min-width: 200px; }
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            overflow-x: auto;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border-light); }
        th { background: var(--bg-secondary); font-weight: 600; font-size: 13px; color: var(--text-muted); white-space: nowrap; }
        th a { color: inherit; text-decoration: none; }
        th a:hover { text-decoration: underline; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .empty-msg { padding: 40px; text-align: center; color: var(--text-muted); }
        .empty-msg p { margin: 0 0 8px 0; }
        .empty-msg-hint { font-size: 13px; opacity: 0.9; max-width: 480px; margin: 12px auto 0 !important; }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php adminSidebarHTML($currentPage); ?>

        <main class="main-content">
            <div class="page-header">
                <h2>🤖 AI使用量</h2>
                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <?php if ($aiUsageLogsExists && defined('AI_USAGE_LOGGING_ENABLED') && AI_USAGE_LOGGING_ENABLED): ?>
                        <span style="font-size: 13px; color: #059669;">● ログ記録: 有効</span>
                    <?php elseif ($aiUsageLogsExists): ?>
                        <span style="font-size: 13px; color: #6b7280;">○ ログ記録: 無効（config で有効にしてください）</span>
                    <?php endif; ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-primary btn-sm">CSVエクスポート</a>
                </div>
            </div>

            <?php if (isset($_GET['created']) && $_GET['created'] === '1'): ?>
                <div class="stat-card" style="background: #dcfce7; color: #166534;">
                    <p>AI使用量テーブル（ai_usage_logs）を作成しました。機能が有効になりました。</p>
                </div>
            <?php endif; ?>
            <?php if (!$aiUsageLogsExists && !$translationUsageExists): ?>
                <div class="stat-card">
                    <p>AI使用量のテーブル（ai_usage_logs）が存在しません。下のボタンでテーブルを作成し、機能を有効にしてください。</p>
                    <?php if (!empty($createTableError)): ?>
                        <p style="color: #dc2626; font-size: 13px;">作成エラー: <?= htmlspecialchars($createTableError) ?></p>
                    <?php endif; ?>
                    <form method="post" style="margin-top: 12px;">
                        <input type="hidden" name="action" value="create_ai_usage_table">
                        <button type="submit" class="btn btn-primary">AI使用量テーブルを作成して機能を有効にする</button>
                    </form>
                </div>
            <?php else: ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="label">AI秘書チャット</div>
                    <div class="value"><?= number_format($summary['chat_count']) ?> 回</div>
                    <div class="sub"><?= number_format($summary['chat_chars']) ?> 文字</div>
                </div>
                <div class="stat-card">
                    <div class="label">翻訳</div>
                    <div class="value"><?= number_format($summary['translate_count']) ?> 回</div>
                    <div class="sub"><?= number_format($summary['translate_chars']) ?> 文字</div>
                </div>
                <div class="stat-card">
                    <div class="label">タスク・メモ検索</div>
                    <div class="value"><?= number_format($summary['task_search_count']) ?> 回</div>
                </div>
                <div class="stat-card">
                    <div class="label">推定コスト（翻訳）</div>
                    <div class="value">約 <?= number_format($translateCostJpy) ?> 円</div>
                    <div class="sub">$<?= number_format($summary['translate_cost_usd'], 2) ?> USD</div>
                </div>
            </div>

            <!-- AI利用料金表（弊社コスト×1.2） -->
            <div class="stat-card" style="grid-column: 1 / -1; text-align: left; padding: 20px;">
                <h3 style="margin: 0 0 12px; font-size: 16px;">🤖 AI利用料金表（現在の設定）</h3>
                <p style="margin: 0 0 12px; font-size: 13px; color: var(--text-muted);">AI秘書・翻訳・タスク検索の請求単価（弊社コストに20%を乗せた金額）です。</p>
                <div class="table-container" style="max-width: 640px;">
                    <?php
                    if (function_exists('renderAiBillingTable')) {
                        renderAiBillingTable('sb-table');
                    }
                    ?>
                </div>
            </div>

            <form class="filters" method="GET">
                <?php if ($period === 'custom'): ?>
                    <input type="date" name="start_date" value="<?= htmlspecialchars(substr($dateFrom, 0, 10)) ?>">
                    <input type="date" name="end_date" value="<?= htmlspecialchars(substr($dateTo, 0, 10)) ?>">
                <?php endif; ?>
                <select name="period" onchange="this.form.submit()">
                    <option value="this_month" <?= $period === 'this_month' ? 'selected' : '' ?>>今月</option>
                    <option value="last_month" <?= $period === 'last_month' ? 'selected' : '' ?>>先月</option>
                    <option value="this_week" <?= $period === 'this_week' ? 'selected' : '' ?>>今週</option>
                    <option value="last_7_days" <?= $period === 'last_7_days' ? 'selected' : '' ?>>過去7日</option>
                    <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>カスタム</option>
                </select>
                <input type="text" name="search" placeholder="ユーザー名・メールで検索" value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn btn-primary btn-sm">絞り込み</button>
            </form>

            <div class="table-container">
                <?php if (empty($userStats)): ?>
                    <div class="empty-msg">
                        <p>該当する使用データがありません。</p>
                        <p class="empty-msg-hint">ユーザーがAI秘書チャット・タスク・メモ検索・翻訳を利用すると、ここに使用量が表示されます。ログは利用のたびに自動で記録されます。</p>
                    </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th><a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'display_name', 'order' => ($sort === 'display_name' && $order === 'ASC') ? 'DESC' : 'ASC'])) ?>">ユーザー</a></th>
                            <th class="num"><a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'chat_count', 'order' => ($sort === 'chat_count' && $order === 'ASC') ? 'DESC' : 'ASC'])) ?>">AI秘書</a></th>
                            <th class="num">AI秘書文字</th>
                            <th class="num"><a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'task_search_count', 'order' => ($sort === 'task_search_count' && $order === 'ASC') ? 'DESC' : 'ASC'])) ?>">タスク検索</a></th>
                            <th class="num"><a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'translate_count', 'order' => ($sort === 'translate_count' && $order === 'ASC') ? 'DESC' : 'ASC'])) ?>">翻訳</a></th>
                            <th class="num">翻訳文字</th>
                            <th class="num"><a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'total_chars', 'order' => ($sort === 'total_chars' && $order === 'ASC') ? 'DESC' : 'ASC'])) ?>">合計文字</a></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($userStats as $s): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($s['display_name']) ?></strong>
                                <?php if ($s['email']): ?>
                                    <br><small style="color:var(--text-muted)"><?= htmlspecialchars($s['email']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= number_format($s['chat_count']) ?></td>
                            <td class="num"><?= number_format($s['chat_chars']) ?></td>
                            <td class="num"><?= number_format($s['task_search_count']) ?></td>
                            <td class="num"><?= number_format($s['translate_count']) ?></td>
                            <td class="num"><?= number_format($s['translate_chars']) ?></td>
                            <td class="num"><?= number_format($s['total_chars']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <?php endif; ?>
        </main>
    </div>
<script src="../assets/js/admin-sidebar-sort.js"></script>
</body>
</html>
