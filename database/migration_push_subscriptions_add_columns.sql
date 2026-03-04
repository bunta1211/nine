-- push_subscriptions テーブルに不足しているカラムを追加
-- エラー: Unknown column 'is_active' in 'field list' の解消用
-- 実行: phpMyAdmin または mysql クライアントで実行

-- 1. is_active カラムを追加（必須）
ALTER TABLE push_subscriptions 
ADD COLUMN is_active TINYINT(1) DEFAULT 1 COMMENT '有効フラグ' 
AFTER user_agent;

-- 2. インデックス追加（オプション。idx_user_active が既にある場合はエラーになりますが無視して構いません）
-- ALTER TABLE push_subscriptions ADD INDEX idx_user_active (user_id, is_active);
