-- AI秘書 性格設定・熟慮モード・自動話しかけ用カラム追加
-- 実行: mysql -u user -p database_name < migration_personality_json.sql

-- 1. user_ai_settings に性格JSON・熟慮時間・自動話しかけ設定を追加
ALTER TABLE user_ai_settings
    ADD COLUMN IF NOT EXISTS personality_json TEXT DEFAULT NULL
        COMMENT '性格設定JSON（7項目: pronoun,tone,character,expertise,behavior,avoid,other）'
        AFTER custom_instructions;

ALTER TABLE user_ai_settings
    ADD COLUMN IF NOT EXISTS deliberation_max_seconds INT DEFAULT 180
        COMMENT '熟慮モード最大秒数（デフォルト180=3分、最大1800=30分）'
        AFTER personality_json;

ALTER TABLE user_ai_settings
    ADD COLUMN IF NOT EXISTS proactive_message_enabled TINYINT(1) DEFAULT 1
        COMMENT '毎日の自動話しかけ ON=1 OFF=0'
        AFTER deliberation_max_seconds;

ALTER TABLE user_ai_settings
    ADD COLUMN IF NOT EXISTS proactive_message_hour TINYINT DEFAULT 18
        COMMENT '自動話しかけ時刻（0-23、デフォルト18時）'
        AFTER proactive_message_enabled;

-- 2. ai_conversations に自動話しかけフラグを追加
ALTER TABLE ai_conversations
    ADD COLUMN IF NOT EXISTS is_proactive TINYINT(1) DEFAULT 0
        COMMENT '自動話しかけメッセージ=1'
        AFTER language;
