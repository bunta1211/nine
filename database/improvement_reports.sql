-- 改善提案・デバッグログ用テーブル（汎用デバッグフロー）
-- 実行前に左で「social9」を選択してから実行すること
-- phpMyAdmin: データベース social9 を選択 → SQL タブで実行
-- 本番 RDS の users.id が INT（符号付き）の場合があるため、user_id は INT NULL とし、
-- 外部キーは付けない（型不一致 ERROR 3780 を避ける）。参照整合性はアプリ側で担保。

USE social9;

CREATE TABLE IF NOT EXISTS improvement_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL COMMENT '報告者（NULL=管理者手動作成）。users.id への論理参照',
    title VARCHAR(255) NOT NULL,
    problem_summary TEXT NOT NULL,
    suspected_location TEXT NULL COMMENT '想定される原因・場所（ファイル名・処理名など）',
    suggested_fix TEXT NULL COMMENT '望ましい対応・修正方針',
    related_files VARCHAR(500) NULL COMMENT '関連しそうなファイル（カンマ区切りまたはJSON）',
    ui_location VARCHAR(255) NULL COMMENT '問題の場所（上パネル／左／中央／右、携帯の場合はそれに沿った表現）',
    status ENUM('pending','done','cancelled') NOT NULL DEFAULT 'pending',
    source VARCHAR(50) NOT NULL DEFAULT 'manual' COMMENT 'ai_chat / manual',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_source (source),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='改善・デバッグ提案（管理者がCursor用にコピーして開発に利用）';
