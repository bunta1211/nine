-- AI関連テーブルの更新マイグレーション

-- ai_conversationsテーブルの確認と作成
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

-- ai_knowledge_baseテーブルの確認と作成
CREATE TABLE IF NOT EXISTS ai_knowledge_base (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(50) NOT NULL,
    question VARCHAR(500) NOT NULL,
    answer TEXT NOT NULL,
    keywords TEXT,
    language VARCHAR(10) DEFAULT 'ja',
    priority INT UNSIGNED DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_language (language),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- user_ai_settingsテーブルの確認と作成
CREATE TABLE IF NOT EXISTS user_ai_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    secretary_name VARCHAR(50) DEFAULT 'あなたの秘書',
    character_type ENUM('female_20s', 'male_20s') DEFAULT 'female_20s',
    custom_instructions TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ai_usage_logsテーブル（使用量記録用）
CREATE TABLE IF NOT EXISTS ai_usage_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    provider VARCHAR(20) DEFAULT 'gemini',
    feature VARCHAR(50),
    input_chars INT UNSIGNED DEFAULT 0,
    output_chars INT UNSIGNED DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
