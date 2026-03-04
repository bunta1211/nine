<?php
/**
 * メンバー一括登録スクリプト
 * user_master.csv からメンバーを一括登録
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

// 組織管理者チェック
requireOrgAdmin();

$pdo = getDB();

// CSVファイルのパス
$csvFile = __DIR__ . '/../data/user_master.csv';

if (!file_exists($csvFile)) {
    die('CSVファイルが見つかりません: ' . $csvFile);
}

$results = [
    'success' => 0,
    'skipped' => 0,
    'errors' => []
];

// CSVを読み込み
$handle = fopen($csvFile, 'r');
$header = fgetcsv($handle); // ヘッダー行をスキップ

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) < 6) continue;
    
    $id = $row[0];
    $fullName = $row[1];
    $displayName = $row[2];
    $email = $row[3];
    $password = $row[4];
    $isOrgAdmin = $row[5] ?? 0;
    
    // メールアドレスの重複チェック
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->fetch()) {
        // 既に存在する場合はスキップ
        $results['skipped']++;
        continue;
    }
    
    // パスワードハッシュ化
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // roleを設定
    $role = ($isOrgAdmin == 1) ? 'org_admin' : 'user';
    
    try {
        $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, display_name, role, auth_level) 
                               VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->execute([$email, $hashedPassword, $fullName, $displayName, $role]);
        $results['success']++;
    } catch (PDOException $e) {
        $results['errors'][] = "[$email] " . $e->getMessage();
    }
}

fclose($handle);

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>メンバー一括登録結果</title>
    <style>
        body { font-family: sans-serif; padding: 40px; background: #f5f7fa; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; margin-bottom: 20px; }
        .stat { padding: 15px; margin: 10px 0; border-radius: 6px; }
        .success { background: #d4edda; color: #155724; }
        .skip { background: #fff3cd; color: #856404; }
        .error { background: #f8d7da; color: #721c24; }
        .btn { display: inline-block; padding: 10px 20px; background: #3498db; color: #fff; text-decoration: none; border-radius: 6px; margin-top: 20px; }
        .btn:hover { background: #2980b9; }
    </style>
</head>
<body>
    <div class="container">
        <h1>✅ メンバー一括登録完了</h1>
        
        <div class="stat success">
            <strong>新規登録:</strong> <?= $results['success'] ?>件
        </div>
        
        <div class="stat skip">
            <strong>スキップ（既存）:</strong> <?= $results['skipped'] ?>件
        </div>
        
        <?php if (!empty($results['errors'])): ?>
        <div class="stat error">
            <strong>エラー:</strong>
            <ul>
                <?php foreach ($results['errors'] as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        
        <a href="members.php" class="btn">メンバー管理へ戻る</a>
    </div>
</body>
</html>





