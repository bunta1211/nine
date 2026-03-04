-- 追加テーブル定義
-- 仕様書: 42_保護者児童連携統合仕様.md, 06_通話機能.md, 43_AI機能統合仕様.md

-- =====================================================
-- 保護者・児童連携関連テーブル
-- =====================================================

-- 保護者・子ども関係テーブル
CREATE TABLE IF NOT EXISTS parent_child_relations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_user_id INT NOT NULL COMMENT '保護者ユーザーID',
    child_user_id INT NOT NULL COMMENT '子どもユーザーID',
    status ENUM('pending', 'parent_approved', 'active', 'inactive') DEFAULT 'pending' COMMENT '状態',
    parent_approved_at DATETIME DEFAULT NULL COMMENT '保護者承認日時',
    child_approved_at DATETIME DEFAULT NULL COMMENT '子ども承認日時',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_relation (parent_user_id, child_user_id),
    INDEX idx_parent (parent_user_id),
    INDEX idx_child (child_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 子どものSNS設定テーブル
CREATE TABLE IF NOT EXISTS child_sns_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    relation_id INT NOT NULL UNIQUE COMMENT '関係ID',
    friend_add_restriction ENUM('free', 'school_only', 'parent_approved') DEFAULT 'parent_approved' COMMENT '友達追加制限',
    call_restriction ENUM('free', 'approved_only', 'disabled') DEFAULT 'approved_only' COMMENT '通話制限',
    allow_japanese TINYINT(1) DEFAULT 1 COMMENT '日本語許可',
    allow_english TINYINT(1) DEFAULT 1 COMMENT '英語許可',
    allow_chinese TINYINT(1) DEFAULT 1 COMMENT '中国語許可',
    allow_stamps TINYINT(1) DEFAULT 1 COMMENT 'スタンプ許可',
    usage_start_time TIME DEFAULT '07:00:00' COMMENT '利用開始時間',
    usage_end_time TIME DEFAULT '21:00:00' COMMENT '利用終了時間',
    daily_limit_minutes INT DEFAULT 120 COMMENT '1日の利用制限（分）',
    can_view_messages TINYINT(1) DEFAULT 0 COMMENT '保護者がメッセージ閲覧可能',
    can_view_online_status TINYINT(1) DEFAULT 1 COMMENT '保護者がオンライン状態閲覧可能',
    can_view_friends TINYINT(1) DEFAULT 1 COMMENT '保護者が友達リスト閲覧可能',
    parent_proposed_at DATETIME DEFAULT NULL COMMENT '保護者提案日時',
    child_agreed_at DATETIME DEFAULT NULL COMMENT '子ども同意日時',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (relation_id) REFERENCES parent_child_relations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 許可済み連絡先テーブル
CREATE TABLE IF NOT EXISTS approved_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    child_user_id INT NOT NULL COMMENT '子どもユーザーID',
    approved_user_id INT NOT NULL COMMENT '許可されたユーザーID',
    approved_by INT NOT NULL COMMENT '許可した保護者ID',
    allow_dm TINYINT(1) DEFAULT 1 COMMENT 'DM許可',
    allow_call TINYINT(1) DEFAULT 1 COMMENT '通話許可',
    approved_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_contact (child_user_id, approved_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 通話関連テーブル
-- =====================================================

-- 通話テーブル
CREATE TABLE IF NOT EXISTS calls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL COMMENT '会話ID',
    initiator_id INT NOT NULL COMMENT '発信者ID',
    room_id VARCHAR(100) NOT NULL UNIQUE COMMENT 'Jitsi Room ID',
    call_type ENUM('audio', 'video') DEFAULT 'video' COMMENT '通話種類',
    status ENUM('ringing', 'active', 'ended', 'missed', 'declined') DEFAULT 'ringing' COMMENT '状態',
    started_at DATETIME DEFAULT NULL COMMENT '開始日時',
    ended_at DATETIME DEFAULT NULL COMMENT '終了日時',
    duration_seconds INT DEFAULT 0 COMMENT '通話時間（秒）',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (initiator_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_conversation (conversation_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 通話参加者テーブル
CREATE TABLE IF NOT EXISTS call_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    call_id INT NOT NULL COMMENT '通話ID',
    user_id INT NOT NULL COMMENT 'ユーザーID',
    status ENUM('invited', 'joined', 'left', 'declined') DEFAULT 'invited' COMMENT '状態',
    joined_at DATETIME DEFAULT NULL COMMENT '参加日時',
    left_at DATETIME DEFAULT NULL COMMENT '退出日時',
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_participant (call_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- AI相談室関連テーブル
-- =====================================================

-- AI会話テーブル
CREATE TABLE IF NOT EXISTS ai_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'ユーザーID',
    question TEXT NOT NULL COMMENT '質問',
    answer TEXT COMMENT '回答',
    answered_by ENUM('ai', 'admin') DEFAULT 'ai' COMMENT '回答者',
    language VARCHAR(10) DEFAULT 'ja' COMMENT '言語',
    is_helpful TINYINT(1) DEFAULT NULL COMMENT '役に立ったか',
    feedback_at DATETIME DEFAULT NULL COMMENT 'フィードバック日時',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI知識ベーステーブル
CREATE TABLE IF NOT EXISTS ai_knowledge_base (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(50) NOT NULL COMMENT 'カテゴリ',
    question TEXT NOT NULL COMMENT '質問例',
    answer TEXT NOT NULL COMMENT '回答',
    keywords TEXT COMMENT '検索キーワード',
    language VARCHAR(10) DEFAULT 'ja' COMMENT '言語',
    priority INT DEFAULT 0 COMMENT '優先度',
    is_active TINYINT(1) DEFAULT 1 COMMENT '有効フラグ',
    created_by INT DEFAULT NULL COMMENT '作成者',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_category (category),
    INDEX idx_language (language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 通知テーブル
-- =====================================================

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'ユーザーID',
    type ENUM('message', 'mention', 'call_incoming', 'call_missed', 'permission_request', 'system') NOT NULL COMMENT '種類',
    title VARCHAR(200) NOT NULL COMMENT 'タイトル',
    content TEXT COMMENT '内容',
    related_type VARCHAR(50) DEFAULT NULL COMMENT '関連種類',
    related_id INT DEFAULT NULL COMMENT '関連ID',
    is_read TINYINT(1) DEFAULT 0 COMMENT '既読フラグ',
    read_at DATETIME DEFAULT NULL COMMENT '既読日時',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_unread (user_id, is_read),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 初期データ: AI知識ベース
-- =====================================================

INSERT INTO ai_knowledge_base (category, question, answer, keywords, language, priority) VALUES
('基本操作', 'メッセージの送り方を教えてください', 'メッセージを送るには：\n1. 左側のチャットリストから相手を選択\n2. 画面下部の入力欄にメッセージを入力\n3. 送信ボタン（または Enterキー）で送信\n\n画像やファイルを送る場合は、クリップアイコンをクリックしてください。', 'メッセージ 送り方 送信 チャット 入力', 'ja', 100),
('基本操作', 'グループの作り方を教えてください', 'グループを作成するには：\n1. 左側パネル上部の「＋」アイコンをクリック\n2. 「グループ作成」を選択\n3. グループ名を入力\n4. メンバーを選択して「作成」をクリック\n\nグループ作成後は、あなたが管理者になります。', 'グループ 作成 作り方 新規', 'ja', 95),
('アカウント', 'パスワードを忘れました', 'パスワードをお忘れの場合：\n1. ログイン画面の「パスワードを忘れた」をクリック\n2. 登録メールアドレスを入力\n3. 届いたメールのリンクから新しいパスワードを設定\n\nメールが届かない場合は、迷惑メールフォルダも確認してください。', 'パスワード 忘れた リセット ログイン できない', 'ja', 90),
('通話', 'ビデオ通話のやり方を教えてください', 'ビデオ通話を開始するには：\n1. 通話したい相手とのチャットを開く\n2. 画面上部のビデオアイコンをクリック\n3. 相手が応答するのを待つ\n\n初回はカメラとマイクの許可が必要です。', 'ビデオ 通話 やり方 電話 かける', 'ja', 85),
('プライバシー', 'オンライン状態を非表示にしたい', 'オンライン状態を非表示にするには：\n1. 画面右上のプロフィールアイコンをクリック\n2. 「設定」を選択\n3. 「プライバシー」タブを開く\n4. 「オンライン状態」を「非公開」に設定\n\nこれで他のユーザーにはあなたのオンライン状態が表示されなくなります。', 'オンライン 非表示 ステータス 隠す プライバシー', 'ja', 80),
('保護者', '子どものアカウントを管理したい', '保護者として子どものアカウントを管理するには：\n1. 子どもがアカウント登録時に保護者として紐付け\n2. 紐付け後、保護者ダッシュボードから設定可能\n\n設定できる項目：\n・友達追加の制限\n・通話の制限\n・使用時間の制限\n・言語の制限\n\n設定変更には子どもの同意が必要です。', '子ども 保護者 管理 制限 見守り 親', 'ja', 75);








