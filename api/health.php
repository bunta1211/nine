<?php
/**
 * ヘルスチェックAPI
 * 
 * システムの健全性を確認
 * 管理者のみアクセス可能（詳細情報）
 * 公開エンドポイント（基本情報のみ）
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$action = $_GET['action'] ?? 'basic';

// 管理者チェック（developer, admin, system_admin, super_admin）
$role = $_SESSION['role'] ?? 'user';
$isAdmin = in_array($role, ['developer', 'admin', 'system_admin', 'super_admin']);

// ========================================
// 管理者向けデプロイ確認（?action=deploy）
// .htaccess でブロックされていないので本番で利用可
// ========================================
if ($action === 'deploy') {
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['error' => '管理者のみ利用できます。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $base = dirname(__DIR__);
    $topbar = $base . '/includes/chat/topbar.php';
    $topbarContent = file_exists($topbar) ? file_get_contents($topbar) : '';
    $hasTestBadge = (strpos($topbarContent, 'topbar-deploy-test') !== false || strpos($topbarContent, 'テスト') !== false);
    $topbarPreview = null;
    if ($topbarContent !== '') {
        foreach (preg_split('/\r?\n/', $topbarContent) as $line) {
            if (strpos($line, 'top-center') !== false || strpos($line, 'テスト') !== false) {
                $topbarPreview = trim($line);
                break;
            }
        }
    }
    $response = [
        'deploy_verify' => '管理者用デプロイ確認-' . date('YmdHis'),
        'message' => 'このサーバーが参照しているプロジェクトルートを base_dir で確認し、FTP等のアップロード先がそのパス（またはその配下）と一致しているか確認してください。',
        'http_host' => $_SERVER['HTTP_HOST'] ?? 'unknown',
        'base_dir' => $base,
        'base_dir_realpath' => realpath($base) ?: null,
        'topbar_has_test_badge' => $hasTestBadge,
        'topbar_preview_line' => $topbarPreview,
        'topbar_mtime' => file_exists($topbar) ? date('Y-m-d H:i:s', filemtime($topbar)) : null,
    ];
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$checks = [];
$overallStatus = 'ok';

// ========================================
// 1. データベース接続チェック
// ========================================
$dbCheck = checkDatabase($pdo);
$checks['database'] = $dbCheck;
if ($dbCheck['status'] !== 'ok') {
    $overallStatus = 'error';
}

// ========================================
// 2. セッションチェック
// ========================================
$sessionCheck = checkSession();
$checks['session'] = $sessionCheck;
if ($sessionCheck['status'] === 'error') {
    $overallStatus = 'error';
}

// ========================================
// 3. ファイルシステムチェック
// ========================================
$fsCheck = checkFileSystem();
$checks['filesystem'] = $fsCheck;
if ($fsCheck['status'] === 'error') {
    $overallStatus = 'error';
} elseif ($fsCheck['status'] === 'warning' && $overallStatus === 'ok') {
    $overallStatus = 'warning';
}

// ========================================
// 4. 管理者向け詳細情報
// ========================================
if ($isAdmin || $action === 'full') {
    // 最近のエラー数
    $checks['errors'] = checkRecentErrors($pdo);
    if ($checks['errors']['status'] !== 'ok') {
        $overallStatus = $checks['errors']['status'] === 'error' ? 'error' : 
                        ($overallStatus === 'ok' ? 'warning' : $overallStatus);
    }
    
    // API使用状況
    $checks['api_usage'] = checkApiUsage($pdo);
    
    // アクティブユーザー数
    $checks['active_users'] = checkActiveUsers($pdo);
    
    // ディスク使用量
    $checks['disk'] = checkDiskUsage();
    if ($checks['disk']['status'] === 'error') {
        $overallStatus = 'error';
    } elseif ($checks['disk']['status'] === 'warning' && $overallStatus === 'ok') {
        $overallStatus = 'warning';
    }
}

// ========================================
// レスポンス
// ========================================
echo json_encode([
    'success' => true,
    'status' => $overallStatus,
    'timestamp' => date('Y-m-d H:i:s'),
    'checks' => $checks
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// ========================================
// チェック関数
// ========================================

function checkDatabase($pdo) {
    $start = microtime(true);
    try {
        $stmt = $pdo->query("SELECT 1");
        $stmt->fetch();
        $responseTime = round((microtime(true) - $start) * 1000);
        
        // テーブル数を取得
        $stmt = $pdo->query("SHOW TABLES");
        $tableCount = $stmt->rowCount();
        
        return [
            'status' => 'ok',
            'message' => "接続正常 ({$tableCount}テーブル)",
            'response_time_ms' => $responseTime
        ];
    } catch (PDOException $e) {
        return [
            'status' => 'error',
            'message' => 'データベース接続エラー: ' . $e->getMessage(),
            'response_time_ms' => round((microtime(true) - $start) * 1000)
        ];
    }
}

function checkSession() {
    $sessionPath = session_save_path() ?: sys_get_temp_dir();
    
    if (!is_writable($sessionPath)) {
        return [
            'status' => 'error',
            'message' => 'セッション保存先が書き込み不可: ' . $sessionPath
        ];
    }
    
    return [
        'status' => 'ok',
        'message' => 'セッション正常',
        'path' => $sessionPath
    ];
}

function checkFileSystem() {
    $uploadDir = __DIR__ . '/../uploads/';
    $logsDir = __DIR__ . '/../logs/';
    $tmpDir = __DIR__ . '/../tmp/';
    
    $issues = [];
    
    if (!is_writable($uploadDir)) {
        $issues[] = 'uploads/が書き込み不可';
    }
    
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0755, true);
    }
    if (!is_writable($logsDir)) {
        $issues[] = 'logs/が書き込み不可';
    }
    
    if (!is_writable($tmpDir)) {
        $issues[] = 'tmp/が書き込み不可';
    }
    
    if (count($issues) > 0) {
        return [
            'status' => count($issues) > 1 ? 'error' : 'warning',
            'message' => implode(', ', $issues)
        ];
    }
    
    return [
        'status' => 'ok',
        'message' => 'ファイルシステム正常'
    ];
}

function checkRecentErrors($pdo) {
    try {
        // テーブルが存在するかチェック
        $stmt = $pdo->query("SHOW TABLES LIKE 'error_logs'");
        if ($stmt->rowCount() === 0) {
            return [
                'status' => 'ok',
                'message' => 'エラーログテーブル未初期化',
                'count_24h' => 0
            ];
        }
        
        // 過去24時間のエラー数
        $stmt = $pdo->query("
            SELECT COUNT(*) as count,
                   SUM(occurrence_count) as total_occurrences
            FROM error_logs 
            WHERE last_occurred_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND is_resolved = 0
        ");
        $result = $stmt->fetch();
        
        $count = (int)$result['count'];
        $total = (int)$result['total_occurrences'];
        
        $status = 'ok';
        if ($total > 100) {
            $status = 'error';
        } elseif ($total > 20) {
            $status = 'warning';
        }
        
        return [
            'status' => $status,
            'message' => $total > 0 ? "{$count}種類のエラー（計{$total}回）" : 'エラーなし',
            'count_24h' => $count,
            'occurrences_24h' => $total
        ];
    } catch (PDOException $e) {
        return [
            'status' => 'ok',
            'message' => 'エラーログ確認不可',
            'count_24h' => 0
        ];
    }
}

function checkApiUsage($pdo) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'api_usage_logs'");
        if ($stmt->rowCount() === 0) {
            return [
                'status' => 'ok',
                'message' => 'API使用ログ未初期化',
                'requests_1h' => 0
            ];
        }
        
        $stmt = $pdo->query("
            SELECT COUNT(*) as count,
                   AVG(response_time_ms) as avg_time
            FROM api_usage_logs 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $result = $stmt->fetch();
        
        return [
            'status' => 'ok',
            'message' => "過去1時間: {$result['count']}リクエスト",
            'requests_1h' => (int)$result['count'],
            'avg_response_time_ms' => round((float)$result['avg_time'], 2)
        ];
    } catch (PDOException $e) {
        return [
            'status' => 'ok',
            'message' => 'API使用状況確認不可',
            'requests_1h' => 0
        ];
    }
}

function checkActiveUsers($pdo) {
    try {
        // 過去5分間のアクティブユーザー
        $stmt = $pdo->query("
            SELECT COUNT(*) as count
            FROM users 
            WHERE last_activity > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $result = $stmt->fetch();
        $active5min = (int)$result['count'];
        
        // 過去24時間のアクティブユーザー
        $stmt = $pdo->query("
            SELECT COUNT(*) as count
            FROM users 
            WHERE last_activity > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $result = $stmt->fetch();
        $active24h = (int)$result['count'];
        
        return [
            'status' => 'ok',
            'message' => "オンライン: {$active5min}人, 24時間: {$active24h}人",
            'online_now' => $active5min,
            'active_24h' => $active24h
        ];
    } catch (PDOException $e) {
        return [
            'status' => 'ok',
            'message' => 'ユーザー情報確認不可',
            'online_now' => 0
        ];
    }
}

function checkDiskUsage() {
    $uploadDir = __DIR__ . '/../uploads/';
    
    // アップロードフォルダのサイズ
    $size = 0;
    if (is_dir($uploadDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $size += $file->getSize();
        }
    }
    
    $sizeMB = round($size / 1024 / 1024, 2);
    $sizeGB = round($size / 1024 / 1024 / 1024, 2);
    
    // ディスク空き容量
    $freeSpace = disk_free_space($uploadDir);
    $freeSpaceGB = round($freeSpace / 1024 / 1024 / 1024, 2);
    
    $status = 'ok';
    if ($freeSpaceGB < 1) {
        $status = 'error';
    } elseif ($freeSpaceGB < 5) {
        $status = 'warning';
    }
    
    return [
        'status' => $status,
        'message' => "使用: {$sizeGB}GB, 空き: {$freeSpaceGB}GB",
        'uploads_size_mb' => $sizeMB,
        'free_space_gb' => $freeSpaceGB
    ];
}
