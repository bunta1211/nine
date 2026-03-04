<?php
/**
 * エラーチェック
 * 
 * エラーログ、ヘルスチェック、API使用状況を可視化
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/lang.php';

$currentPage = 'monitor';
require_once __DIR__ . '/_sidebar.php';

// 管理者チェック（developer, admin, system_admin, super_admin）
$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['developer', 'admin', 'system_admin', 'super_admin'])) {
    header('Location: ../index.php');
    exit;
}

// データベース接続
$pdo = getDB();

$currentLang = $_SESSION['lang'] ?? 'ja';

// ヘルスチェックデータを取得
$healthData = [];
try {
    $response = file_get_contents('http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/../api/health.php?action=full');
    $healthData = json_decode($response, true);
} catch (Exception $e) {
    $healthData = ['status' => 'error', 'message' => 'Failed to fetch health data'];
}

// エラーログを取得（ユーザー名も結合）
$errorLogs = [];
$errorStats = ['total' => 0, 'js' => 0, 'api' => 0];
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'error_logs'");
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->query("
            SELECT el.*, u.display_name AS user_name
            FROM error_logs el
            LEFT JOIN users u ON el.user_id = u.id
            WHERE el.is_resolved = 0
            ORDER BY el.last_occurred_at DESC 
            LIMIT 50
        ");
        $errorLogs = $stmt->fetchAll();
        
        $stmt = $pdo->query("
            SELECT error_type, COUNT(*) as count, SUM(occurrence_count) as total
            FROM error_logs 
            WHERE is_resolved = 0
            AND last_occurred_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY error_type
        ");
        while ($row = $stmt->fetch()) {
            $errorStats[$row['error_type']] = (int)$row['total'];
            $errorStats['total'] += (int)$row['total'];
        }
    }
} catch (PDOException $e) {
    // テーブルなし
}
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>エラーチェック - Social9</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        <?php adminSidebarCSS(); ?>
        .monitor-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .monitor-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .monitor-title {
            font-size: 24px;
            font-weight: bold;
            color: #1f2937;
        }
        
        .refresh-btn {
            padding: 10px 20px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }
        
        .status-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .status-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .status-card.ok {
            border-left: 4px solid #22c55e;
        }
        
        .status-card.warning {
            border-left: 4px solid #f59e0b;
        }
        
        .status-card.error {
            border-left: 4px solid #ef4444;
        }
        
        .status-card-title {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 8px;
        }
        
        .status-card-value {
            font-size: 24px;
            font-weight: bold;
            color: #1f2937;
        }
        
        .status-card-detail {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 8px;
        }
        
        .section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .section-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 20px;
            color: #1f2937;
        }
        
        .error-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .error-table th,
        .error-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .error-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        
        .error-type {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .error-type.js {
            background: #fef3c7;
            color: #92400e;
        }
        
        .error-type.api {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .error-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 24px;
            height: 24px;
            padding: 0 8px;
            background: #fee2e2;
            color: #dc2626;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .error-message {
            max-width: 400px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-family: monospace;
            font-size: 13px;
            cursor: pointer;
        }
        .error-message:hover { color: #3b82f6; }
        
        .error-url {
            max-width: 180px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 12px;
            color: #6b7280;
        }

        .error-user {
            font-size: 12px;
            color: #6b7280;
            white-space: nowrap;
        }

        .error-date {
            font-size: 12px;
            white-space: nowrap;
        }
        
        .resolve-btn {
            padding: 4px 12px;
            background: #10b981;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }

        .resolve-selected-btn {
            padding: 8px 16px;
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            display: none;
        }
        .resolve-selected-btn:hover { background: #dc2626; }

        .error-detail-row td {
            padding: 0 12px 12px 12px;
            border-bottom: 2px solid #e5e7eb;
        }
        .error-detail-content {
            background: #f9fafb;
            border-radius: 8px;
            padding: 12px;
            font-size: 12px;
            font-family: monospace;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 200px;
            overflow-y: auto;
            color: #374151;
        }
        .error-detail-content .detail-label {
            font-weight: 600;
            color: #6b7280;
            font-family: sans-serif;
        }
        .error-detail-content .detail-section {
            margin-bottom: 8px;
        }

        .error-table input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        
        .health-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .health-item {
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
        }
        
        .health-item.ok .health-status { color: #22c55e; }
        .health-item.warning .health-status { color: #f59e0b; }
        .health-item.error .health-status { color: #ef4444; }
        
        .health-name {
            font-weight: 600;
            color: #374151;
            margin-bottom: 4px;
        }
        
        .health-status {
            font-size: 14px;
        }
        
        .health-message {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #9ca3af;
        }
        
        
        .quick-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .quick-action-btn {
            padding: 8px 16px;
            border: 1px solid #e5e7eb;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            color: #374151;
        }
        
        .quick-action-btn:hover {
            background: #f9fafb;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php adminSidebarHTML($currentPage); ?>
        <main class="main-content">
            <div class="monitor-container">
        
        <div class="monitor-header">
            <h1 class="monitor-title">エラーチェック</h1>
            <button class="refresh-btn" onclick="location.reload()">更新</button>
        </div>
        
        <!-- クイックアクション -->
        <div class="quick-actions">
            <button class="quick-action-btn" onclick="runHealthCheck()">ヘルスチェック実行</button>
            <button class="quick-action-btn" onclick="resolveAllErrors()">全エラーを解決済みにする</button>
            <button class="quick-action-btn" onclick="downloadErrorLog()">エラーログをダウンロード</button>
        </div>
        
        <!-- ステータスカード -->
        <div class="status-cards">
            <div class="status-card <?= ($healthData['status'] ?? 'error') ?>">
                <div class="status-card-title">全体ステータス</div>
                <div class="status-card-value">
                    <?php
                    $statusText = ['ok' => '正常', 'warning' => '警告', 'error' => 'エラー'];
                    echo $statusText[$healthData['status'] ?? 'error'] ?? '不明';
                    ?>
                </div>
                <div class="status-card-detail">
                    最終更新: <?= date('H:i:s') ?>
                </div>
            </div>
            
            <div class="status-card <?= $errorStats['total'] > 50 ? 'error' : ($errorStats['total'] > 10 ? 'warning' : 'ok') ?>">
                <div class="status-card-title">24時間のエラー</div>
                <div class="status-card-value"><?= $errorStats['total'] ?>件</div>
                <div class="status-card-detail">
                    JS: <?= $errorStats['js'] ?> / API: <?= $errorStats['api'] ?>
                </div>
            </div>
            
            <?php if (isset($healthData['checks']['active_users'])): ?>
            <div class="status-card ok">
                <div class="status-card-title">アクティブユーザー</div>
                <div class="status-card-value"><?= $healthData['checks']['active_users']['online_now'] ?? 0 ?>人</div>
                <div class="status-card-detail">
                    24時間: <?= $healthData['checks']['active_users']['active_24h'] ?? 0 ?>人
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (isset($healthData['checks']['disk'])): ?>
            <div class="status-card <?= $healthData['checks']['disk']['status'] ?>">
                <div class="status-card-title">ディスク使用量</div>
                <div class="status-card-value"><?= $healthData['checks']['disk']['uploads_size_mb'] ?? 0 ?>MB</div>
                <div class="status-card-detail">
                    空き: <?= $healthData['checks']['disk']['free_space_gb'] ?? 0 ?>GB
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- ヘルスチェック詳細 -->
        <div class="section">
            <h2 class="section-title">ヘルスチェック</h2>
            <div class="health-grid">
                <?php if (isset($healthData['checks'])): ?>
                    <?php foreach ($healthData['checks'] as $name => $check): ?>
                    <div class="health-item <?= $check['status'] ?? 'ok' ?>">
                        <div class="health-name"><?= htmlspecialchars($name) ?></div>
                        <div class="health-status">
                            <?php
                            $icons = ['ok' => '✓', 'warning' => '⚠', 'error' => '✕'];
                            echo $icons[$check['status'] ?? 'ok'] ?? '';
                            echo ' ' . ($check['status'] ?? 'ok');
                            ?>
                        </div>
                        <div class="health-message"><?= htmlspecialchars($check['message'] ?? '') ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-data">ヘルスチェックデータを取得できませんでした</div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- エラーログ -->
        <div class="section">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                <h2 class="section-title" style="margin-bottom:0;">最新のエラー（未解決）<?php if (!empty($errorLogs)): ?> <small style="color:#9ca3af;font-weight:normal;"><?= count($errorLogs) ?>件</small><?php endif; ?></h2>
                <button class="resolve-selected-btn" id="resolveSelectedBtn" onclick="resolveSelected()">選択したエラーを解決</button>
            </div>
            <?php if (empty($errorLogs)): ?>
                <div class="no-data">
                    エラーはありません
                    <br><small>エラーログテーブルが初期化されていない可能性があります</small>
                </div>
            <?php else: ?>
            <table class="error-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
                        <th>種別</th>
                        <th>回数</th>
                        <th>エラーメッセージ（クリックで詳細）</th>
                        <th>URL</th>
                        <th>ユーザー</th>
                        <th>初回発生</th>
                        <th>最終発生</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($errorLogs as $error):
                        $extraData = $error['extra_data'] ? json_decode($error['extra_data'], true) : null;
                        $urlPath = $error['url'] ?? '-';
                        if ($urlPath !== '-') {
                            $parsed = parse_url($urlPath);
                            $urlPath = ($parsed['path'] ?? '') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
                        }
                    ?>
                    <tr data-error-id="<?= $error['id'] ?>">
                        <td><input type="checkbox" class="error-checkbox" value="<?= $error['id'] ?>" onchange="updateSelectedCount()"></td>
                        <td>
                            <span class="error-type <?= $error['error_type'] ?>">
                                <?= strtoupper($error['error_type']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="error-count"><?= $error['occurrence_count'] ?></span>
                        </td>
                        <td class="error-message" title="クリックで詳細表示" onclick="toggleDetail(<?= $error['id'] ?>)">
                            <?= htmlspecialchars($error['error_message']) ?>
                        </td>
                        <td class="error-url" title="<?= htmlspecialchars($error['url'] ?? '') ?>">
                            <?= htmlspecialchars($urlPath) ?>
                        </td>
                        <td class="error-user"><?= htmlspecialchars($error['user_name'] ?? ($error['user_id'] ? 'ID:' . $error['user_id'] : '-')) ?></td>
                        <td class="error-date"><?= date('m/d H:i', strtotime($error['first_occurred_at'])) ?></td>
                        <td class="error-date"><?= date('m/d H:i', strtotime($error['last_occurred_at'])) ?></td>
                        <td>
                            <button class="resolve-btn" onclick="resolveError(<?= $error['id'] ?>, this)">解決</button>
                        </td>
                    </tr>
                    <tr class="error-detail-row" id="detail-<?= $error['id'] ?>" style="display:none;">
                        <td colspan="9">
                            <div class="error-detail-content">
                                <div class="detail-section"><span class="detail-label">メッセージ:</span> <?= htmlspecialchars($error['error_message']) ?></div>
                                <?php if (!empty($error['error_stack'])): ?>
                                <div class="detail-section"><span class="detail-label">スタック:</span>
<?= htmlspecialchars($error['error_stack']) ?></div>
                                <?php endif; ?>
                                <?php if ($extraData): ?>
                                <div class="detail-section"><span class="detail-label">追加情報:</span>
<?= htmlspecialchars(json_encode($extraData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></div>
                                <?php endif; ?>
                                <div class="detail-section"><span class="detail-label">URL:</span> <?= htmlspecialchars($error['url'] ?? '-') ?></div>
                                <div class="detail-section"><span class="detail-label">ユーザーエージェント:</span> <?= htmlspecialchars($error['user_agent'] ?? '-') ?></div>
                                <div class="detail-section"><span class="detail-label">IP:</span> <?= htmlspecialchars($error['ip_address'] ?? '-') ?></div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
            </div>
        </main>
    </div>
    
    <script>
        function runHealthCheck() {
            fetch('../api/health.php?action=full')
                .then(r => r.json())
                .then(data => {
                    alert('ヘルスチェック完了\nステータス: ' + data.status);
                    location.reload();
                })
                .catch(e => alert('エラー: ' + e.message));
        }

        function resolveError(id, btn) {
            if (btn) { btn.disabled = true; btn.textContent = '...'; }
            fetch('../api/error-log.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'resolve', id: id })
            }).then(() => {
                const row = document.querySelector('tr[data-error-id="' + id + '"]');
                const detailRow = document.getElementById('detail-' + id);
                if (row) row.style.display = 'none';
                if (detailRow) detailRow.style.display = 'none';
            }).catch(e => {
                if (btn) { btn.disabled = false; btn.textContent = '解決'; }
                alert('エラー: ' + e.message);
            });
        }

        function resolveAllErrors() {
            if (!confirm('全ての未解決エラーを解決済みにしますか？')) return;
            fetch('../api/error-log.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'resolve_all' })
            }).then(() => location.reload())
            .catch(e => alert('エラー: ' + e.message));
        }

        function resolveSelected() {
            const checked = document.querySelectorAll('.error-checkbox:checked');
            if (checked.length === 0) return;
            const ids = Array.from(checked).map(cb => parseInt(cb.value));
            const btn = document.getElementById('resolveSelectedBtn');
            btn.disabled = true;
            btn.textContent = '解決中...';
            fetch('../api/error-log.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'resolve_batch', ids: ids })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    ids.forEach(id => {
                        const row = document.querySelector('tr[data-error-id="' + id + '"]');
                        const detail = document.getElementById('detail-' + id);
                        if (row) row.style.display = 'none';
                        if (detail) detail.style.display = 'none';
                    });
                    btn.style.display = 'none';
                    document.getElementById('selectAll').checked = false;
                } else {
                    alert('解決に失敗しました: ' + (data.error || ''));
                    btn.disabled = false;
                    btn.textContent = '選択した ' + ids.length + ' 件を解決';
                }
            })
            .catch(e => {
                alert('エラー: ' + e.message);
                btn.disabled = false;
                btn.textContent = '選択した ' + ids.length + ' 件を解決';
            });
        }

        function toggleSelectAll(el) {
            document.querySelectorAll('.error-checkbox').forEach(cb => { cb.checked = el.checked; });
            updateSelectedCount();
        }

        function updateSelectedCount() {
            const count = document.querySelectorAll('.error-checkbox:checked').length;
            const btn = document.getElementById('resolveSelectedBtn');
            if (count > 0) {
                btn.style.display = 'inline-block';
                btn.textContent = '選択した ' + count + ' 件を解決';
            } else {
                btn.style.display = 'none';
            }
        }

        function toggleDetail(id) {
            const row = document.getElementById('detail-' + id);
            if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
        }

        function downloadErrorLog() {
            fetch('../api/test-helper.php?action=errors&limit=100')
                .then(r => r.json())
                .then(data => {
                    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'error-log-' + new Date().toISOString().slice(0, 10) + '.json';
                    a.click();
                    URL.revokeObjectURL(url);
                });
        }
    </script>
    <script src="../assets/js/admin-sidebar-sort.js"></script>
</body>
</html>
