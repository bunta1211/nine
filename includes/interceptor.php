<?php
/**
 * 自動迎撃システム（Interceptor）
 * 
 * 攻撃を検出し、自動的にブロック＆情報収集
 * 
 * 迎撃レベル:
 * 1: 監視のみ（ログ記録）
 * 2: 警告（複数回の不審な行動でブロック）
 * 3: 積極防御（不審な行動を即座にブロック）
 * 4: 最大防御（疑わしいアクセスは全てブロック）
 */

class Interceptor {
    private $pdo;
    private $security;
    private $level = 3; // デフォルト迎撃レベル
    private $clientInfo = null;
    
    // 攻撃パターン定義
    private $attackPatterns = [
        'sql_injection' => [
            'patterns' => [
                '/(\%27)|(\')|(\-\-)|(\%23)|(#)/i',
                '/((\%3D)|(=))[^\n]*((\%27)|(\')|(\-\-)|(\%3B)|(;))/i',
                '/\w*((\%27)|(\'))((\%6F)|o|(\%4F))((\%72)|r|(\%52))/i',
                '/((\%27)|(\'))union/i',
                '/exec(\s|\+)+(s|x)p\w+/i',
                '/UNION(\s+)SELECT/i',
                '/INSERT(\s+)INTO/i',
                '/DELETE(\s+)FROM/i',
                '/DROP(\s+)TABLE/i',
                '/UPDATE(\s+)\w+(\s+)SET/i',
                '/SELECT(\s+).*(\s+)FROM/i',
                '/LOAD_FILE/i',
                '/INTO(\s+)OUTFILE/i',
                '/BENCHMARK\s*\(/i',
                '/SLEEP\s*\(/i'
            ],
            'severity' => 'critical',
            'auto_block' => true,
            'block_duration' => 1440 // 24時間
        ],
        'xss' => [
            'patterns' => [
                '/<script\b[^>]*>(.*?)<\/script>/is',
                '/javascript\s*:/i',
                '/on\w+\s*=/i',
                '/<iframe/i',
                '/<object/i',
                '/<embed/i',
                '/<svg.*?onload/i',
                '/expression\s*\(/i',
                '/vbscript\s*:/i',
                '/data\s*:.*?base64/i'
            ],
            'severity' => 'high',
            'auto_block' => true,
            'block_duration' => 720 // 12時間
        ],
        'path_traversal' => [
            'patterns' => [
                '/\.\.\//i',
                '/\.\.\\\/i',
                '/%2e%2e%2f/i',
                '/%2e%2e\//i',
                '/\.%2e\//i',
                '/%2e\.\//i',
                '/etc\/passwd/i',
                '/etc\/shadow/i',
                '/proc\/self/i',
                '/windows\/system32/i'
            ],
            'severity' => 'critical',
            'auto_block' => true,
            'block_duration' => 1440
        ],
        'command_injection' => [
            'patterns' => [
                '/;\s*(ls|cat|wget|curl|bash|sh|python|perl|php|nc|netcat)/i',
                '/\|\s*(ls|cat|wget|curl|bash|sh)/i',
                '/`.*`/',
                '/\$\(.*\)/',
                '/system\s*\(/i',
                '/exec\s*\(/i',
                '/passthru\s*\(/i',
                '/shell_exec\s*\(/i',
                '/eval\s*\(/i'
            ],
            'severity' => 'critical',
            'auto_block' => true,
            'block_duration' => 2880 // 48時間
        ],
        'scanner' => [
            'patterns' => [
                '/sqlmap/i',
                '/nikto/i',
                '/nmap/i',
                '/masscan/i',
                '/acunetix/i',
                '/nessus/i',
                '/burpsuite/i',
                '/havij/i',
                '/w3af/i',
                '/wpscan/i',
                '/dirbuster/i',
                '/gobuster/i',
                '/dirb/i',
                '/ffuf/i',
                '/nuclei/i',
                '/zap/i'
            ],
            'severity' => 'critical',
            'auto_block' => true,
            'block_duration' => 10080 // 7日間
        ],
        'bot' => [
            'patterns' => [
                '/python-requests/i',
                '/python-urllib/i',
                '/libwww-perl/i',
                '/lwp-trivial/i',
                '/wget/i',
                '/curl\//i',
                '/httpclient/i',
                '/java\//i',
                '/okhttp/i'
            ],
            'severity' => 'medium',
            'auto_block' => false,
            'block_duration' => 60
        ],
        'suspicious_file' => [
            'patterns' => [
                '/\.php$/i',
                '/\.asp$/i',
                '/\.aspx$/i',
                '/\.jsp$/i',
                '/\.cgi$/i',
                '/\.pl$/i',
                '/wp-admin/i',
                '/wp-login/i',
                '/wp-content/i',
                '/phpmyadmin/i',
                '/administrator/i',
                '/admin\.php/i',
                '/config\.php/i',
                '/\.env$/i',
                '/\.git/i',
                '/\.svn/i'
            ],
            'severity' => 'high',
            'auto_block' => true,
            'block_duration' => 360 // 6時間
        ]
    ];
    
    public function __construct($pdo, $security = null) {
        $this->pdo = $pdo;
        $this->security = $security ?? getSecurity();
        $this->loadLevel();
    }
    
    /**
     * 迎撃レベルを読み込み
     */
    private function loadLevel() {
        // PDOがnullの場合はデフォルト値を使用
        if (!$this->pdo) {
            return;
        }
        
        try {
            $stmt = $this->pdo->query("
                SELECT setting_value FROM security_settings 
                WHERE setting_key = 'intercept_level'
            ");
            $result = $stmt->fetch();
            if ($result) {
                $this->level = (int)$result['setting_value'];
            }
        } catch (PDOException $e) {
            // デフォルト値を使用
        }
    }
    
    /**
     * 全リクエストをスキャンして迎撃
     */
    public function intercept() {
        // レベル0は迎撃無効
        if ($this->level === 0) {
            return false;
        }
        
        // messages.php / upload.php へのPOSTはスキップ（ファイル送信・誤検知防止）
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($method === 'POST' && (strpos($uri, 'messages.php') !== false || strpos($uri, 'upload.php') !== false)) {
            return false;
        }
        // チャット・ポーリングで使うGETは迎撃スキップ（403でメッセージが消える問題を防ぐ）
        if ($method === 'GET' && strpos($uri, '/api/') !== false) {
            $getSkipPaths = ['messages.php', 'conversations.php', 'friends.php', 'tasks.php', 'memos.php', 'notifications.php', 'calls.php'];
            foreach ($getSkipPaths as $path) {
                if (strpos($uri, $path) !== false) {
                    return false;
                }
            }
        }
        
        $this->clientInfo = $this->security->collectClientInfo();
        $blocked = false;
        $threats = [];
        
        // 1. ユーザーエージェントチェック
        $uaThreat = $this->checkUserAgent();
        if ($uaThreat) {
            $threats[] = $uaThreat;
        }
        
        // 2. リクエストURIチェック
        $uriThreat = $this->checkRequestUri();
        if ($uriThreat) {
            $threats[] = $uriThreat;
        }
        
        // 3. パラメータチェック（GET/POST）
        $paramThreats = $this->checkParameters();
        $threats = array_merge($threats, $paramThreats);
        
        // 4. レート制限チェック
        $rateThreat = $this->checkRateLimit();
        if ($rateThreat) {
            $threats[] = $rateThreat;
        }
        
        // 5. 不審なヘッダーチェック
        $headerThreat = $this->checkHeaders();
        if ($headerThreat) {
            $threats[] = $headerThreat;
        }
        
        // 脅威が検出された場合
        if (!empty($threats)) {
            $onlyRateLimit = (count($threats) === 1 && ($threats[0]['type'] ?? '') === 'rate_limit');
            if ($onlyRateLimit) {
                http_response_code(429);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error' => 'リクエストが多すぎます。しばらく待ってからお試しください。',
                    'code' => 'RATE_LIMIT'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $blocked = $this->handleThreats($threats);
        }
        
        return $blocked;
    }
    
    /**
     * ユーザーエージェントチェック
     */
    private function checkUserAgent() {
        $ua = $this->clientInfo['user_agent'] ?? '';
        
        // 空のUA
        if (empty($ua) && $this->level >= 3) {
            return [
                'type' => 'empty_ua',
                'severity' => 'high',
                'message' => '空のユーザーエージェント',
                'auto_block' => $this->level >= 4,
                'duration' => 60
            ];
        }
        
        // スキャナー検出
        foreach ($this->attackPatterns['scanner']['patterns'] as $pattern) {
            if (preg_match($pattern, $ua)) {
                return [
                    'type' => 'scanner',
                    'severity' => 'critical',
                    'message' => 'セキュリティスキャナー検出: ' . $pattern,
                    'auto_block' => true,
                    'duration' => $this->attackPatterns['scanner']['block_duration']
                ];
            }
        }
        
        // ボット検出
        if ($this->level >= 3) {
            foreach ($this->attackPatterns['bot']['patterns'] as $pattern) {
                if (preg_match($pattern, $ua)) {
                    return [
                        'type' => 'bot',
                        'severity' => 'medium',
                        'message' => '自動化ツール検出: ' . $pattern,
                        'auto_block' => $this->level >= 4,
                        'duration' => 30
                    ];
                }
            }
        }
        
        return null;
    }
    
    /**
     * リクエストURIチェック
     */
    private function checkRequestUri() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        // パストラバーサル
        foreach ($this->attackPatterns['path_traversal']['patterns'] as $pattern) {
            if (preg_match($pattern, $uri)) {
                return [
                    'type' => 'path_traversal',
                    'severity' => 'critical',
                    'message' => 'パストラバーサル攻撃: ' . substr($uri, 0, 100),
                    'auto_block' => true,
                    'duration' => $this->attackPatterns['path_traversal']['block_duration']
                ];
            }
        }
        
        // 不審なファイルアクセス
        foreach ($this->attackPatterns['suspicious_file']['patterns'] as $pattern) {
            if (preg_match($pattern, $uri)) {
                // 正規のAPIアクセスは除外
                if (strpos($uri, '/api/') === 0 || strpos($uri, '/admin/') === 0) {
                    continue;
                }
                return [
                    'type' => 'suspicious_file',
                    'severity' => 'high',
                    'message' => '不審なファイルアクセス: ' . substr($uri, 0, 100),
                    'auto_block' => $this->level >= 3,
                    'duration' => $this->attackPatterns['suspicious_file']['block_duration']
                ];
            }
        }
        
        return null;
    }
    
    /**
     * パラメータチェック
     */
    private function checkParameters() {
        $threats = [];
        $allParams = array_merge($_GET, $_POST);
        
        // リクエストボディもチェック（php://input は1回しか読めないため共有キャッシュを使用）
        $rawInput = $GLOBALS['_API_RAW_INPUT'] ?? file_get_contents('php://input');
        if ($rawInput) {
            $jsonInput = json_decode($rawInput, true);
            if (is_array($jsonInput)) {
                $allParams = array_merge($allParams, $jsonInput);
            }
        }
        
        // 正当なAPIの action 値はスキャン対象から除外（誤検知防止：update_design 等）
        $safeActions = ['update_design', 'get', 'update_notification', 'update_sound', 'update_detail', 'update_call', 'update_ringtone_preview'];
        $isSafeAction = isset($allParams['action']) && in_array($allParams['action'], $safeActions, true);

        foreach ($allParams as $key => $value) {
            if (!is_string($value)) continue;
            
            // 正当なカラー値（#hex, rgb(), rgba()）はスキップ（誤検知防止：accent_color 等）
            if (preg_match('/^#[0-9a-fA-F]{3,8}$/i', $value) || preg_match('/^rgba?\([^)]*\)$/i', $value)) {
                continue;
            }
            // 設定APIの正当な action のときはパラメータスキャンをスキップ（デザイン保存など）
            if ($key === 'action' && $isSafeAction) {
                continue;
            }
            
            // SQLインジェクション
            foreach ($this->attackPatterns['sql_injection']['patterns'] as $pattern) {
                if (preg_match($pattern, $value)) {
                    $threats[] = [
                        'type' => 'sql_injection',
                        'severity' => 'critical',
                        'message' => "SQLインジェクション (param: {$key})",
                        'auto_block' => true,
                        'duration' => $this->attackPatterns['sql_injection']['block_duration'],
                        'data' => ['param' => $key, 'value' => substr($value, 0, 100)]
                    ];
                    break;
                }
            }
            
            // XSS
            foreach ($this->attackPatterns['xss']['patterns'] as $pattern) {
                if (preg_match($pattern, $value)) {
                    $threats[] = [
                        'type' => 'xss',
                        'severity' => 'high',
                        'message' => "XSS攻撃 (param: {$key})",
                        'auto_block' => true,
                        'duration' => $this->attackPatterns['xss']['block_duration'],
                        'data' => ['param' => $key, 'value' => substr($value, 0, 100)]
                    ];
                    break;
                }
            }
            
            // コマンドインジェクション
            foreach ($this->attackPatterns['command_injection']['patterns'] as $pattern) {
                if (preg_match($pattern, $value)) {
                    $threats[] = [
                        'type' => 'command_injection',
                        'severity' => 'critical',
                        'message' => "コマンドインジェクション (param: {$key})",
                        'auto_block' => true,
                        'duration' => $this->attackPatterns['command_injection']['block_duration'],
                        'data' => ['param' => $key, 'value' => substr($value, 0, 100)]
                    ];
                    break;
                }
            }
        }
        
        return $threats;
    }
    
    /**
     * レート制限チェック
     */
    private function checkRateLimit() {
        $ip = $this->clientInfo['ip'];
        $key = 'rate_' . md5($ip);
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['count' => 0, 'start' => time()];
        }
        
        $data = $_SESSION[$key];
        $elapsed = time() - $data['start'];
        
        // 1分経過でリセット
        if ($elapsed > 60) {
            $_SESSION[$key] = ['count' => 1, 'start' => time()];
            return null;
        }
        
        $_SESSION[$key]['count']++;
        
        // 1分間に100リクエスト以上
        $limit = $this->level >= 4 ? 60 : ($this->level >= 3 ? 100 : 200);
        
        if ($_SESSION[$key]['count'] > $limit) {
            return [
                'type' => 'rate_limit',
                'severity' => 'high',
                'message' => "レート制限超過: {$_SESSION[$key]['count']}リクエスト/分",
                'auto_block' => true,
                'duration' => 30
            ];
        }
        
        return null;
    }
    
    /**
     * ヘッダーチェック
     */
    private function checkHeaders() {
        // X-Forwarded-For偽装チェック
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff && substr_count($xff, ',') > 5) {
            return [
                'type' => 'header_manipulation',
                'severity' => 'medium',
                'message' => 'X-Forwarded-For偽装の可能性',
                'auto_block' => $this->level >= 4,
                'duration' => 60
            ];
        }
        
        // 不審なホストヘッダー
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host && !preg_match('/^[\w\.\-:]+$/', $host)) {
            return [
                'type' => 'header_manipulation',
                'severity' => 'high',
                'message' => '不正なHostヘッダー',
                'auto_block' => true,
                'duration' => 120
            ];
        }
        
        return null;
    }
    
    /**
     * 脅威を処理
     */
    private function handleThreats($threats) {
        $blocked = false;
        $ip = $this->clientInfo['ip'];
        
        // 最も深刻な脅威を特定
        $maxSeverity = 'low';
        $severityOrder = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $shouldBlock = false;
        $blockDuration = 60;
        
        foreach ($threats as $threat) {
            // 重要度を更新
            if ($severityOrder[$threat['severity']] > $severityOrder[$maxSeverity]) {
                $maxSeverity = $threat['severity'];
            }
            
            // ブロック判定
            if ($threat['auto_block']) {
                $shouldBlock = true;
                if ($threat['duration'] > $blockDuration) {
                    $blockDuration = $threat['duration'];
                }
            }
            
            // セキュリティログに記録
            $this->security->logEvent($threat['type'], $threat['severity'], [
                'description' => $threat['message'],
                'raw_data' => $threat['data'] ?? null,
                'auto_action' => $shouldBlock ? 'blocked' : 'logged'
            ]);
        }
        
        // IP詳細情報を取得して保存
        $this->enrichIPInfo($ip);
        
        // ブロック実行
        if ($shouldBlock && $this->level >= 2) {
            $reason = "自動迎撃: " . $threats[0]['message'];
            $this->security->blockIP($ip, $reason, $blockDuration);
            $blocked = true;
        }
        
        return $blocked;
    }
    
    /**
     * IP情報を詳細に取得
     */
    private function enrichIPInfo($ip) {
        // IPInfoモジュールで詳細情報を取得
        if (class_exists('IPInfo')) {
            $ipInfo = new IPInfo();
            $details = $ipInfo->lookup($ip);
            
            if ($details) {
                // セキュリティログを更新
                try {
                    $stmt = $this->pdo->prepare("
                        UPDATE security_logs 
                        SET ip_info = ?
                        WHERE ip_address = ? 
                        AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                    ");
                    $stmt->execute([json_encode($details), $ip]);
                } catch (PDOException $e) {
                    // 静かに失敗
                }
            }
        }
    }
    
    /**
     * 迎撃レベルを設定
     */
    public function setLevel($level) {
        $this->level = max(0, min(4, (int)$level));
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO security_settings (setting_key, setting_value, description)
                VALUES ('intercept_level', ?, '迎撃レベル (0-4)')
                ON DUPLICATE KEY UPDATE setting_value = ?
            ");
            $stmt->execute([$this->level, $this->level]);
        } catch (PDOException $e) {
            // 静かに失敗
        }
    }
    
    /**
     * 現在の迎撃レベルを取得
     */
    public function getLevel() {
        return $this->level;
    }
    
    /**
     * ブロックレスポンスを返す
     */
    public function sendBlockedResponse() {
        http_response_code(403);
        
        // APIリクエストまたはJSON希望の場合はJSONで返す（携帯からのfetchでもHTMLにならないよう）
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $wantJson = (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
            || (strpos($uri, '/api/') !== false);
        if ($wantJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'アクセスが拒否されました',
                'code' => 'BLOCKED'
            ]);
        } else {
            // HTMLレスポンス
            include __DIR__ . '/../templates/blocked.php';
        }
        exit;
    }
}

/**
 * グローバルインスタンス取得
 */
function getInterceptor() {
    static $interceptor = null;
    
    if ($interceptor === null) {
        // getDB()を使用してデータベース接続を取得
        $pdo = function_exists('getDB') ? getDB() : null;
        $interceptor = new Interceptor($pdo);
    }
    
    return $interceptor;
}
