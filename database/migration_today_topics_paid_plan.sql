-- 今日の話題: 200名超での有料切り替え・月額プラン加入用カラム（計画書 4.1, 12.6）
-- 実行: mysql -h ... -u admin -p social9 < migration_today_topics_paid_plan.sql
-- 注意: 既にカラムがある場合は「Duplicate column name」でエラーになるが無視してよい
-- （AFTER は使わない: 本番に today_topics_morning_hour がない場合があるため）

ALTER TABLE user_ai_settings
    ADD COLUMN today_topics_paid_plan TINYINT(1) DEFAULT 0
        COMMENT '月額ニュース配信プラン加入 0=未加入 1=加入（Stripe等連携時に1にする）';
