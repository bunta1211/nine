-- 返信引用機能用: messages.reply_to_id カラム追加（存在しない場合のみ）
-- 実行前にバックアップを取ることを推奨
-- 本番で引用がリロード後に消える場合は、このマイグレーションを実行してから api/messages.php をデプロイしてください。
--
-- 【EC2 上で実行する場合】
-- 1) このファイルを EC2 に置く（例: scp で database ごと送る、またはこのファイルだけ ~ に送る）
-- 2) EC2 で RDS に接続して流し込む。例（パスワードは db-env.conf の DB_PASS）:
--
--     cd /var/www/html
--     mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < database/migration_messages_reply_to_id.sql
--
--     ※ ファイルをホームに置いた場合:
--     mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < /home/ec2-user/migration_messages_reply_to_id.sql
--
-- ローカルや別サーバーでの実行例:
--   mysql -u ユーザー -p データベース名 < database/migration_messages_reply_to_id.sql

SET @exist_reply_to_id := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'reply_to_id');

SET @sql_reply_to_id = IF(@exist_reply_to_id = 0,
    'ALTER TABLE messages ADD COLUMN reply_to_id INT UNSIGNED DEFAULT NULL COMMENT ''返信先メッセージID'' AFTER content',
    'SELECT ''Column reply_to_id already exists'' AS message');

PREPARE stmt_reply_to_id FROM @sql_reply_to_id;
EXECUTE stmt_reply_to_id;
DEALLOCATE PREPARE stmt_reply_to_id;

-- オプション: 外部キーを張る場合（既存データの整合性を確認してから実行）
-- ALTER TABLE messages ADD CONSTRAINT fk_messages_reply_to
--   FOREIGN KEY (reply_to_id) REFERENCES messages(id) ON DELETE SET NULL;
