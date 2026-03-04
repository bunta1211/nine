-- 着信音設定（自分宛メッセージ通知）を user_settings に追加
-- 実行は任意。api/settings.php の ensureSettingsTable でもカラムが追加される。
-- 既にカラムがある場合はエラーになるのでスキップしてよい。

ALTER TABLE user_settings
ADD COLUMN notification_sound VARCHAR(30) DEFAULT 'default' COMMENT '着信音（自分宛メッセージ）';
