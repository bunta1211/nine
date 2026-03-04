-- 友だち候補・招待機能用テーブル

-- 非表示にした友だち候補
CREATE TABLE IF NOT EXISTS hidden_friend_suggestions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    suggested_user_id INT NOT NULL,
    hidden_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_hidden (user_id, suggested_user_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 招待テーブル
CREATE TABLE IF NOT EXISTS invitations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    inviter_id INT NOT NULL,
    contact VARCHAR(255) NOT NULL,
    contact_type ENUM('email', 'phone') NOT NULL DEFAULT 'email',
    token VARCHAR(64) NOT NULL,
    group_id INT NULL COMMENT 'グループからの招待の場合、そのグループID',
    status ENUM('pending', 'accepted', 'expired') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    UNIQUE KEY unique_token (token),
    INDEX idx_contact (contact),
    INDEX idx_inviter (inviter_id),
    INDEX idx_group (group_id),
    INDEX idx_status_expires (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 既存のinvitationsテーブルにgroup_idカラムを追加（存在しない場合）
-- ALTER TABLE invitations ADD COLUMN group_id INT NULL AFTER token;
-- ALTER TABLE invitations ADD INDEX idx_group (group_id);

-- usersテーブルにphone_numberカラムを追加（存在しない場合）
-- ALTER TABLE users ADD COLUMN phone_number VARCHAR(20) NULL AFTER email;
-- ALTER TABLE users ADD INDEX idx_phone (phone_number);
