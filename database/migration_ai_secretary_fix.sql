-- AI秘書設定テーブルの修正
-- character_typeをNULL許容に変更し、character_selectedフラグを追加

-- 既存テーブルがある場合の修正
ALTER TABLE user_ai_settings 
    MODIFY COLUMN character_type VARCHAR(20) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS character_selected TINYINT(1) DEFAULT 0 AFTER character_type,
    ADD COLUMN IF NOT EXISTS user_profile TEXT AFTER custom_instructions COMMENT 'ユーザーの個人情報（秘書が記憶）';

-- character_typeがnullの場合、character_selectedを0にリセット
UPDATE user_ai_settings SET character_selected = 0 WHERE character_type IS NULL OR character_type = '';

-- character_typeが設定されている場合、character_selectedを1にする
UPDATE user_ai_settings SET character_selected = 1 WHERE character_type IS NOT NULL AND character_type != '';

-- テーブルが存在しない場合の新規作成
CREATE TABLE IF NOT EXISTS user_ai_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    secretary_name VARCHAR(50) DEFAULT 'あなたの秘書',
    character_type VARCHAR(20) DEFAULT NULL,
    character_selected TINYINT(1) DEFAULT 0,
    custom_instructions TEXT,
    user_profile TEXT COMMENT 'ユーザーの個人情報（秘書が記憶）',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
