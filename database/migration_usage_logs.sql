-- ============================================
-- 利用履歴テーブル
-- 子どもの利用時間制限管理用
-- ============================================

CREATE TABLE IF NOT EXISTS usage_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NOT NULL,
    
    -- 利用時間
    duration_minutes INT UNSIGNED NOT NULL DEFAULT 1,
    
    -- 活動タイプ
    activity_type ENUM('message', 'call', 'general') DEFAULT 'general',
    
    -- メタデータ
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_date (user_id, created_at),
    INDEX idx_org_date (organization_id, created_at),
    INDEX idx_user_org_date (user_id, organization_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='ユーザー利用履歴（利用時間制限用）';

-- organization_members に利用時間カラムを追加（存在しない場合）
-- ALTER TABLE organization_members ADD COLUMN IF NOT EXISTS usage_start_time TIME DEFAULT '07:00:00';
-- ALTER TABLE organization_members ADD COLUMN IF NOT EXISTS usage_end_time TIME DEFAULT '21:00:00';
-- ALTER TABLE organization_members ADD COLUMN IF NOT EXISTS daily_limit_minutes INT UNSIGNED DEFAULT 120;


