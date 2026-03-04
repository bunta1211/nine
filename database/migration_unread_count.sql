-- 未読メッセージカウント用マイグレーション
-- conversation_membersテーブルにlast_read_atカラムを追加

-- last_read_atカラムが存在しない場合に追加
ALTER TABLE conversation_members ADD COLUMN IF NOT EXISTS last_read_at DATETIME NULL;

-- last_read_message_idカラムも追加（オプション）
ALTER TABLE conversation_members ADD COLUMN IF NOT EXISTS last_read_message_id INT UNSIGNED NULL;

-- インデックスを追加（パフォーマンス向上）
-- ALTER TABLE conversation_members ADD INDEX idx_last_read (last_read_at);


