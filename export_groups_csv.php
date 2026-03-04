<?php
/**
 * グループとメンバーのCSVエクスポート
 * 
 * このファイルは登録されている全グループとそのメンバーをCSV形式でダウンロードします。
 */

// エラー表示を有効化（デバッグ用）
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// データベース接続
try {
    require_once __DIR__ . '/includes/db.php';
    $pdo = getDB(); // getDB()関数でPDO接続を取得
} catch (Exception $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

// ログインチェック（管理者のみアクセス可能にする場合はここで制限）
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// ============================================
// CSVダウンロードモード
// ============================================
if (isset($_GET['download'])) {
    // CSVヘッダー設定
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="groups_members_' . date('Y-m-d_His') . '.csv"');
    
    // BOM（Excel用）
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    
    // ヘッダー行
    fputcsv($output, [
        'group_id',           // グループID
        'group_name',         // グループ名
        'group_type',         // 種類（dm/group）
        'group_description',  // グループ説明
        'group_created_at',   // グループ作成日時
        'member_user_id',     // メンバーのユーザーID
        'member_email',       // メンバーのメールアドレス
        'member_display_name',// メンバーの表示名
        'member_role',        // メンバーの役割（admin/member/viewer）
        'member_joined_at',   // メンバーの参加日時
        'member_left_at',     // メンバーの退出日時（NULLなら現在も参加中）
    ]);
    
    // データ取得
    $sql = "
        SELECT 
            c.id AS group_id,
            c.name AS group_name,
            c.type AS group_type,
            c.description AS group_description,
            c.created_at AS group_created_at,
            u.id AS member_user_id,
            u.email AS member_email,
            u.display_name AS member_display_name,
            cm.role AS member_role,
            cm.joined_at AS member_joined_at,
            cm.left_at AS member_left_at
        FROM conversations c
        LEFT JOIN conversation_members cm ON c.id = cm.conversation_id
        LEFT JOIN users u ON cm.user_id = u.id
        ORDER BY c.id, cm.role DESC, u.display_name
    ";
    
    $stmt = $pdo->query($sql);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['group_id'],
            $row['group_name'],
            $row['group_type'],
            $row['group_description'],
            $row['group_created_at'],
            $row['member_user_id'],
            $row['member_email'],
            $row['member_display_name'],
            $row['member_role'],
            $row['member_joined_at'],
            $row['member_left_at'],
        ]);
    }
    
    fclose($output);
    exit;
}

// ============================================
// プレビュー画面
// ============================================
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>グループ・メンバーCSVエクスポート - Social9</title>
    <style>
        * { box-sizing: border-box; }
        body { 
            font-family: 'Hiragino Sans', 'Meiryo', sans-serif; 
            padding: 20px; 
            max-width: 1200px; 
            margin: 0 auto;
            background: #f5f5f5;
        }
        h1 { color: #333; border-bottom: 2px solid #22c55e; padding-bottom: 10px; }
        h2 { color: #555; margin-top: 30px; }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #22c55e;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
            margin: 10px 0;
        }
        .btn:hover { background: #16a34a; }
        .btn-secondary {
            background: #6b7280;
        }
        .btn-secondary:hover { background: #4b5563; }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 20px 0;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        th, td { 
            padding: 10px; 
            border: 1px solid #ddd; 
            text-align: left;
            font-size: 13px;
        }
        th { 
            background: #f8f9fa; 
            font-weight: 600;
            position: sticky;
            top: 0;
        }
        tr:nth-child(even) { background: #fafafa; }
        tr:hover { background: #f0f9ff; }
        .group-row { background: #e8f5e9 !important; font-weight: 600; }
        .stats { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); 
            gap: 15px; 
            margin: 20px 0;
        }
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-value { font-size: 28px; font-weight: bold; color: #22c55e; }
        .stat-label { font-size: 12px; color: #666; margin-top: 5px; }
        pre {
            background: #1e1e2e;
            color: #cdd6f4;
            padding: 20px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 13px;
            line-height: 1.5;
        }
        code { font-family: 'Consolas', 'Monaco', monospace; }
        .info-box {
            background: #e0f2fe;
            border-left: 4px solid #0ea5e9;
            padding: 15px;
            margin: 20px 0;
            border-radius: 0 8px 8px 0;
        }
    </style>
</head>
<body>
    <h1>📊 グループ・メンバーCSVエクスポート</h1>
    
    <?php
    // 統計情報
    try {
        $groupCount = $pdo->query("SELECT COUNT(*) FROM conversations")->fetchColumn();
        $memberCount = $pdo->query("SELECT COUNT(*) FROM conversation_members WHERE left_at IS NULL")->fetchColumn();
        $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    } catch (PDOException $e) {
        echo "<div style='color:red;padding:20px;background:#fee;border:1px solid #f00;margin:20px 0;'>";
        echo "<strong>データベースエラー:</strong> " . htmlspecialchars($e->getMessage());
        echo "</div>";
        $groupCount = $memberCount = $userCount = 0;
    }
    ?>
    
    <div class="stats">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($groupCount) ?></div>
            <div class="stat-label">グループ数</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($memberCount) ?></div>
            <div class="stat-label">メンバー登録数</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($userCount) ?></div>
            <div class="stat-label">ユーザー数</div>
        </div>
    </div>
    
    <a href="?download=1" class="btn">📥 CSVをダウンロード</a>
    <a href="chat.php" class="btn btn-secondary">← チャットに戻る</a>
    
    <h2>📋 データプレビュー（最新20件）</h2>
    
    <?php
    $previewSql = "
        SELECT 
            c.id AS group_id,
            c.name AS group_name,
            c.type AS group_type,
            u.display_name AS member_display_name,
            cm.role AS member_role,
            cm.joined_at AS member_joined_at
        FROM conversations c
        LEFT JOIN conversation_members cm ON c.id = cm.conversation_id AND cm.left_at IS NULL
        LEFT JOIN users u ON cm.user_id = u.id
        ORDER BY c.id DESC, cm.role DESC
        LIMIT 50
    ";
    try {
        $previewData = $pdo->query($previewSql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        echo "<div style='color:red;padding:20px;background:#fee;border:1px solid #f00;margin:20px 0;'>";
        echo "<strong>プレビュークエリエラー:</strong> " . htmlspecialchars($e->getMessage());
        echo "</div>";
        $previewData = [];
    }
    ?>
    
    <table>
        <thead>
            <tr>
                <th>グループID</th>
                <th>グループ名</th>
                <th>種類</th>
                <th>メンバー名</th>
                <th>役割</th>
                <th>参加日時</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $lastGroupId = null;
            foreach ($previewData as $row): 
                $isNewGroup = $lastGroupId !== $row['group_id'];
                $lastGroupId = $row['group_id'];
            ?>
            <tr class="<?= $isNewGroup ? 'group-row' : '' ?>">
                <td><?= $isNewGroup ? $row['group_id'] : '' ?></td>
                <td><?= $isNewGroup ? htmlspecialchars($row['group_name'] ?? '(無題)') : '' ?></td>
                <td><?= $isNewGroup ? $row['group_type'] : '' ?></td>
                <td><?= htmlspecialchars($row['member_display_name'] ?? '-') ?></td>
                <td><?= $row['member_role'] ?? '-' ?></td>
                <td><?= $row['member_joined_at'] ?? '-' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <h2>📖 CSV形式の説明（他のAI向け）</h2>
    
    <div class="info-box">
        <p><strong>このCSVは「Social9」というチャットアプリのグループとメンバー情報をエクスポートしたものです。</strong></p>
    </div>
    
    <h3>CSVカラム定義</h3>
    <pre><code>group_id            : グループの一意識別子（INT）
group_name          : グループ名（VARCHAR(100)、NULLの場合はDMや無題のグループ）
group_type          : グループ種類（'dm' = 1対1チャット, 'group' = グループチャット）
group_description   : グループ説明（TEXT、オプション）
group_created_at    : グループ作成日時（DATETIME）
member_user_id      : メンバーのユーザーID（INT）
member_email        : メンバーのメールアドレス（VARCHAR(255)）
member_display_name : メンバーの表示名（VARCHAR(100)）
member_role         : メンバーの役割（'admin' = 管理者, 'member' = 一般, 'viewer' = 閲覧者）
member_joined_at    : グループ参加日時（DATETIME）
member_left_at      : グループ退出日時（DATETIME、NULLなら現在も参加中）</code></pre>

    <h3>データベース構造</h3>
    <pre><code>-- グループ/会話テーブル
CREATE TABLE conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('dm', 'group') NOT NULL DEFAULT 'group',
    name VARCHAR(100) DEFAULT NULL,
    description TEXT,
    icon VARCHAR(255) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- グループメンバーテーブル
CREATE TABLE conversation_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,  -- conversations.id への外部キー
    user_id INT NOT NULL,          -- users.id への外部キー
    role ENUM('admin', 'member', 'viewer') DEFAULT 'member',
    is_pinned TINYINT(1) DEFAULT 0,
    is_muted TINYINT(1) DEFAULT 0,
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    left_at DATETIME DEFAULT NULL,  -- NULLなら現在も参加中
    UNIQUE KEY unique_member (conversation_id, user_id)
);

-- ユーザーテーブル
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    -- ... その他のカラム
);</code></pre>

    <h3>CSVインポート時の注意点</h3>
    <pre><code>1. CSVはBOM付きUTF-8形式です
2. 同じgroup_idを持つ行は同一グループのメンバーです
3. member_left_atがNULL（空）の場合、そのメンバーは現在もグループに参加中です
4. group_typeが'dm'の場合は1対1チャット、'group'はグループチャットです
5. member_roleが'admin'のメンバーはグループ管理者権限を持ちます

インポート手順（推奨）:
1. まずconversationsテーブルにグループを作成
2. 次にusersテーブルにユーザーを確認/作成
3. 最後にconversation_membersテーブルでメンバー関係を登録</code></pre>

    <h3>インポート用SQLサンプル</h3>
    <pre><code>-- グループを作成
INSERT INTO conversations (id, name, type, description, created_at)
VALUES (1, 'サンプルグループ', 'group', '説明文', '2024-01-01 00:00:00');

-- メンバーを追加
INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
VALUES 
    (1, 100, 'admin', '2024-01-01 00:00:00'),
    (1, 101, 'member', '2024-01-02 00:00:00'),
    (1, 102, 'member', '2024-01-03 00:00:00');</code></pre>

    <p style="margin-top: 30px; color: #666;">
        <a href="chat.php">← チャットに戻る</a>
    </p>
</body>
</html>

