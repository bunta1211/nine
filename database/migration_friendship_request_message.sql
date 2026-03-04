-- 友達申請メッセージ・ソース用マイグレーション
-- SEARCH_DESIGN_V2 対応: 友達申請にメッセージ付与、未成年同士の制限用source
-- MySQL 8.0.16+ / MariaDB 10.5.2+ で ADD COLUMN IF NOT EXISTS をサポート

-- request_message: 友達申請時のメッセージ（最大500文字）
ALTER TABLE friendships ADD COLUMN IF NOT EXISTS request_message TEXT NULL 
  COMMENT '友達申請時のメッセージ' AFTER status;

-- source: 友達申請の経路（search=検索, qr=QRコード, invite_link=招待リンク, group=グループ経由）
ALTER TABLE friendships ADD COLUMN IF NOT EXISTS source VARCHAR(20) DEFAULT NULL 
  COMMENT '友達申請の経路: search, qr, invite_link, group' AFTER request_message;

-- 旧MySQL用（IF NOT EXISTS 非対応の場合、上記でエラーが出たら以下を個別実行）:
-- ALTER TABLE friendships ADD COLUMN request_message TEXT NULL COMMENT '友達申請時のメッセージ' AFTER status;
-- ALTER TABLE friendships ADD COLUMN source VARCHAR(20) DEFAULT NULL COMMENT '友達申請の経路' AFTER request_message;
