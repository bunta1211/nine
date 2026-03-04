-- AI秘書ユーザー記憶テーブル
-- 実行日: 2026-02-01

CREATE TABLE IF NOT EXISTS ai_user_memories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    category VARCHAR(50) NOT NULL DEFAULT 'general' COMMENT 'カテゴリ（family, pet, preference, work, etc）',
    content TEXT NOT NULL COMMENT '記憶内容',
    importance TINYINT(1) DEFAULT 1 COMMENT '重要度（1-5）',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_category (user_id, category),
    INDEX idx_user_date (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
