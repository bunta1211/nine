-- メモページ「金庫」機能用テーブル
-- 計画書: DOCS/MEMO_VAULT_WEBAUTHN_PLAN.md
-- 顔認証・指紋認証（WebAuthn）で入室し、パスワード・メモ・ファイルを暗号化保管する

-- =====================================================
-- 金庫セッション（開いている状態の管理）
-- =====================================================
CREATE TABLE IF NOT EXISTS vault_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL COMMENT '金庫アクセス用トークン',
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_vault_token (token),
    INDEX idx_user_expires (user_id, expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 金庫アイテム（パスワード・メモ・ファイル）
-- =====================================================
CREATE TABLE IF NOT EXISTS vault_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('password', 'note', 'file') NOT NULL COMMENT '種別',
    title VARCHAR(255) NOT NULL,
    encrypted_payload LONGTEXT NOT NULL COMMENT 'AES暗号化済み本文',
    encryption_iv VARCHAR(32) NOT NULL COMMENT 'IV（hex）',
    file_name VARCHAR(255) DEFAULT NULL COMMENT 'type=file 時の元ファイル名',
    file_size INT UNSIGNED DEFAULT NULL COMMENT 'type=file 時のサイズ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_type (user_id, type),
    INDEX idx_user_updated (user_id, updated_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- WebAuthn 認証子（顔・指紋登録情報）
-- =====================================================
-- WebAuthn の credentialId は長さ可変のため VARBINARY で保存
CREATE TABLE IF NOT EXISTS webauthn_credentials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    credential_id VARBINARY(1024) NOT NULL COMMENT 'credentialId（バイナリ）',
    public_key BLOB NOT NULL COMMENT '公開鍵（バイナリ）',
    sign_count INT UNSIGNED DEFAULT 0,
    device_name VARCHAR(100) DEFAULT NULL COMMENT '端末名（任意）',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_credential_id (credential_id(255)),
    INDEX idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
