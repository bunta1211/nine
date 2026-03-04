-- プライバシー・検索機能 改善マイグレーション
-- 2026-01-21

-- ============================================
-- user_privacy_settings テーブル作成/更新
-- ============================================

-- テーブル作成（存在しない場合）
CREATE TABLE IF NOT EXISTS user_privacy_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    hide_online_status TINYINT(1) DEFAULT 0 COMMENT 'オンライン状態を非公開',
    hide_read_receipts TINYINT(1) DEFAULT 0 COMMENT '既読を非表示',
    profile_visibility ENUM('everyone', 'chatted', 'group_members') DEFAULT 'everyone' COMMENT 'プロフィール公開範囲',
    exclude_from_search TINYINT(1) DEFAULT 1 COMMENT '検索から除外（デフォルトON=非公開）',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_search (exclude_from_search)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='ユーザープライバシー設定';

-- exclude_from_searchカラム追加（存在しない場合）
-- ALTER TABLE user_privacy_settings ADD COLUMN IF NOT EXISTS exclude_from_search TINYINT(1) DEFAULT 1 COMMENT '検索から除外';

-- ============================================
-- blocked_users テーブル確認/作成
-- ============================================

CREATE TABLE IF NOT EXISTS blocked_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'ブロックした側',
    blocked_user_id INT NOT NULL COMMENT 'ブロックされた側',
    reason VARCHAR(200) COMMENT 'ブロック理由',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_block (user_id, blocked_user_id),
    INDEX idx_user (user_id),
    INDEX idx_blocked (blocked_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='ブロックユーザー';

-- ============================================
-- 既存ユーザーのプライバシー設定初期化
-- デフォルト: 検索非公開（exclude_from_search = 1）
-- ============================================

-- 全既存ユーザーに対してプライバシー設定がない場合は追加
INSERT IGNORE INTO user_privacy_settings (user_id, exclude_from_search, created_at, updated_at)
SELECT id, 1, NOW(), NOW() FROM users WHERE status = 'active';

-- ============================================
-- usersテーブル: birth_dateをNULL許可に変更（任意入力対応）
-- ============================================

-- birth_dateカラムをNULL許可に変更
-- ALTER TABLE users MODIFY COLUMN birth_date DATE NULL DEFAULT NULL;

-- ============================================
-- 補足
-- ============================================
-- 
-- 検索ロジック:
--   1. 同じグループのメンバー（DM許可グループ）→ 常に検索可能
--   2. exclude_from_search = 0 のユーザー → 検索可能
--   3. ブロックしているユーザー → 検索から除外
--
-- デフォルト設定:
--   - 初期フェーズ: 新規ユーザーは exclude_from_search = 1（非公開）
--   - 中期フェーズ以降: 新規ユーザーは exclude_from_search = 0（公開）に変更予定
