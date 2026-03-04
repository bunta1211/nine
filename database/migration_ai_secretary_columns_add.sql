-- 既存 user_ai_settings に character_selected / user_profile がない場合に追加
-- テーブルは migration_ai_secretary_tables_ensure.sql または schema.sql で作成済みであること。
-- 実行: 1文ずつ実行し、「Duplicate column」エラーはそのカラムが既にあるので無視してよい。

-- character_type を NULL 許容に変更（ENUM のままの環境用）
ALTER TABLE user_ai_settings MODIFY COLUMN character_type VARCHAR(20) DEFAULT NULL;

-- character_selected を追加（MySQL 8.0 では ADD COLUMN IF NOT EXISTS が使える）
ALTER TABLE user_ai_settings ADD COLUMN character_selected TINYINT(1) DEFAULT 0 AFTER character_type;

-- user_profile を追加
ALTER TABLE user_ai_settings ADD COLUMN user_profile TEXT AFTER custom_instructions COMMENT 'ユーザーの個人情報（秘書が記憶）';

-- 既存データの character_selected を整合
UPDATE user_ai_settings SET character_selected = 0 WHERE character_type IS NULL OR character_type = '';
UPDATE user_ai_settings SET character_selected = 1 WHERE character_type IS NOT NULL AND character_type != '';
