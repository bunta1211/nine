-- エラーログテーブル
-- JavaScriptエラー、APIエラーを自動収集

CREATE TABLE IF NOT EXISTS error_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    error_type ENUM('js', 'api', 'php') DEFAULT 'js' COMMENT 'エラー種別',
    error_message TEXT NOT NULL COMMENT 'エラーメッセージ',
    error_stack TEXT COMMENT 'スタックトレース',
    url VARCHAR(500) COMMENT '発生URL',
    user_agent VARCHAR(500) COMMENT 'ブラウザ情報',
    user_id INT COMMENT 'ユーザーID（ログイン時）',
    ip_address VARCHAR(45) COMMENT 'IPアドレス',
    extra_data JSON COMMENT '追加情報',
    occurrence_count INT DEFAULT 1 COMMENT '発生回数',
    first_occurred_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '初回発生日時',
    last_occurred_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最終発生日時',
    is_resolved TINYINT(1) DEFAULT 0 COMMENT '解決済みフラグ',
    resolved_at DATETIME COMMENT '解決日時',
    resolved_by INT COMMENT '解決者ID',
    notes TEXT COMMENT 'メモ',
    analysis_note TEXT COMMENT '自動分析（原因の目安・調査のヒント。中学生にも分かる日本語）',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_error_type (error_type),
    INDEX idx_user_id (user_id),
    INDEX idx_is_resolved (is_resolved),
    INDEX idx_last_occurred (last_occurred_at),
    INDEX idx_error_hash (error_message(100), url(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ヘルスチェック用テーブル
CREATE TABLE IF NOT EXISTS health_checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    check_name VARCHAR(100) NOT NULL COMMENT 'チェック名',
    status ENUM('ok', 'warning', 'error') DEFAULT 'ok' COMMENT 'ステータス',
    message TEXT COMMENT 'メッセージ',
    response_time_ms INT COMMENT 'レスポンス時間（ミリ秒）',
    checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_check_name (check_name),
    INDEX idx_status (status),
    INDEX idx_checked_at (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API使用状況ログ
CREATE TABLE IF NOT EXISTS api_usage_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(200) NOT NULL COMMENT 'APIエンドポイント',
    method VARCHAR(10) DEFAULT 'GET' COMMENT 'HTTPメソッド',
    status_code INT COMMENT 'ステータスコード',
    response_time_ms INT COMMENT 'レスポンス時間',
    user_id INT COMMENT 'ユーザーID',
    ip_address VARCHAR(45) COMMENT 'IPアドレス',
    request_size INT COMMENT 'リクエストサイズ',
    response_size INT COMMENT 'レスポンスサイズ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_endpoint (endpoint),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    INDEX idx_status_code (status_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
