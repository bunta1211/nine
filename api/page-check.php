<?php
/**
 * ページチェックAPI
 * 
 * AIやスクリプトから呼び出して、サイト全体の健全性を確認
 * 「ページチェッカーで報告」コマンド用
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'quick';

// 管理者チェック（fullは管理者のみ）
$role = $_SESSION['role'] ?? 'user';
$isAdmin = in_array($role, ['developer', 'admin', 'system_admin', 'super_admin']);

if ($action === 'full' && !$isAdmin) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access required for full check']);
    exit;
}

// ベースURL
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
           . '://' . $_SERVER['HTTP_HOST'];

// ベースパスを自動検出（/nine/ または /）
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$basePath = (strpos($scriptPath, '/nine') !== false) ? '/nine' : '';

// チェック対象ページ
$pages = [
    // 公開ページ
    ['category' => 'public', 'url' => $basePath . '/', 'name' => 'ログイン', 'auth' => false],
    ['category' => 'public', 'url' => $basePath . '/register.php', 'name' => '新規登録', 'auth' => false],
    
    // メイン機能
    ['category' => 'main', 'url' => $basePath . '/chat.php', 'name' => 'チャット', 'auth' => true],
    ['category' => 'main', 'url' => $basePath . '/settings.php', 'name' => '設定', 'auth' => true],
    ['category' => 'main', 'url' => $basePath . '/tasks.php', 'name' => 'タスク', 'auth' => true],
    ['category' => 'main', 'url' => $basePath . '/memos.php', 'name' => 'メモ', 'auth' => true],
    ['category' => 'main', 'url' => $basePath . '/notifications.php', 'name' => '通知', 'auth' => true],
    
    // 管理画面
    ['category' => 'admin', 'url' => $basePath . '/admin/index.php', 'name' => '管理ダッシュボード', 'auth' => true, 'admin' => true],
    ['category' => 'admin', 'url' => $basePath . '/admin/users.php', 'name' => 'ユーザー管理', 'auth' => true, 'admin' => true],
    ['category' => 'admin', 'url' => $basePath . '/admin/security.php', 'name' => 'セキュリティ', 'auth' => true, 'admin' => true],
    
    // API
    ['category' => 'api', 'url' => $basePath . '/api/health.php', 'name' => 'ヘルスチェックAPI', 'auth' => false, 'api' => true],
];

switch ($action) {
    case 'quick':
        // クイックチェック（HTTPステータスのみ）
        $results = checkPagesHttp($pages, $baseUrl);
        echo json_encode([
            'success' => true,
            'type' => 'quick',
            'timestamp' => date('Y-m-d H:i:s'),
            'summary' => summarizeResults($results),
            'results' => $results
        ]);
        break;
        
    case 'full':
        // フルチェック（HTTP + 詳細情報）
        $results = checkPagesHttp($pages, $baseUrl);
        $errors = getRecentErrors();
        $health = getHealthStatus();
        
        echo json_encode([
            'success' => true,
            'type' => 'full',
            'timestamp' => date('Y-m-d H:i:s'),
            'summary' => summarizeResults($results),
            'results' => $results,
            'js_errors' => $errors,
            'system_health' => $health,
            'recommendations' => generateRecommendations($results, $errors)
        ]);
        break;
        
    case 'errors':
        // エラーのみ取得
        $errors = getRecentErrors();
        echo json_encode([
            'success' => true,
            'error_count' => count($errors),
            'errors' => $errors
        ]);
        break;
        
    case 'pages':
        // ページリストのみ
        echo json_encode([
            'success' => true,
            'pages' => $pages
        ]);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action. Use: quick, full, errors, pages']);
}

/**
 * ページをHTTPチェック
 */
function checkPagesHttp($pages, $baseUrl) {
    $results = [];
    
    // localhostを127.0.0.1に置換（DNS解決問題を回避）
    $baseUrl = str_replace('localhost', '127.0.0.1', $baseUrl);
    
    foreach ($pages as $page) {
        $url = $baseUrl . $page['url'];
        $start = microtime(true);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 5, // タイムアウトを短く
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Cookie: PHPSESSID=' . session_id(),
                'X-Page-Check: 1',
                'Host: localhost' // 正しいHostヘッダー
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $time = round((microtime(true) - $start) * 1000);
        $error = curl_error($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($response, $headerSize);
        curl_close($ch);
        
        // PHPエラーをチェック
        $hasPhpError = preg_match('/(Fatal error|Parse error|Warning:|Notice:)/i', $body);
        
        $results[] = [
            'page' => $page['name'],
            'url' => $page['url'],
            'category' => $page['category'],
            'status' => $httpCode,
            'time_ms' => $time,
            'ok' => $httpCode >= 200 && $httpCode < 400 && !$hasPhpError,
            'has_php_error' => (bool)$hasPhpError,
            'error' => $error ?: null
        ];
    }
    
    return $results;
}

/**
 * 結果をサマリー
 */
function summarizeResults($results) {
    $ok = 0;
    $warnings = 0;
    $errors = 0;
    $totalTime = 0;
    
    foreach ($results as $r) {
        $totalTime += $r['time_ms'];
        
        if ($r['ok']) {
            $ok++;
        } elseif ($r['status'] >= 300 && $r['status'] < 400) {
            $warnings++;
        } else {
            $errors++;
        }
    }
    
    return [
        'total' => count($results),
        'ok' => $ok,
        'warnings' => $warnings,
        'errors' => $errors,
        'avg_time_ms' => count($results) > 0 ? round($totalTime / count($results)) : 0,
        'status' => $errors > 0 ? 'error' : ($warnings > 0 ? 'warning' : 'ok')
    ];
}

/**
 * 最近のエラーを取得
 */
function getRecentErrors() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT error_type, error_message, url, occurrence_count, last_occurred_at
            FROM error_logs 
            WHERE is_resolved = 0 
            ORDER BY last_occurred_at DESC 
            LIMIT 10
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * システムヘルス取得
 */
function getHealthStatus() {
    global $pdo;
    
    $health = ['status' => 'ok', 'checks' => []];
    
    // DB接続
    try {
        $stmt = $pdo->query("SELECT 1");
        $health['checks']['database'] = 'ok';
    } catch (Exception $e) {
        $health['checks']['database'] = 'error';
        $health['status'] = 'error';
    }
    
    // ディスク容量
    $freeSpace = disk_free_space(__DIR__ . '/..');
    $totalSpace = disk_total_space(__DIR__ . '/..');
    $usedPercent = round((($totalSpace - $freeSpace) / $totalSpace) * 100);
    
    $health['checks']['disk'] = [
        'used_percent' => $usedPercent,
        'status' => $usedPercent > 90 ? 'error' : ($usedPercent > 80 ? 'warning' : 'ok')
    ];
    
    if ($health['checks']['disk']['status'] === 'error') {
        $health['status'] = 'error';
    } elseif ($health['checks']['disk']['status'] === 'warning' && $health['status'] === 'ok') {
        $health['status'] = 'warning';
    }
    
    return $health;
}

/**
 * 改善提案を生成
 */
function generateRecommendations($results, $errors) {
    $recommendations = [];
    
    // ページエラー
    $errorPages = array_filter($results, fn($r) => !$r['ok']);
    if (count($errorPages) > 0) {
        foreach ($errorPages as $p) {
            $recommendations[] = [
                'priority' => 'high',
                'type' => 'page_error',
                'message' => "{$p['page']} ({$p['url']}) がエラー状態です (HTTP {$p['status']})",
                'action' => 'ページのPHPエラーを確認してください'
            ];
        }
    }
    
    // 遅いページ
    $slowPages = array_filter($results, fn($r) => $r['time_ms'] > 2000);
    if (count($slowPages) > 0) {
        foreach ($slowPages as $p) {
            $recommendations[] = [
                'priority' => 'medium',
                'type' => 'slow_page',
                'message' => "{$p['page']} の応答が遅い ({$p['time_ms']}ms)",
                'action' => 'データベースクエリやリソース読み込みを最適化してください'
            ];
        }
    }
    
    // JSエラー
    if (count($errors) > 0) {
        $recommendations[] = [
            'priority' => 'high',
            'type' => 'js_errors',
            'message' => count($errors) . '件のJavaScriptエラーが未解決です',
            'action' => 'admin/page-checker.php でエラー詳細を確認してください'
        ];
    }
    
    if (empty($recommendations)) {
        $recommendations[] = [
            'priority' => 'info',
            'type' => 'all_ok',
            'message' => 'すべてのチェックに合格しました',
            'action' => '問題はありません'
        ];
    }
    
    return $recommendations;
}
