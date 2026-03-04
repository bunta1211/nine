-- ============================================================
-- 共有フォルダ（Shared Folder）マイグレーション
-- 実行日: 2026-02-27
-- ============================================================

-- 1. 料金プラン定義（Dropbox準拠）
CREATE TABLE IF NOT EXISTS storage_plans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    quota_bytes BIGINT UNSIGNED NOT NULL,
    monthly_price INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '円単位、0=無料',
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO storage_plans (name, quota_bytes, monthly_price, description) VALUES
('free',     2147483648,        0, '無料プラン（2GB）'),
('plus',     2199023255552,  1500, 'Plusプラン（2TB / 月額1,500円）'),
('business', 5497558138880,  2400, 'Businessプラン（5TB / 月額2,400円）');

-- 2. 契約管理（法人/個人）
CREATE TABLE IF NOT EXISTS storage_subscriptions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('organization','user') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('active','cancelled','past_due') NOT NULL DEFAULT 'active',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL COMMENT 'ダウングレード猶予期限など',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_entity (entity_type, entity_id),
    CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES storage_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. フォルダ管理（複数階層対応）
CREATE TABLE IF NOT EXISTS storage_folders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    parent_id INT UNSIGNED NULL COMMENT 'NULLならルート直下',
    name VARCHAR(255) NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_conv_parent (conversation_id, parent_id),
    CONSTRAINT fk_folder_parent FOREIGN KEY (parent_id) REFERENCES storage_folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. ファイル管理
CREATE TABLE IF NOT EXISTS storage_files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    folder_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(500) NOT NULL,
    s3_key VARCHAR(1000) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
    thumbnail_s3_key VARCHAR(1000) NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    status ENUM('pending','active','deleted') NOT NULL DEFAULT 'pending' COMMENT 'pending=アップロード中, active=正常, deleted=ゴミ箱',
    deleted_at DATETIME NULL COMMENT 'ゴミ箱移動日時',
    deleted_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_folder_status (folder_id, status),
    INDEX idx_status_deleted (status, deleted_at),
    INDEX idx_uploaded_by (uploaded_by),
    CONSTRAINT fk_file_folder FOREIGN KEY (folder_id) REFERENCES storage_folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. フォルダ共有
CREATE TABLE IF NOT EXISTS storage_folder_shares (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    folder_id INT UNSIGNED NOT NULL,
    shared_with_conversation_id INT UNSIGNED NOT NULL,
    permission ENUM('read','readwrite') NOT NULL DEFAULT 'read',
    shared_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_share (folder_id, shared_with_conversation_id),
    CONSTRAINT fk_share_folder FOREIGN KEY (folder_id) REFERENCES storage_folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. 請求履歴
CREATE TABLE IF NOT EXISTS storage_billing_records (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT UNSIGNED NOT NULL,
    billing_month VARCHAR(7) NOT NULL COMMENT '2026-03 形式',
    amount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '円',
    status ENUM('pending','billed','paid','failed') NOT NULL DEFAULT 'pending',
    zengin_exported_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_billing (subscription_id, billing_month),
    CONSTRAINT fk_billing_sub FOREIGN KEY (subscription_id) REFERENCES storage_subscriptions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. 容量通知履歴
CREATE TABLE IF NOT EXISTS storage_usage_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('organization','user') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    quota_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    notification_type ENUM('80_percent','90_percent','exceeded','downgrade_warning','downgrade_deleted') NOT NULL,
    notified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entity_type (entity_type, entity_id, notification_type, notified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. メンバー権限（組織グループ用）
CREATE TABLE IF NOT EXISTS storage_member_permissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    can_create_folder TINYINT(1) NOT NULL DEFAULT 1,
    can_delete_folder TINYINT(1) NOT NULL DEFAULT 0,
    can_upload TINYINT(1) NOT NULL DEFAULT 1,
    can_delete_file TINYINT(1) NOT NULL DEFAULT 0,
    updated_by INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_perm (conversation_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. 口座振替用の銀行口座情報
CREATE TABLE IF NOT EXISTS storage_bank_accounts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('organization','user') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    bank_code CHAR(4) NOT NULL COMMENT '金融機関コード',
    bank_name_kana VARCHAR(15) NOT NULL COMMENT '金融機関名（カナ）',
    branch_code CHAR(3) NOT NULL COMMENT '支店コード',
    branch_name_kana VARCHAR(15) NOT NULL COMMENT '支店名（カナ）',
    account_type TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1:普通, 2:当座',
    account_number CHAR(7) NOT NULL COMMENT '口座番号',
    account_holder_kana VARCHAR(30) NOT NULL COMMENT '口座名義（カナ）',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bank_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
