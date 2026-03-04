-- wish_patterns テーブルに不足カラムを追加

-- priority カラム（優先度）
ALTER TABLE wish_patterns ADD COLUMN IF NOT EXISTS priority INT DEFAULT 0;

-- extract_group カラム（正規表現のグループ番号）
ALTER TABLE wish_patterns ADD COLUMN IF NOT EXISTS extract_group INT DEFAULT 0;

-- confidence カラム（信頼度）
ALTER TABLE wish_patterns ADD COLUMN IF NOT EXISTS confidence DECIMAL(3,2) DEFAULT 0.80;

-- suffix_removal カラム（除去する接尾辞）
ALTER TABLE wish_patterns ADD COLUMN IF NOT EXISTS suffix_removal VARCHAR(255) NULL;

-- tasks テーブルに不足カラムを追加（確認のため再実行可能）
-- ALTER TABLE tasks ADD COLUMN IF NOT EXISTS confidence DECIMAL(3,2) DEFAULT NULL;

-- 既存パターンにpriorityを設定
UPDATE wish_patterns SET priority = 10 WHERE pattern LIKE '%たい%' AND priority = 0;
UPDATE wish_patterns SET priority = 8 WHERE pattern LIKE '%欲しい%' AND priority = 0;
UPDATE wish_patterns SET priority = 8 WHERE pattern LIKE '%ほしい%' AND priority = 0;


