-- 組織招待候補テーブル（一斉招待用）
-- マスター計画: DOCS/PRIVATE_GROUP_AND_ADDRESS_BOOK_MASTER_PLAN.md 2.1
--
-- 【実行方法】phpMyAdmin の場合:
-- 1. このファイルを開き、下の SQL をコピー
-- 2. phpMyAdmin の「SQL」タブを開く
-- 3. コピーした SQL を貼り付けて「実行」をクリック

CREATE TABLE IF NOT EXISTS org_invite_candidates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL DEFAULT '',
    display_name VARCHAR(100) NOT NULL DEFAULT '',
    invited_at DATETIME DEFAULT NULL,
    status ENUM('pending', 'sent', 'accepted', 'expired') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_org (organization_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
