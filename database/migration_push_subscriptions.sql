-- Web Push通知購読テーブル
-- 実行日: 2026-01-21

-- プッシュ購読情報テーブル
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'ユーザーID',
    endpoint TEXT NOT NULL COMMENT 'プッシュサービスのエンドポイントURL',
    p256dh VARCHAR(255) NOT NULL COMMENT '公開鍵（P-256 DH）',
    auth VARCHAR(255) NOT NULL COMMENT '認証シークレット',
    user_agent VARCHAR(500) DEFAULT NULL COMMENT 'ユーザーエージェント（デバイス識別用）',
    is_active TINYINT(1) DEFAULT 1 COMMENT '有効フラグ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT NULL COMMENT '最後に使用した日時',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_active (user_id, is_active),
    INDEX idx_endpoint (endpoint(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- プッシュ通知ログテーブル（デバッグ・分析用）
CREATE TABLE IF NOT EXISTS push_notification_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT DEFAULT NULL COMMENT '購読ID',
    user_id INT NOT NULL COMMENT '送信先ユーザーID',
    notification_type VARCHAR(50) NOT NULL COMMENT '通知タイプ（message, mention, call等）',
    title VARCHAR(255) NOT NULL COMMENT '通知タイトル',
    body TEXT DEFAULT NULL COMMENT '通知本文',
    data JSON DEFAULT NULL COMMENT '追加データ',
    status ENUM('pending', 'sent', 'failed', 'expired') DEFAULT 'pending' COMMENT 'ステータス',
    error_message TEXT DEFAULT NULL COMMENT 'エラーメッセージ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME DEFAULT NULL COMMENT '送信日時',
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- notification_settings テーブルにプッシュ通知設定カラムを追加
ALTER TABLE notification_settings 
    ADD COLUMN IF NOT EXISTS push_enabled TINYINT(1) DEFAULT 1 COMMENT 'プッシュ通知有効' AFTER sound_enabled,
    ADD COLUMN IF NOT EXISTS push_new_message TINYINT(1) DEFAULT 0 COMMENT '新着メッセージでプッシュ' AFTER push_enabled,
    ADD COLUMN IF NOT EXISTS push_mention TINYINT(1) DEFAULT 1 COMMENT 'メンション時プッシュ' AFTER push_new_message,
    ADD COLUMN IF NOT EXISTS push_dm TINYINT(1) DEFAULT 1 COMMENT 'DM時プッシュ' AFTER push_mention;
