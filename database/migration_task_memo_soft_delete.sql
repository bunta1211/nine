-- タスク・メモの論理削除対応（削除後も秘書の検索で記憶として参照可能にする）
-- 実行日: 2026-02
--
-- 【実行方法】phpMyAdmin の場合:
-- 1. 左でデータベースを選択
-- 2. 上部の「SQL」タブをクリック
-- 3. 以下の4行をコピーして貼り付け、「実行」をクリック
-- （このファイルをインポートする場合は「インポート」タブからファイルを選択）
--

ALTER TABLE tasks ADD COLUMN deleted_at DATETIME DEFAULT NULL COMMENT '論理削除日時（NULL=有効）';
ALTER TABLE tasks ADD INDEX idx_tasks_deleted (deleted_at);
ALTER TABLE memos ADD COLUMN deleted_at DATETIME DEFAULT NULL COMMENT '論理削除日時（NULL=有効）';
ALTER TABLE memos ADD INDEX idx_memos_deleted (deleted_at);
