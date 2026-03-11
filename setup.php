<?php
/**
 * Social9 セットアップスクリプト
 * データベースの作成と初期テーブルをセットアップします
 */

// エラー表示を有効化
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'social9';
$charset = 'utf8mb4';

echo "<h1>🚀 Social9 セットアップ</h1>";
echo "<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; background: #f5f7fa; }
    .success { color: #28a745; background: #d4edda; padding: 10px 15px; border-radius: 8px; margin: 10px 0; }
    .error { color: #dc3545; background: #f8d7da; padding: 10px 15px; border-radius: 8px; margin: 10px 0; }
    .info { color: #0c5460; background: #d1ecf1; padding: 10px 15px; border-radius: 8px; margin: 10px 0; }
    .step { background: white; padding: 20px; border-radius: 12px; margin: 20px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    h2 { color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
    pre { background: #2d3748; color: #e2e8f0; padding: 15px; border-radius: 8px; overflow-x: auto; }
    .btn { display: inline-block; background: #667eea; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; margin-top: 20px; }
    .btn:hover { background: #5a6fd6; }
</style>";

// ステップ1: MySQLに接続（データベースなし）
echo "<div class='step'>";
echo "<h2>ステップ 1: MySQL接続</h2>";

try {
    $pdo = new PDO("mysql:host=$host;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "<div class='success'>✅ MySQLに接続しました</div>";
} catch (PDOException $e) {
    echo "<div class='error'>❌ MySQL接続エラー: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<div class='info'>XAMPPのMySQLが起動していることを確認してください。</div>";
    exit;
}
echo "</div>";

// ステップ2: データベース作成
echo "<div class='step'>";
echo "<h2>ステップ 2: データベース作成</h2>";

try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<div class='success'>✅ データベース '$dbname' を作成しました（または既に存在）</div>";
} catch (PDOException $e) {
    echo "<div class='error'>❌ データベース作成エラー: " . htmlspecialchars($e->getMessage()) . "</div>";
    exit;
}
echo "</div>";

// ステップ3: データベースに接続
echo "<div class='step'>";
echo "<h2>ステップ 3: テーブル作成</h2>";

try {
    $pdo->exec("USE `$dbname`");
    
    // 主要テーブルを作成
    $sql = file_get_contents(__DIR__ . '/database/schema_complete.sql');
    
    if ($sql === false) {
        throw new Exception('schema_complete.sql が見つかりません');
    }
    
    // 複数のSQL文を実行
    $pdo->exec($sql);
    
    echo "<div class='success'>✅ テーブルを作成しました</div>";
    
} catch (PDOException $e) {
    // 既存テーブルのエラーは無視
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "<div class='info'>ℹ️ テーブルは既に存在します</div>";
    } else {
        echo "<div class='error'>❌ テーブル作成エラー: " . htmlspecialchars($e->getMessage()) . "</div>";
        echo "<div class='info'>個別にテーブルを作成します...</div>";
        
        // 個別テーブル作成を試行
        createTablesIndividually($pdo);
    }
}
echo "</div>";

// ステップ4: 確認
echo "<div class='step'>";
echo "<h2>ステップ 4: セットアップ確認</h2>";

try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<div class='success'>✅ " . count($tables) . " 個のテーブルが存在します</div>";
    echo "<pre>" . implode("\n", $tables) . "</pre>";
    
    // 管理者ユーザーを確認
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'system_admin'");
    $adminCount = $stmt->fetchColumn();
    
    if ($adminCount > 0) {
        echo "<div class='success'>✅ 管理者アカウントが存在します</div>";
    } else {
        // 管理者を作成
        $hash = password_hash('Admin123!', PASSWORD_DEFAULT);
        $pdo->prepare("
            INSERT INTO users (email, password_hash, display_name, role, auth_level, email_verified_at, created_at)
            VALUES ('admin@social9.jp', ?, 'システム管理者', 'system_admin', 3, NOW(), NOW())
        ")->execute([$hash]);
        echo "<div class='success'>✅ 管理者アカウントを作成しました</div>";
        echo "<div class='info'>メール: admin@social9.jp / パスワード: Admin123!<br>本番では <a href='admin/consolidate_system_admin.php'>admin/consolidate_system_admin.php</a> で saitanibunta@social9.jp（表示名・本名・パスワード設定）に統合できます。</div>";
    }
    
} catch (PDOException $e) {
    echo "<div class='error'>❌ 確認エラー: " . htmlspecialchars($e->getMessage()) . "</div>";
}
echo "</div>";

// 完了
echo "<div class='step' style='text-align: center;'>";
echo "<h2>🎉 セットアップ完了！</h2>";
echo "<p>Social9のセットアップが完了しました。</p>";
echo "<a href='index.php' class='btn'>ログイン画面へ</a>";
echo "</div>";

/**
 * 個別テーブル作成
 */
function createTablesIndividually($pdo) {
    $tables = [
        "users" => "
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                display_name VARCHAR(100) NOT NULL,
                avatar_path VARCHAR(500) DEFAULT NULL,
                bio TEXT,
                birth_date DATE,
                phone VARCHAR(20) DEFAULT NULL,
                prefecture VARCHAR(50) DEFAULT NULL,
                city VARCHAR(100) DEFAULT NULL,
                auth_level TINYINT DEFAULT 0,
                email_verified_at DATETIME DEFAULT NULL,
                phone_verified_at DATETIME DEFAULT NULL,
                identity_verified_at DATETIME DEFAULT NULL,
                role ENUM('system_admin', 'org_admin', 'user') DEFAULT 'user',
                is_minor TINYINT(1) DEFAULT 0,
                is_qualified_investor TINYINT(1) DEFAULT 0,
                online_status ENUM('online', 'away', 'offline') DEFAULT 'offline',
                last_seen DATETIME DEFAULT NULL,
                password_reset_token VARCHAR(100) DEFAULT NULL,
                password_reset_expires DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
        "conversations" => "
            CREATE TABLE IF NOT EXISTS conversations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type ENUM('dm', 'group') NOT NULL DEFAULT 'group',
                name VARCHAR(100) DEFAULT NULL,
                description TEXT,
                icon VARCHAR(255) DEFAULT NULL,
                is_organization TINYINT(1) DEFAULT 0,
                is_public TINYINT(1) DEFAULT 0,
                created_by INT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
        "conversation_members" => "
            CREATE TABLE IF NOT EXISTS conversation_members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT NOT NULL,
                user_id INT NOT NULL,
                role ENUM('admin', 'member', 'viewer') DEFAULT 'member',
                is_pinned TINYINT(1) DEFAULT 0,
                is_muted TINYINT(1) DEFAULT 0,
                joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                left_at DATETIME DEFAULT NULL,
                UNIQUE KEY unique_member (conversation_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
        "messages" => "
            CREATE TABLE IF NOT EXISTS messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT NOT NULL,
                sender_id INT NOT NULL,
                content TEXT,
                message_type ENUM('text', 'image', 'file', 'audio', 'video', 'system') DEFAULT 'text',
                scheduled_at DATETIME DEFAULT NULL,
                deleted_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
        "notifications" => "
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                type VARCHAR(50) NOT NULL,
                title VARCHAR(200) NOT NULL,
                content TEXT,
                related_type VARCHAR(50) DEFAULT NULL,
                related_id INT DEFAULT NULL,
                is_read TINYINT(1) DEFAULT 0,
                read_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        "
    ];
    
    foreach ($tables as $name => $sql) {
        try {
            $pdo->exec($sql);
            echo "<div class='success'>✅ テーブル '$name' を作成しました</div>";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "<div class='error'>❌ $name: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }
}
?>








