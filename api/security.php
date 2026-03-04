<?php
/**
 * セキュリティAPI
 * 
 * セキュリティログの取得、IPブロック管理
 * 管理者専用
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ハニーポット情報収集は認証不要
if (!in_array($action, ['honeypot_collect', 'collect_blocked'])) {
    // 管理者チェック（developer, admin, system_admin, super_admin）
    $role = $_SESSION['role'] ?? 'user';
    if (!in_array($role, ['developer', 'admin', 'system_admin', 'super_admin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }
}

$security = getSecurity();

switch ($action) {
    
    // ========================================
    // ハニーポット情報収集（認証不要）
    // ========================================
    case 'honeypot_collect':
    case 'collect_blocked':
        // 認証不要で情報を収集
        $input = json_decode(file_get_contents('php://input'), true);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        if ($input) {
            try {
                // フィンガープリントデータを保存
                $stmt = $pdo->prepare("
                    UPDATE security_logs
                    SET fingerprint_data = ?, raw_data = JSON_SET(COALESCE(raw_data, '{}'), '$.client_data', ?)
                    WHERE ip_address = ?
                    AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                $stmt->execute([
                    json_encode($input),
                    json_encode($input),
                    $ip
                ]);
            } catch (PDOException $e) {
                // 静かに失敗
            }
        }
        
        echo json_encode(['success' => true]);
        exit;
    
    // ========================================
    // サマリー取得
    // ========================================
    case 'summary':
        $hours = (int)($_GET['hours'] ?? 24);
        $summary = $security->getSummary($hours);
        echo json_encode([
            'success' => true,
            'hours' => $hours,
            'summary' => $summary
        ], JSON_UNESCAPED_UNICODE);
        break;
    
    // ========================================
    // ログ一覧取得
    // ========================================
    case 'logs':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;
        
        $eventType = $_GET['event_type'] ?? '';
        $severity = $_GET['severity'] ?? '';
        $ip = $_GET['ip'] ?? '';
        $handled = $_GET['handled'] ?? '';
        
        $where = [];
        $params = [];
        
        if ($eventType) {
            $where[] = "event_type = ?";
            $params[] = $eventType;
        }
        if ($severity) {
            $where[] = "severity = ?";
            $params[] = $severity;
        }
        if ($ip) {
            $where[] = "ip_address LIKE ?";
            $params[] = "%{$ip}%";
        }
        if ($handled !== '') {
            $where[] = "is_handled = ?";
            $params[] = (int)$handled;
        }
        
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        
        try {
            // 総件数
            $countStmt = $pdo->prepare("SELECT COUNT(*) as count FROM security_logs {$whereClause}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetch()['count'];
            
            // データ取得
            $stmt = $pdo->prepare("
                SELECT id, event_type, severity, target_user_id, target_username, target_resource,
                       ip_address, user_agent, user_agent_parsed, referer,
                       request_method, request_uri, fingerprint_hash,
                       description, is_handled, auto_action_taken, created_at
                FROM security_logs
                {$whereClause}
                ORDER BY created_at DESC
                LIMIT {$limit} OFFSET {$offset}
            ");
            $stmt->execute($params);
            $logs = $stmt->fetchAll();
            
            // JSON フィールドをパース
            foreach ($logs as &$log) {
                if ($log['user_agent_parsed']) {
                    $log['user_agent_parsed'] = json_decode($log['user_agent_parsed'], true);
                }
            }
            
            echo json_encode([
                'success' => true,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit),
                'logs' => $logs
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    // ========================================
    // ログ詳細取得
    // ========================================
    case 'log_detail':
        $id = (int)($_GET['id'] ?? 0);
        
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid ID']);
            break;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM security_logs WHERE id = ?");
            $stmt->execute([$id]);
            $log = $stmt->fetch();
            
            if (!$log) {
                echo json_encode(['success' => false, 'error' => 'Not found']);
                break;
            }
            
            // JSONフィールドをパース
            $jsonFields = ['ip_info', 'user_agent_parsed', 'request_params', 'request_headers', 'fingerprint_data', 'raw_data'];
            foreach ($jsonFields as $field) {
                if ($log[$field]) {
                    $log[$field] = json_decode($log[$field], true);
                }
            }
            
            // 同一IPの他のイベント
            $stmt = $pdo->prepare("
                SELECT id, event_type, severity, created_at
                FROM security_logs
                WHERE ip_address = ? AND id != ?
                ORDER BY created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$log['ip_address'], $id]);
            $relatedEvents = $stmt->fetchAll();
            
            // 同一フィンガープリントの他のイベント
            $relatedFingerprint = [];
            if ($log['fingerprint_hash']) {
                $stmt = $pdo->prepare("
                    SELECT id, event_type, ip_address, created_at
                    FROM security_logs
                    WHERE fingerprint_hash = ? AND id != ?
                    ORDER BY created_at DESC
                    LIMIT 20
                ");
                $stmt->execute([$log['fingerprint_hash'], $id]);
                $relatedFingerprint = $stmt->fetchAll();
            }
            
            echo json_encode([
                'success' => true,
                'log' => $log,
                'related_by_ip' => $relatedEvents,
                'related_by_fingerprint' => $relatedFingerprint
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    // ========================================
    // ログを対応済みにする
    // ========================================
    case 'handle':
        $id = (int)($_POST['id'] ?? 0);
        $notes = $_POST['notes'] ?? '';
        
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid ID']);
            break;
        }
        
        try {
            $stmt = $pdo->prepare("
                UPDATE security_logs
                SET is_handled = 1, handled_at = NOW(), handled_by = ?, handling_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $notes, $id]);
            
            echo json_encode(['success' => true]);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    // ========================================
    // ブロックIP一覧
    // ========================================
    case 'blocked_ips':
        try {
            $stmt = $pdo->query("
                SELECT bi.*, u.username as blocked_by_name
                FROM blocked_ips bi
                LEFT JOIN users u ON bi.blocked_by = u.id
                ORDER BY bi.created_at DESC
            ");
            $ips = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'blocked_ips' => $ips
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    // ========================================
    // IPをブロック
    // ========================================
    case 'block_ip':
        $ip = $_POST['ip'] ?? '';
        $reason = $_POST['reason'] ?? 'Manual block';
        $permanent = (bool)($_POST['permanent'] ?? false);
        $duration = $permanent ? null : (int)($_POST['duration'] ?? 60);
        
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['success' => false, 'error' => 'Invalid IP address']);
            break;
        }
        
        if ($security->blockIP($ip, $reason, $duration)) {
            echo json_encode(['success' => true, 'message' => 'IP blocked']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to block IP']);
        }
        break;
    
    // ========================================
    // IPブロック解除
    // ========================================
    case 'unblock_ip':
        $ip = $_POST['ip'] ?? '';
        
        if (!$ip) {
            echo json_encode(['success' => false, 'error' => 'Invalid IP']);
            break;
        }
        
        if ($security->unblockIP($ip)) {
            echo json_encode(['success' => true, 'message' => 'IP unblocked']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to unblock IP']);
        }
        break;
    
    // ========================================
    // ログイン試行履歴
    // ========================================
    case 'login_attempts':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;
        
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM login_attempts");
            $total = (int)$stmt->fetch()['count'];
            
            $stmt = $pdo->prepare("
                SELECT * FROM login_attempts
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$limit, $offset]);
            $attempts = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'total' => $total,
                'page' => $page,
                'attempts' => $attempts
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    // ========================================
    // 設定取得
    // ========================================
    case 'settings':
        try {
            $stmt = $pdo->query("SELECT * FROM security_settings ORDER BY setting_key");
            $settings = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'settings' => $settings
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    // ========================================
    // 設定更新
    // ========================================
    case 'update_setting':
        $key = $_POST['key'] ?? '';
        $value = $_POST['value'] ?? '';
        
        if (!$key) {
            echo json_encode(['success' => false, 'error' => 'Invalid key']);
            break;
        }
        
        try {
            $stmt = $pdo->prepare("
                UPDATE security_settings SET setting_value = ? WHERE setting_key = ?
            ");
            $stmt->execute([$value, $key]);
            
            echo json_encode(['success' => true]);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    // ========================================
    // IP情報を外部APIから取得
    // ========================================
    case 'ip_lookup':
        $ip = $_GET['ip'] ?? '';
        
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['success' => false, 'error' => 'Invalid IP']);
            break;
        }
        
        // ip-api.com（無料）を使用
        $url = "http://ip-api.com/json/{$ip}?fields=status,message,country,countryCode,region,regionName,city,zip,lat,lon,timezone,isp,org,as,mobile,proxy,hosting,query";
        
        $context = stream_context_create([
            'http' => ['timeout' => 5]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response) {
            $data = json_decode($response, true);
            echo json_encode([
                'success' => true,
                'ip' => $ip,
                'info' => $data
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to lookup IP']);
        }
        break;
    
    // ========================================
    // 統計データ
    // ========================================
    case 'stats':
        $days = (int)($_GET['days'] ?? 7);
        
        try {
            // 日別イベント数
            $stmt = $pdo->prepare("
                SELECT DATE(created_at) as date, severity, COUNT(*) as count
                FROM security_logs
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY DATE(created_at), severity
                ORDER BY date
            ");
            $stmt->execute([$days]);
            $dailyEvents = $stmt->fetchAll();
            
            // 時間帯別
            $stmt = $pdo->prepare("
                SELECT HOUR(created_at) as hour, COUNT(*) as count
                FROM security_logs
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY HOUR(created_at)
                ORDER BY hour
            ");
            $stmt->execute([$days]);
            $hourlyEvents = $stmt->fetchAll();
            
            // 国別（IP情報がある場合）
            $stmt = $pdo->prepare("
                SELECT 
                    JSON_UNQUOTE(JSON_EXTRACT(ip_info, '$.country')) as country,
                    COUNT(*) as count
                FROM security_logs
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                AND ip_info IS NOT NULL
                GROUP BY country
                ORDER BY count DESC
                LIMIT 10
            ");
            $stmt->execute([$days]);
            $countryStats = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'days' => $days,
                'daily_events' => $dailyEvents,
                'hourly_events' => $hourlyEvents,
                'country_stats' => $countryStats
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
