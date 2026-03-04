-- ============================================
-- AI秘書連携機能 — 専門AI・記憶・通報・権限テーブル
-- 計画書 セクション 2〜2.4, 6.1 に基づく
-- ============================================

-- ============================================
-- 1. 組織別 専門AI設定
-- ============================================
CREATE TABLE IF NOT EXISTS org_ai_specialists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    specialist_type ENUM(
        'work',        -- 業務内容統括AI
        'people',      -- 人財AI
        'finance',     -- 会計統括AI
        'compliance',  -- コンプライアンスAI
        'mentalcare',  -- メンタルケアAI
        'education',   -- 社内教育型AI
        'customer'     -- 顧客管理AI
    ) NOT NULL,
    display_name VARCHAR(100) NOT NULL DEFAULT '',
    system_prompt TEXT COMMENT '組織カスタムのシステムプロンプト（NULLならデフォルト使用）',
    custom_rules TEXT COMMENT '組織固有ルール・ポリシーテキスト',
    config_json JSON COMMENT '閾値・パラメータ等のJSON設定',
    is_enabled TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_org_specialist (organization_id, specialist_type),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    INDEX idx_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. 組織別 ナレッジ記憶ストア（全専門AI共通構造）
-- ============================================
CREATE TABLE IF NOT EXISTS org_ai_memories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    specialist_type ENUM('work','people','finance','compliance','mentalcare','education','customer') NOT NULL,
    title VARCHAR(500) NOT NULL DEFAULT '',
    content TEXT NOT NULL,
    content_type VARCHAR(50) DEFAULT 'text' COMMENT 'text / procedure / faq / metadata',
    tags JSON COMMENT 'タグ配列',
    source_conversation_id INT NULL COMMENT '元の会話ID',
    source_message_id BIGINT UNSIGNED NULL COMMENT '元のメッセージID',
    source_type ENUM('auto_chat','auto_batch','manual','import') DEFAULT 'auto_batch',
    extracted_entities JSON COMMENT '抽出エンティティ（人名/顧客名/金額/日付等）',
    status ENUM('active','archived','deleted') DEFAULT 'active',
    created_by INT NULL COMMENT '手動追加時のユーザーID',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    INDEX idx_org_type (organization_id, specialist_type),
    INDEX idx_org_status (organization_id, status),
    INDEX idx_source (source_conversation_id, source_message_id),
    FULLTEXT INDEX ft_content (title, content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. 記憶の編集履歴
-- ============================================
CREATE TABLE IF NOT EXISTS org_ai_memory_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    memory_id BIGINT UNSIGNED NOT NULL,
    action ENUM('create','update','delete','restore') NOT NULL,
    old_title VARCHAR(500) NULL,
    old_content TEXT NULL,
    new_title VARCHAR(500) NULL,
    new_content TEXT NULL,
    changed_by INT NOT NULL COMMENT '変更したユーザーID',
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (memory_id) REFERENCES org_ai_memories(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_memory (memory_id),
    INDEX idx_changed_by (changed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. 記憶のアクセス権限
-- ============================================
CREATE TABLE IF NOT EXISTS org_ai_memory_permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    user_id INT NULL COMMENT 'NULLならロール単位の設定',
    role VARCHAR(50) NULL COMMENT 'owner / admin / member / ナレッジ編集者 等',
    specialist_type ENUM('work','people','finance','compliance','mentalcare','education','customer') NULL COMMENT 'NULLなら全専門AI',
    permission_level ENUM('view','edit','delete') NOT NULL DEFAULT 'view',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    INDEX idx_org_user (organization_id, user_id),
    INDEX idx_org_role (organization_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. ユーザー性格プロファイル（秘書の自動成長用）
-- ============================================
CREATE TABLE IF NOT EXISTS user_ai_profile (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    personality_traits JSON COMMENT '性格特性（慎重/即断, データ重視/感覚重視 等）',
    communication_style JSON COMMENT 'コミュニケーション傾向（丁寧/砕けた, 詳細/端的 等）',
    preferred_topics JSON COMMENT '関心トピック・頻出キーワード',
    avoided_expressions JSON COMMENT '避ける表現・嫌うパターン',
    behavior_patterns JSON COMMENT '行動パターン（朝型/夜型, 活動時間帯 等）',
    interaction_stats JSON COMMENT '統計（会話回数, 平均長さ, フィードバック傾向 等）',
    profile_version INT DEFAULT 1 COMMENT '更新回数',
    last_analyzed_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. 運営への自動通報
-- ============================================
CREATE TABLE IF NOT EXISTS ai_safety_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT '通報対象ユーザー',
    organization_id INT NULL COMMENT '所属組織',
    report_type ENUM('social_norm','life_danger','bullying','other') NOT NULL,
    severity ENUM('low','medium','high','critical') DEFAULT 'medium',
    summary TEXT NOT NULL COMMENT '要約',
    raw_context LONGTEXT NOT NULL COMMENT '前後の文脈・判断した生文章',
    source_conversation_id INT NULL COMMENT '元のAI会話ID',
    user_social_context TEXT COMMENT 'ユーザーの社会的立場・場所等',
    user_personality_snapshot JSON COMMENT '通報時点の性格分析スナップショット',
    ai_reasoning TEXT COMMENT 'AIが通報と判断した理由',
    status ENUM('new','reviewing','resolved','dismissed') DEFAULT 'new',
    reviewed_by INT NULL COMMENT '確認した運営責任者ID',
    reviewed_at DATETIME NULL,
    review_notes TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. 運営→秘書への追加質問
-- ============================================
CREATE TABLE IF NOT EXISTS ai_safety_report_questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    asked_by INT NOT NULL COMMENT '質問した運営責任者ID',
    question TEXT NOT NULL,
    answer TEXT NULL COMMENT '秘書からの回答',
    answered_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (report_id) REFERENCES ai_safety_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (asked_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. グループチャット記憶バッチ処理ログ
-- ============================================
CREATE TABLE IF NOT EXISTS org_ai_memory_batch_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    conversation_id INT NOT NULL,
    last_processed_message_id BIGINT UNSIGNED NULL COMMENT '最後に処理したメッセージID',
    messages_processed INT DEFAULT 0,
    memories_created INT DEFAULT 0,
    status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
    error_message TEXT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    INDEX idx_org_conv (organization_id, conversation_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 9. 専門AI利用ログ（振り分け・呼び出し記録）
-- ============================================
CREATE TABLE IF NOT EXISTS org_ai_specialist_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    user_id INT NOT NULL,
    specialist_type ENUM('work','people','finance','compliance','mentalcare','education','customer') NOT NULL,
    intent_detected VARCHAR(200) COMMENT '検出された意図',
    query_summary TEXT COMMENT 'ユーザー発話の要約',
    response_summary TEXT COMMENT '専門AIの応答要約',
    tokens_used INT DEFAULT 0,
    latency_ms INT DEFAULT 0,
    was_helpful TINYINT(1) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_org_type (organization_id, specialist_type),
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 10. システム全体の機能有効化設定
-- ============================================
CREATE TABLE IF NOT EXISTS ai_feature_flags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    feature_number INT NOT NULL COMMENT '機能番号 1-33',
    feature_name VARCHAR(200) NOT NULL,
    status ENUM('disabled','beta','enabled') DEFAULT 'disabled',
    description TEXT,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_feature (feature_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 11. デフォルト専門AIプロンプト（システム管理用）
-- ============================================
CREATE TABLE IF NOT EXISTS ai_specialist_defaults (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    specialist_type ENUM('work','people','finance','compliance','mentalcare','education','customer') NOT NULL,
    default_prompt TEXT NOT NULL COMMENT 'デフォルトシステムプロンプト',
    default_config JSON COMMENT 'デフォルト設定JSON',
    intent_keywords JSON COMMENT '振り分け用キーワード・意図分類ルール',
    version INT DEFAULT 1,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_type (specialist_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
