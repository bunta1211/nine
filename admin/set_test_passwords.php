<?php
/**
 * 会話テスト用アカウントのパスワード設定
 * システム管理者（才谷文太）と奈良健太郎を別人格でログイン可能にする
 *
 * 実行: ブラウザで /admin/set_test_passwords.php にアクセス
 * または: php -f admin/set_test_passwords.php
 *
 * 設定されるアカウント:
 * - システム管理者 saitanibunta@social9.jp / cloverkids456（表示名「システム管理者」本名「才谷文太」）
 * - 奈良健太郎 narakenn1211@gmail.com / cloverkids456
 * - kyoko clover.shibatakyoko@gmail.com / clover123（ログインできない場合の再設定用）
 */
require_once __DIR__ . '/../config/database.php';

$pdo = getDB();

$accounts = [
    [
        'email' => 'saitanibunta@social9.jp',
        'password' => 'cloverkids456',
        'display_name' => 'システム管理者',
    ],
    [
        'email' => 'narakenn1211@gmail.com',
        'password' => 'cloverkids456',
        'display_name' => '奈良健太郎',
    ],
    [
        'email' => 'clover.shibatakyoko@gmail.com',
        'password' => 'clover123',
        'display_name' => 'kyoko',
    ],
];

$updated = 0;
$errors = [];

foreach ($accounts as $acc) {
    $hash = password_hash($acc['password'], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        UPDATE users 
        SET password_hash = ?, updated_at = NOW()
        WHERE email = ?
    ");
    try {
        $stmt->execute([$hash, $acc['email']]);
        if ($stmt->rowCount() > 0) {
            $updated++;
        } else {
            $errors[] = "ユーザーが見つかりません: {$acc['email']}";
        }
    } catch (PDOException $e) {
        $errors[] = "エラー ({$acc['email']}): " . $e->getMessage();
    }
}

// CLI実行時
if (php_sapi_name() === 'cli') {
    if ($updated > 0) {
        echo "OK: {$updated} 件のパスワードを更新しました。\n";
        foreach ($accounts as $a) {
            echo "  - {$a['display_name']} ({$a['email']})\n";
        }
    }
    if (!empty($errors)) {
        foreach ($errors as $e) {
            echo "ERROR: {$e}\n";
        }
    }
    exit(empty($errors) ? 0 : 1);
}

// Web実行時
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>テストアカウント設定</title></head>
<body>
<h1>会話テスト用アカウント パスワード設定</h1>
<?php if ($updated > 0): ?>
<p style="color:green;"><?= $updated ?> 件のパスワードを更新しました。</p>
<ul>
<?php foreach ($accounts as $a): ?>
<li><?= htmlspecialchars($a['display_name']) ?> (<?= htmlspecialchars($a['email']) ?>)</li>
<?php endforeach; ?>
</ul>
<p>以下の内容でログインできます:</p>
<table border="1" cellpadding="8">
<tr><th>表示名</th><th>メール</th><th>パスワード</th></tr>
<tr><td>システム管理者</td><td>saitanibunta@social9.jp</td><td>cloverkids456</td></tr>
<tr><td>奈良健太郎</td><td>narakenn1211@gmail.com</td><td>cloverkids456</td></tr>
<tr><td>kyoko</td><td>clover.shibatakyoko@gmail.com</td><td>clover123</td></tr>
</table>
<?php endif; ?>
<?php if (!empty($errors)): ?>
<p style="color:red;"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></p>
<?php endif; ?>
<p><a href="/index.php">ログイン画面へ</a></p>
</body>
</html>
