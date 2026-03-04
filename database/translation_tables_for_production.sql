-- =====================================================
-- 翻訳機能用テーブル（本番サーバー用・一括作成）
--
-- 【コピー用】EC2 にこのファイルを /home/ec2-user/ に置いたあと、以下を実行：
--
--   mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < /home/ec2-user/translation_tables_for_production.sql
--
-- 接続情報が違う場合は DOCS/AWS_RDS_SQL_EXECUTE.md の「接続情報の確認」を参照。
-- ※ 既に translation_usage がある場合は migration_auto_translation.sql で
--    cost_usd 等のカラムを追加してください。
-- =====================================================

-- 翻訳キャッシュ（同一テキストの再利用）
CREATE TABLE IF NOT EXISTS translation_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cache_key VARCHAR(64) NOT NULL UNIQUE,
    original_text TEXT NOT NULL,
    translated_text TEXT NOT NULL,
    source_lang VARCHAR(10),
    target_lang VARCHAR(10) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_key (cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 翻訳使用量（予算管理用）
CREATE TABLE IF NOT EXISTS translation_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    character_count INT NOT NULL,
    token_count INT DEFAULT 0,
    source_lang VARCHAR(10),
    target_lang VARCHAR(10) NOT NULL,
    api_provider VARCHAR(20) DEFAULT 'google',
    cost_usd DECIMAL(10, 6) DEFAULT 0,
    message_id INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_created (created_at),
    INDEX idx_monthly (created_at, api_provider),
    INDEX idx_message (message_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- メッセージ別翻訳キャッシュ
CREATE TABLE IF NOT EXISTS message_translations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    target_lang VARCHAR(10) NOT NULL,
    translated_text TEXT NOT NULL,
    api_provider VARCHAR(20) DEFAULT 'openai',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_msg_lang (message_id, target_lang),
    INDEX idx_message_lang (message_id, target_lang),
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 翻訳予算設定（任意）
CREATE TABLE IF NOT EXISTS translation_budget (
    id INT AUTO_INCREMENT PRIMARY KEY,
    month_year VARCHAR(7) NOT NULL,
    budget_jpy INT DEFAULT 30000,
    alert_threshold_percent INT DEFAULT 80,
    is_auto_switch_enabled TINYINT(1) DEFAULT 1,
    fallback_provider VARCHAR(20) DEFAULT 'manual',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_month (month_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO translation_budget (month_year, budget_jpy)
VALUES (DATE_FORMAT(NOW(), '%Y-%m'), 30000)
ON DUPLICATE KEY UPDATE budget_jpy = 30000;
