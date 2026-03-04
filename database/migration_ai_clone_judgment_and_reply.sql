-- ============================================================
-- AIクローン（あなたの秘書）育成計画用マイグレーション
-- 判断材料フォルダ・アイテム、返信提案履歴、user_ai_settings 追加カラム
-- 実行: 1文ずつ実行し、「Duplicate column」等は既存のため無視してよい。
-- ============================================================

-- 1. 判断材料フォルダ（共有フォルダ形式・ユーザー単位）
CREATE TABLE IF NOT EXISTS user_ai_judgment_folders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    parent_id INT UNSIGNED NULL COMMENT 'NULLならルート直下',
    name VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_parent (user_id, parent_id),
    INDEX idx_user_sort (user_id, sort_order),
    CONSTRAINT fk_judgment_folder_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_judgment_folder_parent FOREIGN KEY (parent_id) REFERENCES user_ai_judgment_folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 判断材料フォルダ内アイテム（テキストまたは file_path）
CREATE TABLE IF NOT EXISTS user_ai_judgment_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    folder_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(500) NOT NULL DEFAULT '',
    content TEXT NULL COMMENT 'テキスト本文。file_path がある場合はファイル内容の要約やメモ',
    file_path VARCHAR(1000) NULL COMMENT '実ファイル参照（任意）',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_folder_sort (folder_id, sort_order),
    INDEX idx_user (user_id),
    CONSTRAINT fk_judgment_item_folder FOREIGN KEY (folder_id) REFERENCES user_ai_judgment_folders(id) ON DELETE CASCADE,
    CONSTRAINT fk_judgment_item_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 返信提案・教材記録（修正率算出用）
CREATE TABLE IF NOT EXISTS user_ai_reply_suggestions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    conversation_id INT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NOT NULL COMMENT 'メンションされたメッセージID',
    suggested_content TEXT NOT NULL COMMENT 'AIが提案した返信文',
    final_content TEXT NULL COMMENT 'ユーザーが修正して送信した本文。NULL=未送信',
    sent_at DATETIME NULL COMMENT '送信日時',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_conv_message (conversation_id, message_id),
    CONSTRAINT fk_reply_sugg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. user_ai_settings に AIクローン用カラムを追加
-- （Duplicate column の場合はその行をスキップ）
ALTER TABLE user_ai_settings ADD COLUMN conversation_memory_summary TEXT NULL COMMENT '会話記憶の要約JSON' AFTER user_profile;
ALTER TABLE user_ai_settings ADD COLUMN clone_training_language VARCHAR(10) DEFAULT 'ja' COMMENT '訓練・返信の言語 ja/en/zh' AFTER conversation_memory_summary;
ALTER TABLE user_ai_settings ADD COLUMN clone_auto_reply_enabled TINYINT(1) DEFAULT 0 COMMENT 'AI[ユーザー名]自動返信 1=ON' AFTER clone_training_language;
