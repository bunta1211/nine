<?php
/**
 * Googleカレンダー OAuthコールバック
 */

define('IS_API', true);
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/google_calendar.php';
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}
require_once __DIR__ . '/../includes/google_calendar_helper.php';

$redirectUrl = '../settings.php?section=calendar';

if (!isLoggedIn()) {
    header('Location: ' . $redirectUrl . '&error=login_required');
    exit;
}

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$errorParam = $_GET['error'] ?? '';

if (!empty($errorParam)) {
    header('Location: ' . $redirectUrl . '&error=user_denied');
    exit;
}

if (empty($code) || empty($state)) {
    header('Location: ' . $redirectUrl . '&error=invalid_callback');
    exit;
}

$savedState = $_SESSION['google_calendar_state'] ?? '';
if ($state !== $savedState) {
    unset($_SESSION['google_calendar_state']);
    header('Location: ' . $redirectUrl . '&error=state_mismatch');
    exit;
}

// URL-safe base64でデコード（+と/の破損を逆変換）
$stateDecoded = base64_decode(strtr($state, '-_', '+/') . str_repeat('=', (4 - strlen($state) % 4) % 4));
$decoded = json_decode($stateDecoded, true);
$user_id = (int)($decoded['user_id'] ?? 0);
$display_name = trim($decoded['display_name'] ?? '');

if ($user_id !== (int)($_SESSION['user_id']) || empty($display_name)) {
    unset($_SESSION['google_calendar_state']);
    header('Location: ' . $redirectUrl . '&error=invalid_state');
    exit;
}

unset($_SESSION['google_calendar_state']);

if (!isGoogleCalendarEnabled() || !isGoogleCalendarClientAvailable()) {
    header('Location: ' . $redirectUrl . '&error=calendar_not_configured');
    exit;
}

try {
    $client = new \Google\Client();
    $client->setClientId(GOOGLE_CALENDAR_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CALENDAR_CLIENT_SECRET);
    $client->setRedirectUri(getGoogleCalendarRedirectUri());

    $token = $client->fetchAccessTokenWithAuthCode($code);

    if (isset($token['error'])) {
        error_log('Google Calendar OAuth error: ' . ($token['error_description'] ?? $token['error']));
        header('Location: ' . $redirectUrl . '&error=token_failed');
        exit;
    }

    $google_email = '';
    if (isset($token['id_token'])) {
        $parts = explode('.', $token['id_token']);
        if (isset($parts[1])) {
            $payload = json_decode(base64_decode($parts[1]), true);
            $google_email = $payload['email'] ?? '';
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
        }
    }
    if (empty($google_email)) {
        header('Location: ' . $redirectUrl . '&error=no_email');
        exit;
    }

    $pdo = getDB();

    // テーブル存在確認（未作成なら作成）
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'google_calendar_accounts'");
    if ($tableCheck->rowCount() === 0) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS google_calendar_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                display_name VARCHAR(50) NOT NULL,
                google_email VARCHAR(255) NOT NULL,
                access_token TEXT,
                refresh_token TEXT NOT NULL,
                token_expires_at DATETIME,
                is_default TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_user_google (user_id, google_email),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // 既存チェック（同一ユーザー・同一Googleアカウント）
    $stmt = $pdo->prepare("SELECT id FROM google_calendar_accounts WHERE user_id = ? AND google_email = ?");
    $stmt->execute([$user_id, $google_email]);
    $existing = $stmt->fetch();

    $expiresAt = null;
    if (isset($token['expires_in'])) {
        $expiresAt = date('Y-m-d H:i:s', time() + (int)$token['expires_in']);
    }

    $refreshToken = $token['refresh_token'] ?? '';
    if (empty($refreshToken) && $existing) {
        // 既存レコードのrefresh_tokenを維持（Googleが再発行しない場合）
        $stmt = $pdo->prepare("SELECT refresh_token FROM google_calendar_accounts WHERE id = ?");
        $stmt->execute([$existing['id']]);
        $row = $stmt->fetch();
        $refreshToken = $row['refresh_token'] ?? '';
    }
    if (!$existing && empty($refreshToken)) {
        // 新規登録でrefresh_tokenがない場合はリダイレクトURI不一致の可能性
        error_log('Google Calendar: refresh_token not received (redirect_uri mismatch?)');
        header('Location: ' . $redirectUrl . '&error=token_failed');
        exit;
    }

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE google_calendar_accounts 
            SET display_name = ?, access_token = ?, refresh_token = ?, token_expires_at = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $display_name,
            json_encode($token),
            $refreshToken,
            $expiresAt,
            $existing['id'],
        ]);
    } else {
        $isFirst = true;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM google_calendar_accounts WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $isFirst = ((int)$stmt->fetchColumn()) === 0;

        $stmt = $pdo->prepare("
            INSERT INTO google_calendar_accounts (user_id, display_name, google_email, access_token, refresh_token, token_expires_at, is_default)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $display_name,
            $google_email,
            json_encode($token),
            $refreshToken,
            $expiresAt,
            $isFirst ? 1 : 0,
        ]);
    }

    header('Location: ' . $redirectUrl . '&success=calendar_connected');
    exit;

} catch (Throwable $e) {
    error_log('Google Calendar callback error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    header('Location: ' . $redirectUrl . '&error=calendar_callback_failed');
    exit;
}
