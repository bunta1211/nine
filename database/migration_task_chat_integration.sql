-- タスクとチャット連携のためのマイグレーション
-- 2026-02-02

-- =====================================================
-- 1. messagesテーブルにtask_idカラムを追加
-- タスク関連メッセージ（依頼・完了通知等）をチャットに表示するため
-- =====================================================

ALTER TABLE messages 
ADD COLUMN IF NOT EXISTS task_id INT DEFAULT NULL COMMENT 'タスクID（タスク関連メッセージ用）' AFTER mentions;

ALTER TABLE messages 
ADD INDEX IF NOT EXISTS idx_messages_task_id (task_id);

-- =====================================================
-- 2. tasksテーブルにnotification_message_idを追加
-- タスク作成時にチャットに投稿されたメッセージIDを記録
-- =====================================================

ALTER TABLE tasks 
ADD COLUMN IF NOT EXISTS notification_message_id INT DEFAULT NULL COMMENT 'チャット通知メッセージID' AFTER conversation_id;

-- =====================================================
-- 3. message_typeにtaskを追加（VARCHAR型の場合は不要）
-- ENUMの場合のみ実行
-- =====================================================

-- message_typeがVARCHAR(20)の場合、以下は実行不要
-- ENUMの場合は手動で以下を実行:
-- ALTER TABLE messages MODIFY COLUMN message_type ENUM('text', 'image', 'file', 'audio', 'video', 'system', 'task') DEFAULT 'text';

-- =====================================================
-- 確認クエリ
-- =====================================================
-- SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS 
-- WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'message_type';
