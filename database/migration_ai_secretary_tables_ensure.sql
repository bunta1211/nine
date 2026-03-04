-- AI秘書の会話・設定・記憶を永続化するテーブルを確実に作成する
-- 本番で「会話ログ・記憶・キャラが消える」場合に実行してください。
-- CREATE TABLE IF NOT EXISTS のため、既に存在する場合はスキップされます。
-- 実行: mysql -u user -p database_name < migration_ai_secretary_tables_ensure.sql

-- 1. AI会話履歴（schema.sql に無い環境用）
CREATE TABLE IF NOT EXISTS ai_conversations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    question TEXT NOT NULL,
    answer TEXT,
    answered_by ENUM('ai', 'admin') DEFAULT 'ai',
    admin_id INT UNSIGNED,
    is_helpful TINYINT(1),
    feedback_at DATETIME,
    language VARCHAR(10) DEFAULT 'ja',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. AI秘書ユーザー設定（名前・キャラクター・選択状態・プロファイル）
CREATE TABLE IF NOT EXISTS user_ai_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    secretary_name VARCHAR(50) DEFAULT 'あなたの秘書',
    character_type VARCHAR(20) DEFAULT NULL COMMENT 'female_20s / male_20s',
    character_selected TINYINT(1) DEFAULT 0,
    custom_instructions TEXT,
    user_profile TEXT COMMENT 'ユーザーの個人情報（秘書が記憶）',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. AI秘書ユーザー記憶（家族・趣味・仕事など秘書が覚える内容）
CREATE TABLE IF NOT EXISTS ai_user_memories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    category VARCHAR(50) NOT NULL DEFAULT 'general',
    content TEXT NOT NULL,
    importance TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_category (user_id, category),
    INDEX idx_user_date (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
