<?php
/**
 * メール送信設定
 * 本番(AWS EC2)では mail.local.php で SES/SMTP を指定すること推奨。
 * mail.local.php を先に読み込み、定義されていない項目だけデフォルト値を使う。
 */
$mailLocal = __DIR__ . '/mail.local.php';
if (is_file($mailLocal)) {
    require_once $mailLocal;
}

if (!defined('MAIL_DRIVER')) {
    define('MAIL_DRIVER', 'php');
}
if (!defined('MAIL_FROM_EMAIL')) {
    define('MAIL_FROM_EMAIL', 'noreply@social9.jp');
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', 'Social9');
}
if (!defined('MAIL_SMTP_HOST')) {
    define('MAIL_SMTP_HOST', '');
}
if (!defined('MAIL_SMTP_PORT')) {
    define('MAIL_SMTP_PORT', 587);
}
if (!defined('MAIL_SMTP_USER')) {
    define('MAIL_SMTP_USER', '');
}
if (!defined('MAIL_SMTP_PASS')) {
    define('MAIL_SMTP_PASS', '');
}
if (!defined('MAIL_SMTP_ENCRYPTION')) {
    define('MAIL_SMTP_ENCRYPTION', 'tls');
}
