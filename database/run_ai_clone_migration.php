<?php
/**
 * AIクローン用マイグレーション実行スクリプト（CLI専用・1回限り）
 */
if (php_sapi_name() !== 'cli') die('CLI only');

require_once dirname(__DIR__) . '/config/database.php';
$pdo = getDB();

$statements = [
    "CREATE TABLE IF NOT EXISTS user_ai_judgment_folders (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        parent_id INT NULL COMMENT 'NULLならルート直下',
        name VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_parent (user_id, parent_id),
        INDEX idx_user_sort (user_id, sort_order),
        CONSTRAINT fk_judgment_folder_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_judgment_folder_parent FOREIGN KEY (parent_id) REFERENCES user_ai_judgment_folders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS user_ai_judgment_items (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        folder_id INT NOT NULL,
        user_id INT NOT NULL,
        title VARCHAR(500) NOT NULL DEFAULT '',
        content TEXT NULL,
        file_path VARCHAR(1000) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_folder_sort (folder_id, sort_order),
        INDEX idx_user (user_id),
        CONSTRAINT fk_judgment_item_folder FOREIGN KEY (folder_id) REFERENCES user_ai_judgment_folders(id) ON DELETE CASCADE,
        CONSTRAINT fk_judgment_item_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS user_ai_reply_suggestions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        conversation_id INT NOT NULL,
        message_id BIGINT NOT NULL,
        suggested_content TEXT NOT NULL,
        final_content TEXT NULL,
        sent_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_created (user_id, created_at),
        INDEX idx_conv_message (conversation_id, message_id),
        CONSTRAINT fk_reply_sugg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

$alterStatements = [
    "ALTER TABLE user_ai_settings ADD COLUMN conversation_memory_summary TEXT NULL COMMENT '会話記憶の要約JSON'",
    "ALTER TABLE user_ai_settings ADD COLUMN clone_training_language VARCHAR(10) DEFAULT 'ja' COMMENT '訓練・返信の言語 ja/en/zh'",
    "ALTER TABLE user_ai_settings ADD COLUMN clone_auto_reply_enabled TINYINT(1) DEFAULT 0 COMMENT 'AI自動返信 1=ON'",
];

echo "=== AI Clone Migration (tables) ===\n";
foreach ($statements as $i => $sql) {
    $label = substr(trim($sql), 0, 65);
    echo ($i + 1) . ". {$label}... ";
    try {
        $pdo->exec($sql);
        echo "OK\n";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'already exists') !== false) {
            echo "SKIP (already exists)\n";
        } else {
            echo "ERROR: {$msg}\n";
        }
    }
}
echo "=== AI Clone Migration (user_ai_settings columns) ===\n";
foreach ($alterStatements as $i => $sql) {
    $label = substr(trim($sql), 0, 65);
    echo ($i + 1) . ". {$label}... ";
    try {
        $pdo->exec($sql);
        echo "OK\n";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate column') !== false) {
            echo "SKIP (already exists)\n";
        } else {
            echo "ERROR: {$msg}\n";
        }
    }
}
echo "=== Done ===\n";
