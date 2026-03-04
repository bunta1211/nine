-- 着信音が鳴る条件を設定（パソコン版・携帯版で個別に選択可能）
-- all: 全メッセージに反応
-- to_me: 自分へのメッセージに反応（メンション、To指定、To全員）
-- none: 鳴らさない
-- 実行は任意。settings.php の読み込み時にもカラムが追加される。

ALTER TABLE user_settings
ADD COLUMN notification_trigger_pc VARCHAR(20) DEFAULT 'to_me' COMMENT '着信音が鳴る条件（PC）',
ADD COLUMN notification_trigger_mobile VARCHAR(20) DEFAULT 'to_me' COMMENT '着信音が鳴る条件（携帯）';
