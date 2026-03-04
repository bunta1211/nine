<?php
/**
 * SMS送信設定
 * 本番では sms.local.php で Twilio / AWS SNS 等を指定すること推奨。
 */
if (!defined('SMS_DRIVER')) {
    define('SMS_DRIVER', 'log'); // 'log' = 送信せずログのみ / 'twilio' / 'sns'
}
if (!defined('SMS_FROM_NUMBER')) {
    define('SMS_FROM_NUMBER', ''); // Twilio の送信元番号（E.164形式）
}
// Twilio 用（SMS_DRIVER=twilio のとき）
if (!defined('SMS_TWILIO_SID')) {
    define('SMS_TWILIO_SID', '');
}
if (!defined('SMS_TWILIO_TOKEN')) {
    define('SMS_TWILIO_TOKEN', '');
}
// AWS SNS 用（SMS_DRIVER=sns のとき）
if (!defined('SMS_AWS_REGION')) {
    define('SMS_AWS_REGION', 'ap-northeast-1');
}
if (!defined('SMS_AWS_KEY')) {
    define('SMS_AWS_KEY', '');
}
if (!defined('SMS_AWS_SECRET')) {
    define('SMS_AWS_SECRET', '');
}

$smsLocal = __DIR__ . '/sms.local.php';
if (is_file($smsLocal)) {
    require_once $smsLocal;
}
