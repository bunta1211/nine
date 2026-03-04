-- =====================================================
-- お知らせ・運営通知関連テーブル
-- 仕様書: 09_UI共通仕様.md
-- =====================================================

-- =====================================================
-- 運営からのお知らせテーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS announcements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL COMMENT 'タイトル',
    content TEXT NOT NULL COMMENT '本文',
    type ENUM('info', 'important', 'maintenance', 'update', 'event') DEFAULT 'info' COMMENT '種類',
    priority INT DEFAULT 0 COMMENT '優先度（高いほど上に表示）',
    target_role ENUM('all', 'user', 'parent', 'organization', 'admin') DEFAULT 'all' COMMENT '対象ユーザー種別',
    is_active TINYINT(1) DEFAULT 1 COMMENT '有効フラグ',
    starts_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '表示開始日時',
    expires_at DATETIME DEFAULT NULL COMMENT '表示終了日時（NULLは無期限）',
    created_by INT UNSIGNED COMMENT '作成者（管理者）',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active_priority (is_active, priority DESC),
    INDEX idx_expires (expires_at),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- お知らせ既読管理テーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS announcement_reads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    announcement_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_read (announcement_id, user_id),
    FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 初期お知らせデータ（サンプル）
-- =====================================================

INSERT INTO announcements (title, content, type, priority, is_active) VALUES
('Social9へようこそ！', 'Social9をご利用いただきありがとうございます。\n\nこのアプリでは、安全なコミュニケーションと便利な機能をお楽しみいただけます。\n\n何かご不明な点がございましたら、お気軽にお問い合わせください。', 'info', 100, 1),
('新機能「Wish」がリリースされました', 'チャットから自動的に願望や予定を抽出する「Wish」機能をリリースしました。\n\n普段の会話から「〜したい」「〜行きたい」といった内容を自動で拾い上げ、リスト化します。', 'update', 90, 1)
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;








