-- メール認証コードテーブル
CREATE TABLE IF NOT EXISTS email_verification_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    code VARCHAR(255) NOT NULL COMMENT 'ハッシュ化されたコード',
    expires_at DATETIME NOT NULL,
    is_new_user TINYINT(1) DEFAULT 0,
    attempts INT DEFAULT 0 COMMENT '試行回数',
    verified_at DATETIME NULL COMMENT '検証完了日時',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 古いコードを自動削除するイベント（オプション）
-- CREATE EVENT IF NOT EXISTS cleanup_verification_codes
-- ON SCHEDULE EVERY 1 HOUR
-- DO DELETE FROM email_verification_codes WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY);


