-- Googleログイン対応: usersテーブルにgoogle_idカラムを追加
-- 実行: mysql -u user -p database < database/migration_google_login.sql
-- 注: google_idが既に存在する場合はエラーになるが、その場合はスキップして問題なし

ALTER TABLE users
    ADD COLUMN google_id VARCHAR(255) DEFAULT NULL COMMENT 'Google OAuth sub（一意ID）' AFTER password_hash;

ALTER TABLE users
    ADD UNIQUE INDEX idx_google_id (google_id);
