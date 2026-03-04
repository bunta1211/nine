<?php
/**
 * バックアップ管理画面
 * バックアップ状況の確認と手動バックアップの実行
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
$currentPage = 'backup';
require_once __DIR__ . '/_sidebar.php';

// 管理者権限チェック
if (!isLoggedIn() || !isOrgAdminUser()) {
    header('Location: ../index.php');
    exit;
}

// バックアップディレクトリ
$backupDir = 'C:/xampp/backup';
$dailyDir = $backupDir . '/daily';
$manualDir = $backupDir . '/manual';

/**
 * ディレクトリサイズを計算
 */
function getDirSize($dir) {
    $size = 0;
    if (!is_dir($dir)) return 0;
    
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
        $size += $file->getSize();
    }
    return $size;
}

/**
 * バイトを人間が読める形式に変換
 */
function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * バックアップ一覧を取得
 */
function getBackups($dir) {
    $backups = [];
    if (!is_dir($dir)) return $backups;
    
    $dirs = scandir($dir, SCANDIR_SORT_DESCENDING);
    foreach ($dirs as $d) {
        if ($d === '.' || $d === '..') continue;
        $path = $dir . '/' . $d;
        if (is_dir($path)) {
            $backups[] = [
                'name' => $d,
                'path' => $path,
                'date' => filemtime($path),
                'size' => getDirSize($path)
            ];
        }
    }
    return $backups;
}

$dailyBackups = getBackups($dailyDir);
$manualBackups = getBackups($manualDir);

// 最終バックアップ日時
$lastBackup = !empty($dailyBackups) ? date('Y-m-d H:i', $dailyBackups[0]['date']) : '未実行';

// ログファイル確認
$logFile = $backupDir . '/backup.log';
$logContent = file_exists($logFile) ? file_get_contents($logFile) : 'ログファイルなし';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>バックアップ管理 - 管理画面</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        <?php adminSidebarCSS(); ?>
        .backup-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0;
        }
        
        .backup-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .backup-header h1 {
            font-size: 24px;
            color: #333;
        }
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .summary-card.warning {
            border-left: 4px solid #f59e0b;
        }
        
        .summary-card.success {
            border-left: 4px solid #10b981;
        }
        
        .summary-card.info {
            border-left: 4px solid #3b82f6;
        }
        
        .summary-card h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
        }
        
        .summary-card .value {
            font-size: 24px;
            font-weight: 600;
            color: #333;
        }
        
        .section {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .section h2 {
            font-size: 18px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .backup-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .backup-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .backup-item:last-child {
            border-bottom: none;
        }
        
        .backup-name {
            font-weight: 500;
        }
        
        .backup-meta {
            display: flex;
            gap: 20px;
            color: #666;
            font-size: 14px;
        }
        
        .log-view {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 15px;
            border-radius: 8px;
            font-family: 'Consolas', monospace;
            font-size: 13px;
            max-height: 300px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: #3b82f6;
            color: #fff;
        }
        
        .btn-primary:hover {
            background: #2563eb;
        }
        
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        .btn-secondary:hover {
            background: #d1d5db;
        }
        
        .instructions {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
        }
        
        .instructions h3 {
            font-size: 16px;
            color: #0369a1;
            margin-bottom: 10px;
        }
        
        .instructions ol {
            margin: 0;
            padding-left: 20px;
            color: #0369a1;
        }
        
        .instructions li {
            margin-bottom: 5px;
        }
        
        .status-ok {
            color: #10b981;
        }
        
        .status-warn {
            color: #f59e0b;
        }
        
    </style>
</head>
<body>
    <div class="admin-container">
        <?php adminSidebarHTML($currentPage); ?>
        <main class="main-content">
            <div class="backup-container">
        
        <div class="backup-header">
            <h1>🗄️ バックアップ管理</h1>
            <div class="action-buttons">
                <a href="logs.php" class="btn btn-secondary">ログ確認</a>
            </div>
        </div>
        
        <div class="summary-cards">
            <div class="summary-card <?= count($dailyBackups) >= 3 ? 'success' : 'warning' ?>">
                <h3>最終バックアップ</h3>
                <div class="value"><?= htmlspecialchars($lastBackup) ?></div>
            </div>
            <div class="summary-card info">
                <h3>日次バックアップ数</h3>
                <div class="value"><?= count($dailyBackups) ?> 件</div>
            </div>
            <div class="summary-card info">
                <h3>手動バックアップ数</h3>
                <div class="value"><?= count($manualBackups) ?> 件</div>
            </div>
        </div>
        
        <div class="section">
            <h2>📅 日次バックアップ一覧</h2>
            <?php if (empty($dailyBackups)): ?>
                <p style="color: #f59e0b;">⚠️ 日次バックアップがありません。タスクスケジューラの設定を確認してください。</p>
            <?php else: ?>
                <ul class="backup-list">
                    <?php foreach ($dailyBackups as $backup): ?>
                        <li class="backup-item">
                            <span class="backup-name">📁 <?= htmlspecialchars($backup['name']) ?></span>
                            <span class="backup-meta">
                                <span><?= date('Y-m-d H:i', $backup['date']) ?></span>
                                <span><?= formatBytes($backup['size']) ?></span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        
        <div class="section">
            <h2>📌 手動バックアップ一覧</h2>
            <?php if (empty($manualBackups)): ?>
                <p style="color: #666;">手動バックアップはまだありません。</p>
            <?php else: ?>
                <ul class="backup-list">
                    <?php foreach ($manualBackups as $backup): ?>
                        <li class="backup-item">
                            <span class="backup-name">📁 <?= htmlspecialchars($backup['name']) ?></span>
                            <span class="backup-meta">
                                <span><?= date('Y-m-d H:i', $backup['date']) ?></span>
                                <span><?= formatBytes($backup['size']) ?></span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        
        <div class="section">
            <h2>📋 バックアップログ（最新10行）</h2>
            <div class="log-view"><?php
                if (file_exists($logFile)) {
                    $lines = file($logFile, FILE_IGNORE_NEW_LINES);
                    $lastLines = array_slice($lines, -10);
                    echo htmlspecialchars(implode("\n", $lastLines));
                } else {
                    echo 'ログファイルがありません';
                }
            ?></div>
        </div>
        
        <div class="section">
            <h2>🛠️ 手動バックアップ方法</h2>
            <div class="instructions">
                <h3>バックアップスクリプトの実行</h3>
                <ol>
                    <li>Windowsエクスプローラーで <code>C:\xampp\backup\scripts\</code> を開く</li>
                    <li><strong>daily_backup.bat</strong>（日次）または <strong>manual_backup.bat</strong>（手動）をダブルクリック</li>
                    <li>コマンドプロンプトが開き、バックアップが実行される</li>
                    <li>「バックアップが完了しました」と表示されたら完了</li>
                </ol>
            </div>
            
            <div class="instructions" style="margin-top: 15px; background: #fef3c7; border-color: #fcd34d;">
                <h3 style="color: #92400e;">🔄 復旧方法</h3>
                <ol style="color: #92400e;">
                    <li><strong>restore_database.bat</strong> をダブルクリック</li>
                    <li>復元したいバックアップのSQLファイルパスを入力</li>
                    <li>確認メッセージで「y」を入力して実行</li>
                    <li>完了後、システムの動作確認を行う</li>
                </ol>
            </div>
        </div>
        
        <div class="section">
            <h2>📚 ドキュメント</h2>
            <p>詳細な手順は仕様書を参照してください：</p>
            <p><a href="../docs/spec/12_バックアップ・リカバリ戦略.md" target="_blank">
                📄 バックアップ・リカバリ戦略（仕様書）
            </a></p>
        </div>
            </div>
        </main>
    </div>
    <script src="../assets/js/admin-sidebar-sort.js"></script>
</body>
</html>



