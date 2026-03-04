-- ===========================================
-- Wishパターン提案テーブル
-- ユーザーからの提案を収集し、人気パターンを自動検出
-- ===========================================

CREATE TABLE IF NOT EXISTS wish_pattern_suggestions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_text VARCHAR(500) NOT NULL COMMENT '元のメッセージ全文',
    extracted_wish VARCHAR(200) NOT NULL COMMENT '抽出されたWish（ユーザーが確認）',
    suggested_pattern VARCHAR(300) DEFAULT NULL COMMENT '提案パターン（自動生成）',
    suggested_category VARCHAR(50) DEFAULT 'other' COMMENT 'カテゴリ',
    user_id INT UNSIGNED NOT NULL COMMENT '提案者',
    suggestion_count INT DEFAULT 1 COMMENT '同じパターンの提案回数',
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending' COMMENT 'ステータス',
    approved_by INT UNSIGNED DEFAULT NULL COMMENT '承認者',
    approved_at DATETIME DEFAULT NULL COMMENT '承認日時',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_status (status),
    INDEX idx_user (user_id),
    INDEX idx_count (suggestion_count DESC),
    INDEX idx_pattern (suggested_pattern(100)),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 同じWish表現のカウント用ビュー（オプション）
-- CREATE VIEW wish_suggestion_stats AS
-- SELECT 
--     extracted_wish,
--     COUNT(*) as total_count,
--     COUNT(DISTINCT user_id) as unique_users,
--     MAX(created_at) as last_suggested
-- FROM wish_pattern_suggestions
-- WHERE status = 'pending'
-- GROUP BY extracted_wish
-- HAVING total_count >= 3
-- ORDER BY total_count DESC;


