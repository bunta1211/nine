-- =============================================
-- Wish機能修正用マイグレーション
-- 本番環境で実行してください
-- =============================================

-- 1. wish_patterns テーブルに不足カラムを追加
ALTER TABLE wish_patterns ADD COLUMN IF NOT EXISTS priority INT DEFAULT 0;
ALTER TABLE wish_patterns ADD COLUMN IF NOT EXISTS extract_group INT DEFAULT 0;
ALTER TABLE wish_patterns ADD COLUMN IF NOT EXISTS suffix_remove VARCHAR(255) NULL;
ALTER TABLE wish_patterns ADD COLUMN IF NOT EXISTS confidence DECIMAL(3,2) DEFAULT 0.80;
ALTER TABLE wish_patterns ADD COLUMN IF NOT EXISTS category_label VARCHAR(100) NULL;
ALTER TABLE wish_patterns ADD COLUMN IF NOT EXISTS description TEXT NULL;
ALTER TABLE wish_patterns ADD COLUMN IF NOT EXISTS example_input VARCHAR(255) NULL;
ALTER TABLE wish_patterns ADD COLUMN IF NOT EXISTS example_output VARCHAR(255) NULL;

-- 2. tasks テーブルに不足カラムを追加
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS source VARCHAR(50) DEFAULT NULL;
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS source_message_id INT NULL;
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS original_text TEXT NULL;
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS confidence DECIMAL(3,2) DEFAULT NULL;
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS category VARCHAR(50) DEFAULT NULL;

-- 3. 既存パターンにpriorityを設定（0のものを更新）
UPDATE wish_patterns SET priority = 50 WHERE priority = 0 OR priority IS NULL;
UPDATE wish_patterns SET priority = 55 WHERE pattern LIKE '%たい%' AND priority <= 50;
UPDATE wish_patterns SET priority = 60 WHERE pattern LIKE '%欲しい%' OR pattern LIKE '%ほしい%';
UPDATE wish_patterns SET priority = 65 WHERE pattern LIKE '%買いたい%' OR pattern LIKE '%行きたい%';

-- 4. インデックス追加（存在しない場合のみ）
-- ALTER TABLE wish_patterns ADD INDEX IF NOT EXISTS idx_pattern_active (is_active);
-- ALTER TABLE wish_patterns ADD INDEX IF NOT EXISTS idx_priority (priority);
-- ALTER TABLE tasks ADD INDEX IF NOT EXISTS idx_source (source);
-- ALTER TABLE tasks ADD INDEX IF NOT EXISTS idx_source_message (source_message_id);


