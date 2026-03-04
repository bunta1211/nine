<?php
/**
 * セキュリティ監視モジュール
 * 
 * 侵入者の検出、詳細情報の記録、自動対応
 */

class Security {
    private $pdo;
    private $settings = [];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadSettings();
    }
    
    /**
     * 設定を読み込み
     */
    private function loadSettings() {
        // デフォルト設定
        $defaults = [
            'max_login_attempts' => 5,
            'lockout_duration_minutes' => 30,
            'brute_force_threshold' => 10,
            'rate_limit_requests_per_minute' => 60,
            'session_hijack_detection' => 'true',
            'log_all_logins' => 'true',
            'auto_block_brute_force' => 'true'
        ];
        
        // PDOがnullの場合はデフォルト値を使用
        if (!$this->pdo) {
            $this->settings = $defaults;
            return;
        }
        
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM security_settings");
            while ($row = $stmt->fetch()) {
                $this->settings[$row['setting_key']] = $row['setting_value'];
            }
            // デフォルト値をマージ（DBにないキーはデフォルト）
            $this->settings = array_merge($defaults, $this->settings);
        } catch (PDOException $e) {
            // テーブルがない場合はデフォルト値を使用
            $this->settings = $defaults;
        }
    }
    
    /**
     * 設定値を取得
     */
    public function getSetting($key, $default = null) {
        return $this->settings[$key] ?? $default;
    }
    
    // ========================================
    // 情報収集
    // ========================================
    
    /**
     * クライアント情報を全て収集
     */
    public function collectClientInfo() {
        $info = [
            'ip' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'headers' => $this->getAllHeaders(),
            'fingerprint' => $this->generateFingerprint()
        ];
        
        // ユーザーエージェント解析
        $info['ua_parsed'] = $this->parseUserAgent($info['user_agent']);
        
        return $info;
    }
    
    /**
     * クライアントIPアドレスを取得（プロキシ対応）
     */
    public function getClientIP() {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * 全リクエストヘッダーを取得
     */
    private function getAllHeaders() {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace('_', '-', substr($key, 5));
                // 機密情報を除外
                if (!in_array(strtolower($headerName), ['cookie', 'authorization'])) {
                    $headers[$headerName] = $value;
                }
            }
        }
        return $headers;
    }
    
    /**
     * ブラウザフィンガープリントを生成
     */
    public function generateFingerprint() {
        $components = [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
            $_SERVER['HTTP_ACCEPT'] ?? ''
        ];
        
        return hash('sha256', implode('|', $components));
    }
    
    /**
     * ユーザーエージェントを解析
     */
    private function parseUserAgent($ua) {
        $result = [
            'browser' => 'Unknown',
            'browser_version' => '',
            'os' => 'Unknown',
            'os_version' => '',
            'device' => 'Desktop',
            'is_bot' => false,
            'is_suspicious' => false
        ];
        
        if (empty($ua)) {
            $result['is_suspicious'] = true;
            return $result;
        }
        
        // ブラウザ検出
        if (preg_match('/Chrome\/(\d+)/', $ua, $m)) {
            $result['browser'] = 'Chrome';
            $result['browser_version'] = $m[1];
        } elseif (preg_match('/Firefox\/(\d+)/', $ua, $m)) {
            $result['browser'] = 'Firefox';
            $result['browser_version'] = $m[1];
        } elseif (preg_match('/Safari\/(\d+)/', $ua, $m) && strpos($ua, 'Chrome') === false) {
            $result['browser'] = 'Safari';
            $result['browser_version'] = $m[1];
        } elseif (preg_match('/Edge\/(\d+)/', $ua, $m)) {
            $result['browser'] = 'Edge';
            $result['browser_version'] = $m[1];
        }
        
        // OS検出
        if (strpos($ua, 'Windows') !== false) {
            $result['os'] = 'Windows';
            if (preg_match('/Windows NT (\d+\.\d+)/', $ua, $m)) {
                $versions = ['10.0' => '10/11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7'];
                $result['os_version'] = $versions[$m[1]] ?? $m[1];
            }
        } elseif (strpos($ua, 'Mac OS X') !== false) {
            $result['os'] = 'macOS';
        } elseif (strpos($ua, 'Linux') !== false) {
            $result['os'] = 'Linux';
        } elseif (strpos($ua, 'Android') !== false) {
            $result['os'] = 'Android';
            $result['device'] = 'Mobile';
        } elseif (strpos($ua, 'iPhone') !== false || strpos($ua, 'iPad') !== false) {
            $result['os'] = 'iOS';
            $result['device'] = strpos($ua, 'iPad') !== false ? 'Tablet' : 'Mobile';
        }
        
        // ボット検出
        $botPatterns = ['bot', 'crawler', 'spider', 'scraper', 'curl', 'wget', 'python'];
        foreach ($botPatterns as $pattern) {
            if (stripos($ua, $pattern) !== false) {
                $result['is_bot'] = true;
                break;
            }
        }
        
        // 不審なUA検出
        $this->checkSuspiciousUA($ua, $result);
        
        return $result;
    }
    
    /**
     * 不審なユーザーエージェントをチェック
     */
    private function checkSuspiciousUA($ua, &$result) {
        try {
            $stmt = $this->pdo->query("SELECT pattern FROM suspicious_user_agents WHERE is_active = 1");
            while ($row = $stmt->fetch()) {
                if (stripos($ua, $row['pattern']) !== false) {
                    $result['is_suspicious'] = true;
                    $result['suspicious_pattern'] = $row['pattern'];
                    return;
                }
            }
        } catch (PDOException $e) {
            // テーブルがない場合は基本パターンでチェック
            $patterns = ['sqlmap', 'nikto', 'nmap', 'burpsuite', 'acunetix'];
            foreach ($patterns as $pattern) {
                if (stripos($ua, $pattern) !== false) {
                    $result['is_suspicious'] = true;
                    $result['suspicious_pattern'] = $pattern;
                    return;
                }
            }
        }
    }
    
    // ========================================
    // セキュリティイベント記録
    // ========================================
    
    /**
     * セキュリティイベントを記録
     */
    public function logEvent($eventType, $severity, $data = []) {
        try {
            $clientInfo = $this->collectClientInfo();
            
            $stmt = $this->pdo->prepare("
                INSERT INTO security_logs (
                    event_type, severity, 
                    target_user_id, target_username, target_resource,
                    ip_address, ip_info,
                    user_agent, user_agent_parsed,
                    referer, request_method, request_uri, request_params, request_headers,
                    fingerprint_hash, fingerprint_data,
                    session_id, description, raw_data, auto_action_taken
                ) VALUES (
                    ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?,
                    ?, ?, ?, ?
                )
            ");
            
            // リクエストパラメータ（パスワードは除外）
            $params = array_merge($_GET, $_POST);
            unset($params['password'], $params['pass'], $params['pwd'], $params['secret']);
            
            $stmt->execute([
                $eventType,
                $severity,
                $data['user_id'] ?? null,
                $data['username'] ?? null,
                $data['resource'] ?? $clientInfo['request_uri'],
                $clientInfo['ip'],
                json_encode($data['ip_info'] ?? null),
                $clientInfo['user_agent'],
                json_encode($clientInfo['ua_parsed']),
                $clientInfo['referer'],
                $clientInfo['request_method'],
                $clientInfo['request_uri'],
                json_encode($params),
                json_encode($clientInfo['headers']),
                $clientInfo['fingerprint'],
                json_encode($data['fingerprint_data'] ?? null),
                session_id(),
                $data['description'] ?? null,
                json_encode($data['raw_data'] ?? null),
                $data['auto_action'] ?? null
            ]);
            
            return $this->pdo->lastInsertId();
            
        } catch (PDOException $e) {
            // ログ記録自体のエラーはファイルに記録
            error_log("Security log error: " . $e->getMessage());
            return false;
        }
    }
    
    // ========================================
    // ログイン監視
    // ========================================
    
    /**
     * ログイン試行を記録
     */
    public function recordLoginAttempt($username, $success, $failureReason = null) {
        $ip = $this->getClientIP();
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO login_attempts (username, ip_address, user_agent, success, failure_reason)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $username,
                $ip,
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $success ? 1 : 0,
                $failureReason
            ]);
            
            // 失敗時は詳細ログも記録
            if (!$success) {
                $this->logEvent('login_failed', 'medium', [
                    'username' => $username,
                    'description' => "ログイン失敗: {$failureReason}"
                ]);
                
                // ブルートフォース検出
                $this->checkBruteForce($username, $ip);
            } else {
                // ログイン成功を記録（設定で有効な場合）
                if ($this->getSetting('log_all_logins') === 'true') {
                    $this->logEvent('login_success', 'low', [
                        'username' => $username,
                        'description' => 'ログイン成功'
                    ]);
                }
            }
            
        } catch (PDOException $e) {
            error_log("Login attempt log error: " . $e->getMessage());
        }
    }
    
    /**
     * ブルートフォース攻撃を検出
     */
    private function checkBruteForce($username, $ip) {
        $threshold = (int)$this->getSetting('brute_force_threshold', 10);
        $lockoutMinutes = (int)$this->getSetting('lockout_duration_minutes', 30);
        
        try {
            // 過去30分間の同一IPからの失敗回数
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count
                FROM login_attempts
                WHERE ip_address = ?
                AND success = 0
                AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $stmt->execute([$ip, $lockoutMinutes]);
            $result = $stmt->fetch();
            
            if ($result['count'] >= $threshold) {
                // ブルートフォース攻撃と判定
                $this->logEvent('brute_force', 'critical', [
                    'username' => $username,
                    'description' => "ブルートフォース攻撃検出: {$result['count']}回の試行"
                ]);
                
                // 自動ブロック
                if ($this->getSetting('auto_block_brute_force') === 'true') {
                    $this->blockIP($ip, "ブルートフォース攻撃（{$result['count']}回試行）", $lockoutMinutes);
                }
                
                return true;
            }
            
        } catch (PDOException $e) {
            error_log("Brute force check error: " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * アカウントがロックされているか確認
     */
    public function isAccountLocked($username) {
        $maxAttempts = (int)$this->getSetting('max_login_attempts', 5);
        $lockoutMinutes = (int)$this->getSetting('lockout_duration_minutes', 30);
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count
                FROM login_attempts
                WHERE username = ?
                AND success = 0
                AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $stmt->execute([$username, $lockoutMinutes]);
            $result = $stmt->fetch();
            
            return $result['count'] >= $maxAttempts;
            
        } catch (PDOException $e) {
            return false;
        }
    }
    
    // ========================================
    // IPブロック
    // ========================================
    
    /**
     * IPをブロック
     */
    public function blockIP($ip, $reason, $durationMinutes = null) {
        try {
            $expiresAt = $durationMinutes ? 
                date('Y-m-d H:i:s', strtotime("+{$durationMinutes} minutes")) : null;
            
            $stmt = $this->pdo->prepare("
                INSERT INTO blocked_ips (ip_address, reason, is_permanent, expires_at, blocked_by)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    reason = VALUES(reason),
                    is_permanent = VALUES(is_permanent),
                    expires_at = VALUES(expires_at),
                    block_count = block_count + 1,
                    last_attempt_at = NOW()
            ");
            
            $stmt->execute([
                $ip,
                $reason,
                $durationMinutes === null ? 1 : 0,
                $expiresAt,
                $_SESSION['user_id'] ?? null
            ]);
            
            $this->logEvent('ip_blocked', 'high', [
                'description' => "IPブロック: {$ip} - {$reason}",
                'auto_action' => 'ip_blocked'
            ]);
            
            return true;
            
        } catch (PDOException $e) {
            error_log("IP block error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * IPがブロックされているか確認
     */
    public function isIPBlocked($ip = null) {
        $ip = $ip ?? $this->getClientIP();
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM blocked_ips
                WHERE ip_address = ?
                AND (is_permanent = 1 OR expires_at > NOW())
            ");
            $stmt->execute([$ip]);
            $blocked = $stmt->fetch();
            
            if ($blocked) {
                // アクセス試行を記録
                $this->pdo->prepare("
                    UPDATE blocked_ips 
                    SET last_attempt_at = NOW(), block_count = block_count + 1
                    WHERE id = ?
                ")->execute([$blocked['id']]);
                
                return $blocked;
            }
            
        } catch (PDOException $e) {
            // テーブルがない場合は通過
        }
        
        return false;
    }
    
    /**
     * IPブロックを解除
     */
    public function unblockIP($ip) {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM blocked_ips WHERE ip_address = ?");
            $stmt->execute([$ip]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    // ========================================
    // セッション監視
    // ========================================
    
    /**
     * セッションハイジャックを検出
     */
    public function detectSessionHijack() {
        if ($this->getSetting('session_hijack_detection') !== 'true') {
            return false;
        }
        
        $currentFingerprint = $this->generateFingerprint();
        $currentIP = $this->getClientIP();
        
        // セッションにフィンガープリントが保存されているか
        if (isset($_SESSION['security_fingerprint'])) {
            if ($_SESSION['security_fingerprint'] !== $currentFingerprint) {
                // フィンガープリントが変わった（疑わしい）
                $this->logEvent('session_hijack', 'critical', [
                    'user_id' => $_SESSION['user_id'] ?? null,
                    'description' => 'セッションハイジャックの疑い: フィンガープリント変更',
                    'raw_data' => [
                        'old_fingerprint' => $_SESSION['security_fingerprint'],
                        'new_fingerprint' => $currentFingerprint,
                        'old_ip' => $_SESSION['security_ip'] ?? 'unknown',
                        'new_ip' => $currentIP
                    ]
                ]);
                
                return true;
            }
        } else {
            // 初回：フィンガープリントを保存
            $_SESSION['security_fingerprint'] = $currentFingerprint;
            $_SESSION['security_ip'] = $currentIP;
        }
        
        return false;
    }
    
    // ========================================
    // 攻撃検出
    // ========================================
    
    /**
     * SQLインジェクション試行を検出
     */
    public function detectSQLInjection($input) {
        $patterns = [
            '/(\%27)|(\')|(\-\-)|(\%23)|(#)/i',
            '/((\%3D)|(=))[^\n]*((\%27)|(\')|(\-\-)|(\%3B)|(;))/i',
            '/\w*((\%27)|(\'))((\%6F)|o|(\%4F))((\%72)|r|(\%52))/i',
            '/((\%27)|(\'))union/i',
            '/exec(\s|\+)+(s|x)p\w+/i',
            '/UNION(\s+)SELECT/i',
            '/INSERT(\s+)INTO/i',
            '/DELETE(\s+)FROM/i',
            '/DROP(\s+)TABLE/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                $this->logEvent('sql_injection', 'critical', [
                    'description' => 'SQLインジェクション試行を検出',
                    'raw_data' => ['input' => substr($input, 0, 500)]
                ]);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * XSS試行を検出
     */
    public function detectXSS($input) {
        $patterns = [
            '/<script\b[^>]*>(.*?)<\/script>/is',
            '/javascript:/i',
            '/on\w+\s*=/i',
            '/<iframe/i',
            '/<object/i',
            '/<embed/i',
            '/expression\s*\(/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                $this->logEvent('xss_attempt', 'high', [
                    'description' => 'XSS攻撃試行を検出',
                    'raw_data' => ['input' => substr($input, 0, 500)]
                ]);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 全入力をスキャン
     */
    public function scanRequest() {
        $allInput = array_merge($_GET, $_POST);
        
        foreach ($allInput as $key => $value) {
            if (is_string($value)) {
                $this->detectSQLInjection($value);
                $this->detectXSS($value);
            }
        }
    }
    
    // ========================================
    // レポート
    // ========================================
    
    /**
     * セキュリティサマリーを取得
     */
    public function getSummary($hours = 24) {
        $summary = [
            'total_events' => 0,
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'blocked_ips' => 0,
            'login_failures' => 0,
            'top_ips' => [],
            'top_events' => []
        ];
        
        try {
            // 重要度別カウント
            $stmt = $this->pdo->prepare("
                SELECT severity, COUNT(*) as count
                FROM security_logs
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY severity
            ");
            $stmt->execute([$hours]);
            while ($row = $stmt->fetch()) {
                $summary[$row['severity']] = (int)$row['count'];
                $summary['total_events'] += (int)$row['count'];
            }
            
            // ブロックIP数
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as count FROM blocked_ips
                WHERE is_permanent = 1 OR expires_at > NOW()
            ");
            $summary['blocked_ips'] = (int)$stmt->fetch()['count'];
            
            // ログイン失敗数
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count FROM login_attempts
                WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            $stmt->execute([$hours]);
            $summary['login_failures'] = (int)$stmt->fetch()['count'];
            
            // 頻出IP
            $stmt = $this->pdo->prepare("
                SELECT ip_address, COUNT(*) as count
                FROM security_logs
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY ip_address
                ORDER BY count DESC
                LIMIT 10
            ");
            $stmt->execute([$hours]);
            $summary['top_ips'] = $stmt->fetchAll();
            
            // 頻出イベント
            $stmt = $this->pdo->prepare("
                SELECT event_type, COUNT(*) as count
                FROM security_logs
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY event_type
                ORDER BY count DESC
            ");
            $stmt->execute([$hours]);
            $summary['top_events'] = $stmt->fetchAll();
            
        } catch (PDOException $e) {
            // テーブルがない場合
        }
        
        return $summary;
    }
}

/**
 * グローバルインスタンス取得
 */
function getSecurity() {
    static $security = null;
    
    if ($security === null) {
        // getDB()を使用してデータベース接続を取得
        $pdo = function_exists('getDB') ? getDB() : null;
        $security = new Security($pdo);
    }
    
    return $security;
}
