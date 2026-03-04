<?php
/**
 * SMS送信 ローカル/本番設定サンプル
 * コピーして sms.local.php にリネームし、値を設定してください。
 * sms.local.php は .gitignore に追加推奨。
 */

// 送信方法: 'log' = 送信せずログのみ（開発用） / 'twilio' / 'sns'（AWS SNS）
define('SMS_DRIVER', 'log');

// 送信元番号（E.164形式。例: +819012345678）
define('SMS_FROM_NUMBER', '');

// ----- Twilio を使う場合（SMS_DRIVER=twilio） -----
// define('SMS_DRIVER', 'twilio');
// define('SMS_TWILIO_SID', 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
// define('SMS_TWILIO_TOKEN', 'your_auth_token');
// define('SMS_FROM_NUMBER', '+1234567890'); // Twilio で取得した番号

// ----- AWS SNS を使う場合（SMS_DRIVER=sns） -----
// define('SMS_DRIVER', 'sns');
// define('SMS_AWS_REGION', 'ap-northeast-1');
// define('SMS_AWS_KEY', 'AKIA...');
// define('SMS_AWS_SECRET', '...');
// SNS では送信元番号は不要（SMS タイプで送信）
