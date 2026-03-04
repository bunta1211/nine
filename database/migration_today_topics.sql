-- 今日の話題（本日のニューストピックス・興味トピックレポート）用テーブル・カラム追加
-- 実行: mysql -u user -p database_name < migration_today_topics.sql
-- 本番: DOCS/SERVER_DEPLOY_AND_SQL.md に従い SQL ファイルを EC2 に送り、mysql < で実行

-- 1. クリック記録テーブル（詳細を見たニュース・トピック）
CREATE TABLE IF NOT EXISTS today_topic_clicks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    topic_id VARCHAR(255) DEFAULT NULL COMMENT 'トピック識別子またはニュースID',
    external_url VARCHAR(2048) DEFAULT NULL COMMENT '記事URL（クリック先）',
    source VARCHAR(100) DEFAULT NULL COMMENT 'RSS名・ソース名',
    category_or_keywords VARCHAR(500) DEFAULT NULL COMMENT 'カテゴリ・キーワード（JSON可）',
    clicked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_clicked (user_id, clicked_at),
    INDEX idx_clicked_at (clicked_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. ユーザーが希望した興味・推し
CREATE TABLE IF NOT EXISTS user_topic_interests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    interest_type ENUM('category', 'keyword', 'oshi') NOT NULL DEFAULT 'keyword'
        COMMENT 'category=分野, keyword=キーワード, oshi=推し',
    value VARCHAR(255) NOT NULL COMMENT '分野名・キーワード・推しの名前など',
    source_message_id INT UNSIGNED DEFAULT NULL COMMENT '元メッセージID（任意）',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_user_type (user_id, interest_type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. user_ai_settings に今日の話題用カラムを追加
-- （MariaDB 10.5+ または MySQL 8.0.12+ の IF NOT EXISTS に対応していない場合はエラーになるため、その場合は 1 文ずつ実行して存在するカラムはスキップ）
ALTER TABLE user_ai_settings
    ADD COLUMN IF NOT EXISTS today_topics_morning_enabled TINYINT(1) DEFAULT 1
        COMMENT '本日のニューストピックス（朝）ON=1 OFF=0'
        AFTER proactive_message_hour;

ALTER TABLE user_ai_settings
    ADD COLUMN IF NOT EXISTS today_topics_evening_enabled TINYINT(1) DEFAULT 1
        COMMENT '興味トピックレポート（夜）ON=1 OFF=0'
        AFTER today_topics_morning_enabled;

ALTER TABLE user_ai_settings
    ADD COLUMN IF NOT EXISTS today_topics_morning_hour TINYINT DEFAULT 7
        COMMENT '朝の配信希望時刻 6=6時 7=7時（デフォルト7）'
        AFTER today_topics_evening_enabled;
