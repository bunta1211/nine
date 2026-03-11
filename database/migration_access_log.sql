-- ============================================
-- アクセスログ（管理ダッシュボード用）
-- 本日のアクセス・検索経由・離脱率の集計に利用
-- ============================================

CREATE TABLE IF NOT EXISTS access_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    visitor_key VARCHAR(64) NOT NULL COMMENT 'session_id または IP+UA のハッシュ',
    path VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'リクエストパス例: /index.php, /chat.php',
    referer_host VARCHAR(255) NULL COMMENT 'Referer のホスト部分（検索経由・同ドメイン判定用）',
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    INDEX idx_created_at (created_at),
    INDEX idx_visitor_created (visitor_key, created_at),
    INDEX idx_referer_created (referer_host(64), created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='ページアクセスログ（index/chat 等。本日アクセス・検索経由・離脱率集計用）';
