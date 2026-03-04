-- タスク・メモ関連テーブル
-- 仕様書: 01_全体設計.md

-- =====================================================
-- タスクテーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL COMMENT 'タイトル',
    description TEXT COMMENT '説明',
    due_date DATE DEFAULT NULL COMMENT '期限日',
    priority TINYINT DEFAULT 0 COMMENT '優先度（0:低, 1:中, 2:高, 3:緊急）',
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending' COMMENT '状態',
    created_by INT NOT NULL COMMENT '作成者',
    assigned_to INT DEFAULT NULL COMMENT '担当者',
    conversation_id INT DEFAULT NULL COMMENT '関連会話ID',
    is_shared TINYINT(1) DEFAULT 0 COMMENT '共有フラグ',
    completed_at DATETIME DEFAULT NULL COMMENT '完了日時',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
    INDEX idx_creator (created_by),
    INDEX idx_assignee (assigned_to),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- メモテーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS memos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL COMMENT 'タイトル',
    content TEXT COMMENT '内容',
    color VARCHAR(20) DEFAULT '#ffffff' COMMENT '背景色',
    created_by INT NOT NULL COMMENT '作成者',
    conversation_id INT DEFAULT NULL COMMENT '関連会話ID',
    is_pinned TINYINT(1) DEFAULT 0 COMMENT 'ピン留め',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
    INDEX idx_creator (created_by),
    INDEX idx_pinned (is_pinned)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;








