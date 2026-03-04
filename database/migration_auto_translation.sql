-- =====================================================
-- 自動翻訳機能マイグレーション
-- 作成日: 2026-01-21
-- 
-- 機能:
-- - ChatGPT API (GPT-4o) による自動翻訳
-- - 3日以内のメッセージを自動翻訳
-- - 月額3万円の予算制限
-- =====================================================

-- translation_usage テーブルの拡張
ALTER TABLE translation_usage
    ADD COLUMN token_count INT DEFAULT 0 COMMENT 'トークン数' AFTER character_count,
    ADD COLUMN api_provider VARCHAR(20) DEFAULT 'google' COMMENT 'API提供元 (openai/google/deepl)' AFTER target_lang,
    ADD COLUMN cost_usd DECIMAL(10, 6) DEFAULT 0 COMMENT 'コスト（USD）' AFTER api_provider,
    ADD COLUMN message_id INT DEFAULT NULL COMMENT '関連メッセージID' AFTER cost_usd,
    ADD INDEX idx_monthly (created_at, api_provider),
    ADD INDEX idx_message (message_id);

-- メッセージテーブルに言語情報を追加
ALTER TABLE messages
    ADD COLUMN source_lang VARCHAR(10) DEFAULT NULL COMMENT '投稿時の言語' AFTER is_edited;

-- メッセージ翻訳キャッシュテーブル（メッセージ単位）
CREATE TABLE IF NOT EXISTS message_translations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL COMMENT 'メッセージID',
    target_lang VARCHAR(10) NOT NULL COMMENT '翻訳先言語',
    translated_text TEXT NOT NULL COMMENT '翻訳テキスト',
    api_provider VARCHAR(20) DEFAULT 'openai' COMMENT 'API提供元',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_msg_lang (message_id, target_lang),
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    INDEX idx_message_lang (message_id, target_lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 翻訳予算設定テーブル
CREATE TABLE IF NOT EXISTS translation_budget (
    id INT AUTO_INCREMENT PRIMARY KEY,
    month_year VARCHAR(7) NOT NULL COMMENT '対象月（YYYY-MM形式）',
    budget_jpy INT DEFAULT 30000 COMMENT '月間予算（円）',
    alert_threshold_percent INT DEFAULT 80 COMMENT 'アラート閾値（%）',
    is_auto_switch_enabled TINYINT(1) DEFAULT 1 COMMENT '予算超過時の自動切替',
    fallback_provider VARCHAR(20) DEFAULT 'manual' COMMENT 'フォールバック先',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_month (month_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 現在月の予算設定を挿入
INSERT INTO translation_budget (month_year, budget_jpy) 
VALUES (DATE_FORMAT(NOW(), '%Y-%m'), 30000)
ON DUPLICATE KEY UPDATE budget_jpy = 30000;

-- =====================================================
-- 実行確認用クエリ
-- =====================================================
-- SELECT * FROM translation_budget;
-- DESCRIBE translation_usage;
-- DESCRIBE messages;
