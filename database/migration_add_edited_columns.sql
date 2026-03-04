-- メッセージ編集機能用カラム追加
-- 実行前にバックアップを取ることを推奨

-- is_edited カラムを追加（存在しない場合）
SET @exist_is_edited := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'is_edited');

SET @sql_is_edited = IF(@exist_is_edited = 0, 
    'ALTER TABLE messages ADD COLUMN is_edited TINYINT(1) DEFAULT 0 AFTER reply_to_id',
    'SELECT "Column is_edited already exists" AS message');

PREPARE stmt_is_edited FROM @sql_is_edited;
EXECUTE stmt_is_edited;
DEALLOCATE PREPARE stmt_is_edited;

-- edited_at カラムを追加（存在しない場合）
SET @exist_edited_at := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'edited_at');

SET @sql_edited_at = IF(@exist_edited_at = 0, 
    'ALTER TABLE messages ADD COLUMN edited_at DATETIME DEFAULT NULL AFTER is_edited',
    'SELECT "Column edited_at already exists" AS message');

PREPARE stmt_edited_at FROM @sql_edited_at;
EXECUTE stmt_edited_at;
DEALLOCATE PREPARE stmt_edited_at;

-- 確認
SELECT 
    COLUMN_NAME, 
    COLUMN_TYPE, 
    IS_NULLABLE, 
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'messages' 
    AND COLUMN_NAME IN ('is_edited', 'edited_at')
ORDER BY ORDINAL_POSITION;
