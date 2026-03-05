<?php
/**
 * エラーログ収集API
 * 
 * クライアントサイドのJavaScriptエラーを自動収集
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';

$pdo = getDB();

// CORSヘッダー
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// POSTのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// JSONボディを取得
$input = json_decode(file_get_contents('php://input'), true);

// 管理者用アクション（resolve, resolve_all 等）
if (isset($input['action'])) {
    // ログイン必須
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Login required']);
        exit;
    }
    // 管理者チェック（developer, admin, system_admin, super_admin）
    $role = $_SESSION['role'] ?? 'user';
    $isAdmin = in_array($role, ['developer', 'admin', 'system_admin', 'super_admin']);
    if (!$isAdmin) {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }
    
    switch ($input['action']) {
        case 'resolve':
            $id = (int)($input['id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE error_logs 
                    SET is_resolved = 1, resolved_at = NOW(), resolved_by = ?
                    WHERE id = ?
                ");
                $stmt->execute([$_SESSION['user_id'], $id]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid ID']);
            }
            exit;
            
        case 'resolve_batch':
            $ids = $input['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                echo json_encode(['success' => false, 'error' => 'No IDs provided']);
                exit;
            }
            $ids = array_map('intval', $ids);
            $ids = array_filter($ids, function($id) { return $id > 0; });
            if (empty($ids)) {
                echo json_encode(['success' => false, 'error' => 'Invalid IDs']);
                exit;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("
                UPDATE error_logs 
                SET is_resolved = 1, resolved_at = NOW(), resolved_by = ?
                WHERE id IN ({$placeholders}) AND is_resolved = 0
            ");
            $stmt->execute(array_merge([$_SESSION['user_id']], $ids));
            echo json_encode(['success' => true, 'resolved' => $stmt->rowCount()]);
            exit;

        case 'resolve_all':
            $stmt = $pdo->prepare("
                UPDATE error_logs 
                SET is_resolved = 1, resolved_at = NOW(), resolved_by = ?
                WHERE is_resolved = 0
            ");
            $stmt->execute([$_SESSION['user_id']]);
            echo json_encode(['success' => true, 'resolved' => $stmt->rowCount()]);
            exit;
    }
}

// レート制限（1分間に最大30件）
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimitKey = 'error_log_' . md5($ip);
$currentCount = (int)($_SESSION[$rateLimitKey] ?? 0);
$lastTime = $_SESSION[$rateLimitKey . '_time'] ?? 0;

if (time() - $lastTime > 60) {
    $currentCount = 0;
    $_SESSION[$rateLimitKey . '_time'] = time();
}

if ($currentCount >= 30) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded']);
    exit;
}

$_SESSION[$rateLimitKey] = $currentCount + 1;

// データ検証（$inputは既に取得済み）
if (!$input || empty($input['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

try {
    // エラーの重複チェック（同じエラーは回数をカウントアップ）
    $errorHash = md5($input['message'] . ($input['url'] ?? ''));
    
    $stmt = $pdo->prepare("
        SELECT id, occurrence_count FROM error_logs 
        WHERE MD5(CONCAT(error_message, COALESCE(url, ''))) = ?
        AND is_resolved = 0
        AND last_occurred_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        LIMIT 1
    ");
    $stmt->execute([$errorHash]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // 既存エラーの回数を更新
        $stmt = $pdo->prepare("
            UPDATE error_logs 
            SET occurrence_count = occurrence_count + 1,
                last_occurred_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$existing['id']]);
        
        echo json_encode(['success' => true, 'action' => 'updated', 'id' => $existing['id']]);
    } else {
        // 新規エラーを登録
        $stmt = $pdo->prepare("
            INSERT INTO error_logs 
            (error_type, error_message, error_stack, url, user_agent, user_id, ip_address, extra_data)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $userId = $_SESSION['user_id'] ?? null;
        $extraData = isset($input['extra']) ? json_encode($input['extra']) : null;
        
        $stmt->execute([
            $input['type'] ?? 'js',
            substr($input['message'], 0, 65535),
            isset($input['stack']) ? substr($input['stack'], 0, 65535) : null,
            isset($input['url']) ? substr($input['url'], 0, 500) : null,
            isset($input['userAgent']) ? substr($input['userAgent'], 0, 500) : null,
            $userId,
            $ip,
            $extraData
        ]);
        
        echo json_encode(['success' => true, 'action' => 'created', 'id' => $pdo->lastInsertId()]);
    }
    
} catch (PDOException $e) {
    // テーブルが存在しない場合は静かに失敗
    if (strpos($e->getMessage(), "doesn't exist") !== false) {
        echo json_encode(['success' => false, 'error' => 'Table not initialized']);
    } else {
        error_log('Error log API error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
}
