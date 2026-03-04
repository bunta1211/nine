-- 本番で user_privacy_settings が無い場合用：テーブル作成＋既存ユーザーを検索可能で初期化
-- migration_privacy_search 相当 + デフォルト検索可能（exclude_from_search = 0）

-- user_privacy_settings 作成（exclude_from_search デフォルト 0＝検索可能）
CREATE TABLE IF NOT EXISTS user_privacy_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    hide_online_status TINYINT(1) DEFAULT 0,
    hide_read_receipts TINYINT(1) DEFAULT 0,
    profile_visibility ENUM('everyone', 'chatted', 'group_members') DEFAULT 'everyone',
    exclude_from_search TINYINT(1) DEFAULT 0 COMMENT '0=検索可能 1=検索から除外',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_search (exclude_from_search)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- blocked_users 作成（検索・ブロック機能用）
CREATE TABLE IF NOT EXISTS blocked_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    blocked_user_id INT NOT NULL,
    reason VARCHAR(200),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_block (user_id, blocked_user_id),
    INDEX idx_user (user_id),
    INDEX idx_blocked (blocked_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 既存の active ユーザーを検索可能（exclude_from_search = 0）で登録
INSERT IGNORE INTO user_privacy_settings (user_id, exclude_from_search, created_at, updated_at)
SELECT id, 0, NOW(), NOW() FROM users WHERE status = 'active';
