-- メッセージのPDF/ファイルからの抽出テキスト保存用カラム追加
-- 長文メッセージのPDF変換時やアップロードPDFからテキストを抽出して保存
-- 検索機能で content と共に検索対象にする

ALTER TABLE messages ADD COLUMN extracted_text MEDIUMTEXT DEFAULT NULL AFTER content;

-- 検索パフォーマンス向上のためFULLTEXTインデックスを追加
ALTER TABLE messages ADD FULLTEXT INDEX ft_extracted_text (extracted_text);
