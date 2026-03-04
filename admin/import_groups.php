<?php
/**
 * グループ一括作成・メンバー割り当てスクリプト
 * user_registration.csv からグループとメンバーを一括登録
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

// 組織管理者チェック
requireOrgAdmin();

$pdo = getDB();

// Kenのuser_idを取得（管理者として設定）
$stmt = $pdo->prepare("SELECT id FROM users WHERE display_name = 'Ken' OR display_name LIKE '%Ken%' LIMIT 1");
$stmt->execute();
$ken = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ken) {
    // Kenがいない場合は現在のログインユーザーを管理者にする
    $adminUserId = $_SESSION['user_id'] ?? 1;
} else {
    $adminUserId = $ken['id'];
}

// 表示名からuser_idを取得するマッピングを作成
$stmt = $pdo->query("SELECT id, display_name FROM users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
$userMap = [];
foreach ($users as $user) {
    // 表示名の正規化（括弧内を削除、トリム）
    $normalizedName = preg_replace('/\s*\([^)]*\)/', '', $user['display_name']);
    $normalizedName = trim($normalizedName);
    $userMap[strtolower($normalizedName)] = $user['id'];
    $userMap[strtolower($user['display_name'])] = $user['id'];
}

// CSVファイルのパス
$csvFile = __DIR__ . '/../user_registration.csv';

if (!file_exists($csvFile)) {
    die('CSVファイルが見つかりません: ' . $csvFile);
}

$results = [
    'groups_created' => 0,
    'groups_skipped' => 0,
    'members_added' => 0,
    'members_skipped' => 0,
    'errors' => [],
    'group_details' => []
];

// グループとメンバーのデータを収集
$groupData = [];

$handle = fopen($csvFile, 'r');
// BOMをスキップ
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($handle);
}

$header = fgetcsv($handle); // ヘッダー行をスキップ

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) < 4) continue;
    
    $groupName = trim($row[1]);
    $displayName = trim($row[3]);
    
    if (empty($groupName) || empty($displayName)) continue;
    
    // グループ名でグループ化
    if (!isset($groupData[$groupName])) {
        $groupData[$groupName] = [];
    }
    
    // メンバーを追加（重複を避ける）
    $normalizedDisplayName = preg_replace('/\s*\([^)]*\)/', '', $displayName);
    $normalizedDisplayName = trim($normalizedDisplayName);
    
    if (!in_array($normalizedDisplayName, $groupData[$groupName])) {
        $groupData[$groupName][] = $normalizedDisplayName;
    }
}

fclose($handle);

// グループを作成してメンバーを追加
foreach ($groupData as $groupName => $members) {
    // グループが既に存在するかチェック
    $stmt = $pdo->prepare("SELECT id FROM conversations WHERE type = 'group' AND name = ?");
    $stmt->execute([$groupName]);
    $existingGroup = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingGroup) {
        $conversationId = $existingGroup['id'];
        $results['groups_skipped']++;
    } else {
        // 新規グループ作成
        try {
            $stmt = $pdo->prepare("INSERT INTO conversations (type, name, created_by) VALUES ('group', ?, ?)");
            $stmt->execute([$groupName, $adminUserId]);
            $conversationId = $pdo->lastInsertId();
            $results['groups_created']++;
        } catch (PDOException $e) {
            $results['errors'][] = "[グループ作成エラー: {$groupName}] " . $e->getMessage();
            continue;
        }
    }
    
    $memberCount = 0;
    
    // Kenを管理者として追加
    $stmt = $pdo->prepare("SELECT id FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
    $stmt->execute([$conversationId, $adminUserId]);
    if (!$stmt->fetch()) {
        try {
            $stmt = $pdo->prepare("INSERT INTO conversation_members (conversation_id, user_id, role) VALUES (?, ?, 'admin')");
            $stmt->execute([$conversationId, $adminUserId]);
            $memberCount++;
        } catch (PDOException $e) {
            // エラーを無視
        }
    }
    
    // メンバーを追加
    foreach ($members as $memberName) {
        $normalizedMemberName = strtolower($memberName);
        
        if (!isset($userMap[$normalizedMemberName])) {
            // ユーザーが見つからない場合はスキップ
            $results['members_skipped']++;
            continue;
        }
        
        $userId = $userMap[$normalizedMemberName];
        
        // 既にメンバーかチェック
        $stmt = $pdo->prepare("SELECT id FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
        $stmt->execute([$conversationId, $userId]);
        
        if ($stmt->fetch()) {
            $results['members_skipped']++;
            continue;
        }
        
        try {
            // Kenは管理者、それ以外はメンバー
            $role = ($userId == $adminUserId) ? 'admin' : 'member';
            $stmt = $pdo->prepare("INSERT INTO conversation_members (conversation_id, user_id, role) VALUES (?, ?, ?)");
            $stmt->execute([$conversationId, $userId, $role]);
            $results['members_added']++;
            $memberCount++;
        } catch (PDOException $e) {
            $results['errors'][] = "[メンバー追加エラー: {$groupName} - {$memberName}] " . $e->getMessage();
        }
    }
    
    $results['group_details'][] = [
        'name' => $groupName,
        'member_count' => count($members),
        'added_count' => $memberCount
    ];
}

// 作成されたグループ数を取得
$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM conversations WHERE type = 'group'");
$totalGroups = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>グループ一括作成結果</title>
    <style>
        body { font-family: sans-serif; padding: 40px; background: #f5f7fa; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; margin-bottom: 20px; }
        .stat { padding: 15px; margin: 10px 0; border-radius: 6px; }
        .success { background: #d4edda; color: #155724; }
        .skip { background: #fff3cd; color: #856404; }
        .info { background: #e8f4fd; color: #0c5460; }
        .error { background: #f8d7da; color: #721c24; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #f8f9fa; }
        .btn { display: inline-block; padding: 10px 20px; background: #3498db; color: #fff; text-decoration: none; border-radius: 6px; margin-top: 20px; }
        .btn:hover { background: #2980b9; }
    </style>
</head>
<body>
    <div class="container">
        <h1>✅ グループ一括作成完了</h1>
        
        <div class="stat info">
            <strong>データベース内グループ総数:</strong> <?= $totalGroups ?>グループ
        </div>
        
        <div class="stat success">
            <strong>新規作成グループ:</strong> <?= $results['groups_created'] ?>件
        </div>
        
        <div class="stat skip">
            <strong>スキップ（既存グループ）:</strong> <?= $results['groups_skipped'] ?>件
        </div>
        
        <div class="stat success">
            <strong>追加メンバー:</strong> <?= $results['members_added'] ?>件
        </div>
        
        <div class="stat skip">
            <strong>スキップ（既存/未登録メンバー）:</strong> <?= $results['members_skipped'] ?>件
        </div>
        
        <?php if (!empty($results['errors'])): ?>
        <div class="stat error">
            <strong>エラー:</strong>
            <ul>
                <?php foreach (array_slice($results['errors'], 0, 20) as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
                <?php if (count($results['errors']) > 20): ?>
                <li>... 他 <?= count($results['errors']) - 20 ?>件</li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>
        
        <h2>📁 作成されたグループ一覧（先頭30件）</h2>
        <table>
            <tr>
                <th>グループ名</th>
                <th>CSV上のメンバー数</th>
                <th>追加済みメンバー</th>
            </tr>
            <?php foreach (array_slice($results['group_details'], 0, 30) as $group): ?>
            <tr>
                <td><?= htmlspecialchars($group['name']) ?></td>
                <td><?= $group['member_count'] ?></td>
                <td><?= $group['added_count'] ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (count($results['group_details']) > 30): ?>
            <tr>
                <td colspan="3">... 他 <?= count($results['group_details']) - 30 ?>件</td>
            </tr>
            <?php endif; ?>
        </table>
        
        <a href="groups.php" class="btn">📁 グループ一覧へ</a>
        <a href="members.php" class="btn">👥 メンバー管理へ</a>
    </div>
</body>
</html>





