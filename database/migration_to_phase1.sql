-- ============================================
-- Social9 Phase 1 マイグレーション
-- 既存スキーマ → Phase 1 スキーマ
-- 作成日: 2024-12-24
-- ============================================
-- 
-- 注意: このスクリプトは既存データを保持しながら
-- スキーマを更新します。
--
-- 実行前にバックアップを取ってください:
-- mysqldump -u root social9 > backup_before_migration.sql
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- 1. users テーブル更新
-- ============================================

-- 新規カラム追加
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS `status` ENUM('active', 'suspended', 'deleted') DEFAULT 'active' AFTER `role`,
    ADD COLUMN IF NOT EXISTS `phone_verification_code` VARCHAR(6) AFTER `email_verification_token`,
    ADD COLUMN IF NOT EXISTS `phone_verification_expires` DATETIME AFTER `phone_verification_code`,
    ADD COLUMN IF NOT EXISTS `display_language` VARCHAR(10) DEFAULT 'ja' AFTER `last_seen`,
    ADD COLUMN IF NOT EXISTS `translate_to` VARCHAR(10) DEFAULT 'ja' AFTER `display_language`,
    ADD COLUMN IF NOT EXISTS `auto_translate` TINYINT(1) DEFAULT 1 AFTER `translate_to`;

-- online_statusにbusyを追加（ENUMの変更）
ALTER TABLE users 
    MODIFY COLUMN `online_status` ENUM('online', 'away', 'busy', 'offline') DEFAULT 'offline';

-- インデックス追加（存在しなければ）
-- ALTER TABLE users ADD INDEX IF NOT EXISTS idx_display_name (display_name);
-- ALTER TABLE users ADD INDEX IF NOT EXISTS idx_prefecture_city (prefecture, city);
-- ALTER TABLE users ADD INDEX IF NOT EXISTS idx_status (status);

-- ============================================
-- 2. organizations テーブル更新
-- ============================================

-- 新規カラム追加
ALTER TABLE organizations
    ADD COLUMN IF NOT EXISTS `type` ENUM('corporation', 'family', 'school', 'group') NOT NULL DEFAULT 'corporation' AFTER `name`,
    ADD COLUMN IF NOT EXISTS `icon_path` VARCHAR(500) AFTER `description`,
    ADD COLUMN IF NOT EXISTS `default_member_role` ENUM('member', 'restricted') DEFAULT 'member' AFTER `icon_path`,
    ADD COLUMN IF NOT EXISTS `require_admin_approval` TINYINT(1) DEFAULT 0 AFTER `default_member_role`,
    ADD COLUMN IF NOT EXISTS `created_by` INT UNSIGNED AFTER `require_admin_approval`;

-- owner_id → created_by のデータ移行
UPDATE organizations SET created_by = owner_id WHERE created_by IS NULL;

-- logo_path → icon_path のデータ移行
UPDATE organizations SET icon_path = logo_path WHERE icon_path IS NULL AND logo_path IS NOT NULL;

-- ============================================
-- 3. organization_members テーブル作成
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
    
    UNIQUE KEY unique_member (organization_id, user_id),
    INDEX idx_user (user_id),
    INDEX idx_role (organization_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- organizations の owner を organization_members に移行
INSERT IGNORE INTO organization_members (organization_id, user_id, role, joined_at)
SELECT id, owner_id, 'owner', created_at FROM organizations WHERE owner_id IS NOT NULL;

-- ============================================
-- 4. conversations テーブル更新
-- ============================================

-- ENUMにsupportを追加
ALTER TABLE conversations
    MODIFY COLUMN `type` ENUM('dm', 'group', 'support') NOT NULL DEFAULT 'dm';

-- 新規カラム追加
ALTER TABLE conversations
    ADD COLUMN IF NOT EXISTS `organization_id` INT UNSIGNED AFTER `icon_path`,
    ADD COLUMN IF NOT EXISTS `icon_path` VARCHAR(500) AFTER `description`;

-- icon → icon_path のデータ移行
UPDATE conversations SET icon_path = icon WHERE icon_path IS NULL AND icon IS NOT NULL;

-- インデックス追加
-- ALTER TABLE conversations ADD INDEX IF NOT EXISTS idx_organization (organization_id);
-- ALTER TABLE conversations ADD INDEX IF NOT EXISTS idx_name (name);

-- ============================================
-- 5. conversation_members テーブル更新
-- ============================================

-- last_read_at カラム追加
ALTER TABLE conversation_members
    ADD COLUMN IF NOT EXISTS `last_read_at` DATETIME AFTER `is_muted`;

-- roleのviewer削除（admin, memberのみに）
UPDATE conversation_members SET role = 'member' WHERE role = 'viewer';
ALTER TABLE conversation_members
    MODIFY COLUMN `role` ENUM('admin', 'member') DEFAULT 'member';

-- ============================================
-- 6. messages テーブル更新
-- ============================================

-- 新規カラム追加
ALTER TABLE messages
    ADD COLUMN IF NOT EXISTS `is_edited` TINYINT(1) DEFAULT 0 AFTER `reply_to_id`,
    ADD COLUMN IF NOT EXISTS `is_pinned` TINYINT(1) DEFAULT 0 AFTER `edited_at`,
    ADD COLUMN IF NOT EXISTS `original_language` VARCHAR(10) AFTER `is_pinned`;

-- edited_at が設定されていれば is_edited を 1 に
UPDATE messages SET is_edited = 1 WHERE edited_at IS NOT NULL;

-- インデックス追加
-- ALTER TABLE messages ADD INDEX IF NOT EXISTS idx_conversation_time (conversation_id, created_at DESC);
-- ALTER TABLE messages ADD INDEX IF NOT EXISTS idx_sender (sender_id);
-- ALTER TABLE messages ADD INDEX IF NOT EXISTS idx_pinned (conversation_id, is_pinned);

-- ============================================
-- 7. message_mentions テーブル作成（検索用）
-- ============================================

CREATE TABLE IF NOT EXISTS message_mentions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    mentioned_user_id INT UNSIGNED NOT NULL,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_mention (message_id, mentioned_user_id),
    INDEX idx_mentioned_user (mentioned_user_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 既存のmessages.mentionsからデータ移行
-- JSON形式: [{"id": 1, "display_name": "user1"}, ...]
-- INSERT IGNORE INTO message_mentions (message_id, mentioned_user_id, created_at)
-- SELECT m.id, JSON_UNQUOTE(JSON_EXTRACT(j.mention, '$.id')), m.created_at
-- FROM messages m
-- CROSS JOIN JSON_TABLE(m.mentions, '$[*]' COLUMNS (mention JSON PATH '$')) AS j
-- WHERE m.mentions IS NOT NULL AND m.mentions != '[]' AND m.mentions != 'null';

-- ============================================
-- 8. files テーブル更新
-- ============================================

-- カラム名変更: uploaded_by → uploader_id
ALTER TABLE files 
    CHANGE COLUMN IF EXISTS `uploaded_by` `uploader_id` INT UNSIGNED NOT NULL;

-- message_id カラム追加
ALTER TABLE files
    ADD COLUMN IF NOT EXISTS `message_id` INT UNSIGNED AFTER `uploader_id`,
    ADD COLUMN IF NOT EXISTS `width` INT UNSIGNED AFTER `file_size`,
    ADD COLUMN IF NOT EXISTS `height` INT UNSIGNED AFTER `width`;

-- インデックス追加
-- ALTER TABLE files ADD INDEX IF NOT EXISTS idx_uploader (uploader_id, created_at DESC);
-- ALTER TABLE files ADD INDEX IF NOT EXISTS idx_message (message_id);
-- ALTER TABLE files ADD INDEX IF NOT EXISTS idx_mime (mime_type);

-- ============================================
-- 9. notifications テーブル更新
-- ============================================

-- ENUMを拡張
ALTER TABLE notifications
    MODIFY COLUMN `type` ENUM('message', 'mention', 'call', 'call_incoming', 'call_missed', 'request', 'offer', 'permission_request', 'system') NOT NULL;

-- インデックス更新
-- ALTER TABLE notifications ADD INDEX IF NOT EXISTS idx_user_unread (user_id, is_read, created_at DESC);
-- ALTER TABLE notifications ADD INDEX IF NOT EXISTS idx_type (user_id, type);

-- ============================================
-- 外部キー再設定
-- ============================================

-- organization_members の外部キー
-- ALTER TABLE organization_members 
--     ADD CONSTRAINT fk_orgmem_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
--     ADD CONSTRAINT fk_orgmem_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- message_mentions の外部キー
-- ALTER TABLE message_mentions
--     ADD CONSTRAINT fk_mention_msg FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
--     ADD CONSTRAINT fk_mention_user FOREIGN KEY (mentioned_user_id) REFERENCES users(id) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- 完了
-- ============================================
SELECT 'Migration to Phase 1 completed!' AS message;








