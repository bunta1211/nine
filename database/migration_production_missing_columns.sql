-- =====================================================
-- 本番DBで不足している場合があるカラムを追加するマイグレーション
-- api/messages.php および api/tasks.php の 500 対策として、
-- コード側はカラム有無を SHOW COLUMNS で判定して動作するが、
-- 本番で根本対応する場合はこの SQL を実行する。
-- 各文は「カラムが既に存在する」場合にエラーになるが、その場合はスキップして次を実行すればよい。
-- =====================================================

-- messages
ALTER TABLE messages ADD COLUMN reply_to_id INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE messages ADD COLUMN message_type VARCHAR(20) DEFAULT 'text';
ALTER TABLE messages ADD COLUMN is_edited TINYINT(1) DEFAULT 0;
ALTER TABLE messages ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL;
ALTER TABLE messages ADD COLUMN extracted_text LONGTEXT NULL DEFAULT NULL;

-- users（avatar_path が無い環境用）
ALTER TABLE users ADD COLUMN avatar_path VARCHAR(500) NULL DEFAULT NULL;

-- conversation_members（left_at が無い環境用）
ALTER TABLE conversation_members ADD COLUMN left_at DATETIME NULL DEFAULT NULL;

-- tasks（type / status / deleted_at / is_shared が無い環境用）
ALTER TABLE tasks ADD COLUMN type VARCHAR(20) DEFAULT 'task';
ALTER TABLE tasks ADD COLUMN status VARCHAR(20) DEFAULT 'pending';
ALTER TABLE tasks ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL;
ALTER TABLE tasks ADD COLUMN is_shared TINYINT(1) DEFAULT 0;

-- conversation_members（last_read_message_id が無い環境用・既読更新用）
ALTER TABLE conversation_members ADD COLUMN last_read_message_id INT UNSIGNED NULL DEFAULT NULL;
