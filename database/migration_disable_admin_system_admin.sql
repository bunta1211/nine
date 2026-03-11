-- ============================================
-- 旧システム管理者 admin@social9.jp を無効化
-- システム管理は saitanibunta@social9.jp（Bunta）に統一するため、
-- admin@social9.jp は削除扱い（status=deleted、email をリネーム）にする。
-- 実行: 本番DBで 1 回だけ実行。既に無効化済みの場合は影響なし。
-- ============================================

UPDATE users
SET
    status = 'deleted',
    email = CONCAT('_deleted_', id, '_', email),
    updated_at = NOW()
WHERE email = 'admin@social9.jp'
  AND status != 'deleted';
