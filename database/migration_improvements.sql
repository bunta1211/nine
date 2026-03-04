-- ============================================
-- 改善項目のマイグレーション
-- 実行日: 2026-01-01
-- ============================================

-- 1. usage_logs テーブルの作成（利用時間制限用）
CREATE TABLE IF NOT EXISTS usage_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NOT NULL,
    duration_minutes INT UNSIGNED NOT NULL DEFAULT 1,
    activity_type ENUM('message', 'call', 'general') DEFAULT 'general',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_date (user_id, created_at),
    INDEX idx_org_date (organization_id, created_at),
    INDEX idx_user_org_date (user_id, organization_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='ユーザー利用履歴（利用時間制限用）';

-- 2. organization_members に利用時間制限カラムを追加（存在しない場合）
-- 注意: ALTER TABLE ADD COLUMN IF NOT EXISTS はMySQL 8.0.16以降
-- 古いバージョンの場合は手動で確認してから実行

-- 利用開始時間
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members' AND COLUMN_NAME = 'usage_start_time');
SET @query = IF(@col_exists = 0, 
    'ALTER TABLE organization_members ADD COLUMN usage_start_time TIME DEFAULT ''07:00:00''', 
    'SELECT ''usage_start_time already exists''');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 利用終了時間
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members' AND COLUMN_NAME = 'usage_end_time');
SET @query = IF(@col_exists = 0, 
    'ALTER TABLE organization_members ADD COLUMN usage_end_time TIME DEFAULT ''21:00:00''', 
    'SELECT ''usage_end_time already exists''');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 1日の利用制限（分）
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members' AND COLUMN_NAME = 'daily_limit_minutes');
SET @query = IF(@col_exists = 0, 
    'ALTER TABLE organization_members ADD COLUMN daily_limit_minutes INT UNSIGNED DEFAULT 120', 
    'SELECT ''daily_limit_minutes already exists''');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 組織外連絡許可
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members' AND COLUMN_NAME = 'external_contact');
SET @query = IF(@col_exists = 0, 
    'ALTER TABLE organization_members ADD COLUMN external_contact TINYINT(1) DEFAULT 0', 
    'SELECT ''external_contact already exists''');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 通話制限
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members' AND COLUMN_NAME = 'call_restriction');
SET @query = IF(@col_exists = 0, 
    'ALTER TABLE organization_members ADD COLUMN call_restriction ENUM(''none'', ''org_only'', ''approved_only'') DEFAULT ''none''', 
    'SELECT ''call_restriction already exists''');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- グループ作成許可
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members' AND COLUMN_NAME = 'can_create_groups');
SET @query = IF(@col_exists = 0, 
    'ALTER TABLE organization_members ADD COLUMN can_create_groups TINYINT(1) DEFAULT 0', 
    'SELECT ''can_create_groups already exists''');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 組織退出許可
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members' AND COLUMN_NAME = 'can_leave_org');
SET @query = IF(@col_exists = 0, 
    'ALTER TABLE organization_members ADD COLUMN can_leave_org TINYINT(1) DEFAULT 0', 
    'SELECT ''can_leave_org already exists''');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. users テーブルに is_minor カラムを追加（存在しない場合）
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_minor');
SET @query = IF(@col_exists = 0, 
    'ALTER TABLE users ADD COLUMN is_minor TINYINT(1) DEFAULT 0 COMMENT ''未成年フラグ''', 
    'SELECT ''is_minor already exists''');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- 完了メッセージ
-- ============================================
SELECT 'Migration completed successfully!' AS message;


