-- ============================================
-- Guild アプリケーション データベーススキーマ
-- 作成日: 2026-01-20
-- ============================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ============================================
-- ギルド関連テーブル
-- ============================================

-- ギルドマスター
CREATE TABLE IF NOT EXISTS guild_guilds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    name_en VARCHAR(100) DEFAULT NULL,
    name_zh VARCHAR(100) DEFAULT NULL,
    description TEXT,
    logo_path VARCHAR(255) DEFAULT NULL,
    annual_budget INT DEFAULT 0 COMMENT '年度予算（Earth）',
    remaining_budget INT DEFAULT 0 COMMENT '残り予算（Earth）',
    fiscal_year INT NOT NULL COMMENT '年度（例：2026）',
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fiscal_year (fiscal_year),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ギルドメンバーシップ
CREATE TABLE IF NOT EXISTS guild_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guild_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('leader', 'sub_leader', 'coordinator', 'member') NOT NULL DEFAULT 'member',
    can_issue_requests TINYINT(1) DEFAULT 0 COMMENT 'リーダーから付与された依頼発行権限',
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    left_at DATETIME DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (guild_id) REFERENCES guild_guilds(id) ON DELETE CASCADE,
    UNIQUE KEY uk_guild_user (guild_id, user_id),
    INDEX idx_user_id (user_id),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ユーザー拡張情報（Social9のusersテーブルを拡張）
-- ============================================

-- ユーザー追加情報
CREATE TABLE IF NOT EXISTS guild_user_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    hire_date DATE DEFAULT NULL COMMENT '入社日',
    years_of_service INT DEFAULT 0 COMMENT '勤続年数（自動計算用キャッシュ）',
    qualifications TEXT COMMENT '保有資格（JSON配列）',
    skills TEXT COMMENT '技能・スキル（JSON配列）',
    teachable_lessons TEXT COMMENT '講師可能レッスン',
    
    -- 余力表示設定
    availability_today ENUM('available', 'limited', 'unavailable') DEFAULT 'available' COMMENT '本日の状態',
    availability_today_percent INT DEFAULT 100 COMMENT '本日の余力（0-100%）',
    availability_week ENUM('available', 'limited', 'unavailable') DEFAULT 'available' COMMENT '今週の状態',
    availability_week_percent INT DEFAULT 100,
    availability_month ENUM('available', 'limited', 'unavailable') DEFAULT 'available' COMMENT '今月の状態',
    availability_month_percent INT DEFAULT 100,
    availability_next ENUM('available', 'limited', 'unavailable') DEFAULT 'available' COMMENT '来月以降の状態',
    availability_next_percent INT DEFAULT 100,
    unavailable_until DATE DEFAULT NULL COMMENT '新規依頼不可期限',
    
    -- 通知設定
    notify_new_request TINYINT(1) DEFAULT 1,
    notify_assigned TINYINT(1) DEFAULT 1,
    notify_approved TINYINT(1) DEFAULT 1,
    notify_earth_received TINYINT(1) DEFAULT 1,
    notify_thanks TINYINT(1) DEFAULT 1,
    notify_advance_payment TINYINT(1) DEFAULT 1,
    email_notifications TINYINT(1) DEFAULT 1,
    
    -- 表示設定
    language VARCHAR(5) DEFAULT 'ja' COMMENT 'ja, en, zh',
    dark_mode TINYINT(1) DEFAULT 0,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Earth（報酬）管理
-- ============================================

-- ユーザーEarth残高
CREATE TABLE IF NOT EXISTS guild_earth_balances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    fiscal_year INT NOT NULL,
    total_earned INT DEFAULT 0 COMMENT '獲得総額',
    total_spent INT DEFAULT 0 COMMENT '使用総額',
    total_paid INT DEFAULT 0 COMMENT '支払い済み総額',
    current_balance INT DEFAULT 0 COMMENT '現在残高（未支払い分）',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_year (user_id, fiscal_year),
    INDEX idx_fiscal_year (fiscal_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Earth取引履歴
CREATE TABLE IF NOT EXISTS guild_earth_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fiscal_year INT NOT NULL,
    from_user_id INT DEFAULT NULL COMMENT '送付者（システム配布の場合はNULL）',
    to_user_id INT NOT NULL COMMENT '受取者',
    from_guild_id INT DEFAULT NULL COMMENT 'ギルド予算から（個人間の場合はNULL）',
    amount INT NOT NULL,
    transaction_type ENUM(
        'annual_distribution',    -- 年度初め配布
        'tenure_bonus',           -- 在籍年数ボーナス
        'role_bonus',             -- 役職ボーナス
        'request_reward',         -- 依頼報酬
        'personal_request',       -- 個人依頼
        'thanks',                 -- 感謝の気持ち
        'special_reward',         -- 特別報酬
        'shift_swap',             -- 勤務交代
        'refund',                 -- 返金
        'settlement'              -- 年度末精算
    ) NOT NULL,
    request_id INT DEFAULT NULL COMMENT '関連する依頼ID',
    is_anonymous TINYINT(1) DEFAULT 0 COMMENT '匿名送付',
    message TEXT COMMENT '感謝メッセージ等',
    status ENUM('pending', 'completed', 'cancelled') DEFAULT 'completed',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_to_user (to_user_id),
    INDEX idx_from_user (from_user_id),
    INDEX idx_fiscal_year (fiscal_year),
    INDEX idx_type (transaction_type),
    INDEX idx_request_id (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 支払い履歴
CREATE TABLE IF NOT EXISTS guild_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    fiscal_year INT NOT NULL,
    amount INT NOT NULL COMMENT '支払い金額（Earth）',
    amount_yen INT NOT NULL COMMENT '支払い金額（円）',
    payment_type ENUM('regular', 'advance') NOT NULL COMMENT '定期/前借り',
    payment_period VARCHAR(20) COMMENT '対象期間（例：2026-04-06）',
    scheduled_date DATE NOT NULL COMMENT '支払予定日',
    paid_at DATETIME DEFAULT NULL COMMENT '支払い完了日時',
    paid_by INT DEFAULT NULL COMMENT '処理者',
    status ENUM('pending', 'approved', 'paid', 'cancelled') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_scheduled_date (scheduled_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 前借り申請
CREATE TABLE IF NOT EXISTS guild_advance_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    fiscal_year INT NOT NULL,
    requested_amount INT NOT NULL COMMENT '申請金額（Earth）',
    current_balance INT NOT NULL COMMENT '申請時の残高',
    max_allowed INT NOT NULL COMMENT '前借り可能上限（残高の80%）',
    reason TEXT,
    status ENUM('pending', 'approved', 'rejected', 'paid') DEFAULT 'pending',
    processed_by INT DEFAULT NULL,
    processed_at DATETIME DEFAULT NULL,
    rejection_reason TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 依頼システム
-- ============================================

-- 依頼テンプレート
CREATE TABLE IF NOT EXISTS guild_request_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    request_type ENUM(
        'public',           -- 公開依頼
        'designated',       -- 指名依頼
        'order',            -- 業務指令
        'shift_swap',       -- 勤務交代依頼
        'personal',         -- 個人依頼
        'thanks',           -- 感謝の気持ち
        'special_reward'    -- 特別報酬
    ) NOT NULL,
    default_earth INT DEFAULT 0,
    required_qualifications TEXT COMMENT '受注資格（JSON）',
    default_duration VARCHAR(50) COMMENT 'デフォルト期間',
    created_by INT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type (request_type),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 依頼
CREATE TABLE IF NOT EXISTS guild_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guild_id INT DEFAULT NULL COMMENT 'ギルド（個人依頼の場合はNULL）',
    requester_id INT NOT NULL COMMENT '依頼者',
    template_id INT DEFAULT NULL,
    
    title VARCHAR(200) NOT NULL,
    description TEXT,
    request_type ENUM(
        'public',
        'designated',
        'order',
        'shift_swap',
        'personal',
        'thanks',
        'special_reward'
    ) NOT NULL,
    
    earth_amount INT NOT NULL COMMENT '報酬Earth額',
    earth_source ENUM('guild', 'personal') NOT NULL COMMENT 'Earth出所',
    distribution_timing ENUM('on_accept', 'on_date', 'on_complete') DEFAULT 'on_complete' COMMENT '分配タイミング',
    distribution_date DATE DEFAULT NULL COMMENT '期日選択の場合の日付',
    
    required_qualifications TEXT COMMENT '受注資格',
    max_applicants INT DEFAULT 1 COMMENT '募集人数（0=無制限）',
    
    deadline DATE DEFAULT NULL COMMENT '依頼期限',
    work_start_date DATE DEFAULT NULL,
    work_end_date DATE DEFAULT NULL,
    
    -- 勤務交代専用
    shift_date DATE DEFAULT NULL COMMENT '交代対象日',
    shift_time VARCHAR(50) DEFAULT NULL COMMENT '勤務時間帯',
    on_not_found ENUM('cancel', 'extend') DEFAULT 'cancel' COMMENT '見つからない場合',
    
    status ENUM(
        'draft',
        'pending_approval',  -- 1万Earth以上の場合
        'open',
        'in_progress',
        'pending_complete',
        'completed',
        'cancelled',
        'expired'
    ) DEFAULT 'open',
    
    requires_approval TINYINT(1) DEFAULT 0 COMMENT '1万Earth以上フラグ',
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    
    related_request_id INT DEFAULT NULL COMMENT '関連依頼（特別報酬の場合）',
    
    is_carryover TINYINT(1) DEFAULT 0 COMMENT '年度繰越フラグ',
    fiscal_year INT NOT NULL,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_guild_id (guild_id),
    INDEX idx_requester_id (requester_id),
    INDEX idx_status (status),
    INDEX idx_type (request_type),
    INDEX idx_fiscal_year (fiscal_year),
    INDEX idx_deadline (deadline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 依頼対象者（指名依頼・業務指令用）
CREATE TABLE IF NOT EXISTS guild_request_targets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    user_id INT NOT NULL,
    earth_amount INT DEFAULT NULL COMMENT '個別Earth設定（複数人の場合）',
    notified_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES guild_requests(id) ON DELETE CASCADE,
    UNIQUE KEY uk_request_user (request_id, user_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 依頼への応募・立候補
CREATE TABLE IF NOT EXISTS guild_request_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    user_id INT NOT NULL,
    comment TEXT COMMENT '立候補時のコメント',
    status ENUM('pending', 'accepted', 'rejected', 'withdrawn') DEFAULT 'pending',
    accepted_at DATETIME DEFAULT NULL,
    rejected_at DATETIME DEFAULT NULL,
    rejection_reason TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES guild_requests(id) ON DELETE CASCADE,
    UNIQUE KEY uk_request_user (request_id, user_id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 依頼担当者（受注確定）
CREATE TABLE IF NOT EXISTS guild_request_assignees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    user_id INT NOT NULL,
    earth_amount INT NOT NULL COMMENT '個別報酬額',
    status ENUM('assigned', 'in_progress', 'completed', 'cancelled') DEFAULT 'assigned',
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    completion_report TEXT COMMENT '完了報告',
    approved_by INT DEFAULT NULL COMMENT '完了承認者',
    approved_at DATETIME DEFAULT NULL,
    earth_paid TINYINT(1) DEFAULT 0 COMMENT 'Earth支払い済み',
    earth_paid_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES guild_requests(id) ON DELETE CASCADE,
    UNIQUE KEY uk_request_user (request_id, user_id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 依頼編集履歴
CREATE TABLE IF NOT EXISTS guild_request_edits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    edited_by INT NOT NULL,
    changes TEXT COMMENT '変更内容（JSON）',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES guild_requests(id) ON DELETE CASCADE,
    INDEX idx_request_id (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- カレンダー機能
-- ============================================

-- 勤務カレンダー
CREATE TABLE IF NOT EXISTS guild_calendar_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    entry_date DATE NOT NULL,
    entry_type ENUM('work', 'holiday', 'paid_leave', 'other') NOT NULL,
    work_location VARCHAR(100) DEFAULT NULL COMMENT '勤務場所',
    start_time TIME DEFAULT NULL,
    end_time TIME DEFAULT NULL,
    note TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_date (user_id, entry_date),
    INDEX idx_entry_date (entry_date),
    INDEX idx_entry_type (entry_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- カレンダー閲覧権限
CREATE TABLE IF NOT EXISTS guild_calendar_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    viewer_user_id INT NOT NULL COMMENT '閲覧者',
    target_user_id INT DEFAULT NULL COMMENT '対象者（NULLは全員）',
    target_guild_id INT DEFAULT NULL COMMENT '対象ギルド',
    permission_type ENUM('view', 'edit') DEFAULT 'view',
    granted_by INT NOT NULL,
    expires_at DATE DEFAULT NULL COMMENT '許可期限',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_viewer (viewer_user_id),
    INDEX idx_target_user (target_user_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 権限管理
-- ============================================

-- システム権限
CREATE TABLE IF NOT EXISTS guild_system_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    is_system_admin TINYINT(1) DEFAULT 0 COMMENT 'システム管理者',
    is_payroll_admin TINYINT(1) DEFAULT 0 COMMENT '給与支払い担当',
    can_manage_users TINYINT(1) DEFAULT 0,
    can_manage_guilds TINYINT(1) DEFAULT 0,
    can_approve_large_requests TINYINT(1) DEFAULT 0 COMMENT '1万Earth以上承認',
    can_approve_advances TINYINT(1) DEFAULT 0 COMMENT '前借り承認',
    can_view_all_data TINYINT(1) DEFAULT 0,
    can_export_data TINYINT(1) DEFAULT 0,
    can_manage_fiscal_year TINYINT(1) DEFAULT 0 COMMENT '年度管理',
    can_register_qualifications TINYINT(1) DEFAULT 0 COMMENT '資格登録',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 他ギルド閲覧許可
CREATE TABLE IF NOT EXISTS guild_cross_view_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    guild_id INT NOT NULL,
    granted_by INT NOT NULL,
    expires_at DATE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_guild (user_id, guild_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 通知システム
-- ============================================

-- 通知
CREATE TABLE IF NOT EXISTS guild_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    notification_type ENUM(
        'new_request',
        'designated_request',
        'order_request',
        'application_approved',
        'application_rejected',
        'request_edited',
        'earth_received',
        'thanks_received',
        'advance_approved',
        'advance_rejected',
        'approval_required',
        'shift_swap_approved',
        'request_completed',
        'system'
    ) NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT,
    related_id INT DEFAULT NULL COMMENT '関連ID（依頼ID等）',
    related_type VARCHAR(50) DEFAULT NULL COMMENT '関連タイプ',
    is_read TINYINT(1) DEFAULT 0,
    read_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- メール送信キュー
CREATE TABLE IF NOT EXISTS guild_email_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    email_type VARCHAR(50) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    scheduled_at DATETIME NOT NULL COMMENT '送信予定時刻（18時）',
    sent_at DATETIME DEFAULT NULL,
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_scheduled (scheduled_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 年度管理
-- ============================================

-- 年度設定
CREATE TABLE IF NOT EXISTS guild_fiscal_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fiscal_year INT NOT NULL UNIQUE,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    settlement_date DATE NOT NULL COMMENT '最終決済日（3月10日）',
    freeze_start DATE NOT NULL COMMENT '依頼停止開始日（3月11日）',
    freeze_end DATE NOT NULL COMMENT '依頼停止終了日（3月31日）',
    total_budget INT DEFAULT 0 COMMENT '年度総予算',
    distributed_budget INT DEFAULT 0 COMMENT '配布済み予算',
    status ENUM('preparing', 'active', 'frozen', 'settled', 'closed') DEFAULT 'preparing',
    opened_by INT DEFAULT NULL,
    opened_at DATETIME DEFAULT NULL,
    closed_by INT DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 年度初め配布設定
CREATE TABLE IF NOT EXISTS guild_annual_distributions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fiscal_year INT NOT NULL,
    user_id INT NOT NULL,
    tenure_years INT NOT NULL COMMENT '勤続年数',
    tenure_earth INT NOT NULL COMMENT '勤続年数ボーナス',
    role_earth INT DEFAULT 0 COMMENT '役職ボーナス',
    other_earth INT DEFAULT 0 COMMENT 'その他',
    total_earth INT NOT NULL COMMENT '合計',
    note TEXT,
    status ENUM('draft', 'approved', 'distributed') DEFAULT 'draft',
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    distributed_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_year_user (fiscal_year, user_id),
    INDEX idx_fiscal_year (fiscal_year),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ログ・監査
-- ============================================

-- 活動ログ
CREATE TABLE IF NOT EXISTS guild_activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action_type VARCHAR(50) NOT NULL,
    target_type VARCHAR(50) DEFAULT NULL,
    target_id INT DEFAULT NULL,
    details TEXT COMMENT 'JSON形式の詳細',
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_action_type (action_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 感謝メッセージログ（評価用）
CREATE TABLE IF NOT EXISTS guild_thanks_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_user_id INT NOT NULL,
    to_user_id INT NOT NULL,
    request_id INT DEFAULT NULL,
    transaction_id INT DEFAULT NULL,
    message TEXT,
    earth_amount INT DEFAULT 0,
    is_anonymous TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_to_user (to_user_id),
    INDEX idx_from_user (from_user_id),
    INDEX idx_request_id (request_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 初期データ
-- ============================================

-- 2026年度の初期設定
INSERT INTO guild_fiscal_years (fiscal_year, start_date, end_date, settlement_date, freeze_start, freeze_end, status)
VALUES (2026, '2026-04-01', '2027-03-31', '2027-03-10', '2027-03-11', '2027-03-31', 'preparing')
ON DUPLICATE KEY UPDATE fiscal_year = fiscal_year;
