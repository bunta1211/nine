<?php
/**
 * 管理パネル - システムログ
 * 仕様書: 13_管理機能.md
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$currentPage = basename(__FILE__, '.php');
require_once __DIR__ . '/_sidebar.php';

// 管理者権限チェック
if (!isLoggedIn() || !isOrgAdminUser()) {
    header('Location: ../index.php');
    exit;
}

// ログディレクトリ（存在しなければ作成して後で書き込めるようにする）
$log_dir = __DIR__ . '/../logs/';
$log_files = [];
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}
if (is_dir($log_dir)) {
    $files = scandir($log_dir, SCANDIR_SORT_DESCENDING);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'log') {
            $log_files[] = [
                'name' => $file,
                'path' => $log_dir . $file,
                'size' => filesize($log_dir . $file),
                'modified' => filemtime($log_dir . $file)
            ];
        }
    }
}

// ログの内容を取得
$selected_log = $_GET['file'] ?? '';
$log_content = '';
$log_lines = [];

if ($selected_log && in_array($selected_log, array_column($log_files, 'name'))) {
    $file_path = $log_dir . $selected_log;
    if (file_exists($file_path)) {
        $log_content = file_get_contents($file_path);
        $lines = explode("\n", $log_content);
        $lines = array_reverse(array_filter($lines));
        $log_lines = array_slice($lines, 0, 500); // 最新500行
    }
}

// フィルター
$level_filter = $_GET['level'] ?? '';
if ($level_filter && !empty($log_lines)) {
    $log_lines = array_filter($log_lines, function($line) use ($level_filter) {
        return stripos($line, "[$level_filter]") !== false;
    });
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>システムログ - 管理パネル | Social9</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <style>
        body { background: var(--bg-secondary); font-family: 'Hiragino Sans', 'Meiryo', sans-serif; }
        <?php adminSidebarCSS(); ?>
        
        .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .page-header h2 { font-size: 24px; }
        
        .content-layout { display: grid; grid-template-columns: 250px 1fr; gap: 20px; }
        
        .file-list {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            padding: 16px;
        }
        
        .file-list h4 { font-size: 14px; margin-bottom: 12px; color: var(--text-muted); }
        
        .file-item {
            display: block;
            padding: 12px;
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-primary);
            margin-bottom: 4px;
            transition: background 0.2s;
        }
        .file-item:hover { background: var(--bg-secondary); }
        .file-item.active { background: var(--primary-bg); color: var(--primary); }
        .file-item .name { font-size: 14px; font-weight: 500; }
        .file-item .meta { font-size: 11px; color: var(--text-muted); margin-top: 4px; }
        
        .log-viewer {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        
        .log-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .log-header h4 { font-size: 16px; }
        
        .log-filters { display: flex; gap: 8px; }
        .log-filters select { padding: 6px 12px; border: 1px solid var(--border-light); border-radius: 6px; font-size: 13px; }
        
        .log-content {
            background: #1a1a2e;
            color: #e0e0e0;
            padding: 20px;
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 12px;
            line-height: 1.6;
            max-height: 600px;
            overflow-y: auto;
        }
        
        .log-line { margin-bottom: 2px; padding: 2px 0; }
        .log-line:hover { background: rgba(255,255,255,0.05); }
        
        .log-error { color: #ef4444; }
        .log-warning { color: #f59e0b; }
        .log-info { color: #3b82f6; }
        .log-debug { color: #9ca3af; }
        
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state .icon { font-size: 48px; margin-bottom: 12px; }
        
        .stats-row { display: flex; gap: 16px; margin-bottom: 20px; }
        .stat-item {
            background: white;
            border-radius: 10px;
            padding: 16px 20px;
            box-shadow: var(--shadow-sm);
        }
        .stat-item .value { font-size: 24px; font-weight: 600; }
        .stat-item .label { font-size: 13px; color: var(--text-muted); }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php adminSidebarHTML($currentPage); ?>
        
        <main class="main-content">
            <div class="page-header">
                <h2>📝 システムログ</h2>
            </div>
            
            <div class="log-description" style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 14px; color: #0c4a6e;">
                <strong>表示されるログ</strong><br>
                <code>logs/</code> フォルダ内の <code>*.log</code> ファイルを一覧表示します。<br>
                アプリでは <code>includes/logger.php</code> により、次の種類のログが日付別ファイルで出力されます。<br>
                ・<strong>app_YYYY-MM-DD.log</strong> … 通常の情報・警告・デバッグ（logInfo / logWarning / logDebug）<br>
                ・<strong>error_YYYY-MM-DD.log</strong> … エラー（logError / logException）<br>
                ・<strong>audit_YYYY-MM-DD.log</strong> … 監査（logAudit：ログイン・操作履歴など）<br>
                ログは、これらの関数が呼ばれたタイミングで初めてファイルが作成されます。
            </div>
            
            <div class="stats-row">
                <div class="stat-item">
                    <div class="value"><?= count($log_files) ?></div>
                    <div class="label">ログファイル数</div>
                </div>
                <div class="stat-item">
                    <div class="value"><?= count($log_lines) ?></div>
                    <div class="label">表示中の行数</div>
                </div>
            </div>
            
            <div class="content-layout">
                <div class="file-list">
                    <h4>ログファイル</h4>
                    <?php if (empty($log_files)): ?>
                    <p style="font-size: 13px; color: var(--text-muted);">ログファイルはありません</p>
                    <p style="font-size: 12px; color: var(--text-muted); margin-top: 8px;">まだアプリからログが1件も出力されていないため、表示するファイルがありません。ログイン・APIエラー・監査記録などが発生すると、<code>logs/</code> に日付別の .log ファイルが自動作成されます。</p>
                    <?php else: ?>
                        <?php foreach ($log_files as $file): ?>
                        <a href="?file=<?= urlencode($file['name']) ?>" class="file-item <?= $selected_log === $file['name'] ? 'active' : '' ?>">
                            <div class="name">📄 <?= htmlspecialchars($file['name']) ?></div>
                            <div class="meta">
                                <?= number_format($file['size'] / 1024, 1) ?> KB • 
                                <?= date('m/d H:i', $file['modified']) ?>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div class="log-viewer">
                    <div class="log-header">
                        <h4><?= $selected_log ? htmlspecialchars($selected_log) : 'ログを選択してください' ?></h4>
                        <?php if ($selected_log): ?>
                        <div class="log-filters">
                            <select onchange="location.href='?file=<?= urlencode($selected_log) ?>&level='+this.value">
                                <option value="">すべてのレベル</option>
                                <option value="ERROR" <?= $level_filter === 'ERROR' ? 'selected' : '' ?>>ERROR</option>
                                <option value="WARNING" <?= $level_filter === 'WARNING' ? 'selected' : '' ?>>WARNING</option>
                                <option value="INFO" <?= $level_filter === 'INFO' ? 'selected' : '' ?>>INFO</option>
                                <option value="DEBUG" <?= $level_filter === 'DEBUG' ? 'selected' : '' ?>>DEBUG</option>
                            </select>
                            <button onclick="location.reload()" style="padding:6px 12px;border:1px solid var(--border-light);border-radius:6px;background:white;cursor:pointer;">🔄 更新</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="log-content">
                        <?php if (empty($log_lines) && !$selected_log): ?>
                        <div class="empty-state" style="color: #9ca3af;">
                            <div class="icon">📝</div>
                            <p>左のリストからログファイルを選択してください</p>
                        </div>
                        <?php elseif (empty($log_lines)): ?>
                        <div class="empty-state" style="color: #9ca3af;">
                            <div class="icon">📭</div>
                            <p>ログが空です</p>
                        </div>
                        <?php else: ?>
                            <?php foreach ($log_lines as $line): ?>
                            <?php
                                $class = '';
                                if (stripos($line, '[ERROR]') !== false) $class = 'log-error';
                                elseif (stripos($line, '[WARNING]') !== false) $class = 'log-warning';
                                elseif (stripos($line, '[INFO]') !== false) $class = 'log-info';
                                elseif (stripos($line, '[DEBUG]') !== false) $class = 'log-debug';
                            ?>
                            <div class="log-line <?= $class ?>"><?= htmlspecialchars($line) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
<script src="../assets/js/admin-sidebar-sort.js"></script>
</body>
</html>





