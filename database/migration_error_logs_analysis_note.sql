-- エラーログに「自動分析（調査のヒント）」用カラムを追加
-- 記録時にメッセージ・種別・URLからプログラムで生成した説明を保存し、管理画面で表示する
-- 既にカラムがある場合はエラーになるので、1回だけ実行すること

ALTER TABLE error_logs
ADD COLUMN analysis_note TEXT NULL COMMENT '自動分析（原因の目安・調査のヒント。中学生にも分かる日本語）' AFTER notes;
