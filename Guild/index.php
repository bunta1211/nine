<?php
/**
 * Guild エントリーポイント
 * Social9のログイン状態を確認してリダイレクト
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';

// Social9にログイン済みならホームへリダイレクト
if (isGuildLoggedIn()) {
    header('Location: home.php');
    exit;
}

// 未ログインならSocial9のログインページへリダイレクト
$social9Url = getSocial9Url();
header('Location: ' . $social9Url . '/index.php');
exit;
