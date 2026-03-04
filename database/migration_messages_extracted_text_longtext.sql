-- 貼り付け上限200M（2億文字）対応: extracted_text を MEDIUMTEXT から LONGTEXT に変更
-- 実行前に ft_extracted_text が存在する場合は削除（LONGTEXT では FULLTEXT の長さ制限に抵触するため）
-- ft_extracted_text が無い環境では DROP はエラーになるので、その場合は下記 MODIFY のみ実行すること

-- FULLTEXT インデックスを削除（存在する場合）
ALTER TABLE messages DROP INDEX ft_extracted_text;

-- extracted_text を LONGTEXT に変更（約4GBまで格納可能）
ALTER TABLE messages MODIFY extracted_text LONGTEXT DEFAULT NULL COMMENT 'PDF/長文の全文（検索・AI学習用）';
