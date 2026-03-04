-- =====================================================
-- 多言語対応：名前カラム追加マイグレーション
-- 対象: conversations, users, organizations
-- 言語: 日本語(既存), 英語(en), 中国語(zh)
-- =====================================================

-- =====================================================
-- 1. conversations テーブル（グループ名）
-- =====================================================
ALTER TABLE conversations 
    ADD COLUMN IF NOT EXISTS name_en VARCHAR(100) DEFAULT NULL COMMENT 'グループ名（英語）' AFTER name,
    ADD COLUMN IF NOT EXISTS name_zh VARCHAR(100) DEFAULT NULL COMMENT 'グループ名（中国語）' AFTER name_en,
    ADD COLUMN IF NOT EXISTS description_en TEXT DEFAULT NULL COMMENT '説明（英語）' AFTER description,
    ADD COLUMN IF NOT EXISTS description_zh TEXT DEFAULT NULL COMMENT '説明（中国語）' AFTER description_en;

-- =====================================================
-- 2. users テーブル（表示名）
-- =====================================================
ALTER TABLE users 
    ADD COLUMN IF NOT EXISTS display_name_en VARCHAR(100) DEFAULT NULL COMMENT '表示名（英語）' AFTER display_name,
    ADD COLUMN IF NOT EXISTS display_name_zh VARCHAR(100) DEFAULT NULL COMMENT '表示名（中国語）' AFTER display_name_en,
    ADD COLUMN IF NOT EXISTS full_name_en VARCHAR(100) DEFAULT NULL COMMENT '本名（英語）' AFTER full_name,
    ADD COLUMN IF NOT EXISTS full_name_zh VARCHAR(100) DEFAULT NULL COMMENT '本名（中国語）' AFTER full_name_en;

-- =====================================================
-- 3. organizations テーブル（組織名）
-- =====================================================
ALTER TABLE organizations 
    ADD COLUMN IF NOT EXISTS name_en VARCHAR(100) DEFAULT NULL COMMENT '組織名（英語）' AFTER name,
    ADD COLUMN IF NOT EXISTS name_zh VARCHAR(100) DEFAULT NULL COMMENT '組織名（中国語）' AFTER name_en,
    ADD COLUMN IF NOT EXISTS description_en TEXT DEFAULT NULL COMMENT '説明（英語）' AFTER description,
    ADD COLUMN IF NOT EXISTS description_zh TEXT DEFAULT NULL COMMENT '説明（中国語）' AFTER description_en;

-- =====================================================
-- 確認クエリ
-- =====================================================
-- 変更確認用
-- DESCRIBE conversations;
-- DESCRIBE users;
-- DESCRIBE organizations;


