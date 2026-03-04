-- 組織メンバー招待・承諾フロー用: 承諾日時カラム追加
-- accepted_at IS NULL = 招待済み未承諾, NOT NULL = 承諾済み（正式所属）
-- 既にカラムがある場合はエラーになるので、必要なら SHOW COLUMNS で確認してから実行

ALTER TABLE organization_members
ADD COLUMN accepted_at DATETIME NULL DEFAULT NULL
COMMENT '承諾日時（NULL=未承諾・招待中）'
AFTER joined_at;
