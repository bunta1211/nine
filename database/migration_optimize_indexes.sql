-- ============================================
-- インデックス最適化マイグレーション
-- 2026-01-04
-- ============================================

-- メッセージの会話+作成日複合インデックス（チャット履歴取得の高速化）
ALTER TABLE messages ADD INDEX IF NOT EXISTS idx_conv_created (conversation_id, created_at DESC);

-- メッセージの送信者インデックス（ユーザーのメッセージ検索用）
ALTER TABLE messages ADD INDEX IF NOT EXISTS idx_sender (sender_id);

-- メンションのユーザーインデックス（通知用）
ALTER TABLE message_mentions ADD INDEX IF NOT EXISTS idx_mentioned_user (mentioned_user_id);

-- メンションのメッセージインデックス
ALTER TABLE message_mentions ADD INDEX IF NOT EXISTS idx_message (message_id);

-- 会話メンバーの会話+参加日複合インデックス
ALTER TABLE conversation_members ADD INDEX IF NOT EXISTS idx_conv_joined (conversation_id, joined_at);

-- 組織メンバーの組織+ロール複合インデックス
ALTER TABLE organization_members ADD INDEX IF NOT EXISTS idx_org_role (organization_id, role);

-- 組織メンバーのユーザー+脱退日複合インデックス（アクティブメンバー取得用）
ALTER TABLE organization_members ADD INDEX IF NOT EXISTS idx_user_active (user_id, left_at);

-- 友達関係のステータスインデックス
ALTER TABLE friendships ADD INDEX IF NOT EXISTS idx_status (status);

-- 友達関係のユーザー+ステータス複合インデックス
ALTER TABLE friendships ADD INDEX IF NOT EXISTS idx_user_status (user_id, status);
ALTER TABLE friendships ADD INDEX IF NOT EXISTS idx_friend_status (friend_id, status);

-- リアクションのメッセージインデックス
ALTER TABLE message_reactions ADD INDEX IF NOT EXISTS idx_message (message_id);

-- タスクのユーザー+ステータス複合インデックス
ALTER TABLE tasks ADD INDEX IF NOT EXISTS idx_user_status (user_id, status);

-- メモのユーザーインデックス
ALTER TABLE memos ADD INDEX IF NOT EXISTS idx_user (user_id);

-- 通知のユーザー+既読複合インデックス
ALTER TABLE notifications ADD INDEX IF NOT EXISTS idx_user_read (user_id, is_read);

-- WishパターンのIDインデックス（パターンマッチング用）
ALTER TABLE wish_patterns ADD INDEX IF NOT EXISTS idx_pattern_active (is_active);

-- ユーザー設定のユーザーインデックス
ALTER TABLE user_settings ADD INDEX IF NOT EXISTS idx_user (user_id);


