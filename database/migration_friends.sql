-- 友だち管理機能用マイグレーション

-- 1. usersテーブルにオンライン状態・最終アクティビティカラムを追加
ALTER TABLE users 
    ADD COLUMN IF NOT EXISTS last_activity DATETIME DEFAULT NULL COMMENT '最終アクティビティ時刻',
    ADD COLUMN IF NOT EXISTS online_status ENUM('online', 'away', 'offline') DEFAULT 'offline' COMMENT 'オンライン状態';

-- last_activityにインデックスを追加
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_last_activity (last_activity);

-- 2. 友だち関係テーブル
CREATE TABLE IF NOT EXISTS friendships (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL COMMENT '申請者',
    friend_id INT UNSIGNED NOT NULL COMMENT '相手',
    status ENUM('pending', 'accepted', 'rejected', 'blocked') DEFAULT 'pending' COMMENT '状態',
    nickname VARCHAR(50) DEFAULT NULL COMMENT 'ニックネーム（自分用）',
    memo TEXT DEFAULT NULL COMMENT 'メモ',
    is_favorite TINYINT(1) DEFAULT 0 COMMENT 'お気に入り',
    group_name VARCHAR(50) DEFAULT NULL COMMENT 'グループ分け',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_friendship (user_id, friend_id),
    INDEX idx_user_id (user_id),
    INDEX idx_friend_id (friend_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='友だち関係';

-- 3. 連絡先インポート履歴テーブル
CREATE TABLE IF NOT EXISTS contact_imports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL COMMENT 'インポートしたユーザー',
    contact_name VARCHAR(100) NOT NULL COMMENT '連絡先名',
    contact_email VARCHAR(255) DEFAULT NULL COMMENT 'メールアドレス',
    contact_phone VARCHAR(50) DEFAULT NULL COMMENT '電話番号',
    matched_user_id INT UNSIGNED DEFAULT NULL COMMENT 'マッチしたユーザーID',
    status ENUM('pending', 'invited', 'matched', 'ignored') DEFAULT 'pending' COMMENT '状態',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_matched_user_id (matched_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='連絡先インポート履歴';


