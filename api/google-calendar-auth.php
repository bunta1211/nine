<?php
/**
 * Googleカレンダー OAuth認証開始
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

if (!isLoggedIn()) {
    header('Location: ../index.php');
    exit;
}

if (!isGoogleCalendarEnabled() || !isGoogleCalendarClientAvailable()) {
    header('Location: ../settings.php?section=calendar&error=calendar_not_configured');
    exit;
}

$display_name = trim($_POST['display_name'] ?? $_GET['display_name'] ?? '');
if (empty($display_name)) {
    header('Location: ../settings.php?section=calendar&error=display_name_required');
    exit;
}

if (mb_strlen($display_name) > 50) {
    header('Location: ../settings.php?section=calendar&error=display_name_too_long');
    exit;
}

$user_id = $_SESSION['user_id'];

$client = new \Google\Client();
$client->setClientId(GOOGLE_CALENDAR_CLIENT_ID);
$client->setClientSecret(GOOGLE_CALENDAR_CLIENT_SECRET);
$client->setRedirectUri(getGoogleCalendarRedirectUri());
$client->addScope(\Google\Service\Calendar::CALENDAR);
$client->addScope('https://www.googleapis.com/auth/userinfo.email');
$client->setAccessType('offline');
$client->setPrompt('consent');

// URL-safe base64（+と/がURLで破損するのを防ぐ）
$state = rtrim(strtr(base64_encode(json_encode([
    'user_id' => $user_id,
    'display_name' => $display_name,
])), '+/', '-_'), '=');
$client->setState($state);
$_SESSION['google_calendar_state'] = $state;
session_write_close(); // リダイレクト前にセッションを確実に保存

$authUrl = $client->createAuthUrl();
header('Location: ' . $authUrl);
exit;
