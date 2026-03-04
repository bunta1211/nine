-- =====================================================
-- タスク・メモ統合マイグレーション
-- tasks テーブルに type カラム等を追加し、memos データを移行する
-- =====================================================

-- 1. tasks テーブルにメモ用カラムを追加（カラムが既にある場合はエラーになるが無視してよい）
ALTER TABLE tasks
  ADD COLUMN type ENUM('task','memo') NOT NULL DEFAULT 'task' AFTER id,
  ADD COLUMN content TEXT DEFAULT NULL AFTER description,
  ADD COLUMN color VARCHAR(20) DEFAULT NULL AFTER content,
  ADD COLUMN message_id INT UNSIGNED DEFAULT NULL AFTER conversation_id,
  ADD COLUMN is_pinned TINYINT(1) DEFAULT 0 AFTER is_shared;

-- 2. インデックス追加（IF NOT EXISTS はインデックスには使えないため、エラーは無視してよい）
-- ALTER TABLE tasks ADD INDEX idx_type (type);
-- ALTER TABLE tasks ADD INDEX idx_pinned (is_pinned);
-- ALTER TABLE tasks ADD INDEX idx_message_id (message_id);

-- 3. memos テーブルのデータを tasks に移行（deleted_at がない場合を考慮）
INSERT INTO tasks (type, title, description, content, color, created_by, conversation_id, message_id, is_pinned, status, priority, created_at, updated_at)
SELECT 'memo', title, NULL, content, color, created_by, conversation_id, message_id, is_pinned, 'pending', 0, created_at, updated_at
FROM memos
WHERE NOT EXISTS (
    SELECT 1 FROM tasks t2 WHERE t2.type = 'memo' AND t2.title = memos.title AND t2.created_by = memos.created_by AND t2.created_at = memos.created_at
);

-- memos テーブルは削除しない（バックアップとして残す）
