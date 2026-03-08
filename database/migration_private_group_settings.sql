-- プライベートグループ設定カラムを conversations に追加
-- マスター計画: DOCS/PRIVATE_GROUP_AND_ADDRESS_BOOK_MASTER_PLAN.md 1.1, 2.1
-- 既存レコードは DEFAULT の 0/1 で問題なし。
--
-- 【MySQL 5.7 対応】IF NOT EXISTS は MySQL 8.0 以降のため使用していません。
-- 「Duplicate column name」が出た場合はそのカラムは既に存在します。次の ALTER を実行するか、該当行をスキップしてください。
--
-- 【実行方法】phpMyAdmin / mysql クライアント:
-- 1. このファイルを開き、下の ALTER 文を1行ずつ実行するか、まとめて実行
-- 2. エラーが出た行はスキップして次を実行

ALTER TABLE conversations ADD COLUMN is_private_group TINYINT(1) DEFAULT 0 COMMENT 'プライベートグループ 1=組織管理からのみ作成';
ALTER TABLE conversations ADD COLUMN allow_member_post TINYINT(1) DEFAULT 1 COMMENT '発言許可 0=不可';
ALTER TABLE conversations ADD COLUMN allow_data_send TINYINT(1) DEFAULT 1 COMMENT 'データ送信許可 0=不可';
ALTER TABLE conversations ADD COLUMN member_list_visible TINYINT(1) DEFAULT 1 COMMENT 'メンバー一覧公開 0=非表示(人数は表示)';
ALTER TABLE conversations ADD COLUMN allow_add_contact_from_group TINYINT(1) DEFAULT 1 COMMENT 'グループ内からアドレス追加 0=不許可';
