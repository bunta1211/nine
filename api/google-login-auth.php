<?php
/**
 * Googleログイン OAuth認証開始
 * 未ログインのユーザーをGoogle認証画面にリダイレクトする
 */

define('IS_API', true);
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/google_login.php';

// 既にログイン済みの場合はチャットへ
if (isLoggedIn()) {
    header('Location: ../chat.php');
    exit;
}

if (!isGoogleLoginEnabled()) {
    header('Location: ../index.php?error=google_login_disabled');
    exit;
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    header('Location: ../index.php?error=google_login_unavailable');
    exit;
}
require_once $autoload;

$client = new \Google\Client();
$client->setClientId(GOOGLE_LOGIN_CLIENT_ID);
$client->setClientSecret(GOOGLE_LOGIN_CLIENT_SECRET);
$client->setRedirectUri(getGoogleLoginRedirectUri());
$client->addScope('email');
$client->addScope('profile');
$client->addScope('openid');
$client->setAccessType('online');
$client->setPrompt('select_account');

// CSRF対策: stateにランダム値を保存
$state = bin2hex(random_bytes(16));
$_SESSION['google_login_state'] = $state;
$client->setState($state);

$authUrl = $client->createAuthUrl();
header('Location: ' . $authUrl);
exit;
