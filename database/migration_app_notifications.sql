-- =====================================================
-- アプリ間共有通知テーブル
-- Social9とGuild等の外部アプリ間で通知を共有するためのテーブル
-- =====================================================

USE social9;

-- 共有通知テーブル
CREATE TABLE IF NOT EXISTS app_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL COMMENT '通知対象ユーザーID',
    source_app VARCHAR(50) NOT NULL COMMENT '通知元アプリ (guild, social9, etc)',
    notification_type VARCHAR(50) NOT NULL COMMENT '通知タイプ',
    title VARCHAR(200) NOT NULL COMMENT '通知タイトル',
    message TEXT COMMENT '通知メッセージ',
    link VARCHAR(500) COMMENT 'リンク先URL',
    data JSON COMMENT '追加データ（アプリ固有）',
    is_read TINYINT(1) DEFAULT 0 COMMENT '既読フラグ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME DEFAULT NULL COMMENT '既読日時',
    
    -- インデックス
    INDEX idx_user_read (user_id, is_read),
    INDEX idx_user_source (user_id, source_app),
    INDEX idx_created (created_at),
    
    -- 外部キー（Social9のusersテーブルを参照）
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='アプリ間共有通知';

-- 通知タイプ一覧（参考）
-- Guild関連:
--   guild_earth_received: Earth受け取り
--   guild_request_assigned: 依頼に採用された
--   guild_request_completed: 依頼が完了した
--   guild_thanks_received: 感謝を受け取った
--   guild_advance_approved: 前借り申請が承認された
