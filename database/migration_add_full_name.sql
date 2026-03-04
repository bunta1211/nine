-- =====================================================
-- full_name（本名）カラムを追加
-- =====================================================

-- usersテーブルにfull_nameカラムを追加（存在しない場合）
ALTER TABLE users 
    ADD COLUMN IF NOT EXISTS full_name VARCHAR(100) DEFAULT NULL COMMENT '本名' AFTER display_name;

-- インデックスを追加
ALTER TABLE users 
    ADD INDEX IF NOT EXISTS idx_full_name (full_name);


