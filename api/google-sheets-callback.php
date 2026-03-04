<?php
/**
 * Googleスプレッドシート OAuthコールバック
 */

define('IS_API', true);
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/google_sheets.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

$redirectUrl = '../settings.php?section=sheets';

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

$savedState = $_SESSION['google_sheets_state'] ?? '';
if ($state !== $savedState) {
    unset($_SESSION['google_sheets_state']);
    header('Location: ' . $redirectUrl . '&error=state_mismatch');
    exit;
}

$stateDecoded = base64_decode(strtr($state, '-_', '+/') . str_repeat('=', (4 - strlen($state) % 4) % 4));
$decoded = json_decode($stateDecoded, true);
$user_id = (int)($decoded['user_id'] ?? 0);

if ($user_id !== (int)($_SESSION['user_id'])) {
    unset($_SESSION['google_sheets_state']);
    header('Location: ' . $redirectUrl . '&error=invalid_state');
    exit;
}

unset($_SESSION['google_sheets_state']);

if (!isGoogleSheetsEnabled() || !class_exists('Google\Client')) {
    header('Location: ' . $redirectUrl . '&error=sheets_not_configured');
    exit;
}

try {
    $client = new \Google\Client();
    $client->setClientId(GOOGLE_SHEETS_CLIENT_ID);
    $client->setClientSecret(GOOGLE_SHEETS_CLIENT_SECRET);
    $client->setRedirectUri(getGoogleSheetsRedirectUri());

    $token = $client->fetchAccessTokenWithAuthCode($code);

    if (isset($token['error'])) {
        error_log('Google Sheets OAuth error: ' . ($token['error_description'] ?? $token['error']));
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

    $tableCheck = $pdo->query("SHOW TABLES LIKE 'google_sheets_accounts'");
    if ($tableCheck->rowCount() === 0) {
        $sql = @file_get_contents(__DIR__ . '/../database/migration_google_sheets_accounts.sql');
        if (empty($sql)) {
            $sql = "CREATE TABLE IF NOT EXISTS google_sheets_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                google_email VARCHAR(255) NOT NULL,
                access_token TEXT,
                refresh_token TEXT NOT NULL,
                token_expires_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_user (user_id),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Googleスプレッドシート連携'";
        }
        $pdo->exec($sql);
    }

    $stmt = $pdo->prepare("SELECT id FROM google_sheets_accounts WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $existing = $stmt->fetch();

    $expiresAt = null;
    if (isset($token['expires_in'])) {
        $expiresAt = date('Y-m-d H:i:s', time() + (int)$token['expires_in']);
    }

    $refreshToken = $token['refresh_token'] ?? '';
    if (empty($refreshToken) && $existing) {
        $stmt = $pdo->prepare("SELECT refresh_token FROM google_sheets_accounts WHERE id = ?");
        $stmt->execute([$existing['id']]);
        $row = $stmt->fetch();
        $refreshToken = $row['refresh_token'] ?? '';
    }
    if (!$existing && empty($refreshToken)) {
        error_log('Google Sheets: refresh_token not received');
        header('Location: ' . $redirectUrl . '&error=token_failed');
        exit;
    }

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE google_sheets_accounts 
            SET google_email = ?, access_token = ?, refresh_token = ?, token_expires_at = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $google_email,
            json_encode($token),
            $refreshToken,
            $expiresAt,
            $existing['id'],
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO google_sheets_accounts (user_id, google_email, access_token, refresh_token, token_expires_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $google_email,
            json_encode($token),
            $refreshToken,
            $expiresAt,
        ]);
    }

    header('Location: ' . $redirectUrl . '&success=sheets_connected');
    exit;

} catch (Throwable $e) {
    error_log('Google Sheets callback error: ' . $e->getMessage());
    header('Location: ' . $redirectUrl . '&error=callback_failed');
    exit;
}
