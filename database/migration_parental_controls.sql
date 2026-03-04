-- 保護者機能マイグレーション
-- B+C ハイブリッドモデル
-- 2026-01-21

-- ============================================
-- parent_child_links テーブル
-- 保護者と子のアカウントを紐付けるテーブル
-- ============================================

CREATE TABLE IF NOT EXISTS parent_child_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_user_id INT UNSIGNED NOT NULL COMMENT '保護者ユーザーID',
    child_user_id INT UNSIGNED NOT NULL COMMENT '子ユーザーID',
    status ENUM('pending', 'approved', 'rejected', 'revoked') DEFAULT 'pending' COMMENT 'リンク状態',
    requested_by ENUM('parent', 'child') DEFAULT 'child' COMMENT '誰が申請したか',
    request_token VARCHAR(64) NULL COMMENT '承認用トークン',
    token_expires_at DATETIME NULL COMMENT 'トークン有効期限',
    approved_at DATETIME NULL COMMENT '承認日時',
    revoked_at DATETIME NULL COMMENT '解除日時',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_parent_child (parent_user_id, child_user_id),
    INDEX idx_parent (parent_user_id),
    INDEX idx_child (child_user_id),
    INDEX idx_status (status),
    INDEX idx_token (request_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='保護者-子アカウント紐付け';

-- ============================================
-- parental_restrictions テーブル
-- 保護者が設定する子への制限
-- ============================================

CREATE TABLE IF NOT EXISTS parental_restrictions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    child_user_id INT UNSIGNED NOT NULL UNIQUE COMMENT '子ユーザーID',
    parent_user_id INT UNSIGNED NOT NULL COMMENT '制限を設定した保護者ID',
    
    -- 利用時間制限
    daily_usage_limit_minutes INT NULL COMMENT '1日の利用上限（分）、NULLは無制限',
    usage_start_time TIME NULL COMMENT '利用可能開始時間',
    usage_end_time TIME NULL COMMENT '利用可能終了時間',
    
    -- 曜日別制限（JSON: {"mon": true, "tue": true, ...}）
    allowed_days JSON NULL COMMENT '利用可能曜日',
    
    -- 検索・コミュニケーション制限
    search_restricted TINYINT(1) DEFAULT 0 COMMENT '検索を制限（グループメンバーのみ）',
    dm_restricted TINYINT(1) DEFAULT 0 COMMENT 'DMを制限（保護者承認が必要）',
    group_join_restricted TINYINT(1) DEFAULT 0 COMMENT 'グループ参加を制限',
    call_restricted TINYINT(1) DEFAULT 0 COMMENT '通話を制限',
    
    -- コンテンツ制限
    file_upload_restricted TINYINT(1) DEFAULT 0 COMMENT 'ファイル送信を制限',
    
    -- 通知設定
    notify_parent_on_dm TINYINT(1) DEFAULT 0 COMMENT 'DM時に保護者へ通知',
    notify_parent_on_group_join TINYINT(1) DEFAULT 0 COMMENT 'グループ参加時に保護者へ通知',
    
    is_active TINYINT(1) DEFAULT 1 COMMENT '制限が有効か',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_child (child_user_id),
    INDEX idx_parent (parent_user_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='保護者による制限設定';

-- ============================================
-- usage_time_logs テーブル
-- 子の利用時間を記録
-- ============================================

CREATE TABLE IF NOT EXISTS usage_time_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL COMMENT 'ユーザーID',
    log_date DATE NOT NULL COMMENT '記録日',
    total_minutes INT DEFAULT 0 COMMENT '合計利用時間（分）',
    last_activity_at DATETIME NULL COMMENT '最終アクティビティ時刻',
    session_count INT DEFAULT 0 COMMENT 'セッション数',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_user_date (user_id, log_date),
    INDEX idx_user (user_id),
    INDEX idx_date (log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='利用時間ログ';

-- ============================================
-- parental_approval_requests テーブル
-- 保護者の承認が必要なアクションの申請
-- ============================================

CREATE TABLE IF NOT EXISTS parental_approval_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    child_user_id INT UNSIGNED NOT NULL COMMENT '子ユーザーID',
    parent_user_id INT UNSIGNED NOT NULL COMMENT '保護者ユーザーID',
    request_type ENUM('dm', 'group_join', 'friend_add', 'call') NOT NULL COMMENT '申請種類',
    target_id INT UNSIGNED NULL COMMENT '対象ID（会話ID、ユーザーID等）',
    target_name VARCHAR(100) NULL COMMENT '対象名（表示用）',
    status ENUM('pending', 'approved', 'rejected', 'expired') DEFAULT 'pending',
    approval_token VARCHAR(64) NULL COMMENT '承認用トークン',
    message TEXT NULL COMMENT '子からのメッセージ',
    parent_message TEXT NULL COMMENT '保護者からのメッセージ',
    expires_at DATETIME NULL COMMENT '申請有効期限',
    responded_at DATETIME NULL COMMENT '応答日時',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_child (child_user_id),
    INDEX idx_parent (parent_user_id),
    INDEX idx_status (status),
    INDEX idx_token (approval_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='保護者承認待ち申請';

-- ============================================
-- usersテーブルへのカラム追加
-- ============================================

-- 保護者管理フラグを追加（存在しない場合）
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS is_under_parental_control TINYINT(1) DEFAULT 0 COMMENT '保護者管理下';
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS parental_control_type ENUM('none', 'parent_linked', 'org_managed') DEFAULT 'none' COMMENT '保護者管理タイプ';

-- ============================================
-- 組織による子アカウント管理
-- organizations テーブルへのカラム追加
-- ============================================

-- ALTER TABLE organizations ADD COLUMN IF NOT EXISTS can_manage_minors TINYINT(1) DEFAULT 0 COMMENT '未成年管理権限';
-- ALTER TABLE organizations ADD COLUMN IF NOT EXISTS minor_default_restrictions JSON NULL COMMENT 'デフォルト制限設定';

-- ============================================
-- organization_managed_users テーブル
-- 組織が管理する未成年ユーザー
-- ============================================

CREATE TABLE IF NOT EXISTS organization_managed_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT UNSIGNED NOT NULL COMMENT '組織ID',
    user_id INT UNSIGNED NOT NULL COMMENT 'ユーザーID',
    managed_by_user_id INT UNSIGNED NOT NULL COMMENT '管理担当者ID',
    guardian_name VARCHAR(100) NULL COMMENT '保護者名（組織側記録用）',
    guardian_contact VARCHAR(200) NULL COMMENT '保護者連絡先',
    enrollment_date DATE NULL COMMENT '登録日',
    graduation_date DATE NULL COMMENT '卒業予定日',
    notes TEXT NULL COMMENT '備考',
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_org_user (organization_id, user_id),
    INDEX idx_org (organization_id),
    INDEX idx_user (user_id),
    INDEX idx_manager (managed_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='組織管理ユーザー';

-- ============================================
-- 補足
-- ============================================
-- 
-- B+C ハイブリッドモデル:
--   B: 組織（学校/塾）が未成年アカウントを管理
--   C: 子が自分のメールで登録し、保護者と紐付け
-- 
-- フロー:
--   1. 子が設定画面から「保護者と紐付け」を選択
--   2. 保護者のメールアドレスを入力
--   3. 保護者にリンク承認メールが送信される
--   4. 保護者が承認すると紐付け完了
--   5. 保護者は設定画面から制限を設定可能
-- 
-- 制限の種類:
--   - 利用時間制限（1日○時間、○時〜○時のみ）
--   - 検索制限（グループメンバーのみ検索可能）
--   - DM制限（保護者承認が必要）
--   - グループ参加制限（保護者承認が必要）
--   - 通話制限
