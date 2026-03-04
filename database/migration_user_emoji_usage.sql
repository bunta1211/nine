-- ユーザーごとの絵文字使用頻度（AI秘書の絵文字学習用）
-- メッセージ送信時に集計し、api/ai.php の ask で参照して応答に反映する

CREATE TABLE IF NOT EXISTS user_emoji_usage (
    user_id INT UNSIGNED NOT NULL,
    emoji_char VARCHAR(20) NOT NULL COMMENT '絵文字1文字（UTF-8複数バイト可）',
    cnt INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, emoji_char),
    INDEX idx_user_cnt (user_id, cnt DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
