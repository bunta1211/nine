-- グループメンバー間DM許可設定を追加
-- デフォルトは1（許可）
--
-- 【実行方法】phpMyAdmin の場合:
-- 1. このファイルを開き、下のALTER文をコピー
-- 2. phpMyAdminの「SQL」タブを開く
-- 3. コピーしたSQLを貼り付けて「実行」をクリック
-- ※ ファイルパス(database/migration_allow_member_dm.sql)を貼り付けないこと

ALTER TABLE conversations ADD COLUMN IF NOT EXISTS allow_member_dm TINYINT(1) DEFAULT 1;

