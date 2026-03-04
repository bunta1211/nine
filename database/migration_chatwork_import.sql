-- チャットワークログインポート用カラム追加
-- 重複インポート防止と出所の識別のため
-- 
-- 実行前: 既にカラムが存在する場合はエラーになるので、その場合はスキップしてください

-- external_id: ChatworkメッセージID（重複チェック用）
ALTER TABLE messages ADD COLUMN external_id VARCHAR(100) DEFAULT NULL COMMENT 'ChatworkメッセージID等';

-- source: 出所（'social9'=通常, 'chatwork'=インポート）
ALTER TABLE messages ADD COLUMN source VARCHAR(20) DEFAULT 'social9' COMMENT '出所: social9, chatwork';

-- インデックス（重複チェック用、任意）
-- CREATE INDEX idx_messages_external_id ON messages(conversation_id, external_id);
