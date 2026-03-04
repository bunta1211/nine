-- DMの両ユーザーを管理者に更新
-- DM（type='dm'）の会話に所属する全メンバーをadminに変更

UPDATE conversation_members cm
INNER JOIN conversations c ON cm.conversation_id = c.id
SET cm.role = 'admin'
WHERE c.type = 'dm' AND cm.left_at IS NULL;

-- 確認クエリ（実行不要）
-- SELECT c.id, c.type, cm.user_id, cm.role 
-- FROM conversations c 
-- INNER JOIN conversation_members cm ON c.id = cm.conversation_id 
-- WHERE c.type = 'dm';


