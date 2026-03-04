-- グループ管理者機能用マイグレーション
-- 実行日: 2026-01-04

-- 1. conversation_members に is_silenced カラムを追加（発言制限用）
ALTER TABLE conversation_members 
ADD COLUMN IF NOT EXISTS is_silenced TINYINT(1) DEFAULT 0 COMMENT 'グループ管理者による発言制限' AFTER is_muted;

-- 2. conversations に invite_code カラムを追加（招待リンク用）
ALTER TABLE conversations 
ADD COLUMN IF NOT EXISTS invite_code VARCHAR(64) DEFAULT NULL COMMENT 'グループ招待コード' AFTER icon_path;

-- 3. インデックスを追加（招待コード検索用）
CREATE INDEX IF NOT EXISTS idx_conversations_invite_code ON conversations(invite_code);


