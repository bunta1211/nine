<?php
/**
 * グループ招待リンク参加ページ
 */
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/design_loader.php';
require_once __DIR__ . '/includes/lang.php';

$invite_code = $_GET['code'] ?? '';
$invite_token = $_GET['token'] ?? '';
$error = '';
$success = '';
$group = null;

if (empty($invite_code) && empty($invite_token)) {
    $error = __('invalid_invite_link') ?: '無効な招待リンクです';
} else {
    $pdo = getDB();
    
    // invite_tokenカラムを追加（存在しない場合）
    try {
        $pdo->exec("ALTER TABLE conversations ADD COLUMN invite_token VARCHAR(64) NULL");
    } catch (Exception $e) {
        // カラムが既に存在する場合は無視
    }
    
    // 招待コードまたはトークンからグループを取得
    if (!empty($invite_token)) {
        $stmt = $pdo->prepare("SELECT id, name, type FROM conversations WHERE invite_token = ? AND deleted_at IS NULL AND type = 'group'");
        $stmt->execute([$invite_token]);
    } else {
        $stmt = $pdo->prepare("SELECT id, name, type FROM conversations WHERE invite_code = ? AND deleted_at IS NULL AND type = 'group'");
        $stmt->execute([$invite_code]);
    }
    $group = $stmt->fetch();
    
    if (!$group) {
        $error = __('group_not_found') ?: 'グループが見つかりません。リンクが無効か、期限切れの可能性があります。';
    } elseif (isLoggedIn()) {
        $user_id = $_SESSION['user_id'];
        
        // 既にメンバーかチェック
        $stmt = $pdo->prepare("SELECT id FROM conversation_members WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL");
        $stmt->execute([$group['id'], $user_id]);
        
        if ($stmt->fetch()) {
            // 既にメンバーならチャットへリダイレクト（移転後も絶対URLで）
            $base = function_exists('getBaseUrl') ? getBaseUrl() : '';
            header('Location: ' . ($base !== '' ? $base . '/' : '') . 'chat.php?c=' . $group['id']);
            exit;
        }
        
        // POSTで参加リクエスト
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join'])) {
            // メンバー数チェック（50人まで）
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM conversation_members WHERE conversation_id = ? AND left_at IS NULL");
            $stmt->execute([$group['id']]);
            $memberCount = (int)$stmt->fetch()['count'];
            
            if ($memberCount >= 50) {
                $error = __('group_full') ?: 'このグループは満員です（最大50人）';
            } else {
                // 参加
                $pdo->prepare("
                    INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
                    VALUES (?, ?, 'member', NOW())
                    ON DUPLICATE KEY UPDATE left_at = NULL, joined_at = NOW()
                ")->execute([$group['id'], $user_id]);
                
                // チャットへリダイレクト（移転後も絶対URLで）
                $base = function_exists('getBaseUrl') ? getBaseUrl() : '';
                header('Location: ' . ($base !== '' ? $base . '/' : '') . 'chat.php?c=' . $group['id']);
                exit;
            }
        }
    }
}

$currentLang = getCurrentLanguage();
$designSettings = [];
if (isLoggedIn()) {
    $pdo = getDB();
    $designSettings = getDesignSettings($pdo, $_SESSION['user_id']);
}
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $currentLang === 'en' ? 'Join Group' : ($currentLang === 'zh' ? '加入群组' : 'グループに参加') ?> - Social9</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Noto Sans JP', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 400px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
        }
        .group-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 24px;
            color: #1a1a2e;
            margin-bottom: 8px;
        }
        .group-name {
            font-size: 20px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 20px;
        }
        .description {
            color: #666;
            font-size: 14px;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        .btn {
            display: inline-block;
            padding: 14px 40px;
            border-radius: 30px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            background: #f0f0f0;
            color: #333;
        }
        .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .login-prompt {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .login-prompt p {
            color: #666;
            font-size: 14px;
            margin-bottom: 15px;
        }
        .login-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        .login-link:hover {
            text-decoration: underline;
        }
        @media (max-width: 768px) {
            body { padding: 12px; }
            .card { padding: 24px 20px; max-width: 100%; }
            .btn { min-height: 44px; font-size: 16px; -webkit-tap-highlight-color: transparent; touch-action: manipulation; }
        }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
            <a href="chat.php" class="btn btn-secondary"><?= $currentLang === 'en' ? 'Go to Chat' : ($currentLang === 'zh' ? '前往聊天' : 'チャットへ') ?></a>
        <?php elseif ($group): ?>
            <div class="group-icon">👥</div>
            <h1><?= $currentLang === 'en' ? 'Join Group' : ($currentLang === 'zh' ? '加入群组' : 'グループに参加') ?></h1>
            <div class="group-name"><?= htmlspecialchars($group['name']) ?></div>
            <p class="description">
                <?= $currentLang === 'en' 
                    ? 'You have been invited to join this group. Click the button below to join.'
                    : ($currentLang === 'zh' 
                        ? '您已被邀请加入此群组。点击下方按钮加入。'
                        : 'このグループに招待されています。下のボタンをクリックして参加してください。') ?>
            </p>
            
            <?php if (isLoggedIn()): ?>
                <form method="post">
                    <button type="submit" name="join" class="btn btn-primary">
                        <?= $currentLang === 'en' ? 'Join Group' : ($currentLang === 'zh' ? '加入群组' : 'グループに参加') ?>
                    </button>
                </form>
            <?php else: ?>
                <div class="login-prompt">
                    <p><?= $currentLang === 'en' 
                        ? 'Please log in to join this group.'
                        : ($currentLang === 'zh' 
                            ? '请登录以加入此群组。'
                            : 'グループに参加するにはログインしてください。') ?></p>
                    <?php 
                    $redirectUrl = 'join_group.php?' . (!empty($invite_token) ? 'token=' . $invite_token : 'code=' . $invite_code);
                    ?>
                    <a href="index.php?redirect=<?= urlencode($redirectUrl) ?>" class="btn btn-primary">
                        <?= $currentLang === 'en' ? 'Log In' : ($currentLang === 'zh' ? '登录' : 'ログイン') ?>
                    </a>
                    <div style="margin-top: 15px;">
                        <a href="register.php?redirect=<?= urlencode($redirectUrl) ?>" class="login-link">
                            <?= $currentLang === 'en' ? 'New user? Register here' : ($currentLang === 'zh' ? '新用户？在此注册' : '新規登録はこちら') ?>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>


