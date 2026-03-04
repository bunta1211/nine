<?php
/**
 * Guild 認証API
 */

require_once __DIR__ . '/../includes/api-bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        handleLogin();
        break;
    case 'logout':
        handleLogout();
        break;
    case 'check':
        checkAuth();
        break;
    default:
        jsonError('Invalid action', 400);
}

/**
 * ログイン処理
 */
function handleLogin() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Method not allowed', 405);
    }
    
    $input = getJsonInput();
    if (empty($input)) {
        $input = $_POST;
    }
    
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        jsonError(__('login_failed'));
    }
    
    $pdo = getDB();
    
    // Social9のusersテーブルからユーザーを検索
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.password_hash, u.display_name, u.avatar_path,
               u.organization_id,
               gsp.is_system_admin, gsp.is_payroll_admin
        FROM users u
        LEFT JOIN guild_system_permissions gsp ON u.id = gsp.user_id
        WHERE u.email = ?
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    // デバッグ用（問題解決後に削除）
    if (!$user) {
        jsonError('ユーザーが見つかりません: ' . $email);
    }
    
    if (!password_verify($password, $user['password_hash'])) {
        // ログイン失敗をログに記録
        logLoginAttempt($email, false);
        jsonError('パスワードが一致しません');
    }
    
    // セッションに保存
    $_SESSION['guild_user_id'] = (int)$user['id'];
    $_SESSION['guild_user_email'] = $user['email'];
    $_SESSION['guild_user_name'] = $user['display_name'];
    $_SESSION['guild_user_avatar'] = $user['avatar_path'];
    $_SESSION['guild_is_system_admin'] = (int)($user['is_system_admin'] ?? 0);
    $_SESSION['guild_is_payroll_admin'] = (int)($user['is_payroll_admin'] ?? 0);
    $_SESSION['guild_last_activity'] = time();
    
    // ユーザープロフィールを取得
    $stmt = $pdo->prepare("SELECT language, dark_mode FROM guild_user_profiles WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch();
    
    if ($profile) {
        $_SESSION['guild_language'] = $profile['language'] ?? 'ja';
        $_SESSION['guild_dark_mode'] = (int)($profile['dark_mode'] ?? 0);
    }
    
    // ログイン成功をログに記録
    logLoginAttempt($email, true, $user['id']);
    
    // CSRFトークンを生成
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['guild_csrf_token'] = $csrfToken;
    
    jsonSuccess([
        'user' => [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'name' => $user['display_name'],
            'avatar' => $user['avatar_path'],
            'is_system_admin' => (int)($user['is_system_admin'] ?? 0),
            'is_payroll_admin' => (int)($user['is_payroll_admin'] ?? 0),
        ],
        'csrf_token' => $csrfToken,
    ], __('success'));
}

/**
 * ログアウト処理
 */
function handleLogout() {
    $userId = $_SESSION['guild_user_id'] ?? null;
    
    // セッションを破棄
    $_SESSION = [];
    
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 42000, '/');
    }
    
    session_destroy();
    
    jsonSuccess([], 'ログアウトしました');
}

/**
 * 認証状態チェック
 */
function checkAuth() {
    if (!isGuildLoggedIn()) {
        jsonError('Not authenticated', 401);
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.display_name, u.avatar_path,
               gsp.is_system_admin, gsp.is_payroll_admin
        FROM users u
        LEFT JOIN guild_system_permissions gsp ON u.id = gsp.user_id
        WHERE u.id = ?
    ");
    $stmt->execute([$_SESSION['guild_user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        jsonError('User not found', 404);
    }
    
    jsonSuccess([
        'user' => [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'name' => $user['display_name'],
            'avatar' => $user['avatar_path'],
            'is_system_admin' => (int)($user['is_system_admin'] ?? 0),
            'is_payroll_admin' => (int)($user['is_payroll_admin'] ?? 0),
        ],
    ]);
}

/**
 * ログイン試行をログに記録
 */
function logLoginAttempt($email, $success, $userId = null) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO guild_activity_logs 
        (user_id, action_type, details, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $success ? 'login_success' : 'login_failed',
        json_encode(['email' => $email]),
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
}
