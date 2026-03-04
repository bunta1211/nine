-- push_subscriptions に last_used_at カラムを追加
-- エラー: Unknown column 'last_used_at' in 'field list' の解消用
-- 実行: phpMyAdmin で実行

ALTER TABLE push_subscriptions 
ADD COLUMN last_used_at DATETIME DEFAULT NULL COMMENT '最後に使用した日時' 
AFTER updated_at;
