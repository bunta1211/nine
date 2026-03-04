<?php
/**
 * パスワードリセット申請画面
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $message = 'メールアドレスを入力してください。';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '有効なメールアドレスを入力してください。';
    } else {
        // ユーザーを検索
        $stmt = $pdo->prepare("SELECT id, display_name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            // トークンを生成
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // 既存のトークンを無効化
            $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")->execute([$user['id']]);
            
            // 新しいトークンを保存
            $stmt = $pdo->prepare("
                INSERT INTO password_reset_tokens (user_id, token, expires_at, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$user['id'], $token, $expires_at]);
            
            // 実際の本番環境ではメールを送信
            // ここでは開発用にリンクを表示
            $reset_link = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . 
                          $_SERVER['HTTP_HOST'] . 
                          dirname($_SERVER['SCRIPT_NAME']) . 
                          '/reset_password.php?token=' . $token;
            
            $success = true;
            $message = 'パスワードリセットのリンクをメールで送信しました。';
            
            // 開発環境ではリンクを表示
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $message .= "<br><br><small>開発用リンク:<br><a href='{$reset_link}'>{$reset_link}</a></small>";
            }
        } else {
            // セキュリティのため、ユーザーが存在しなくても同じメッセージを表示
            $success = true;
            $message = 'パスワードリセットのリンクをメールで送信しました。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>パスワードをお忘れの方 | Social9</title>
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
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <h1>☆ Social9</h1>
        </div>
        
        <h2>パスワードをリセット</h2>
        <p class="subtitle">登録したメールアドレスを入力してください</p>
        
        <?php if ($message): ?>
        <div class="message <?= $success ? 'success' : 'error' ?>">
            <?= $message ?>
        </div>
        <?php endif; ?>
        
        <?php if (!$success): ?>
        <form method="POST">
            <div class="form-group">
                <label>メールアドレス</label>
                <input type="email" name="email" placeholder="example@email.com" required>
            </div>
            
            <button type="submit" class="btn">リセットリンクを送信</button>
        </form>
        <?php endif; ?>
        
        <a href="index.php" class="back-link">← ログイン画面に戻る</a>
    </div>
</body>
</html>
