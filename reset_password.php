<?php
/**
 * パスワードリセット実行画面
 * 仕様書: 41_認証レベル統合仕様.md
 */

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: chat.php');
    exit;
}

$pdo = getDB();
$message = '';
$success = false;
$valid_token = false;

$token = $_GET['token'] ?? $_POST['token'] ?? '';

// トークンを検証
if ($token) {
    $stmt = $pdo->prepare("
        SELECT prt.*, u.email 
        FROM password_reset_tokens prt
        INNER JOIN users u ON prt.user_id = u.id
        WHERE prt.token = ? AND prt.used_at IS NULL AND prt.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $reset_data = $stmt->fetch();
    
    if ($reset_data) {
        $valid_token = true;
        
        // パスワード変更処理
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = $_POST['password'] ?? '';
            $password_confirm = $_POST['password_confirm'] ?? '';
            
            if (strlen($password) < 8) {
                $message = 'パスワードは8文字以上で入力してください。';
            } elseif ($password !== $password_confirm) {
                $message = 'パスワードが一致しません。';
            } else {
                $pdo->beginTransaction();
                try {
                    // パスワードを更新
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$password_hash, $reset_data['user_id']]);
                    
                    // トークンを使用済みに
                    $stmt = $pdo->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?");
                    $stmt->execute([$reset_data['id']]);
                    
                    $pdo->commit();
                    
                    $success = true;
                    $message = 'パスワードを変更しました。新しいパスワードでログインしてください。';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = 'エラーが発生しました。もう一度お試しください。';
                }
            }
        }
    } else {
        $message = 'このリンクは無効または期限切れです。';
    }
} else {
    $message = 'リセットトークンがありません。';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>パスワードリセット | Social9</title>
    <link rel="stylesheet" href="assets/css/common.css">
    <link rel="stylesheet" href="assets/css/mobile.css?v=<?= file_exists(__DIR__.'/assets/css/mobile.css') ? filemtime(__DIR__.'/assets/css/mobile.css') : '1' ?>">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Hiragino Sans', 'Meiryo', sans-serif;
        }
        .card {
            background: white;
            border-radius: 16px;
            padding: 40px;
            max-width: 400px;
            width: 90%;
            box-shadow: var(--shadow-xl);
        }
        .logo {
            text-align: center;
            margin-bottom: 24px;
        }
        .logo h1 {
            font-size: 28px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        h2 {
            font-size: 20px;
            margin-bottom: 8px;
            text-align: center;
        }
        .subtitle {
            color: var(--text-muted);
            text-align: center;
            margin-bottom: 24px;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 6px;
        }
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            font-size: 14px;
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .btn {
            width: 100%;
            padding: 14px;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
        }
        .btn:hover { opacity: 0.9; }
        .message {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: center;
        }
        .message.success { background: #dcfce7; color: #166534; }
        .message.error { background: #fee2e2; color: #991b1b; }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 14px;
        }
        .back-link:hover { color: var(--primary); }
        .icon { font-size: 48px; text-align: center; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <h1>☆ Social9</h1>
        </div>
        
        <?php if ($success): ?>
        <div class="icon">✅</div>
        <h2>パスワード変更完了</h2>
        <div class="message success"><?= htmlspecialchars($message) ?></div>
        <a href="index.php" class="btn" style="text-decoration:none;display:block;text-align:center;">ログインする</a>
        
        <?php elseif ($valid_token): ?>
        <h2>新しいパスワードを設定</h2>
        <p class="subtitle"><?= htmlspecialchars($reset_data['email']) ?></p>
        
        <?php if ($message): ?>
        <div class="message error"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            
            <div class="form-group">
                <label>新しいパスワード</label>
                <input type="password" name="password" required minlength="8">
                <div class="form-hint">8文字以上で入力してください</div>
            </div>
            
            <div class="form-group">
                <label>パスワード（確認）</label>
                <input type="password" name="password_confirm" required>
            </div>
            
            <button type="submit" class="btn">パスワードを変更</button>
        </form>
        
        <?php else: ?>
        <div class="icon">❌</div>
        <h2>リンクが無効です</h2>
        <div class="message error"><?= htmlspecialchars($message) ?></div>
        <a href="forgot_password.php" class="btn" style="text-decoration:none;display:block;text-align:center;">再度リセットを申請</a>
        <?php endif; ?>
        
        <a href="index.php" class="back-link">← ログイン画面に戻る</a>
    </div>
</body>
</html>
