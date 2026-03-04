-- 拡張機能関連テーブル
-- 仕様書: 05_チャット機能.md

-- =====================================================
-- 翻訳キャッシュテーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS translation_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cache_key VARCHAR(64) NOT NULL UNIQUE COMMENT 'キャッシュキー（MD5）',
    original_text TEXT NOT NULL COMMENT '元テキスト',
    translated_text TEXT NOT NULL COMMENT '翻訳テキスト',
    source_lang VARCHAR(10) COMMENT '元言語',
    target_lang VARCHAR(10) NOT NULL COMMENT '翻訳先言語',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cache_key (cache_key),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 翻訳使用量テーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS translation_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'ユーザーID',
    character_count INT NOT NULL COMMENT '文字数',
    source_lang VARCHAR(10) COMMENT '元言語',
    target_lang VARCHAR(10) NOT NULL COMMENT '翻訳先言語',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ユーザー設定テーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS user_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE COMMENT 'ユーザーID',
    theme VARCHAR(50) DEFAULT 'light' COMMENT 'テーマ',
    language VARCHAR(10) DEFAULT 'ja' COMMENT '表示言語',
    font_size ENUM('small', 'medium', 'large') DEFAULT 'medium' COMMENT 'フォントサイズ',
    auto_translate TINYINT(1) DEFAULT 0 COMMENT '自動翻訳',
    translate_target_lang VARCHAR(10) DEFAULT 'ja' COMMENT '翻訳先言語',
    enter_to_send TINYINT(1) DEFAULT 1 COMMENT 'Enterで送信',
    show_typing_indicator TINYINT(1) DEFAULT 1 COMMENT 'タイピング表示',
    message_preview TINYINT(1) DEFAULT 1 COMMENT 'メッセージプレビュー',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 検索履歴テーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS search_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'ユーザーID',
    search_type ENUM('user', 'message', 'group', 'file') NOT NULL COMMENT '検索種類',
    keyword VARCHAR(200) NOT NULL COMMENT '検索キーワード',
    result_count INT DEFAULT 0 COMMENT '結果数',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ブロックリストテーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS blocked_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'ブロックしたユーザー',
    blocked_user_id INT NOT NULL COMMENT 'ブロックされたユーザー',
    reason VARCHAR(200) COMMENT '理由',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (blocked_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_block (user_id, blocked_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 通報テーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reporter_id INT NOT NULL COMMENT '通報者',
    reported_user_id INT DEFAULT NULL COMMENT '通報対象ユーザー',
    reported_message_id INT DEFAULT NULL COMMENT '通報対象メッセージ',
    reported_conversation_id INT DEFAULT NULL COMMENT '通報対象会話',
    report_type ENUM('spam', 'harassment', 'inappropriate', 'violence', 'other') NOT NULL COMMENT '種類',
    description TEXT COMMENT '詳細',
    status ENUM('pending', 'reviewing', 'resolved', 'dismissed') DEFAULT 'pending' COMMENT '状態',
    reviewed_by INT DEFAULT NULL COMMENT '対応者',
    reviewed_at DATETIME DEFAULT NULL COMMENT '対応日時',
    action_taken VARCHAR(200) COMMENT '対応内容',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reported_message_id) REFERENCES messages(id) ON DELETE SET NULL,
    FOREIGN KEY (reported_conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;








