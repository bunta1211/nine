-- ===========================================
-- Wish機能用テーブル
-- AIチャット解析による願望・希望自動抽出
-- ===========================================

-- Wish抽出パターン（管理者が設定）
CREATE TABLE IF NOT EXISTS wish_patterns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pattern VARCHAR(500) NOT NULL COMMENT '正規表現パターン',
    category ENUM('request', 'desire', 'want', 'travel', 'purchase', 'work', 'other') DEFAULT 'other' COMMENT 'カテゴリ',
    category_label VARCHAR(50) DEFAULT NULL COMMENT 'カテゴリ表示名',
    description VARCHAR(200) DEFAULT NULL COMMENT 'パターンの説明',
    example_input TEXT COMMENT '例文（入力）',
    example_output VARCHAR(200) COMMENT '例文（抽出結果）',
    extract_group INT DEFAULT 1 COMMENT '抽出するキャプチャグループ番号',
    suffix_remove VARCHAR(100) DEFAULT NULL COMMENT '除去する接尾辞（カンマ区切り）',
    is_active TINYINT(1) DEFAULT 1,
    priority INT DEFAULT 0 COMMENT '優先度（高い順に適用）',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active_priority (is_active, priority DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI学習用例文（将来のPhase 2用）
CREATE TABLE IF NOT EXISTS wish_training_examples (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    input_text TEXT NOT NULL COMMENT '入力文',
    extracted_wish VARCHAR(200) COMMENT '抽出されたWish',
    category VARCHAR(50) COMMENT 'カテゴリ',
    is_positive TINYINT(1) DEFAULT 1 COMMENT '正例(1)/負例(0)',
    notes TEXT COMMENT '備考',
    created_by INT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- tasksテーブルに追加カラム（既存テーブルの拡張）
-- source: manual（手動作成）/ ai_extracted（AI抽出）
-- source_message_id: 抽出元のメッセージID
-- original_text: 抽出元の原文
-- confidence: 抽出の確信度

ALTER TABLE tasks 
    ADD COLUMN IF NOT EXISTS source ENUM('manual', 'ai_extracted') DEFAULT 'manual' AFTER status,
    ADD COLUMN IF NOT EXISTS source_message_id INT UNSIGNED DEFAULT NULL AFTER source,
    ADD COLUMN IF NOT EXISTS original_text TEXT DEFAULT NULL AFTER source_message_id,
    ADD COLUMN IF NOT EXISTS confidence DECIMAL(3,2) DEFAULT 1.00 AFTER original_text,
    ADD COLUMN IF NOT EXISTS category VARCHAR(50) DEFAULT NULL AFTER confidence;

-- インデックス追加
ALTER TABLE tasks ADD INDEX IF NOT EXISTS idx_source (source);
ALTER TABLE tasks ADD INDEX IF NOT EXISTS idx_user_source (user_id, source);

-- ===========================================
-- 初期パターンデータ
-- ===========================================

INSERT INTO wish_patterns (pattern, category, category_label, description, example_input, example_output, extract_group, suffix_remove, priority) VALUES
-- 依頼パターン（〜してほしい系）
('(.+?)(?:して|やって)(?:おいて|おいてね)?(?:ください|くださいね|ほしい|もらえる|もらえますか|もらいたい)?(?:です|ね|な|よ)?', 'request', '依頼', '依頼・お願いの文（してほしいです対応）', '資料準備しておいてほしいです', '資料準備', 1, 'を,に,は', 100),
('(.+?)(?:お願い|おねがい)(?:します|しますね|したい|できますか|ね|!)?(?:です)?', 'request', '依頼', 'お願いの文', '確認お願いします', '確認', 1, 'を,の,は', 95),
('(.+?)(?:頼む|たのむ|頼みたい)(?:ね|よ|わ|です)?', 'request', '依頼', '頼む表現', '買い物頼むね', '買い物', 1, 'を,は', 90),

-- 願望パターン（〜したい）
('(.+?)(?:行き|いき)たい(?:な|なー|なぁ|ね|です)?$', 'travel', '旅行', '行きたい場所', '沖縄行きたいなー', '沖縄', 1, 'に,へ,は', 85),
('(.+?)(?:食べ|たべ)たい(?:な|なー|なぁ|ね|です)?$', 'desire', '願望', '食べたいもの', 'ラーメン食べたいな', 'ラーメン', 1, 'を,が,は', 85),
('(.+?)(?:見|み)たい(?:な|なー|なぁ|ね|です)?$', 'desire', '願望', '見たいもの', 'あの映画見たいな', 'あの映画', 1, 'を,が,は', 85),
('(.+?)(?:し|やり)たい(?:な|なー|なぁ|ね|です|こと)?$', 'desire', '願望', 'やりたいこと', '旅行したいな', '旅行', 1, 'を,が,は,も', 80),

-- 欲求パターン（〜ほしい）
('(.+?)(?:欲し|ほし)い(?:な|なー|なぁ|ね|です)?$', 'want', '欲しい', '欲しいもの', '新しいパソコンほしいな', '新しいパソコン', 1, 'が,を,は,も', 85),
('(.+?)(?:買い|かい)たい(?:な|なー|なぁ|ね|です)?$', 'purchase', '購入', '買いたいもの', 'あのバッグ買いたい', 'あのバッグ', 1, 'を,が,は', 85),

-- 仕事・作業パターン
('(.+?)(?:やらなきゃ|やらないと|しなきゃ|しないと)(?:いけない|だめ|ね)?$', 'work', 'やること', 'やらなければいけないこと', 'レポートやらなきゃ', 'レポート', 1, 'を,は', 75),
('(.+?)(?:忘れ|わすれ)ないように(?:しなきゃ|しないと|ね)?$', 'work', 'やること', '忘れないようにすること', '薬忘れないようにしなきゃ', '薬', 1, 'を,は', 70),

-- 予定・計画パターン  
('(?:今度|こんど|いつか)(.+?)(?:しよう|しようね|したい)(?:な|ね)?$', 'desire', '願望', '今度やりたいこと', '今度みんなで遊ぼうね', 'みんなで遊ぶ', 1, 'を,は', 70),
('(.+?)(?:予定|よてい)(?:だ|です|入れ|いれ)(?:よう|たい)?$', 'work', '予定', '予定を入れたいこと', '来週歯医者の予定入れよう', '来週歯医者', 1, 'の,を,は', 65)

ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;








