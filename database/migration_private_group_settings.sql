-- プライベートグループ設定カラムを conversations に追加
-- マスター計画: DOCS/PRIVATE_GROUP_AND_ADDRESS_BOOK_MASTER_PLAN.md 1.1, 2.1
-- 既存レコードは DEFAULT の 0/1 で問題なし。
--
-- 【実行方法】phpMyAdmin の場合:
-- 1. このファイルを開き、下の ALTER 文をコピー
-- 2. phpMyAdmin の「SQL」タブを開く
-- 3. コピーした SQL を貼り付けて「実行」をクリック

ALTER TABLE conversations ADD COLUMN IF NOT EXISTS is_private_group TINYINT(1) DEFAULT 0 COMMENT 'プライベートグループ 1=組織管理からのみ作成';
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS allow_member_post TINYINT(1) DEFAULT 1 COMMENT '発言許可 0=不可';
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS allow_data_send TINYINT(1) DEFAULT 1 COMMENT 'データ送信許可 0=不可';
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS member_list_visible TINYINT(1) DEFAULT 1 COMMENT 'メンバー一覧公開 0=非表示(人数は表示)';
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS allow_add_contact_from_group TINYINT(1) DEFAULT 1 COMMENT 'グループ内からアドレス追加 0=不許可';
