-- ============================================
-- Momoe（久保百恵）アカウント復活用SQL
-- 出典: database/export_utf8.sql（2026-01-05時点のエクスポート）
-- ============================================
-- 【実行例】
-- ■ Linux / EC2:
--   cd /path/to/project
--   mysql -u ユーザー名 -p データベース名 < database/restore_momoe_user.sql
--
-- ■ Windows PowerShell（XAMPP など）:
--   cd C:\xampp\htdocs\nine
--   Get-Content .\database\restore_momoe_user.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root -p データベース名
--   または（cmd 経由）:
--   cmd /c "C:\xampp\mysql\bin\mysql.exe -u root -p データベース名 < C:\xampp\htdocs\nine\database\restore_momoe_user.sql"
-- ============================================

SET NAMES utf8mb4;

-- -------------------------------------------------
-- 1. users テーブル: Momoe（user_id 134）を復元
-- -------------------------------------------------
-- メール: clover.ohamamomoe@gmail.com
-- 表示名: Momoe / 本名: 久保百恵
-- 組織: Clover International (organization_id 6)
-- -------------------------------------------------

INSERT INTO `users` (
  `id`, `email`, `password_hash`, `display_name`,
  `birth_date`, `role`, `organization_id`, `status`,
  `created_at`, `updated_at`
) VALUES (
  134,
  'clover.ohamamomoe@gmail.com',
  '$2y$10$PalgyFF8Qtjq6rXjGZ9WD.WNfpCk9e2PbBL7/ZgrAuIXD0nsgMXQe',
  'Momoe',
  '1990-01-01',
  'user',
  6,
  'active',
  '2026-01-01 03:24:39',
  NOW()
) ON DUPLICATE KEY UPDATE
  `status` = 'active',
  `updated_at` = NOW();

-- full_name カラムがある環境の場合（オプション）
-- UPDATE `users` SET `full_name` = '久保百恵' WHERE `id` = 134;

-- -------------------------------------------------
-- 2. organization_members: 組織6（Clover）に復帰
-- -------------------------------------------------

INSERT INTO `organization_members` (
  `organization_id`, `user_id`, `role`, `joined_at`
) VALUES (
  6, 134, 'member', '2026-01-01 08:06:56'
) ON DUPLICATE KEY UPDATE
  `left_at` = NULL;

-- member_type カラムがある環境の場合（オプション）
-- UPDATE `organization_members` SET `member_type` = 'internal' WHERE `organization_id` = 6 AND `user_id` = 134;

-- -------------------------------------------------
-- 3. conversation_members: グループへの再追加（例）
-- -------------------------------------------------
-- 以下は「クローバー All」(conversation_id 173) への追加例です。
-- 他のグループへも復帰させたい場合は、同様のINSERTを追加するか、
-- 管理画面から該当グループにMomoeを追加してください。
-- -------------------------------------------------

INSERT IGNORE INTO `conversation_members` (
  `conversation_id`, `user_id`, `role`, `is_pinned`, `is_muted`, `is_silenced`,
  `muted_until`, `last_read_at`, `joined_at`, `left_at`
) VALUES (
  173, 134, 'member', 0, 0, 0, NULL, NULL, NOW(), NULL
);

-- 既に退会扱い（left_at が入っている）のレコードがある場合は復帰
UPDATE `conversation_members`
SET `left_at` = NULL, `joined_at` = COALESCE(joined_at, NOW())
WHERE `user_id` = 134 AND `left_at` IS NOT NULL;

-- ============================================
-- 復活後の確認例:
-- SELECT id, email, display_name, status FROM users WHERE id = 134;
-- SELECT * FROM organization_members WHERE user_id = 134 AND left_at IS NULL;
-- ============================================
