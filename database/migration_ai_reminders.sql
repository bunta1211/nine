-- AI秘書リマインダー機能用テーブル
-- 実行日: 2026-01-31

-- リマインダーテーブル
CREATE TABLE IF NOT EXISTS ai_reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL COMMENT 'リマインダーのタイトル',
    description TEXT COMMENT '詳細説明',
    remind_at DATETIME NOT NULL COMMENT '通知する日時',
    remind_type ENUM('once', 'daily', 'weekly', 'monthly', 'yearly') DEFAULT 'once' COMMENT '繰り返しタイプ',
    is_notified TINYINT(1) DEFAULT 0 COMMENT '通知済みフラグ',
    is_active TINYINT(1) DEFAULT 1 COMMENT '有効フラグ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_remind_at (remind_at),
    INDEX idx_user_active (user_id, is_active),
    INDEX idx_pending (is_notified, is_active, remind_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- リマインダー通知ログ（送信履歴）
CREATE TABLE IF NOT EXISTS ai_reminder_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reminder_id INT NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    notified_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    notification_type ENUM('push', 'chat', 'both') DEFAULT 'both',
    status ENUM('sent', 'failed', 'read') DEFAULT 'sent',
    FOREIGN KEY (reminder_id) REFERENCES ai_reminders(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_reminder (reminder_id),
    INDEX idx_user_date (user_id, notified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
