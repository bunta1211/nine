-- 全グループで「メンバー間DMを許可」を有効にする
-- 友達欄にグループメンバーを表示するために実行
--
-- 【phpMyAdminでの実行】このファイルの下のUPDATE文をコピーしてSQLタブに貼り付け実行

UPDATE conversations SET allow_member_dm = 1 WHERE type = 'group';
