-- メッセージ関連テーブル
-- 仕様書: 05_チャット機能.md, 08_グループ会話管理.md

-- =====================================================
-- 会話テーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('dm', 'group') NOT NULL DEFAULT 'group' COMMENT '種類',
    name VARCHAR(100) DEFAULT NULL COMMENT 'グループ名',
    description TEXT COMMENT '説明',
    icon VARCHAR(255) DEFAULT NULL COMMENT 'アイコンパス',
    is_organization TINYINT(1) DEFAULT 0 COMMENT '組織ルームフラグ',
    is_public TINYINT(1) DEFAULT 0 COMMENT '公開フラグ',
    invite_link VARCHAR(100) DEFAULT NULL COMMENT '招待リンク',
    created_by INT DEFAULT NULL COMMENT '作成者',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_type (type),
    INDEX idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 会話メンバーテーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS conversation_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL COMMENT '会話ID',
    user_id INT NOT NULL COMMENT 'ユーザーID',
    role ENUM('admin', 'member', 'viewer') DEFAULT 'member' COMMENT '役割',
    is_pinned TINYINT(1) DEFAULT 0 COMMENT 'ピン留め',
    is_muted TINYINT(1) DEFAULT 0 COMMENT 'ミュート',
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '参加日時',
    left_at DATETIME DEFAULT NULL COMMENT '退出日時',
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_member (conversation_id, user_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- メッセージテーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL COMMENT '会話ID',
    sender_id INT NOT NULL COMMENT '送信者ID',
    content TEXT COMMENT '内容',
    message_type ENUM('text', 'image', 'file', 'audio', 'video', 'system') DEFAULT 'text' COMMENT '種類',
    file_id INT DEFAULT NULL COMMENT 'ファイルID',
    reply_to_id INT DEFAULT NULL COMMENT '返信先メッセージID',
    mentions JSON DEFAULT NULL COMMENT 'メンションユーザーID',
    scheduled_at DATETIME DEFAULT NULL COMMENT '予約送信日時',
    edited_at DATETIME DEFAULT NULL COMMENT '編集日時',
    deleted_at DATETIME DEFAULT NULL COMMENT '削除日時',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL,
    FOREIGN KEY (reply_to_id) REFERENCES messages(id) ON DELETE SET NULL,
    INDEX idx_conversation (conversation_id),
    INDEX idx_created (created_at),
    INDEX idx_scheduled (scheduled_at),
    FULLTEXT INDEX ft_content (content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- メッセージ既読テーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS message_read_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL COMMENT 'メッセージID',
    user_id INT NOT NULL COMMENT 'ユーザーID',
    read_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '既読日時',
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_read (message_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- メッセージリアクション（ナイス）テーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS message_reactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL COMMENT 'メッセージID',
    user_id INT NOT NULL COMMENT 'ユーザーID',
    reaction_type VARCHAR(10) NOT NULL DEFAULT '👍' COMMENT 'リアクション種類',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_reaction (message_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ファイルテーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uploaded_by INT NOT NULL COMMENT 'アップロード者',
    original_name VARCHAR(255) NOT NULL COMMENT '元ファイル名',
    stored_name VARCHAR(255) NOT NULL COMMENT '保存ファイル名',
    file_path VARCHAR(500) NOT NULL COMMENT 'ファイルパス',
    thumbnail_path VARCHAR(500) DEFAULT NULL COMMENT 'サムネイルパス',
    file_size BIGINT NOT NULL DEFAULT 0 COMMENT 'ファイルサイズ',
    mime_type VARCHAR(100) NOT NULL COMMENT 'MIMEタイプ',
    file_type ENUM('image', 'video', 'audio', 'file') DEFAULT 'file' COMMENT 'ファイル種類',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_uploader (uploaded_by),
    INDEX idx_type (file_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 通知設定テーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS notification_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE COMMENT 'ユーザーID',
    notify_new_message TINYINT(1) DEFAULT 1 COMMENT '新着メッセージ通知',
    notify_mention TINYINT(1) DEFAULT 1 COMMENT 'メンション通知',
    notify_call TINYINT(1) DEFAULT 1 COMMENT '着信通知',
    notify_permission_request TINYINT(1) DEFAULT 1 COMMENT '許可リクエスト通知',
    sound_enabled TINYINT(1) DEFAULT 1 COMMENT '音声通知',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;








