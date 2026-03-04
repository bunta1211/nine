-- Googleカレンダー連携（あなたの秘書用）
-- ユーザーが名前を付けて複数カレンダーを連携可能

CREATE TABLE IF NOT EXISTS google_calendar_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,

    -- ユーザーが付ける名前（例: 岡崎西カレンダー, PVカレンダー, 康生カレンダー）
    display_name VARCHAR(50) NOT NULL,

    -- Google側
    google_email VARCHAR(255) NOT NULL,

    -- OAuthトークン
    access_token TEXT,
    refresh_token TEXT NOT NULL,
    token_expires_at DATETIME,

    -- デフォルト（1ユーザーあたり1つのみ）
    is_default TINYINT(1) DEFAULT 0,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_user_google (user_id, google_email),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Googleカレンダー連携アカウント';
