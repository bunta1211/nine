-- messagesテーブルにmessage_typeカラムを追加
-- message_type: 'text'（通常メッセージ）, 'system'（システム通知）, 'media'（メディア）など

ALTER TABLE messages 
ADD COLUMN IF NOT EXISTS message_type VARCHAR(20) DEFAULT 'text' AFTER content;

-- インデックス追加（オプション）
-- CREATE INDEX idx_messages_type ON messages(message_type);


