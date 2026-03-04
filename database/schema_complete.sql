-- =====================================================
-- Social9 完全データベーススキーマ
-- 仕様書: 35_データベーステーブル詳細.md
-- 作成日: 2024-12-24
-- =====================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- ユーザー関連テーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE COMMENT 'メールアドレス',
    password_hash VARCHAR(255) NOT NULL COMMENT 'パスワードハッシュ',
    display_name VARCHAR(100) NOT NULL COMMENT '表示名',
    avatar_path VARCHAR(500) DEFAULT NULL COMMENT 'アバター画像パス',
    bio TEXT COMMENT '自己紹介',
    birth_date DATE COMMENT '生年月日',
    phone VARCHAR(20) DEFAULT NULL COMMENT '電話番号',
    prefecture VARCHAR(50) DEFAULT NULL COMMENT '都道府県',
    city VARCHAR(100) DEFAULT NULL COMMENT '市区町村',
    family_structure VARCHAR(100) DEFAULT NULL COMMENT '家族構成',
    
    -- 認証レベル
    auth_level TINYINT DEFAULT 0 COMMENT '認証レベル（0:未認証, 1:メール, 2:電話, 3:本人確認）',
    email_verified_at DATETIME DEFAULT NULL COMMENT 'メール認証日時',
    phone_verified_at DATETIME DEFAULT NULL COMMENT '電話認証日時',
    identity_verified_at DATETIME DEFAULT NULL COMMENT '本人確認日時',
    identity_document_path VARCHAR(500) DEFAULT NULL COMMENT '本人確認書類パス',
    identity_status ENUM('none', 'pending', 'approved', 'rejected') DEFAULT 'none' COMMENT '本人確認状態',
    
    -- ユーザー属性
    role ENUM('system_admin', 'org_admin', 'user') DEFAULT 'user' COMMENT '役割',
    is_minor TINYINT(1) DEFAULT 0 COMMENT '未成年フラグ',
    is_qualified_investor TINYINT(1) DEFAULT 0 COMMENT '特定投資家フラグ',
    organization_id INT DEFAULT NULL COMMENT '所属組織ID',
    
    -- オンラインステータス
    online_status ENUM('online', 'away', 'offline') DEFAULT 'offline' COMMENT 'オンライン状態',
    last_seen DATETIME DEFAULT NULL COMMENT '最終アクティブ日時',
    custom_status VARCHAR(100) DEFAULT NULL COMMENT 'カスタムステータス',
    
    -- メタデータ
    password_reset_token VARCHAR(100) DEFAULT NULL COMMENT 'パスワードリセットトークン',
    password_reset_expires DATETIME DEFAULT NULL COMMENT 'トークン有効期限',
    email_verification_token VARCHAR(100) DEFAULT NULL COMMENT 'メール認証トークン',
    login_attempts INT DEFAULT 0 COMMENT 'ログイン試行回数',
    locked_until DATETIME DEFAULT NULL COMMENT 'ロック解除日時',
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_online (online_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- オンボーディング進捗
CREATE TABLE IF NOT EXISTS onboarding_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    registration_completed TINYINT(1) DEFAULT 0,
    email_verified TINYINT(1) DEFAULT 0,
    profile_completed TINYINT(1) DEFAULT 0,
    tutorial_completed TINYINT(1) DEFAULT 0,
    parent_linked TINYINT(1) DEFAULT 0 COMMENT '未成年のみ',
    current_step VARCHAR(50) DEFAULT 'registration',
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 組織テーブル
CREATE TABLE IF NOT EXISTS organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL COMMENT '組織名',
    description TEXT COMMENT '説明',
    logo_path VARCHAR(500) DEFAULT NULL COMMENT 'ロゴ画像',
    website VARCHAR(500) DEFAULT NULL COMMENT 'Webサイト',
    owner_id INT NOT NULL COMMENT '代表者',
    is_verified TINYINT(1) DEFAULT 0 COMMENT '認証済みフラグ',
    verified_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ユーザー評判（統合）
CREATE TABLE IF NOT EXISTS user_reputation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    positive_reviews INT DEFAULT 0,
    negative_reviews INT DEFAULT 0,
    negative_given INT DEFAULT 0 COMMENT 'マイナス評価した回数',
    report_count INT DEFAULT 0,
    warning_count INT DEFAULT 0,
    ng_word_count INT DEFAULT 0,
    trust_score DECIMAL(5,2) DEFAULT 100.00,
    badges JSON COMMENT '獲得バッジ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 保護者・児童連携
-- =====================================================

CREATE TABLE IF NOT EXISTS parent_child_relations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_user_id INT NOT NULL,
    child_user_id INT NOT NULL,
    status ENUM('pending', 'parent_approved', 'active', 'inactive') DEFAULT 'pending',
    parent_approved_at DATETIME DEFAULT NULL,
    child_approved_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_relation (parent_user_id, child_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS child_sns_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    relation_id INT NOT NULL UNIQUE,
    friend_add_restriction ENUM('free', 'school_only', 'parent_approved') DEFAULT 'parent_approved',
    call_restriction ENUM('free', 'approved_only', 'disabled') DEFAULT 'approved_only',
    allow_japanese TINYINT(1) DEFAULT 1,
    allow_english TINYINT(1) DEFAULT 1,
    allow_chinese TINYINT(1) DEFAULT 1,
    allow_stamps TINYINT(1) DEFAULT 1,
    usage_start_time TIME DEFAULT '07:00:00',
    usage_end_time TIME DEFAULT '21:00:00',
    daily_limit_minutes INT DEFAULT 120,
    can_view_messages TINYINT(1) DEFAULT 0,
    can_view_online_status TINYINT(1) DEFAULT 1,
    can_view_friends TINYINT(1) DEFAULT 1,
    parent_proposed_at DATETIME DEFAULT NULL,
    child_agreed_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (relation_id) REFERENCES parent_child_relations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approved_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    child_user_id INT NOT NULL,
    approved_user_id INT NOT NULL,
    approved_by INT NOT NULL,
    allow_dm TINYINT(1) DEFAULT 1,
    allow_call TINYINT(1) DEFAULT 1,
    approved_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_contact (child_user_id, approved_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 会話・メッセージ
-- =====================================================

CREATE TABLE IF NOT EXISTS conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('dm', 'group') NOT NULL DEFAULT 'group',
    name VARCHAR(100) DEFAULT NULL,
    description TEXT,
    icon VARCHAR(255) DEFAULT NULL,
    is_organization TINYINT(1) DEFAULT 0,
    is_public TINYINT(1) DEFAULT 0,
    invite_link VARCHAR(100) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_type (type),
    INDEX idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversation_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('admin', 'member', 'viewer') DEFAULT 'member',
    is_pinned TINYINT(1) DEFAULT 0,
    is_muted TINYINT(1) DEFAULT 0,
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    left_at DATETIME DEFAULT NULL,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_member (conversation_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uploaded_by INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    thumbnail_path VARCHAR(500) DEFAULT NULL,
    file_size BIGINT NOT NULL DEFAULT 0,
    mime_type VARCHAR(100) NOT NULL,
    file_type ENUM('image', 'video', 'audio', 'file') DEFAULT 'file',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_id INT NOT NULL,
    content TEXT,
    message_type ENUM('text', 'image', 'file', 'audio', 'video', 'system') DEFAULT 'text',
    file_id INT DEFAULT NULL,
    reply_to_id INT DEFAULT NULL,
    mentions JSON DEFAULT NULL,
    scheduled_at DATETIME DEFAULT NULL,
    edited_at DATETIME DEFAULT NULL,
    deleted_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL,
    FOREIGN KEY (reply_to_id) REFERENCES messages(id) ON DELETE SET NULL,
    INDEX idx_conversation (conversation_id),
    INDEX idx_created (created_at),
    FULLTEXT INDEX ft_content (content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_read_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    user_id INT NOT NULL,
    read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_read (message_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_reactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    user_id INT NOT NULL,
    reaction_type VARCHAR(10) NOT NULL DEFAULT '👍',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_reaction (message_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 通話
-- =====================================================

CREATE TABLE IF NOT EXISTS calls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    initiator_id INT NOT NULL,
    room_id VARCHAR(100) NOT NULL UNIQUE,
    call_type ENUM('audio', 'video') DEFAULT 'video',
    status ENUM('ringing', 'active', 'ended', 'missed', 'declined') DEFAULT 'ringing',
    started_at DATETIME DEFAULT NULL,
    ended_at DATETIME DEFAULT NULL,
    duration_seconds INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (initiator_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS call_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    call_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('invited', 'joined', 'left', 'declined') DEFAULT 'invited',
    joined_at DATETIME DEFAULT NULL,
    left_at DATETIME DEFAULT NULL,
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_participant (call_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 通知
-- =====================================================

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('message', 'mention', 'call_incoming', 'call_missed', 'permission_request', 'system') NOT NULL,
    title VARCHAR(200) NOT NULL,
    content TEXT,
    related_type VARCHAR(50) DEFAULT NULL,
    related_id INT DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    read_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_unread (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    notify_new_message TINYINT(1) DEFAULT 1,
    notify_mention TINYINT(1) DEFAULT 1,
    notify_call TINYINT(1) DEFAULT 1,
    notify_permission_request TINYINT(1) DEFAULT 1,
    sound_enabled TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- タスク・メモ
-- =====================================================

CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    due_date DATE DEFAULT NULL,
    priority TINYINT DEFAULT 0,
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    created_by INT NOT NULL,
    assigned_to INT DEFAULT NULL,
    conversation_id INT DEFAULT NULL,
    is_shared TINYINT(1) DEFAULT 0,
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS memos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    content TEXT,
    color VARCHAR(20) DEFAULT '#ffffff',
    created_by INT NOT NULL,
    conversation_id INT DEFAULT NULL,
    is_pinned TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- AI相談室
-- =====================================================

CREATE TABLE IF NOT EXISTS ai_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    question TEXT NOT NULL,
    answer TEXT,
    answered_by ENUM('ai', 'admin') DEFAULT 'ai',
    language VARCHAR(10) DEFAULT 'ja',
    is_helpful TINYINT(1) DEFAULT NULL,
    feedback_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_knowledge_base (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(50) NOT NULL,
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    keywords TEXT,
    language VARCHAR(10) DEFAULT 'ja',
    priority INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 翻訳
-- =====================================================

CREATE TABLE IF NOT EXISTS translation_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cache_key VARCHAR(64) NOT NULL UNIQUE,
    original_text TEXT NOT NULL,
    translated_text TEXT NOT NULL,
    source_lang VARCHAR(10),
    target_lang VARCHAR(10) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS translation_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    character_count INT NOT NULL,
    source_lang VARCHAR(10),
    target_lang VARCHAR(10) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- マッチング
-- =====================================================

CREATE TABLE IF NOT EXISTS service_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(50) NOT NULL,
    budget INT DEFAULT 0,
    location VARCHAR(100),
    deadline DATE DEFAULT NULL,
    is_urgent TINYINT(1) DEFAULT 0,
    status ENUM('active', 'matched', 'completed', 'cancelled', 'expired') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_category (category),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_providers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    provider_type ENUM('business', 'individual', 'volunteer') NOT NULL,
    business_name VARCHAR(100) NOT NULL,
    description TEXT,
    categories JSON,
    service_areas JSON,
    rating DECIMAL(3,2) DEFAULT 0.00,
    review_count INT DEFAULT 0,
    completed_count INT DEFAULT 0,
    status ENUM('pending', 'active', 'suspended', 'inactive') DEFAULT 'pending',
    verified_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    provider_id INT NOT NULL,
    price INT DEFAULT 0,
    message TEXT,
    estimated_time VARCHAR(50),
    status ENUM('pending', 'accepted', 'declined', 'completed', 'cancelled') DEFAULT 'pending',
    accepted_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (provider_id) REFERENCES service_providers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_offer (request_id, provider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    match_id INT NOT NULL UNIQUE,
    provider_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    rating TINYINT NOT NULL,
    review TEXT,
    provider_response TEXT,
    is_anonymous TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (match_id) REFERENCES service_matches(id) ON DELETE CASCADE,
    FOREIGN KEY (provider_id) REFERENCES service_providers(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 通報・ブロック
-- =====================================================

CREATE TABLE IF NOT EXISTS reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reporter_id INT NOT NULL,
    reported_user_id INT DEFAULT NULL,
    reported_message_id INT DEFAULT NULL,
    reported_conversation_id INT DEFAULT NULL,
    report_type ENUM('spam', 'harassment', 'inappropriate', 'violence', 'other') NOT NULL,
    description TEXT,
    status ENUM('pending', 'reviewing', 'resolved', 'dismissed') DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    action_taken VARCHAR(200),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reported_message_id) REFERENCES messages(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blocked_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    blocked_user_id INT NOT NULL,
    reason VARCHAR(200),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (blocked_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_block (user_id, blocked_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ユーザー設定
-- =====================================================

CREATE TABLE IF NOT EXISTS user_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    theme VARCHAR(50) DEFAULT 'light',
    language VARCHAR(10) DEFAULT 'ja',
    font_size ENUM('small', 'medium', 'large') DEFAULT 'medium',
    auto_translate TINYINT(1) DEFAULT 0,
    translate_target_lang VARCHAR(10) DEFAULT 'ja',
    enter_to_send TINYINT(1) DEFAULT 1,
    show_typing_indicator TINYINT(1) DEFAULT 1,
    message_preview TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 投資家関連（特定投資家機能）
-- =====================================================

CREATE TABLE IF NOT EXISTS qualified_investors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    net_assets BIGINT COMMENT '純資産（円）',
    investment_experience_years INT COMMENT '投資経験年数',
    verification_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    verified_at DATETIME DEFAULT NULL,
    verified_by INT DEFAULT NULL,
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    investor_id INT NOT NULL,
    amount BIGINT NOT NULL COMMENT '出資額（円）',
    capital_portion BIGINT DEFAULT 0 COMMENT '資本金部分',
    reserve_portion BIGINT DEFAULT 0 COMMENT '資本準備金部分',
    investment_date DATE NOT NULL,
    status ENUM('pending', 'confirmed', 'cancelled') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (investor_id) REFERENCES qualified_investors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS funding_periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    period_name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    target_amount BIGINT DEFAULT 99000000 COMMENT '目標額（9,900万円）',
    current_amount BIGINT DEFAULT 0,
    status ENUM('upcoming', 'active', 'completed', 'cancelled') DEFAULT 'upcoming',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- 初期データ
-- =====================================================

-- システム管理者（パスワード: Admin123!）
INSERT INTO users (email, password_hash, display_name, role, auth_level, email_verified_at) VALUES
('admin@social9.jp', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'システム管理者', 'system_admin', 3, NOW())
ON DUPLICATE KEY UPDATE display_name = display_name;

-- AI知識ベース初期データ
INSERT INTO ai_knowledge_base (category, question, answer, keywords, language, priority) VALUES
('基本操作', 'メッセージの送り方を教えてください', 'メッセージを送るには：\n1. 左側のチャットリストから相手を選択\n2. 画面下部の入力欄にメッセージを入力\n3. 送信ボタン（または Enterキー）で送信\n\n画像やファイルを送る場合は、クリップアイコンをクリックしてください。', 'メッセージ 送り方 送信 チャット', 'ja', 100),
('基本操作', 'グループの作り方を教えてください', 'グループを作成するには：\n1. 左側パネル上部の「＋」アイコンをクリック\n2. 「グループ作成」を選択\n3. グループ名を入力\n4. メンバーを選択して「作成」をクリック', 'グループ 作成 作り方', 'ja', 95),
('アカウント', 'パスワードを忘れました', 'パスワードをお忘れの場合：\n1. ログイン画面の「パスワードを忘れた」をクリック\n2. 登録メールアドレスを入力\n3. 届いたメールのリンクから新しいパスワードを設定', 'パスワード 忘れた リセット', 'ja', 90)
ON DUPLICATE KEY UPDATE answer = VALUES(answer);








