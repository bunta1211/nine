-- =====================================================
-- font_size に compact（90%表示）を追加
-- 初回入室者向けデフォルト設定
-- =====================================================

-- ENUMに compact を追加（既存のDEFAULTは維持）
ALTER TABLE user_settings
    MODIFY COLUMN font_size ENUM('compact', 'small', 'medium', 'large') DEFAULT 'medium' COMMENT 'フォントサイズ';
