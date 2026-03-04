-- ============================================
-- message_reactions をアプリ仕様に合わせて作り直す
-- 実行前に左で「social9」を選択してから「SQL」タブで実行すること
-- ============================================
-- アプリは「1メッセージにつき1ユーザー1種類」のため
-- UNIQUE は (message_id, user_id) のみにする
-- ============================================
-- 本番 RDS の messages.id / users.id が INT の場合に合わせ、
-- 参照列は INT に統一（UNSIGNED は id のみ）
-- ============================================

USE social9;

DROP TABLE IF EXISTS message_reactions;

CREATE TABLE message_reactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    user_id INT NOT NULL,
    reaction_type VARCHAR(10) NOT NULL DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reaction (message_id, user_id),
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_message_id (message_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'message_reactions を作り直しました' AS message;
