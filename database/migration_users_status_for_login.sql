-- users テーブルに status カラムを追加（Auth::login の WHERE status = 'active' 用）
-- schema_complete.sql には含まれていないため、Docker 等で schema_complete のみ流した場合に実行する

SET NAMES utf8mb4;

-- MySQL 8.0.12+ の ADD COLUMN IF NOT EXISTS を使用
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS `status` ENUM('active', 'suspended', 'deleted') DEFAULT 'active' COMMENT 'アカウント状態' AFTER `role`;

-- 既存行を active に統一（DEFAULT で入るが念のため）
UPDATE users SET status = 'active' WHERE status IS NULL;
