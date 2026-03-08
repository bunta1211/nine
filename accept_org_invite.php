<?php
/**
 * 組織招待承諾・パスワード設定画面
 * 招待メールのリンク先。トークン検証後、パスワードを設定して送信すると
 * users.password_hash を更新し、organization_members.accepted_at を設定して正式所属とする。
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
$cancelled = false;

$token = $_GET['token'] ?? $_POST['token'] ?? '';

$is_existing_user = false; // パスワード設定済み＝既存ユーザー（マスター計画 4.1: 統合UIを表示）

if ($token) {
    $stmt = $pdo->prepare("
        SELECT prt.*, u.email, u.password_hash
        FROM password_reset_tokens prt
        INNER JOIN users u ON prt.user_id = u.id
        WHERE prt.token = ? AND prt.used_at IS NULL AND prt.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $reset_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($reset_data) {
        $valid_token = true;
        $is_existing_user = !empty(trim((string)($reset_data['password_hash'] ?? '')));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';

            // 既存ユーザー: 統合する / キャンセル（マスター計画 4.1）
            if ($is_existing_user) {
                if ($action === 'merge') {
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?");
                        $stmt->execute([$reset_data['id']]);
                        $chk = $pdo->query("SHOW COLUMNS FROM organization_members LIKE 'accepted_at'");
                        if ($chk && $chk->rowCount() > 0) {
                            $stmt = $pdo->prepare("UPDATE organization_members SET accepted_at = NOW() WHERE user_id = ? AND accepted_at IS NULL");
                            $stmt->execute([$reset_data['user_id']]);
                        }
                        $pdo->commit();
                        $success = true;
                        $message = '組織への統合が完了しました。ログインしてご利用ください。';
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $message = 'エラーが発生しました。もう一度お試しください。';
                    }
                } elseif ($action === 'cancel') {
                    try {
                        $stmt = $pdo->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?");
                        $stmt->execute([$reset_data['id']]);
                        $valid_token = false;
                        $cancelled = true;
                        $message = '招待をキャンセルしました。';
                    } catch (Exception $e) {
                        $message = 'エラーが発生しました。';
                    }
                }
            } else {
                // 新規ユーザー: パスワード設定
            $password = $_POST['password'] ?? '';
            $password_confirm = $_POST['password_confirm'] ?? '';

            if (strlen($password) < 8) {
                $message = 'パスワードは8文字以上で入力してください。';
            } elseif ($password !== $password_confirm) {
                $message = 'パスワードが一致しません。';
            } else {
                $pdo->beginTransaction();
                try {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$password_hash, $reset_data['user_id']]);

                    $stmt = $pdo->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?");
                    $stmt->execute([$reset_data['id']]);

                    // 組織招待承諾: accepted_at を設定して正式所属にする
                    try {
                        $chk = $pdo->query("SHOW COLUMNS FROM organization_members LIKE 'accepted_at'");
                        if ($chk && $chk->rowCount() > 0) {
                            $stmt = $pdo->prepare("UPDATE organization_members SET accepted_at = NOW() WHERE user_id = ? AND accepted_at IS NULL");
                            $stmt->execute([$reset_data['user_id']]);
                        }
                    } catch (Exception $e) {
                        // accepted_at が無い環境ではスキップ
                    }

                    $pdo->commit();
                    $success = true;
                    $message = 'パスワードを設定し、組織への参加が完了しました。ログインしてご利用ください。';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = 'エラーが発生しました。もう一度お試しください。';
                }
            }
            }
        }
        // GET のときは message を上書きしない（統合UI or パスワード設定フォームを表示）
    }
} else {
    $message = 'トークンがありません。';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>組織への参加 - パスワード設定 | Social9</title>
    <link rel="stylesheet" href="assets/css/common.css">
    <link rel="stylesheet" href="assets/css/mobile.css?v=<?= file_exists(__DIR__.'/assets/css/mobile.css') ? filemtime(__DIR__.'/assets/css/mobile.css') : '1' ?>">
    <style>
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); font-family: 'Hiragino Sans', 'Meiryo', sans-serif; }
        .card { background: white; border-radius: 16px; padding: 40px; max-width: 400px; width: 90%; box-shadow: var(--shadow-xl); }
        .logo { text-align: center; margin-bottom: 24px; }
        .logo h1 { font-size: 28px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        h2 { font-size: 20px; margin-bottom: 8px; text-align: center; }
        .subtitle { color: var(--text-muted); text-align: center; margin-bottom: 24px; font-size: 14px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 14px; font-weight: 500; margin-bottom: 6px; }
        .form-group input { width: 100%; padding: 12px 16px; border: 1px solid var(--border-light); border-radius: 8px; font-size: 14px; }
        .form-group input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        .form-hint { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
        .btn { width: 100%; padding: 14px; background: var(--gradient-primary); color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 500; cursor: pointer; text-decoration: none; display: block; text-align: center; }
        .btn:hover { opacity: 0.9; }
        .message { padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; text-align: center; }
        .message.success { background: #dcfce7; color: #166534; }
        .message.error { background: #fee2e2; color: #991b1b; }
        .back-link { display: block; text-align: center; margin-top: 20px; color: var(--text-muted); text-decoration: none; font-size: 14px; }
        .back-link:hover { color: var(--primary); }
        .icon { font-size: 48px; text-align: center; margin-bottom: 16px; }
        .btn-group { display: flex; gap: 12px; margin-top: 20px; }
        .btn-group .btn { flex: 1; }
        .btn-secondary { background: #6b7280; color: white; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo"><h1>☆ Social9</h1></div>

        <?php if ($success): ?>
        <div class="icon">✅</div>
        <h2>参加完了</h2>
        <div class="message success"><?= htmlspecialchars($message) ?></div>
        <a href="index.php" class="btn">ログインする</a>
        <a href="index.php" class="back-link">← ログイン画面に戻る</a>

        <?php elseif ($cancelled): ?>
        <div class="icon">ℹ️</div>
        <h2>キャンセルしました</h2>
        <div class="message success"><?= htmlspecialchars($message) ?></div>
        <a href="index.php" class="back-link">← ログイン画面に戻る</a>

        <?php elseif ($valid_token && $is_existing_user): ?>
        <h2>組織への統合</h2>
        <p class="subtitle">このアドレスはすでに登録されています。組織に統合しますか？</p>
        <?php if ($message): ?>
        <div class="message error"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <div class="btn-group">
                <button type="submit" name="action" value="merge" class="btn">統合する</button>
                <button type="submit" name="action" value="cancel" class="btn btn-secondary">キャンセル</button>
            </div>
        </form>

        <?php elseif ($valid_token): ?>
        <h2>パスワードを設定して承諾</h2>
        <p class="subtitle"><?= htmlspecialchars($reset_data['email']) ?></p>
        <?php if ($message): ?>
        <div class="message error"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <div class="form-group">
                <label>パスワード <span class="required">*</span></label>
                <input type="password" name="password" required minlength="8">
                <div class="form-hint">8文字以上で入力してください</div>
            </div>
            <div class="form-group">
                <label>パスワード（確認） <span class="required">*</span></label>
                <input type="password" name="password_confirm" required>
            </div>
            <button type="submit" class="btn">パスワードを設定して参加する</button>
        </form>

        <?php else: ?>
        <div class="icon">❌</div>
        <h2>リンクが無効です</h2>
        <div class="message error"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if (!$success && !$cancelled): ?>
        <a href="index.php" class="back-link">← ログイン画面に戻る</a>
        <?php endif; ?>
    </div>
</body>
</html>
