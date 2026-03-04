-- ============================================
-- セキュリティ監視テーブル
-- 侵入者・不正アクセスの詳細情報を記録
-- ============================================

-- セキュリティイベントログ
CREATE TABLE IF NOT EXISTS security_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- イベント情報
    event_type ENUM(
        'login_failed',           -- ログイン失敗
        'login_success',          -- ログイン成功
        'brute_force',            -- ブルートフォース攻撃検出
        'session_hijack',         -- セッションハイジャック疑い
        'unauthorized_access',    -- 不正アクセス試行
        'sql_injection',          -- SQLインジェクション試行
        'xss_attempt',            -- XSS攻撃試行
        'csrf_violation',         -- CSRF違反
        'rate_limit',             -- レート制限超過
        'suspicious_activity',    -- 不審な活動
        'admin_access',           -- 管理画面アクセス
        'password_reset',         -- パスワードリセット要求
        'account_locked',         -- アカウントロック
        'ip_blocked',             -- IPブロック
        'file_upload_suspicious', -- 不審なファイルアップロード
        'api_abuse'               -- API乱用
    ) NOT NULL,
    
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    
    -- 対象情報
    target_user_id INT COMMENT '対象ユーザーID',
    target_username VARCHAR(100) COMMENT '試行されたユーザー名',
    target_resource VARCHAR(500) COMMENT '対象リソース（URL等）',
    
    -- 攻撃者情報（可能な限り詳細に）
    ip_address VARCHAR(45) NOT NULL COMMENT 'IPアドレス（IPv6対応）',
    ip_info JSON COMMENT 'IP詳細情報（地理情報等）',
    
    user_agent TEXT COMMENT 'ユーザーエージェント',
    user_agent_parsed JSON COMMENT 'パースされたUA情報',
    
    referer VARCHAR(1000) COMMENT 'リファラー',
    request_method VARCHAR(10) COMMENT 'HTTPメソッド',
    request_uri TEXT COMMENT 'リクエストURI',
    request_params JSON COMMENT 'リクエストパラメータ（機密情報除く）',
    request_headers JSON COMMENT '全リクエストヘッダー',
    
    -- ブラウザフィンガープリント
    fingerprint_hash VARCHAR(64) COMMENT 'ブラウザフィンガープリントハッシュ',
    fingerprint_data JSON COMMENT 'フィンガープリント詳細',
    
    -- セッション情報
    session_id VARCHAR(128) COMMENT 'セッションID',
    
    -- 詳細情報
    description TEXT COMMENT 'イベント説明',
    raw_data JSON COMMENT '生データ（デバッグ用）',
    
    -- 対応情報
    is_handled TINYINT(1) DEFAULT 0 COMMENT '対応済みフラグ',
    handled_at DATETIME COMMENT '対応日時',
    handled_by INT COMMENT '対応者ID',
    handling_notes TEXT COMMENT '対応メモ',
    
    -- 自動対応
    auto_action_taken VARCHAR(100) COMMENT '自動実行されたアクション',
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_event_type (event_type),
    INDEX idx_severity (severity),
    INDEX idx_ip_address (ip_address),
    INDEX idx_target_user (target_user_id),
    INDEX idx_created_at (created_at),
    INDEX idx_is_handled (is_handled),
    INDEX idx_fingerprint (fingerprint_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- IPブロックリスト
CREATE TABLE IF NOT EXISTS blocked_ips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL COMMENT 'ブロックするIP',
    ip_range_start VARCHAR(45) COMMENT '範囲開始（CIDR用）',
    ip_range_end VARCHAR(45) COMMENT '範囲終了（CIDR用）',
    
    reason TEXT COMMENT 'ブロック理由',
    blocked_by INT COMMENT 'ブロック実行者ID',
    
    is_permanent TINYINT(1) DEFAULT 0 COMMENT '永久ブロック',
    expires_at DATETIME COMMENT '有効期限',
    
    block_count INT DEFAULT 0 COMMENT 'このIPからのブロック回数',
    last_attempt_at DATETIME COMMENT '最後のアクセス試行',
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY uk_ip (ip_address),
    INDEX idx_expires (expires_at),
    INDEX idx_is_permanent (is_permanent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ログイン試行追跡
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    username VARCHAR(100) NOT NULL COMMENT '試行されたユーザー名',
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    
    success TINYINT(1) DEFAULT 0 COMMENT '成功/失敗',
    failure_reason VARCHAR(100) COMMENT '失敗理由',
    
    attempt_count INT DEFAULT 1 COMMENT '連続試行回数',
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_username (username),
    INDEX idx_ip (ip_address),
    INDEX idx_created_at (created_at),
    INDEX idx_ip_username (ip_address, username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 不審なユーザーエージェントパターン
CREATE TABLE IF NOT EXISTS suspicious_user_agents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pattern VARCHAR(500) NOT NULL COMMENT '検出パターン（正規表現）',
    description TEXT COMMENT '説明',
    severity ENUM('low', 'medium', 'high') DEFAULT 'medium',
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 初期データ：不審なUAパターン
INSERT IGNORE INTO suspicious_user_agents (pattern, description, severity) VALUES
('sqlmap', 'SQLインジェクションツール', 'critical'),
('nikto', '脆弱性スキャナ', 'high'),
('nmap', 'ポートスキャナ', 'high'),
('masscan', 'ポートスキャナ', 'high'),
('python-requests', 'スクリプトアクセス（要監視）', 'low'),
('curl/', 'cURLアクセス（要監視）', 'low'),
('wget', 'wgetアクセス', 'low'),
('scrapy', 'スクレイピングツール', 'medium'),
('headless', 'ヘッドレスブラウザ', 'medium'),
('phantomjs', 'ヘッドレスブラウザ', 'medium'),
('selenium', '自動テストツール', 'medium'),
('burpsuite', 'セキュリティテストツール', 'high'),
('dirbuster', 'ディレクトリ探索ツール', 'high'),
('gobuster', 'ディレクトリ探索ツール', 'high'),
('wpscan', 'WordPress脆弱性スキャナ', 'high'),
('havij', 'SQLインジェクションツール', 'critical'),
('acunetix', '脆弱性スキャナ', 'high');


-- セキュリティ設定
CREATE TABLE IF NOT EXISTS security_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    description TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 初期設定
INSERT IGNORE INTO security_settings (setting_key, setting_value, description) VALUES
('max_login_attempts', '5', '最大ログイン試行回数'),
('lockout_duration_minutes', '30', 'ロックアウト時間（分）'),
('brute_force_threshold', '10', 'ブルートフォース検出閾値'),
('rate_limit_requests_per_minute', '60', '1分あたりのリクエスト上限'),
('session_hijack_detection', 'true', 'セッションハイジャック検出'),
('log_all_logins', 'true', '全ログインを記録'),
('auto_block_brute_force', 'true', 'ブルートフォース時に自動ブロック'),
('notify_admin_on_critical', 'true', '重大イベント時に管理者通知'),
('intercept_level', '3', '迎撃レベル (0:無効, 1:監視, 2:警告, 3:積極防御, 4:最大防御)');
