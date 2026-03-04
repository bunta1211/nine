-- ============================================
-- 奈良健太郎にシステム管理者権限を付与
-- ============================================

-- 1. ユーザーを確認
SELECT id, username, email, display_name, full_name, role 
FROM users 
WHERE full_name LIKE '%奈良健太郎%' 
   OR display_name LIKE '%奈良%'
   OR username LIKE '%nara%';

-- 2. ロールをadminに変更（上記クエリでIDを確認後、以下を実行）
-- UPDATE users SET role = 'admin' WHERE id = [確認したID];

-- 例：IDが1の場合
-- UPDATE users SET role = 'admin' WHERE id = 1;

-- ============================================
-- ロールの説明
-- ============================================
-- developer    : 最高権限（全システム管理可能）
-- admin        : システム管理者（全管理機能アクセス可能）
-- system_admin : システム管理者（レガシー、adminと同等）
-- org_admin    : 組織管理者（自組織のみ管理可能）
-- user         : 一般ユーザー
