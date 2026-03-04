-- ユーザーAI設定テーブル
-- 「あなたの秘書」の名前・キャラクタータイプなどをユーザーごとに保存

CREATE TABLE IF NOT EXISTS user_ai_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    secretary_name VARCHAR(50) DEFAULT 'あなたの秘書',
    character_type ENUM('female_20s', 'male_20s') DEFAULT 'female_20s' COMMENT '20代女性/20代男性',
    custom_instructions TEXT COMMENT 'ユーザーがAIに教えた内容',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 既存テーブルがある場合のカラム追加用
-- ALTER TABLE user_ai_settings ADD COLUMN character_type ENUM('female_20s', 'male_20s') DEFAULT 'female_20s' AFTER secretary_name;
-- ALTER TABLE user_ai_settings ADD COLUMN custom_instructions TEXT AFTER character_type;
