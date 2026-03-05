<?php
/**
 * 友達追加招待ページ
 * 招待リンクからアクセスした時に友達追加または新規登録を処理する
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/lang.php';

/**
 * 招待者と新規ユーザー間のDMチャットを作成
 */
function createDMWithInviter($pdo, $newUserId, $inviterId, $newUserName, $inviterName) {
    try {
        // 既存のDMがあるかチェック
        $stmt = $pdo->prepare("
            SELECT c.id
            FROM conversations c
            INNER JOIN conversation_members cm1 ON c.id = cm1.conversation_id AND cm1.user_id = ? AND cm1.left_at IS NULL
            INNER JOIN conversation_members cm2 ON c.id = cm2.conversation_id AND cm2.user_id = ? AND cm2.left_at IS NULL
            WHERE c.type = 'group'
            AND (SELECT COUNT(*) FROM conversation_members cm3 WHERE cm3.conversation_id = c.id AND cm3.left_at IS NULL) = 2
            LIMIT 1
        ");
        $stmt->execute([$newUserId, $inviterId]);
        $existingChat = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingChat) {
            // 既にDMがある場合は何もしない
            return (int)$existingChat['id'];
        }
        
        // 新しいDMを作成
        $pdo->beginTransaction();
        
        // 会話名は相手の名前（各ユーザーから見て相手の名前が表示される想定）
        $groupName = $newUserName; // 招待者から見た名前
        
        $stmt = $pdo->prepare("
            INSERT INTO conversations (type, name, created_by, created_at, updated_at)
            VALUES ('group', ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$groupName, $inviterId]);
        $conversationId = (int)$pdo->lastInsertId();
        
        // 両方のユーザーを管理者として追加
        $stmt = $pdo->prepare("
            INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
            VALUES (?, ?, 'admin', NOW())
        ");
        $stmt->execute([$conversationId, $inviterId]);
        $stmt->execute([$conversationId, $newUserId]);
        
        // 招待者からのウェルカムメッセージを自動送信
        $welcomeMessage = "{$newUserName}さん、Social9へようこそ！🎉";
        $stmt = $pdo->prepare("
            INSERT INTO messages (conversation_id, sender_id, content, message_type, created_at)
            VALUES (?, ?, ?, 'text', NOW())
        ");
        $stmt->execute([$conversationId, $inviterId, $welcomeMessage]);
        
        // 会話のupdated_atを更新
        $pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?")->execute([$conversationId]);
        
        $pdo->commit();
        
        return $conversationId;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('DM creation error: ' . $e->getMessage());
        return null;
    }
}

/**
 * ユーザーをグループに追加
 */
function addUserToGroup($pdo, $userId, $groupId, $inviterId, $userName) {
    try {
        // グループが存在するか確認
        $stmt = $pdo->prepare("SELECT id, name FROM conversations WHERE id = ?");
        $stmt->execute([$groupId]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$group) {
            error_log("Group not found: {$groupId}");
            return false;
        }
        
        // 既にメンバーかチェック
        $stmt = $pdo->prepare("
            SELECT id FROM conversation_members 
            WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL
        ");
        $stmt->execute([$groupId, $userId]);
        if ($stmt->fetch()) {
            // 既にメンバー
            return true;
        }
        
        // グループにメンバーとして追加（roleは'member'）
        $stmt = $pdo->prepare("
            INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
            VALUES (?, ?, 'member', NOW())
        ");
        $stmt->execute([$groupId, $userId]);
        
        // システムメッセージを送信
        $systemMessage = "{$userName}さんがグループに参加しました";
        $stmt = $pdo->prepare("
            INSERT INTO messages (conversation_id, sender_id, content, message_type, created_at)
            VALUES (?, ?, ?, 'system', NOW())
        ");
        $stmt->execute([$groupId, $inviterId, $systemMessage]);
        
        // 会話のupdated_atを更新
        $pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?")->execute([$groupId]);
        
        return true;
    } catch (Exception $e) {
        error_log('Add to group error: ' . $e->getMessage());
        return false;
    }
}

$inviter_id = isset($_GET['u']) ? (int)$_GET['u'] : 0;
$inviteToken = isset($_GET['token']) ? trim($_GET['token']) : '';
$error = '';
$success = '';
$inviter = null;
$invitation = null;
$showRegistrationForm = false;

$pdo = getDB();

// トークンベースの招待をチェック
if ($inviteToken) {
    $stmt = $pdo->prepare("
        SELECT i.*, u.display_name as inviter_name, u.avatar as inviter_avatar
        FROM invitations i
        JOIN users u ON i.inviter_id = u.id
        WHERE i.token = ? AND i.status = 'pending' AND i.expires_at > NOW()
    ");
    $stmt->execute([$inviteToken]);
    $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($invitation) {
        $inviter_id = (int)$invitation['inviter_id'];
        $inviter = [
            'id' => $inviter_id,
            'display_name' => $invitation['inviter_name'],
            'avatar' => $invitation['inviter_avatar']
        ];
        
        // グループ招待の場合、グループ名を取得
        $inviteGroupId = isset($invitation['group_id']) ? (int)$invitation['group_id'] : 0;
        $inviteGroupName = null;
        if ($inviteGroupId > 0) {
            $stmt = $pdo->prepare("SELECT name FROM conversations WHERE id = ?");
            $stmt->execute([$inviteGroupId]);
            $groupData = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($groupData) {
                $inviteGroupName = $groupData['name'];
            }
        }
        
        // ログインしていない場合は登録フォームを表示
        if (!isset($_SESSION['user_id'])) {
            $showRegistrationForm = true;
        }
    } else {
        $error = 'この招待リンクは無効または期限切れです。';
    }
} elseif ($inviter_id <= 0) {
    $error = '無効な招待リンクです。';
} else {
    // 招待者の情報を取得
    $stmt = $pdo->prepare("SELECT id, display_name, avatar FROM users WHERE id = ?");
    $stmt->execute([$inviter_id]);
    $inviter = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$inviter) {
        $error = 'ユーザーが見つかりません。';
    }
}

// 新規登録処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $showRegistrationForm && $invitation) {
    $displayName = trim($_POST['display_name'] ?? '');
    
    if (strlen($displayName) < 1) {
        $error = '表示名を入力してください。';
    } elseif (strlen($displayName) > 50) {
        $error = '表示名は50文字以内で入力してください。';
    } else {
        // 連絡先情報を使って新規ユーザーを作成
        $contact = $invitation['contact'];
        $contactType = $invitation['contact_type'];
        
        // メールアドレスまたは電話番号で既存ユーザーをチェック
        if ($contactType === 'email') {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$contact]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE phone_number = ?");
            $stmt->execute([$contact]);
        }
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingUser) {
            // 既存ユーザーの場合はログイン処理
            $_SESSION['user_id'] = $existingUser['id'];
            $showRegistrationForm = false;
        } else {
            // 新規ユーザー作成
            $tempPassword = bin2hex(random_bytes(16));
            $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
            
            if ($contactType === 'email') {
                $stmt = $pdo->prepare("
                    INSERT INTO users (email, display_name, password, email_verified, created_at)
                    VALUES (?, ?, ?, 1, NOW())
                ");
                $stmt->execute([$contact, $displayName, $hashedPassword]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO users (phone_number, display_name, password, email_verified, created_at)
                    VALUES (?, ?, ?, 1, NOW())
                ");
                $stmt->execute([$contact, $displayName, $hashedPassword]);
            }
            
            $newUserId = $pdo->lastInsertId();
            $_SESSION['user_id'] = $newUserId;
            
            // 招待ステータスを更新
            $stmt = $pdo->prepare("UPDATE invitations SET status = 'accepted' WHERE id = ?");
            $stmt->execute([$invitation['id']]);
            
            // 招待者とのDMを自動作成
            $inviterId = (int)$invitation['inviter_id'];
            createDMWithInviter($pdo, $newUserId, $inviterId, $displayName, $inviter['display_name']);
            
            // 友達関係も自動的に作成（双方向で承認済み）
            $stmt = $pdo->prepare("
                INSERT INTO friendships (user_id, friend_id, status, created_at)
                VALUES (?, ?, 'accepted', NOW())
                ON DUPLICATE KEY UPDATE status = 'accepted', updated_at = NOW()
            ");
            $stmt->execute([$newUserId, $inviterId]);
            $stmt->execute([$inviterId, $newUserId]);
            
            // グループからの招待の場合、そのグループに自動入室
            $groupId = isset($invitation['group_id']) ? (int)$invitation['group_id'] : 0;
            $groupName = null;
            if ($groupId > 0) {
                // グループ名を取得
                $stmt = $pdo->prepare("SELECT name FROM conversations WHERE id = ?");
                $stmt->execute([$groupId]);
                $groupData = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($groupData) {
                    $groupName = $groupData['name'];
                }
                
                // グループに追加
                addUserToGroup($pdo, $newUserId, $groupId, $inviterId, $displayName);
            }
            
            $showRegistrationForm = false;
            
            if ($groupName) {
                $success = 'アカウントを作成しました！「' . $groupName . '」グループに参加しました。';
            } else {
                $success = 'アカウントを作成しました！' . $inviter['display_name'] . 'さんとのチャットを開始できます。';
            }
        }
    }
}

// ログイン済みの場合は友達追加処理（およびグループ招待処理）
if (!$error && !$success && isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    
    if ($user_id == $inviter_id) {
        $error = '自分自身を友達に追加することはできません。';
    } else {
        // グループ招待の場合、グループに自動入室
        $joinedGroupName = null;
        if ($invitation && isset($invitation['group_id']) && (int)$invitation['group_id'] > 0) {
            $groupIdToJoin = (int)$invitation['group_id'];
            
            // ユーザーの表示名を取得
            $stmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
            $currentUserName = $currentUser ? $currentUser['display_name'] : 'ユーザー';
            
            // グループに追加
            if (addUserToGroup($pdo, $user_id, $groupIdToJoin, $inviter_id, $currentUserName)) {
                // グループ名を取得
                $stmt = $pdo->prepare("SELECT name FROM conversations WHERE id = ?");
                $stmt->execute([$groupIdToJoin]);
                $groupData = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($groupData) {
                    $joinedGroupName = $groupData['name'];
                }
                
                // 招待ステータスを更新
                $stmt = $pdo->prepare("UPDATE invitations SET status = 'accepted' WHERE id = ?");
                $stmt->execute([$invitation['id']]);
                
                $success = '「' . $joinedGroupName . '」グループに参加しました！';
            }
        }
        
        // グループ招待でない場合、または追加で友達関係も作成
        if (!$joinedGroupName) {
            // 既存の関係をチェック
            $stmt = $pdo->prepare("SELECT status FROM friendships WHERE user_id = ? AND friend_id = ?");
            $stmt->execute([$user_id, $inviter_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                if ($existing['status'] === 'accepted') {
                    $success = 'すでに友達です！';
                } elseif ($existing['status'] === 'pending') {
                    $success = '友達リクエストは送信済みです。';
                } elseif ($existing['status'] === 'blocked') {
                    $error = 'このユーザーとの友達追加はできません。';
                }
            } else {
                // 相手からの申請があるかチェック
                $stmt = $pdo->prepare("SELECT id, status FROM friendships WHERE user_id = ? AND friend_id = ?");
                $stmt->execute([$inviter_id, $user_id]);
                $reverse = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($reverse && $reverse['status'] === 'pending') {
                    // 相手からの申請があれば、両方を承認
                    $stmt = $pdo->prepare("UPDATE friendships SET status = 'accepted', updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$reverse['id']]);
                    
                    $stmt = $pdo->prepare("INSERT INTO friendships (user_id, friend_id, status) VALUES (?, ?, 'accepted')");
                    $stmt->execute([$user_id, $inviter_id]);
                    
                    $success = $inviter['display_name'] . 'さんと友達になりました！';
                } else {
                    // 新規申請
                    $stmt = $pdo->prepare("INSERT INTO friendships (user_id, friend_id, status) VALUES (?, ?, 'pending')");
                    $stmt->execute([$user_id, $inviter_id]);
                    
                    $success = $inviter['display_name'] . 'さんに友達リクエストを送信しました！';
                }
            }
        }
    }
}

$lang = getCurrentLanguage();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>友達追加 - <?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social100' ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .invite-card {
            background: #ffffff;
            border-radius: 24px;
            padding: 40px;
            max-width: 400px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .invite-logo {
            font-size: 48px;
            margin-bottom: 16px;
        }
        
        .invite-title {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 8px;
        }
        
        .invite-subtitle {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 32px;
        }
        
        .inviter-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: white;
            margin: 0 auto 16px;
            font-weight: 600;
        }
        
        .inviter-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .inviter-name {
            font-size: 24px;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 8px;
        }
        
        .inviter-message {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 32px;
        }
        
        .btn {
            display: inline-block;
            padding: 14px 32px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
            width: 100%;
            margin-bottom: 12px;
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
            background: #f3f4f6;
            color: #374151;
        }
        
        .btn-secondary:hover {
            background: #e5e7eb;
        }
        
        .success-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }
        
        .success-message {
            font-size: 18px;
            font-weight: 600;
            color: #10b981;
            margin-bottom: 24px;
        }
        
        .error-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }
        
        .error-message {
            font-size: 18px;
            font-weight: 600;
            color: #ef4444;
            margin-bottom: 24px;
        }
        
        .login-prompt {
            background: #f3f4f6;
            border-radius: 12px;
            padding: 20px;
            margin-top: 24px;
        }
        
        .login-prompt p {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 12px;
        }
        
        .divider {
            display: flex;
            align-items: center;
            margin: 24px 0;
        }
        
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e5e7eb;
        }
        
        .divider span {
            padding: 0 16px;
            color: #9ca3af;
            font-size: 13px;
        }
        
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 8px;
        }
        
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 16px;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-hint {
            font-size: 12px;
            color: #6b7280;
            margin-top: 6px;
        }
        
        .contact-info {
            background: #f3f4f6;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 14px;
            color: #374151;
            margin-bottom: 20px;
            text-align: left;
        }
        
        .contact-info-label {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 4px;
        }
        @media (max-width: 768px) {
            body { padding: 12px; }
            .invite-card { padding: 24px 20px; max-width: 100%; }
            .btn { min-height: 44px; font-size: 16px; -webkit-tap-highlight-color: transparent; touch-action: manipulation; }
            .form-group input { font-size: 16px; min-height: 44px; }
        }
    </style>
</head>
<body>
    <div class="invite-card">
        <?php if ($error && !$showRegistrationForm): ?>
            <div class="error-icon">😕</div>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
            <a href="index.php" class="btn btn-primary">ログインページへ</a>
            
        <?php elseif ($success): ?>
            <div class="success-icon">🎉</div>
            <div class="success-message"><?= htmlspecialchars($success) ?></div>
            <a href="chat.php" class="btn btn-primary">チャットを開く</a>
            
        <?php elseif ($showRegistrationForm && $invitation): ?>
            <!-- トークンベースの招待 - 新規登録フォーム -->
            <div class="invite-logo">👥</div>
            <?php if (!empty($inviteGroupName)): ?>
                <div class="invite-title">「<?= htmlspecialchars($inviteGroupName) ?>」への招待</div>
                <div class="invite-subtitle"><?= htmlspecialchars($inviter['display_name']) ?>さんからのグループ招待</div>
            <?php else: ?>
                <div class="invite-title"><?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social100' ?>への招待</div>
                <div class="invite-subtitle"><?= htmlspecialchars($inviter['display_name']) ?>さんからの招待</div>
            <?php endif; ?>
            
            <div class="inviter-avatar">
                <?php if (!empty($inviter['avatar'])): ?>
                    <img src="uploads/avatars/<?= htmlspecialchars($inviter['avatar']) ?>" alt="">
                <?php else: ?>
                    <?= mb_substr($inviter['display_name'], 0, 1) ?>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($inviteGroupName)): ?>
                <div class="inviter-name"><?= htmlspecialchars($inviter['display_name']) ?>さんが</div>
                <div class="inviter-message">「<?= htmlspecialchars($inviteGroupName) ?>」へあなたを招待しています</div>
            <?php else: ?>
                <div class="inviter-name"><?= htmlspecialchars($inviter['display_name']) ?>さんが</div>
                <div class="inviter-message">あなたを<?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social100' ?>へ招待しています</div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="error-message" style="margin-bottom: 20px;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="contact-info">
                    <div class="contact-info-label"><?= $invitation['contact_type'] === 'email' ? 'メールアドレス' : '電話番号' ?></div>
                    <div><?= htmlspecialchars($invitation['contact']) ?></div>
                </div>
                
                <div class="form-group">
                    <label for="display_name">表示名</label>
                    <input type="text" id="display_name" name="display_name" 
                           placeholder="あなたの名前を入力" required maxlength="50"
                           value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>">
                    <div class="form-hint">他のユーザーに表示される名前です</div>
                </div>
                
                <button type="submit" class="btn btn-primary">登録して<?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social100' ?>を始める</button>
            </form>
            
            <div class="divider"><span>既にアカウントをお持ちの方</span></div>
            
            <a href="index.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="btn btn-secondary">ログイン</a>
            
        <?php elseif ($inviter && !isset($_SESSION['user_id'])): ?>
            <!-- 通常の招待リンク -->
            <div class="invite-logo">👥</div>
            <div class="invite-title">友達追加リクエスト</div>
            <div class="invite-subtitle"><?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social100' ?>で友達になりましょう</div>
            
            <div class="inviter-avatar">
                <?php if (!empty($inviter['avatar'])): ?>
                    <img src="uploads/avatars/<?= htmlspecialchars($inviter['avatar']) ?>" alt="">
                <?php else: ?>
                    <?= mb_substr($inviter['display_name'], 0, 1) ?>
                <?php endif; ?>
            </div>
            
            <div class="inviter-name"><?= htmlspecialchars($inviter['display_name']) ?></div>
            <div class="inviter-message">さんから友達追加の招待が届いています</div>
            
            <a href="index.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="btn btn-primary">ログインして友達追加</a>
            
            <div class="divider"><span>または</span></div>
            
            <a href="register.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="btn btn-secondary">新規登録</a>
            
            <div class="login-prompt">
                <p><?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social100' ?>アカウントをお持ちの方はログインしてください。<br>アカウントをお持ちでない方は新規登録してください。</p>
            </div>
            
        <?php elseif ($inviter && isset($_SESSION['user_id'])): ?>
            <!-- ログイン済み - 友達追加処理済み -->
            <div class="success-icon">✅</div>
            <div class="success-message">処理が完了しました</div>
            <a href="chat.php" class="btn btn-primary">チャットを開く</a>
        <?php endif; ?>
    </div>
</body>
</html>


