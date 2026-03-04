<?php
/**
 * 認証 API（session.php と同一のセッション設定で移転後もログインを維持）
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/security.php';

start_session_once();
$pdo = getDB();
$security = getSecurity();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// IPブロックチェック
$blockedInfo = $security->isIPBlocked();
if ($blockedInfo) {
    $security->logEvent('unauthorized_access', 'high', [
        'description' => 'ブロック中のIPからのアクセス試行'
    ]);
    header('HTTP/1.1 403 Forbidden');
    echo 'アクセスが拒否されました。';
    exit;
}

$baseUrl = getBaseUrl();
$indexUrl = $baseUrl !== '' ? $baseUrl . '/index.php' : 'index.php';

switch ($action) {
    case 'login':
        handleLogin($pdo, $baseUrl, $indexUrl);
        break;
    case 'logout':
        handleLogout($indexUrl);
        break;
    case 'check':
        handleCheck();
        break;
    default:
        header('Location: ' . $indexUrl);
        exit;
}

/**
 * ログイン処理（メールまたは携帯電話番号でユーザー検索）
 */
function handleLogin($pdo, $baseUrl, $indexUrl) {
    $identifier = trim($_POST['email'] ?? $_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']) && $_POST['remember'] == '1';

    if (empty($identifier) || empty($password)) {
        header('Location: ' . $indexUrl . '?error=required');
        exit;
    }

    $isEmail = strpos($identifier, '@') !== false || filter_var($identifier, FILTER_VALIDATE_EMAIL);
    if ($isEmail) {
        $email = $identifier;
        $phone = null;
    } else {
        $email = null;
        $phone = preg_replace('/\D/', '', $identifier);
        if (strlen($phone) < 10) {
            header('Location: ' . $indexUrl . '?error=invalid');
            exit;
        }
    }

    try {
        if ($isEmail) {
            $stmt = $pdo->prepare("
                SELECT id, organization_id, email, phone, password_hash, full_name, display_name, avatar_path, role, auth_level, is_minor, member_type
                FROM users WHERE email = ? AND status = 'active'
            ");
            $stmt->execute([$email]);
        } else {
            $stmt = $pdo->prepare("
                SELECT id, organization_id, email, phone, password_hash, full_name, display_name, avatar_path, role, auth_level, is_minor, member_type
                FROM users WHERE phone = ? AND status = 'active'
            ");
            $stmt->execute([$phone]);
        }
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        global $security;
        $lockKey = $isEmail ? $email : $phone;
        if ($security->isAccountLocked($lockKey)) {
            $security->logEvent('account_locked', 'medium', [
                'username' => $lockKey,
                'description' => 'ロック中のアカウントへのログイン試行'
            ]);
            header('Location: ' . $indexUrl . '?error=locked');
            exit;
        }

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $security->recordLoginAttempt($lockKey, false, $user ? 'パスワード不一致' : 'ユーザー不存在');
            header('Location: ' . $indexUrl . '?error=invalid');
            exit;
        }

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['organization_id'] = (int)($user['organization_id'] ?? 1);
        $_SESSION['email'] = $user['email'] ?? '';
        $_SESSION['phone'] = $user['phone'] ?? '';
        $_SESSION['full_name'] = $user['full_name'] ?? $user['display_name'];
        $_SESSION['display_name'] = $user['display_name'];
        $_SESSION['avatar'] = $user['avatar_path'] ?? null;
        $_SESSION['role'] = $user['role'] ?? 'user';
        $_SESSION['auth_level'] = (int)($user['auth_level'] ?? 1);
        $_SESSION['is_minor'] = (bool)($user['is_minor'] ?? false);
        $_SESSION['is_org_admin'] = in_array($user['role'] ?? '', ['developer', 'system_admin', 'org_admin', 'admin']) ? 1 : 0;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        session_regenerate_id(true);

        if ($remember) {
            $lifetime = 60 * 60 * 24 * 30;
            setcookie(session_name(), session_id(), time() + $lifetime, '/');
        }

        $pdo->prepare("UPDATE users SET last_login = NOW(), online_status = 'online', last_seen = NOW() WHERE id = ?")->execute([$user['id']]);
        $security->recordLoginAttempt($lockKey, true);

        $chatUrl = $baseUrl !== '' ? $baseUrl . '/chat.php' : 'chat.php';
        header('Location: ' . $chatUrl);
        exit;

    } catch (PDOException $e) {
        error_log('Login error: ' . $e->getMessage());
        header('Location: ' . $indexUrl . '?error=invalid');
        exit;
    }
}

/**
 * ログアウト処理
 */
function handleLogout($indexUrl) {
    $userId = $_SESSION['user_id'] ?? null;
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    if ($userId && isset($pdo)) {
        try {
            $pdo->prepare("UPDATE users SET online_status = 'offline', last_seen = NOW() WHERE id = ?")->execute([$userId]);
        } catch (PDOException $e) {
            // 無視
        }
    }
    header('Location: ' . $indexUrl);
    exit;
}

/**
 * ログイン状態チェック（AJAX用）
 */
function handleCheck() {
    header('Content-Type: application/json; charset=utf-8');
    
    if (isset($_SESSION['user_id'])) {
        echo json_encode([
            'success' => true,
            'logged_in' => true,
            'user' => [
                'id' => $_SESSION['user_id'],
                'display_name' => $_SESSION['display_name'],
                'is_org_admin' => $_SESSION['is_org_admin']
            ]
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'logged_in' => false
        ]);
    }
}
