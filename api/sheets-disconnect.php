<?php
/**
 * Googleスプレッドシート連携解除
 */
define('IS_API', true);
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';

if (!isLoggedIn()) {
    header('Location: ../index.php');
    exit;
}

$pdo = getDB();
$user_id = (int)$_SESSION['user_id'];
$pdo->prepare("DELETE FROM google_sheets_accounts WHERE user_id = ?")->execute([$user_id]);

header('Location: ../settings.php?section=sheets&success=disconnected');
exit;
