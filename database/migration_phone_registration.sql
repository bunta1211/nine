-- 携帯電話番号での登録・SMS認証対応
-- 1) users.email を NULL 許容（電話のみ登録ユーザー用）
-- 2) users.phone に UNIQUE 制約（NULL 以外で一意）
-- 3) users.birth_date を NULL 許容（電話のみ登録で未入力可）
-- 4) sms_verification_codes テーブル作成

-- users テーブル変更（既存データはそのまま）
ALTER TABLE users MODIFY COLUMN email VARCHAR(255) NULL COMMENT 'メールアドレス（電話のみ登録の場合はNULL）';
ALTER TABLE users MODIFY COLUMN birth_date DATE NULL COMMENT '生年月日';

-- phone に UNIQUE 追加（重複がある場合は事前に解消すること）
-- MySQL 8.0.13+: ADD CONSTRAINT で UNIQUE 追加。既存の idx_phone がある場合は先に確認
ALTER TABLE users ADD UNIQUE KEY uk_phone (phone);

-- SMS 認証コード用テーブル（email_verification_codes と同様の構造）
CREATE TABLE IF NOT EXISTS sms_verification_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) NOT NULL COMMENT '正規化済み電話番号（数字のみ）',
    code VARCHAR(255) NOT NULL COMMENT '認証コード（平文またはハッシュ）',
    expires_at DATETIME NOT NULL,
    is_new_user TINYINT(1) DEFAULT 0,
    attempts INT DEFAULT 0 COMMENT '試行回数',
    verified_at DATETIME NULL COMMENT '検証完了日時',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_phone (phone),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
