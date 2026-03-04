-- ============================================
-- Guild 初期セットアップ
-- Social9のユーザーをそのまま使用
-- ============================================

-- 奈良健太郎さんをシステム管理者として設定
-- まず、usersテーブルからユーザーを確認してIDを特定します

-- システム管理者権限を設定（奈良さんのuser_idを後で指定）
-- INSERT INTO guild_system_permissions を実行

-- 2026年度を有効化
UPDATE guild_fiscal_years 
SET status = 'active', opened_at = NOW()
WHERE fiscal_year = 2026;
