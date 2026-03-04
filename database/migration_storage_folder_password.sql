-- 共有フォルダ：フォルダ単位のパスワード設定用カラム追加
-- 実行: 既存環境に適用する場合のみ実行

ALTER TABLE storage_folders
ADD COLUMN password_hash VARCHAR(255) NULL DEFAULT NULL
COMMENT 'フォルダ閲覧用パスワードのハッシュ（NULL=未設定）'
AFTER updated_at;
