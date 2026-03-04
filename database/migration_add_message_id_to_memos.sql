-- memosテーブルにmessage_idカラムを追加
-- 元メッセージへのリンク機能用

ALTER TABLE memos 
ADD COLUMN message_id INT UNSIGNED DEFAULT NULL COMMENT '元メッセージID' AFTER conversation_id;

-- インデックスを追加
ALTER TABLE memos 
ADD INDEX idx_message_id (message_id);


