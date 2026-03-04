<?php
/**
 * IP情報詳細取得モジュール
 * 
 * 複数の無料APIを使用してIPアドレスから詳細情報を取得
 * - 国、都市、地域
 * - ISP、組織名
 * - VPN/プロキシ検出
 * - 緯度・経度
 * - 脅威スコア
 */

class IPInfo {
    private $cache = [];
    private $cacheTime = 3600; // 1時間キャッシュ
    private $pdo;
    
    // APIエンドポイント
    private $apis = [
        'ip-api' => 'http://ip-api.com/json/{IP}?fields=status,message,continent,country,countryCode,region,regionName,city,zip,lat,lon,timezone,isp,org,as,asname,mobile,proxy,hosting,query',
        'ipwhois' => 'http://ipwho.is/{IP}',
        'ipapi' => 'https://ipapi.co/{IP}/json/'
    ];
    
    public function __construct($pdo = null) {
        $this->pdo = $pdo;
    }
    
    /**
     * IP情報を取得
     */
    public function lookup($ip) {
        // バリデーション
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        
        // プライベートIP
        if ($this->isPrivateIP($ip)) {
            return [
                'ip' => $ip,
                'type' => 'private',
                'country' => 'ローカルネットワーク',
                'country_code' => 'LO',
                'city' => '-',
                'message' => 'プライベートIPアドレス'
            ];
        }
        
        // キャッシュチェック
        if (isset($this->cache[$ip]) && $this->cache[$ip]['time'] > time() - $this->cacheTime) {
            return $this->cache[$ip]['data'];
        }
        
        // DBキャッシュチェック
        $cached = $this->getFromDB($ip);
        if ($cached) {
            return $cached;
        }
        
        // API呼び出し
        $result = $this->fetchFromAPIs($ip);
        
        if ($result) {
            // キャッシュに保存
            $this->cache[$ip] = ['data' => $result, 'time' => time()];
            $this->saveToDB($ip, $result);
        }
        
        return $result;
    }
    
    /**
     * 複数のAPIから情報取得
     */
    private function fetchFromAPIs($ip) {
        $result = [
            'ip' => $ip,
            'lookup_time' => date('Y-m-d H:i:s')
        ];
        
        // ip-api.com（最も詳細）
        $data = $this->callAPI('ip-api', $ip);
        if ($data && isset($data['status']) && $data['status'] === 'success') {
            $result = array_merge($result, [
                'country' => $data['country'] ?? null,
                'country_code' => $data['countryCode'] ?? null,
                'country_flag' => $this->getCountryFlag($data['countryCode'] ?? ''),
                'continent' => $data['continent'] ?? null,
                'region' => $data['regionName'] ?? null,
                'region_code' => $data['region'] ?? null,
                'city' => $data['city'] ?? null,
                'zip' => $data['zip'] ?? null,
                'latitude' => $data['lat'] ?? null,
                'longitude' => $data['lon'] ?? null,
                'timezone' => $data['timezone'] ?? null,
                'isp' => $data['isp'] ?? null,
                'org' => $data['org'] ?? null,
                'as_number' => $data['as'] ?? null,
                'as_name' => $data['asname'] ?? null,
                'is_mobile' => (bool)($data['mobile'] ?? false),
                'is_proxy' => (bool)($data['proxy'] ?? false),
                'is_hosting' => (bool)($data['hosting'] ?? false)
            ]);
        }
        
        // 追加情報を取得（必要に応じて）
        if (empty($result['country'])) {
            $data = $this->callAPI('ipwhois', $ip);
            if ($data && ($data['success'] ?? false)) {
                $result = array_merge($result, [
                    'country' => $data['country'] ?? null,
                    'country_code' => $data['country_code'] ?? null,
                    'region' => $data['region'] ?? null,
                    'city' => $data['city'] ?? null,
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                    'isp' => $data['connection']['isp'] ?? null,
                    'org' => $data['connection']['org'] ?? null
                ]);
            }
        }
        
        // 国旗を設定
        if (!empty($result['country_code'])) {
            $result['country_flag'] = $this->getCountryFlag($result['country_code']);
        }
        
        // 脅威レベルを計算
        $result['threat_level'] = $this->calculateThreatLevel($result);
        $result['threat_description'] = $this->getThreatDescription($result);
        
        // 人間が読みやすい形式に変換
        $result['display'] = $this->formatForDisplay($result);
        
        return $result;
    }
    
    /**
     * API呼び出し
     */
    private function callAPI($apiName, $ip) {
        if (!isset($this->apis[$apiName])) {
            return null;
        }
        
        $url = str_replace('{IP}', $ip, $this->apis[$apiName]);
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'header' => 'User-Agent: Social9-Security/1.0'
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response) {
            return json_decode($response, true);
        }
        
        return null;
    }
    
    /**
     * 国旗絵文字を取得
     */
    private function getCountryFlag($countryCode) {
        if (strlen($countryCode) !== 2) {
            return '🌍';
        }
        
        $countryCode = strtoupper($countryCode);
        $flag = '';
        
        for ($i = 0; $i < 2; $i++) {
            $flag .= mb_chr(ord($countryCode[$i]) - ord('A') + 0x1F1E6);
        }
        
        return $flag;
    }
    
    /**
     * 脅威レベルを計算
     */
    private function calculateThreatLevel($data) {
        $score = 0;
        
        // プロキシ/VPN使用
        if (!empty($data['is_proxy'])) {
            $score += 30;
        }
        
        // ホスティング/データセンター
        if (!empty($data['is_hosting'])) {
            $score += 20;
        }
        
        // 特定の高リスク国（実際の脅威統計に基づく）
        $highRiskCountries = ['CN', 'RU', 'KP', 'IR'];
        if (in_array($data['country_code'] ?? '', $highRiskCountries)) {
            $score += 15;
        }
        
        // 中リスク国
        $mediumRiskCountries = ['BR', 'IN', 'VN', 'UA', 'RO'];
        if (in_array($data['country_code'] ?? '', $mediumRiskCountries)) {
            $score += 10;
        }
        
        // モバイル接続
        if (!empty($data['is_mobile'])) {
            $score += 5;
        }
        
        // レベル判定
        if ($score >= 50) return 'critical';
        if ($score >= 30) return 'high';
        if ($score >= 15) return 'medium';
        return 'low';
    }
    
    /**
     * 脅威の説明を生成
     */
    private function getThreatDescription($data) {
        $warnings = [];
        
        if (!empty($data['is_proxy'])) {
            $warnings[] = '⚠️ VPN/プロキシ使用中';
        }
        
        if (!empty($data['is_hosting'])) {
            $warnings[] = '⚠️ データセンター/クラウドからのアクセス';
        }
        
        $highRiskCountries = ['CN', 'RU', 'KP', 'IR'];
        if (in_array($data['country_code'] ?? '', $highRiskCountries)) {
            $warnings[] = '⚠️ 高リスク地域からのアクセス';
        }
        
        if (empty($warnings)) {
            return '通常のアクセス';
        }
        
        return implode(' / ', $warnings);
    }
    
    /**
     * 表示用にフォーマット
     */
    private function formatForDisplay($data) {
        $display = [];
        
        // 場所情報
        $location = [];
        if (!empty($data['city'])) $location[] = $data['city'];
        if (!empty($data['region'])) $location[] = $data['region'];
        if (!empty($data['country'])) {
            $flag = $data['country_flag'] ?? '';
            $location[] = $flag . ' ' . $data['country'];
        }
        $display['location'] = implode(', ', $location) ?: '不明';
        
        // 完全な住所（推定）
        $address = [];
        if (!empty($data['zip'])) $address[] = '〒' . $data['zip'];
        if (!empty($data['country'])) $address[] = $data['country'];
        if (!empty($data['region'])) $address[] = $data['region'];
        if (!empty($data['city'])) $address[] = $data['city'];
        $display['address'] = implode(' ', $address) ?: '不明';
        
        // プロバイダ情報
        $display['provider'] = $data['isp'] ?? $data['org'] ?? '不明';
        $display['organization'] = $data['org'] ?? $data['isp'] ?? '不明';
        
        // AS情報
        $display['network'] = $data['as_name'] ?? $data['as_number'] ?? '不明';
        
        // 接続タイプ
        $connectionType = [];
        if (!empty($data['is_mobile'])) $connectionType[] = '📱 モバイル';
        if (!empty($data['is_proxy'])) $connectionType[] = '🔒 VPN/プロキシ';
        if (!empty($data['is_hosting'])) $connectionType[] = '☁️ クラウド/DC';
        $display['connection_type'] = implode(', ', $connectionType) ?: '🖥️ 固定回線';
        
        // 座標
        if (!empty($data['latitude']) && !empty($data['longitude'])) {
            $display['coordinates'] = round($data['latitude'], 4) . ', ' . round($data['longitude'], 4);
            $display['map_url'] = "https://www.google.com/maps?q={$data['latitude']},{$data['longitude']}";
        }
        
        // タイムゾーン
        $display['timezone'] = $data['timezone'] ?? '不明';
        
        // 脅威レベル（色付き）
        $threatColors = [
            'low' => '🟢',
            'medium' => '🟡',
            'high' => '🟠',
            'critical' => '🔴'
        ];
        $display['threat_level'] = ($threatColors[$data['threat_level'] ?? 'low'] ?? '⚪') . ' ' . 
            $this->translateThreatLevel($data['threat_level'] ?? 'low');
        
        return $display;
    }
    
    /**
     * 脅威レベルを日本語に
     */
    private function translateThreatLevel($level) {
        $translations = [
            'low' => '低リスク',
            'medium' => '中リスク',
            'high' => '高リスク',
            'critical' => '危険'
        ];
        return $translations[$level] ?? '不明';
    }
    
    /**
     * プライベートIPか判定
     */
    private function isPrivateIP($ip) {
        return !filter_var(
            $ip, 
            FILTER_VALIDATE_IP, 
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
    
    /**
     * DBからキャッシュ取得
     */
    private function getFromDB($ip) {
        if (!$this->pdo) return null;
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT ip_info FROM security_logs
                WHERE ip_address = ?
                AND ip_info IS NOT NULL
                AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$ip]);
            $result = $stmt->fetch();
            
            if ($result && $result['ip_info']) {
                return json_decode($result['ip_info'], true);
            }
        } catch (PDOException $e) {
            // 静かに失敗
        }
        
        return null;
    }
    
    /**
     * DBにキャッシュ保存
     */
    private function saveToDB($ip, $data) {
        if (!$this->pdo) return;
        
        try {
            // 最新のログを更新
            $stmt = $this->pdo->prepare("
                UPDATE security_logs
                SET ip_info = ?
                WHERE ip_address = ?
                AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                AND ip_info IS NULL
            ");
            $stmt->execute([json_encode($data), $ip]);
        } catch (PDOException $e) {
            // 静かに失敗
        }
    }
    
    /**
     * 複数IPを一括取得
     */
    public function lookupBatch($ips) {
        $results = [];
        foreach ($ips as $ip) {
            $results[$ip] = $this->lookup($ip);
        }
        return $results;
    }
}

/**
 * グローバル関数
 */
function getIPInfo($ip = null) {
    global $pdo;
    static $ipInfo = null;
    
    if ($ipInfo === null) {
        $ipInfo = new IPInfo($pdo);
    }
    
    if ($ip === null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    }
    
    return $ipInfo->lookup($ip);
}
