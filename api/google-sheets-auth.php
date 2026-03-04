<?php
/**
 * Googleスプレッドシート OAuth認証開始
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

if (!isLoggedIn()) {
    header('Location: ../index.php');
    exit;
}

if (!isGoogleSheetsEnabled()) {
    header('Location: ../settings.php?section=sheets&error=sheets_not_configured');
    exit;
}

if (!class_exists('Google\Client')) {
    header('Location: ../settings.php?section=sheets&error=client_unavailable');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$client = new \Google\Client();
$client->setClientId(GOOGLE_SHEETS_CLIENT_ID);
$client->setClientSecret(GOOGLE_SHEETS_CLIENT_SECRET);
$client->setRedirectUri(getGoogleSheetsRedirectUri());
$client->addScope(\Google\Service\Sheets::SPREADSHEETS);
$client->addScope('https://www.googleapis.com/auth/userinfo.email');
$client->setAccessType('offline');
$client->setPrompt('consent');

$state = rtrim(strtr(base64_encode(json_encode(['user_id' => $user_id])), '+/', '-_'), '=');
$client->setState($state);
$_SESSION['google_sheets_state'] = $state;
session_write_close();

header('Location: ' . $client->createAuthUrl());
exit;
