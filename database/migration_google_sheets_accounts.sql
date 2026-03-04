-- Googleスプレッドシート連携（AI秘書での編集用）
-- ユーザーごとに1アカウント連携（複数スプレッドシートはAPIで指定）

CREATE TABLE IF NOT EXISTS google_sheets_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,

    google_email VARCHAR(255) NOT NULL,

    access_token TEXT,
    refresh_token TEXT NOT NULL,
    token_expires_at DATETIME,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_user (user_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Googleスプレッドシート連携';
