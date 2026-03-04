-- ============================================
-- Social9 Phase 1 (MVP) Schema
-- 作成日: 2024-12-24
-- テーブル数: 13
-- phpMyAdmin で実行するときは「social9」を左で選択してから実行すること
-- ============================================

USE social9;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- 1. ユーザー
-- ============================================

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- 認証
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    
    -- 認証レベル
    auth_level TINYINT UNSIGNED DEFAULT 1 COMMENT '1:メール 2:電話 3:本人確認',
    email_verified_at DATETIME,
    phone_verified_at DATETIME,
    identity_verified_at DATETIME,
    
    -- メール認証用
    email_verification_token VARCHAR(100),
    
    -- パスワードリセット用
    password_reset_token VARCHAR(100),
    password_reset_expires DATETIME,
    
    -- 電話認証用
    phone_verification_code VARCHAR(6),
    phone_verification_expires DATETIME,
    
    -- プロフィール
    display_name VARCHAR(50) NOT NULL,
    avatar_path VARCHAR(500),
    bio TEXT,
    birth_date DATE NOT NULL,
    is_minor TINYINT(1) DEFAULT 0,
    
    -- 地域
    prefecture VARCHAR(20),
    city VARCHAR(50),
    
    -- オンライン状態
    online_status ENUM('online', 'away', 'busy', 'offline') DEFAULT 'offline',
    last_seen DATETIME,
    
    -- 言語設定
    display_language VARCHAR(10) DEFAULT 'ja',
    translate_to VARCHAR(10) DEFAULT 'ja',
    auto_translate TINYINT(1) DEFAULT 1,
    
    -- 権限
    role ENUM('user', 'org_admin', 'system_admin') DEFAULT 'user',
    
    -- 状態
    status ENUM('active', 'suspended', 'deleted') DEFAULT 'active',
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- インデックス
    INDEX idx_email (email),
    INDEX idx_status (status),
    INDEX idx_display_name (display_name),
    INDEX idx_prefecture_city (prefecture, city),
    INDEX idx_online (online_status, last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. 組織
-- ============================================

CREATE TABLE IF NOT EXISTS organizations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    name VARCHAR(100) NOT NULL,
    type ENUM('corporation', 'family', 'school', 'group') NOT NULL,
    description TEXT,
    icon_path VARCHAR(500),
    
    default_member_role ENUM('member', 'restricted') DEFAULT 'member',
    require_admin_approval TINYINT(1) DEFAULT 0,
    
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id),
    
    INDEX idx_type (type),
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. 組織メンバー
-- ============================================

CREATE TABLE IF NOT EXISTS organization_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    
    role ENUM('owner', 'admin', 'member', 'restricted') DEFAULT 'member',
    
    -- 制限設定（restricted用）
    external_contact ENUM('none', 'org_only', 'approved', 'notify', 'allow') DEFAULT 'allow',
    call_restriction ENUM('none', 'group_only', 'approved', 'allow') DEFAULT 'allow',
    file_send_allowed TINYINT(1) DEFAULT 1,
    
    -- 利用時間制限
    usage_start_time TIME,
    usage_end_time TIME,
    daily_limit_minutes INT UNSIGNED,
    
    -- 監視設定
    can_view_messages TINYINT(1) DEFAULT 0,
    can_view_online TINYINT(1) DEFAULT 1,
    can_view_contacts TINYINT(1) DEFAULT 1,
    
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    left_at DATETIME,
    
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_member (organization_id, user_id),
    INDEX idx_user (user_id),
    INDEX idx_role (organization_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. 許可済み連絡先
-- ============================================

CREATE TABLE IF NOT EXISTS approved_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    member_id INT UNSIGNED NOT NULL,
    approved_user_id INT UNSIGNED NOT NULL,
    
    allow_dm TINYINT(1) DEFAULT 1,
    allow_call TINYINT(1) DEFAULT 1,
    
    approved_by INT UNSIGNED NOT NULL,
    approved_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (member_id) REFERENCES organization_members(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id),
    
    UNIQUE KEY unique_approval (member_id, approved_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. 会話
-- ============================================

CREATE TABLE IF NOT EXISTS conversations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    type ENUM('dm', 'group', 'support') NOT NULL DEFAULT 'dm',
    name VARCHAR(100),
    description TEXT,
    icon_path VARCHAR(500),
    
    organization_id INT UNSIGNED COMMENT '組織に紐づく場合',
    
    is_public TINYINT(1) DEFAULT 0,
    invite_link VARCHAR(100) UNIQUE,
    
    created_by INT UNSIGNED,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    
    INDEX idx_type (type),
    INDEX idx_organization (organization_id),
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. 会話メンバー
-- ============================================

CREATE TABLE IF NOT EXISTS conversation_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    
    role ENUM('admin', 'member') DEFAULT 'member',
    
    is_muted TINYINT(1) DEFAULT 0,
    is_pinned TINYINT(1) DEFAULT 0,
    
    last_read_at DATETIME,
    
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    left_at DATETIME,
    
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_member (conversation_id, user_id),
    INDEX idx_user (user_id),
    INDEX idx_user_active (user_id, left_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. メッセージ
-- ============================================

CREATE TABLE IF NOT EXISTS messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    
    content TEXT,
    message_type ENUM('text', 'image', 'file', 'system') DEFAULT 'text',
    
    reply_to_id INT UNSIGNED,
    
    is_edited TINYINT(1) DEFAULT 0,
    edited_at DATETIME,
    is_pinned TINYINT(1) DEFAULT 0,
    deleted_at DATETIME,
    
    original_language VARCHAR(10),
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reply_to_id) REFERENCES messages(id) ON DELETE SET NULL,
    
    INDEX idx_conversation_time (conversation_id, created_at DESC),
    INDEX idx_sender (sender_id),
    INDEX idx_pinned (conversation_id, is_pinned),
    FULLTEXT INDEX ft_content (content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. メンション（検索用）
-- ============================================

CREATE TABLE IF NOT EXISTS message_mentions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    mentioned_user_id INT UNSIGNED NOT NULL,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (mentioned_user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_mention (message_id, mentioned_user_id),
    INDEX idx_mentioned_user (mentioned_user_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 9. リアクション
-- ============================================

CREATE TABLE IF NOT EXISTS message_reactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    
    reaction_type VARCHAR(10) NOT NULL COMMENT '👍, ❤️, 😊, 🎉, 😢',
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_reaction (message_id, user_id, reaction_type),
    INDEX idx_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 10. 通話
-- ============================================

CREATE TABLE IF NOT EXISTS calls (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    initiator_id INT UNSIGNED NOT NULL,
    
    room_id VARCHAR(100) NOT NULL UNIQUE,
    call_type ENUM('audio', 'video') DEFAULT 'video',
    
    status ENUM('ringing', 'active', 'ended', 'missed') DEFAULT 'ringing',
    
    started_at DATETIME,
    ended_at DATETIME,
    duration_seconds INT UNSIGNED,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (initiator_id) REFERENCES users(id),
    
    INDEX idx_status (status),
    INDEX idx_conversation (conversation_id, created_at DESC),
    INDEX idx_initiator (initiator_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 11. 通話参加者
-- ============================================

CREATE TABLE IF NOT EXISTS call_participants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    call_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    
    status ENUM('invited', 'joined', 'left', 'declined') DEFAULT 'invited',
    
    joined_at DATETIME,
    left_at DATETIME,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    
    UNIQUE KEY unique_participant (call_id, user_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 12. ファイル
-- ============================================

CREATE TABLE IF NOT EXISTS files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    uploader_id INT UNSIGNED NOT NULL,
    message_id INT UNSIGNED,
    
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(100),
    file_size INT UNSIGNED NOT NULL,
    
    width INT UNSIGNED,
    height INT UNSIGNED,
    thumbnail_path VARCHAR(500),
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE SET NULL,
    
    INDEX idx_uploader (uploader_id, created_at DESC),
    INDEX idx_message (message_id),
    INDEX idx_mime (mime_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 13. 通知
-- ============================================

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    
    type ENUM('message', 'mention', 'call', 'request', 'offer', 'system') NOT NULL,
    
    title VARCHAR(200),
    content TEXT,
    
    related_type VARCHAR(50),
    related_id INT UNSIGNED,
    
    is_read TINYINT(1) DEFAULT 0,
    read_at DATETIME,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_user_unread (user_id, is_read, created_at DESC),
    INDEX idx_type (user_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 14. 利用履歴（利用時間制限用）
-- ============================================

CREATE TABLE IF NOT EXISTS usage_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NOT NULL,
    
    -- 利用時間
    duration_minutes INT UNSIGNED NOT NULL DEFAULT 1,
    
    -- 活動タイプ
    activity_type ENUM('message', 'call', 'general') DEFAULT 'general',
    
    -- メタデータ
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_date (user_id, created_at),
    INDEX idx_org_date (organization_id, created_at),
    INDEX idx_user_org_date (user_id, organization_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='ユーザー利用履歴（利用時間制限用）';

-- ============================================
-- 初期データ
-- ============================================

-- システム管理者（パスワード: admin123 のハッシュ）
INSERT INTO users (email, password_hash, display_name, birth_date, role, auth_level, status) VALUES
('admin@social9.jp', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'システム管理者', '1990-01-01', 'system_admin', 3, 'active')
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

-- 運営サポート用ユーザー
INSERT INTO users (email, password_hash, display_name, birth_date, role, auth_level, status) VALUES
('support@social9.jp', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '運営サポート', '1990-01-01', 'system_admin', 3, 'active')
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- 完了メッセージ（information_schema は使わない）
-- ============================================
SELECT 'Phase 1 Schema created successfully!' AS message;







