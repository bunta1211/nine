-- 標準デザイン固定：user_settings の theme / background_image を統一
-- 実行: 標準デザイン（lavender）のみにした際の既存データ整備用
-- 目的: 全ユーザーの theme を 'lavender'、background_image を 'none' に更新

-- user_settings が存在する場合のみ実行（テーブルがない環境ではエラーにしないこと）
UPDATE user_settings
SET theme = 'lavender',
    background_image = 'none',
    updated_at = COALESCE(updated_at, CURRENT_TIMESTAMP)
WHERE theme != 'lavender' OR (background_image IS NOT NULL AND background_image != 'none' AND background_image != '');
