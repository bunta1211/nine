<?php
/**
 * メール認証確認画面
 * 仕様書: 41_認証レベル統合仕様.md
 */

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';

$pdo = getDB();
$message = '';
$success = false;

$token = $_GET['token'] ?? '';

if ($token) {
    // トークンを検証
    $stmt = $pdo->prepare("
        SELECT * FROM email_verification_tokens 
        WHERE token = ? AND used_at IS NULL AND expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $verification = $stmt->fetch();
    
    if ($verification) {
        // ユーザーのメール認証を完了
        $pdo->beginTransaction();
        try {
            // ユーザーを更新
            $stmt = $pdo->prepare("
                UPDATE users SET 
                    email_verified_at = NOW(),
                    auth_level = GREATEST(auth_level, 1)
                WHERE id = ?
            ");
            $stmt->execute([$verification['user_id']]);
            
            // トークンを使用済みに
            $stmt = $pdo->prepare("UPDATE email_verification_tokens SET used_at = NOW() WHERE id = ?");
            $stmt->execute([$verification['id']]);
            
            // オンボーディング進捗を更新
            $stmt = $pdo->prepare("
                UPDATE onboarding_progress SET email_verified = 1 WHERE user_id = ?
            ");
            $stmt->execute([$verification['user_id']]);
            
            $pdo->commit();
            
            $success = true;
            $message = 'メールアドレスの認証が完了しました！';
            
            // セッションの認証レベルも更新
            if (isLoggedIn() && $_SESSION['user_id'] == $verification['user_id']) {
                $_SESSION['auth_level'] = max($_SESSION['auth_level'] ?? 0, 1);
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = '認証処理中にエラーが発生しました。';
        }
    } else {
        $message = 'このリンクは無効または期限切れです。';
    }
} else {
    $message = '認証トークンがありません。';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>メール認証 | Social9</title>
    <link rel="stylesheet" href="assets/css/common.css">
    <link rel="stylesheet" href="assets/css/mobile.css?v=<?= file_exists(__DIR__.'/assets/css/mobile.css') ? filemtime(__DIR__.'/assets/css/mobile.css') : '1' ?>">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-secondary);
            font-family: 'Hiragino Sans', 'Meiryo', sans-serif;
        }
        .card {
            background: white;
            border-radius: 16px;
            padding: 40px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            box-shadow: var(--shadow-lg);
        }
        .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 24px;
            margin-bottom: 16px;
        }
        p {
            color: var(--text-secondary);
            margin-bottom: 24px;
            line-height: 1.6;
        }
        .success { color: #22c55e; }
        .error { color: #ef4444; }
        .btn {
            display: inline-block;
            padding: 12px 32px;
            background: var(--gradient-primary);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
        }
        .btn:hover { opacity: 0.9; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($success): ?>
        <div class="icon">✅</div>
        <h1 class="success">認証完了！</h1>
        <p><?= htmlspecialchars($message) ?></p>
        <a href="<?= isLoggedIn() ? 'chat.php' : 'index.php' ?>" class="btn">
            <?= isLoggedIn() ? 'チャットへ進む' : 'ログインする' ?>
        </a>
        <?php else: ?>
        <div class="icon">❌</div>
        <h1 class="error">認証失敗</h1>
        <p><?= htmlspecialchars($message) ?></p>
        <a href="index.php" class="btn">トップページへ</a>
        <?php endif; ?>
    </div>
</body>
</html>
