-- 試聴の再生時間（1/3/5秒）を保存するカラムを追加
-- 実行は任意。settings.php の読み込み時にもカラムが追加される。

ALTER TABLE user_settings
ADD COLUMN notification_preview_duration TINYINT DEFAULT 3 COMMENT '試聴再生時間（1/3/5秒）',
ADD COLUMN ringtone_preview_duration TINYINT DEFAULT 3 COMMENT '通話試聴再生時間（1/3/5秒）';
