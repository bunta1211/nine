-- 着信音設定を user_settings に追加
-- 着信音が保存されない問題の解消用
-- 実行: phpMyAdmin で実行（カラムが既にある場合は Duplicate column エラーになるが無視してよい）

-- user_settings テーブルが存在しない場合は settings.php の ensureUserSettingsForRingtone が自動作成
-- 存在する場合、不足カラムを追加（MySQL 5.7 以降対応）

-- user_settings: 着信音用カラム追加
ALTER TABLE user_settings ADD COLUMN notification_sound VARCHAR(30) DEFAULT 'default' COMMENT '着信音（自分宛メッセージ）';
ALTER TABLE user_settings ADD COLUMN notification_trigger_pc VARCHAR(20) DEFAULT 'to_me' COMMENT '着信音が鳴る条件（PC）';
ALTER TABLE user_settings ADD COLUMN notification_trigger_mobile VARCHAR(20) DEFAULT 'to_me' COMMENT '着信音が鳴る条件（携帯）';
ALTER TABLE user_settings ADD COLUMN notification_preview_duration TINYINT DEFAULT 3 COMMENT '試聴再生時間（1/3/5秒）';
ALTER TABLE user_settings ADD COLUMN ringtone_preview_duration TINYINT DEFAULT 3 COMMENT '通話試聴再生時間（1/3/5秒）';

-- user_call_settings が存在しない場合の作成（通話着信音用）
CREATE TABLE IF NOT EXISTS user_call_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    ringtone VARCHAR(30) DEFAULT 'default',
    camera_default_on TINYINT(1) DEFAULT 1,
    mic_default_on TINYINT(1) DEFAULT 1,
    blur_default_on TINYINT(1) DEFAULT 0,
    noise_cancel TINYINT(1) DEFAULT 1,
    echo_cancel TINYINT(1) DEFAULT 1,
    call_quality VARCHAR(20) DEFAULT 'standard',
    share_audio TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
