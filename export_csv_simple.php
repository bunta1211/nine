<?php
/**
 * グループとメンバーのCSVエクスポート（簡易版）
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

echo "<h1>CSVエクスポート（簡易版）</h1>";

// DB接続
try {
    require_once __DIR__ . '/includes/db.php';
    $pdo = getDB(); // getDB()関数でPDO接続を取得
    echo "<p style='color:green'>✅ DB接続成功</p>";
} catch (Exception $e) {
    die("<p style='color:red'>❌ DB接続エラー: " . htmlspecialchars($e->getMessage()) . "</p>");
}

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    die("<p style='color:red'>❌ ログインが必要です。<a href='index.php'>ログイン</a></p>");
}
echo "<p style='color:green'>✅ ログイン済み (user_id: {$_SESSION['user_id']})</p>";

// テーブル確認
try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p>テーブル一覧: " . implode(', ', $tables) . "</p>";
    
    $hasConversations = in_array('conversations', $tables);
    $hasMembers = in_array('conversation_members', $tables);
    $hasUsers = in_array('users', $tables);
    
    echo "<p>" . ($hasConversations ? "✅" : "❌") . " conversations テーブル</p>";
    echo "<p>" . ($hasMembers ? "✅" : "❌") . " conversation_members テーブル</p>";
    echo "<p>" . ($hasUsers ? "✅" : "❌") . " users テーブル</p>";
    
} catch (PDOException $e) {
    die("<p style='color:red'>❌ テーブル確認エラー: " . htmlspecialchars($e->getMessage()) . "</p>");
}

// カウント
try {
    $groupCount = $pdo->query("SELECT COUNT(*) FROM conversations")->fetchColumn();
    $memberCount = $pdo->query("SELECT COUNT(*) FROM conversation_members")->fetchColumn();
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    
    echo "<p>グループ数: $groupCount, メンバー登録数: $memberCount, ユーザー数: $userCount</p>";
} catch (PDOException $e) {
    echo "<p style='color:red'>❌ カウントエラー: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// CSVダウンロードリンク
if (isset($_GET['download'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="groups_' . date('Ymd') . '.csv"');
    
    echo "\xEF\xBB\xBF"; // BOM
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['group_id', 'group_name', 'group_type', 'member_user_id', 'member_name', 'member_role']);
    
    $sql = "
        SELECT c.id, c.name, c.type, u.id AS uid, u.display_name, cm.role
        FROM conversations c
        LEFT JOIN conversation_members cm ON c.id = cm.conversation_id
        LEFT JOIN users u ON cm.user_id = u.id
        ORDER BY c.id
    ";
    
    foreach ($pdo->query($sql) as $row) {
        fputcsv($output, [$row['id'], $row['name'], $row['type'], $row['uid'], $row['display_name'], $row['role']]);
    }
    
    fclose($output);
    exit;
}

echo "<br><a href='?download=1' style='padding:10px 20px;background:#22c55e;color:white;text-decoration:none;border-radius:5px;'>📥 CSVダウンロード</a>";
echo "<br><br><a href='chat.php'>← チャットに戻る</a>";
?>

