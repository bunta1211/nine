-- message_mentions テーブルに mention_type カラムを追加
-- 実行: mysql -u root social9 < database/migration_mention_type.sql

-- mention_type カラムを追加（text: @メンション, to: 個別TO, to_all: 全員TO）
ALTER TABLE message_mentions 
ADD COLUMN IF NOT EXISTS mention_type ENUM('text', 'to', 'to_all') DEFAULT 'text' AFTER mentioned_user_id;

-- インデックス追加
ALTER TABLE message_mentions 
ADD INDEX IF NOT EXISTS idx_mention_type (mention_type);


