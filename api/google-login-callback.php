<?php
/**
 * Googleログイン OAuthコールバック
 * 認証後にユーザー作成またはログインを行う
 *
 * 必要: users に google_id (migration_google_login.sql), full_name (migration_add_full_name.sql)
 * エラー時: logs/google_login_error.log および PHP error_log に出力
 */

define('IS_API', true);
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/google_login.php';

// リダイレクト先（本番では絶対URLの方が確実）
$redirectUrl = (defined('APP_URL') && APP_URL !== '')
    ? rtrim(APP_URL, '/') . '/index.php'
    : '../index.php';

if (!isGoogleLoginEnabled()) {
    header('Location: ' . $redirectUrl . '?error=google_login_disabled');
    exit;
}

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$errorParam = $_GET['error'] ?? '';

if (!empty($errorParam)) {
    if ($errorParam === 'access_denied') {
        header('Location: ' . $redirectUrl . '?error=google_denied');
    } elseif ($errorParam === 'disallowed_useragent') {
        // アプリ内ブラウザ(WebView)でGoogleがブロックした場合
        header('Location: ' . $redirectUrl . '?error=google_webview_blocked');
    } else {
        header('Location: ' . $redirectUrl . '?error=google_auth_failed');
    }
    exit;
}

if (empty($code) || empty($state)) {
    header('Location: ' . $redirectUrl . '?error=invalid_callback');
    exit;
}

// CSRF検証
$savedState = $_SESSION['google_login_state'] ?? '';
if (empty($savedState) || $state !== $savedState) {
    unset($_SESSION['google_login_state']);
    header('Location: ' . $redirectUrl . '?error=state_mismatch');
    exit;
}
unset($_SESSION['google_login_state']);

try {
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    header('Location: ' . $redirectUrl . '?error=google_login_unavailable');
    exit;
}
require_once $autoload;

$client = new \Google\Client();
$client->setClientId(GOOGLE_LOGIN_CLIENT_ID);
$client->setClientSecret(GOOGLE_LOGIN_CLIENT_SECRET);
$client->setRedirectUri(getGoogleLoginRedirectUri());

$token = $client->fetchAccessTokenWithAuthCode($code);

if (isset($token['error'])) {
    error_log('Google Login OAuth error: ' . ($token['error_description'] ?? $token['error']));
    header('Location: ' . $redirectUrl . '?error=token_failed');
    exit;
}

// ユーザー情報取得
$google_email = '';
$google_name = '';
$google_picture = '';
$google_sub = '';

if (isset($token['id_token'])) {
    $parts = explode('.', $token['id_token']);
    if (isset($parts[1])) {
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $google_email = $payload['email'] ?? '';
        $google_name = $payload['name'] ?? '';
        $google_picture = $payload['picture'] ?? '';
        $google_sub = $payload['sub'] ?? '';
    }
}

if (empty($google_email) && !empty($token['access_token'])) {
    $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token['access_token']],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp) {
        $info = json_decode($resp, true);
        $google_email = $info['email'] ?? '';
        $google_name = $info['name'] ?? '';
        $google_picture = $info['picture'] ?? '';
        $google_sub = $info['id'] ?? '';
    }
}

if (empty($google_email)) {
    header('Location: ' . $redirectUrl . '?error=google_no_email');
    exit;
}

$pdo = getDB();

// 既存ユーザー検索: google_id優先、次にemail
$user = null;
if (!empty($google_sub)) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = ? AND status = 'active'");
    $stmt->execute([$google_sub]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$user) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
    $stmt->execute([$google_email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    // 既存ユーザーにgoogle_idを紐づけ（初回Googleログイン時）
    if ($user && !empty($google_sub)) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET google_id = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$google_sub, $user['id']]);
        } catch (PDOException $e) {
            // google_idがユニークで重複する場合はスキップ
            error_log('Google login link error: ' . $e->getMessage());
        }
    }
}

if ($user) {
    // ログイン処理
    setSessionFromUser($user);
    $pdo->prepare("UPDATE users SET last_login = NOW(), online_status = 'online', last_seen = NOW() WHERE id = ?")
        ->execute([$user['id']]);
    header('Location: ../chat.php');
    exit;
}

// 新規ユーザー作成
$display_name = trim($google_name) ?: explode('@', $google_email)[0];
$display_name = mb_substr($display_name, 0, 50);

// password_hash: ランダム値（Googleログインのみのユーザーは使用しない）
$password_hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("
        INSERT INTO users (
            email, password_hash, display_name, full_name, google_id,
            email_verified_at, auth_level, birth_date, status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, NOW(), 1, ?, 'active', NOW(), NOW())
    ");
    $birth_date = '2000-01-01'; // デフォルト（後で設定を促す）
    $stmt->execute([
        $google_email,
        $password_hash,
        $display_name,
        $google_name ?: null,
        $google_sub ?: null,
        $birth_date
    ]);
    $user_id = $pdo->lastInsertId();

    // プライバシー設定を初期化（デフォルト: 検索可能）
    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_privacy_settings (user_id, exclude_from_search, created_at, updated_at)
            VALUES (?, 0, NOW(), NOW())
            ON DUPLICATE KEY UPDATE updated_at = NOW()
        ");
        $stmt->execute([$user_id]);
    } catch (PDOException $e) {
        error_log('Privacy settings init: ' . $e->getMessage());
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $newUser = $stmt->fetch(PDO::FETCH_ASSOC);
    setSessionFromUser($newUser);

    $pdo->prepare("UPDATE users SET last_login = NOW(), online_status = 'online', last_seen = NOW() WHERE id = ?")
        ->execute([$user_id]);

    header('Location: ../chat.php');
    exit;

} catch (PDOException $e) {
    error_log('Google login user create error: ' . $e->getMessage());
    error_log('Google login user create SQLSTATE: ' . $e->getCode());
    if ($e->getCode() == 23000) {
        // 重複キー（email等）の場合はログインを再試行
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
        $stmt->execute([$google_email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            if (!empty($google_sub)) {
                try {
                    $pdo->prepare("UPDATE users SET google_id = ? WHERE id = ?")->execute([$google_sub, $user['id']]);
                } catch (PDOException $ignored) {}
            }
            setSessionFromUser($user);
            header('Location: ../chat.php');
            exit;
        }
    }
    header('Location: ' . $redirectUrl . '?error=user_create_failed');
    exit;
}

} catch (Throwable $e) {
    $msg = 'Google login callback: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    error_log($msg);
    error_log('Google login callback trace: ' . $e->getTraceAsString());
    if (defined('LOG_DIR') && is_dir(LOG_DIR) && is_writable(LOG_DIR)) {
        @file_put_contents(LOG_DIR . 'google_login_error.log', date('Y-m-d H:i:s') . ' ' . $msg . "\n" . $e->getTraceAsString() . "\n\n", FILE_APPEND | LOCK_EX);
    }
    header('Location: ' . $redirectUrl . '?error=server_error');
    exit;
}

function setSessionFromUser($user) {
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['display_name'] = $user['display_name'];
    $_SESSION['full_name'] = $user['full_name'] ?? $user['display_name'];
    $_SESSION['avatar'] = $user['avatar_path'] ?? null;
    $_SESSION['role'] = $user['role'] ?? 'user';
    $_SESSION['auth_level'] = (int)($user['auth_level'] ?? 1);
    $_SESSION['is_minor'] = (bool)($user['is_minor'] ?? false);
    $_SESSION['is_org_admin'] = in_array($user['role'] ?? '', ['developer', 'system_admin', 'org_admin', 'admin']) ? 1 : 0;
    $_SESSION['organization_id'] = (int)($user['organization_id'] ?? 1);
    $_SESSION['login_time'] = time();
    session_regenerate_id(true);
}
