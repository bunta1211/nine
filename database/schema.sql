-- ============================================
-- Social9 データベーススキーマ
-- Phase 1: MVP
-- ============================================

-- データベース作成（開発環境用）
-- CREATE DATABASE IF NOT EXISTS social9 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE social9;

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- 👤 ユーザー関連テーブル
-- ============================================

-- ユーザー
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- 認証情報
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    
    -- 認証レベル (1:メール, 2:電話, 3:本人確認)
    auth_level TINYINT UNSIGNED DEFAULT 1,
    email_verified_at DATETIME,
    phone_verified_at DATETIME,
    identity_verified_at DATETIME,
    identity_document_path VARCHAR(500),
    identity_status ENUM('pending', 'approved', 'rejected') DEFAULT NULL,
    
    -- プロフィール
    display_name VARCHAR(50) NOT NULL,
    avatar_path VARCHAR(500),
    bio TEXT,
    
    -- 年齢確認
    birth_date DATE NOT NULL,
    is_minor TINYINT(1) DEFAULT 0 COMMENT '18歳未満フラグ',
    has_minor_history TINYINT(1) DEFAULT 0 COMMENT '過去に未成年登録あり',
    
    -- 地域情報
    prefecture VARCHAR(20),
    city VARCHAR(50),
    
    -- オンラインステータス
    online_status ENUM('online', 'away', 'busy', 'offline') DEFAULT 'offline',
    custom_status VARCHAR(100),
    custom_status_emoji VARCHAR(10),
    status_expires_at DATETIME,
    last_seen DATETIME,
    
    -- 所属組織
    organization_id INT UNSIGNED,
    
    -- 権限
    role ENUM('user', 'org_admin', 'system_admin') DEFAULT 'user',
    trust_level TINYINT UNSIGNED DEFAULT 0 COMMENT '信用レベル 0-5',
    
    -- 特定投資家（Phase4以降）
    is_qualified_investor TINYINT(1) DEFAULT 0,
    investor_approved_at DATETIME,
    
    -- 言語設定
    language VARCHAR(10) DEFAULT 'ja',
    
    -- アカウント状態
    status ENUM('active', 'suspended', 'deleted') DEFAULT 'active',
    suspended_at DATETIME,
    suspended_reason TEXT,
    
    -- 日時
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME,
    
    INDEX idx_email (email),
    INDEX idx_phone (phone),
    INDEX idx_organization (organization_id),
    INDEX idx_status (status),
    INDEX idx_online (online_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 家族構成
CREATE TABLE IF NOT EXISTS user_family_composition (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    composition_type ENUM(
        'single', 'couple', 'preschool', 'elementary',
        'junior_high', 'high_school', 'university',
        'working_child', 'elderly', 'other'
    ) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_composition (user_id, composition_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- プライバシー設定
CREATE TABLE IF NOT EXISTS user_privacy_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    
    hide_online_status TINYINT(1) DEFAULT 0,
    hide_read_receipts TINYINT(1) DEFAULT 0,
    profile_visibility ENUM('everyone', 'chatted', 'group_members') DEFAULT 'everyone',
    exclude_from_search TINYINT(1) DEFAULT 0,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 👨‍👩‍👧 保護者・子ども関連テーブル
-- ============================================

-- 保護者子ども関係
CREATE TABLE IF NOT EXISTS parent_child_relations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_user_id INT UNSIGNED NOT NULL,
    child_user_id INT UNSIGNED NOT NULL,
    
    status ENUM('pending', 'parent_approved', 'child_approved', 'active', 'revoked') DEFAULT 'pending',
    
    parent_approved_at DATETIME,
    child_approved_at DATETIME,
    revoked_at DATETIME,
    revoked_by INT UNSIGNED,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_relation (parent_user_id, child_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 子どものSNS設定
CREATE TABLE IF NOT EXISTS child_sns_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    relation_id INT UNSIGNED NOT NULL,
    
    -- 友達追加制限
    friend_add_restriction ENUM('school_only', 'parent_approved', 'notify_only', 'none') DEFAULT 'parent_approved',
    
    -- 通話制限
    call_restriction ENUM('group_only', 'approved_only', 'none') DEFAULT 'approved_only',
    
    -- メッセージ制限
    allow_japanese TINYINT(1) DEFAULT 1,
    allow_english TINYINT(1) DEFAULT 1,
    allow_chinese TINYINT(1) DEFAULT 1,
    allow_stamps TINYINT(1) DEFAULT 1,
    
    -- 利用時間制限
    usage_start_time TIME DEFAULT '07:00:00',
    usage_end_time TIME DEFAULT '21:00:00',
    daily_limit_minutes INT UNSIGNED DEFAULT 120,
    
    -- 保護者の確認範囲
    can_view_messages TINYINT(1) DEFAULT 0,
    can_view_online_status TINYINT(1) DEFAULT 1,
    can_view_friends TINYINT(1) DEFAULT 1,
    
    -- 同意状態
    parent_proposed_at DATETIME,
    child_agreed_at DATETIME,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (relation_id) REFERENCES parent_child_relations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 許可済み連絡先
CREATE TABLE IF NOT EXISTS approved_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    child_user_id INT UNSIGNED NOT NULL,
    approved_user_id INT UNSIGNED NOT NULL,
    approved_by INT UNSIGNED NOT NULL COMMENT '承認した保護者',
    
    allow_dm TINYINT(1) DEFAULT 1,
    allow_call TINYINT(1) DEFAULT 1,
    
    approved_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_approval (child_user_id, approved_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 💬 会話・メッセージ関連テーブル
-- ============================================

-- 会話
CREATE TABLE IF NOT EXISTS conversations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    type ENUM('dm', 'group', 'organization') NOT NULL DEFAULT 'dm',
    name VARCHAR(100),
    description TEXT,
    icon_path VARCHAR(500),
    
    -- グループ設定
    is_public TINYINT(1) DEFAULT 0,
    invite_link VARCHAR(100) UNIQUE,
    max_members INT UNSIGNED DEFAULT 50,
    
    -- 作成者（グループの場合）
    created_by INT UNSIGNED,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_type (type),
    INDEX idx_invite_link (invite_link)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 会話メンバー
CREATE TABLE IF NOT EXISTS conversation_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    
    role ENUM('admin', 'member', 'viewer') DEFAULT 'member',
    
    -- 通知設定
    is_muted TINYINT(1) DEFAULT 0,
    muted_until DATETIME,
    is_pinned TINYINT(1) DEFAULT 0,
    is_archived TINYINT(1) DEFAULT 0,
    
    -- 既読位置
    last_read_message_id INT UNSIGNED,
    last_read_at DATETIME,
    
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    left_at DATETIME,
    
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_member (conversation_id, user_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- メッセージ
CREATE TABLE IF NOT EXISTS messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    
    -- メッセージ内容
    content TEXT,
    content_type ENUM('text', 'image', 'file', 'voice', 'system') DEFAULT 'text',
    
    -- 返信
    reply_to_id INT UNSIGNED,
    
    -- 予約送信
    scheduled_at DATETIME,
    is_scheduled TINYINT(1) DEFAULT 0,
    
    -- 編集・削除
    is_edited TINYINT(1) DEFAULT 0,
    edited_at DATETIME,
    is_deleted TINYINT(1) DEFAULT 0,
    deleted_at DATETIME,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_conversation (conversation_id),
    INDEX idx_created (created_at),
    INDEX idx_scheduled (is_scheduled, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- メンション
CREATE TABLE IF NOT EXISTS message_mentions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    mentioned_user_id INT UNSIGNED NOT NULL,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (mentioned_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ナイス/リアクション
CREATE TABLE IF NOT EXISTS message_nice (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    
    type ENUM('thumbsup', 'heart', 'smile', 'party', 'sad') NOT NULL,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_nice (message_id, user_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 📞 通話関連テーブル
-- ============================================

-- 通話
CREATE TABLE IF NOT EXISTS calls (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    initiator_id INT UNSIGNED NOT NULL,
    
    room_id VARCHAR(100) NOT NULL UNIQUE COMMENT 'Jitsi Room ID',
    call_type ENUM('audio', 'video') DEFAULT 'video',
    
    status ENUM('ringing', 'active', 'ended', 'missed') DEFAULT 'ringing',
    
    started_at DATETIME,
    ended_at DATETIME,
    duration_seconds INT UNSIGNED,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (initiator_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_room (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 通話参加者
CREATE TABLE IF NOT EXISTS call_participants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    call_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    
    status ENUM('invited', 'joined', 'left', 'declined') DEFAULT 'invited',
    
    joined_at DATETIME,
    left_at DATETIME,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_participant (call_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 📁 ファイル関連テーブル
-- ============================================

-- ファイル
CREATE TABLE IF NOT EXISTS files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    uploader_id INT UNSIGNED NOT NULL,
    message_id INT UNSIGNED,
    
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(100),
    file_size INT UNSIGNED NOT NULL COMMENT 'バイト単位',
    
    -- 画像の場合
    width INT UNSIGNED,
    height INT UNSIGNED,
    thumbnail_path VARCHAR(500),
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE SET NULL,
    INDEX idx_uploader (uploader_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 🔔 通知関連テーブル
-- ============================================

-- 通知
CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    
    type ENUM(
        'new_message', 'mention', 'call_incoming',
        'parent_request', 'permission_request',
        'group_invite', 'system'
    ) NOT NULL,
    
    title VARCHAR(200),
    content TEXT,
    
    -- 関連データ
    related_type VARCHAR(50) COMMENT 'message, call, user, etc.',
    related_id INT UNSIGNED,
    
    is_read TINYINT(1) DEFAULT 0,
    read_at DATETIME,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_unread (user_id, is_read),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 🤖 AI相談室関連テーブル
-- ============================================

-- AI会話履歴
CREATE TABLE IF NOT EXISTS ai_conversations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    
    question TEXT NOT NULL,
    answer TEXT,
    
    answered_by ENUM('ai', 'admin') DEFAULT 'ai',
    admin_id INT UNSIGNED,
    
    -- フィードバック
    is_helpful TINYINT(1),
    feedback_at DATETIME,
    
    language VARCHAR(10) DEFAULT 'ja',
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_answered_by (answered_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI知識ベース
CREATE TABLE IF NOT EXISTS ai_knowledge_base (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    category VARCHAR(50) NOT NULL,
    question VARCHAR(500) NOT NULL,
    answer TEXT NOT NULL,
    
    keywords TEXT COMMENT 'カンマ区切り',
    language VARCHAR(10) DEFAULT 'ja',
    
    priority INT UNSIGNED DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI秘書ユーザー設定（名前・キャラクター・選択状態・プロファイル）
CREATE TABLE IF NOT EXISTS user_ai_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    secretary_name VARCHAR(50) DEFAULT 'あなたの秘書',
    character_type VARCHAR(20) DEFAULT NULL COMMENT 'female_20s / male_20s',
    character_selected TINYINT(1) DEFAULT 0,
    custom_instructions TEXT,
    user_profile TEXT COMMENT 'ユーザーの個人情報（秘書が記憶）',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI秘書ユーザー記憶（家族・趣味・仕事など秘書が覚える内容）
CREATE TABLE IF NOT EXISTS ai_user_memories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    category VARCHAR(50) NOT NULL DEFAULT 'general',
    content TEXT NOT NULL,
    importance TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_category (user_id, category),
    INDEX idx_user_date (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 🏢 組織関連テーブル
-- ============================================

-- 組織
CREATE TABLE IF NOT EXISTS organizations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    name VARCHAR(100) NOT NULL,
    description TEXT,
    icon_path VARCHAR(500),
    
    -- 管理者
    admin_user_id INT UNSIGNED NOT NULL,
    
    -- 認証
    is_verified TINYINT(1) DEFAULT 0,
    verified_at DATETIME,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (admin_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 📋 認証・セキュリティ関連テーブル
-- ============================================

-- パスワードリセット
CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(100) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_token (token),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- メール認証トークン
CREATE TABLE IF NOT EXISTS email_verifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(100) NOT NULL,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 電話認証コード
CREATE TABLE IF NOT EXISTS phone_verifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    phone VARCHAR(20) NOT NULL,
    code VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts INT UNSIGNED DEFAULT 0,
    verified_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 法的文書の同意ログ
CREATE TABLE IF NOT EXISTS consent_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    
    consent_type ENUM('terms', 'privacy', 'parent', 'marketing') NOT NULL,
    version VARCHAR(20) NOT NULL,
    
    consented_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent TEXT,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 📈 オンボーディング関連テーブル
-- ============================================

-- オンボーディング進捗
CREATE TABLE IF NOT EXISTS onboarding_progress (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    
    tutorial_started_at DATETIME,
    tutorial_completed_at DATETIME,
    tutorial_skipped TINYINT(1) DEFAULT 0,
    
    profile_photo_set TINYINT(1) DEFAULT 0,
    first_group_joined TINYINT(1) DEFAULT 0,
    first_message_sent TINYINT(1) DEFAULT 0,
    phone_verified TINYINT(1) DEFAULT 0,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 初期データ
-- ============================================

-- AI知識ベースの初期データ（使い方マニュアル）
INSERT INTO ai_knowledge_base (category, question, answer, keywords, priority) VALUES
('基本操作', 'メッセージを送信するには？', 
'メッセージを送信するには：\n1. 左パネルから会話を選択\n2. 画面下部の入力欄にメッセージを入力\n3. 送信ボタン（📤）をクリックまたはEnterキーで送信\n\nShift+Enterで改行できます。', 
'メッセージ,送信,入力,チャット', 10),

('基本操作', 'グループを作成するには？',
'グループを作成するには：\n1. 左パネルの「＋ グループ」ボタンをクリック\n2. グループ名を入力\n3. メンバーを選択\n4. 作成ボタンをクリック\n\n※電話認証（Level 2）が必要です。',
'グループ,作成,新規', 10),

('基本操作', '友達を追加するには？',
'友達を追加するには：\n1. 左パネルの「👤 友達追加」ボタンをクリック\n2. 相手のメールアドレスまたは名前で検索\n3. 見つかった相手をクリックしてDMを開始\n\n※電話認証（Level 2）でDMが送れるようになります。',
'友達,追加,検索,DM', 10),

('通話', 'ビデオ通話を開始するには？',
'ビデオ通話を開始するには：\n1. 通話したい相手またはグループの会話を開く\n2. 画面上部の📹ボタンをクリック\n3. 相手が応答すると通話開始\n\n※電話認証（Level 2）が必要です。',
'ビデオ,通話,電話', 10),

('保護者', '子どものアカウントと紐付けるには？',
'お子様のアカウントと紐付けるには：\n1. お子様がアカウント登録時に保護者のメールアドレスを入力\n2. 保護者にメールが届くので、承認リンクをクリック\n3. SNS設定を提案し、お子様が同意\n\n紐付け後は保護者ダッシュボードで利用状況を確認できます。',
'子ども,保護者,紐付け,連携', 10),

('セキュリティ', 'パスワードを忘れました',
'パスワードをお忘れの場合：\n1. ログイン画面の「パスワードを忘れた方」をクリック\n2. 登録したメールアドレスを入力\n3. 届いたメールのリンクから新しいパスワードを設定\n\nリンクは1時間有効です。',
'パスワード,忘れた,リセット', 10);

SET FOREIGN_KEY_CHECKS = 1;
