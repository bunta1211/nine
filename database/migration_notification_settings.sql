-- 通知設定テーブル
CREATE TABLE IF NOT EXISTS user_notification_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    notify_message TINYINT(1) DEFAULT 1 COMMENT 'メッセージ通知',
    notify_mention TINYINT(1) DEFAULT 1 COMMENT 'メンション通知',
    notify_call TINYINT(1) DEFAULT 1 COMMENT '通話着信通知',
    notify_announcement TINYINT(1) DEFAULT 1 COMMENT '運営からのお知らせ',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user (user_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


