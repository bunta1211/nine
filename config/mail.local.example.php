<?php
/**
 * メール送信 ローカル/本番設定サンプル
 * コピーして mail.local.php にリネームし、値を設定してください。
 * mail.local.php は .gitignore に追加推奨。
 */

// 送信方法: 'php' = PHP mail() / 'smtp' = SMTP（AWS SES 含む）
define('MAIL_DRIVER', 'php');

define('MAIL_FROM_EMAIL', 'noreply@social9.jp');
define('MAIL_FROM_NAME', 'Social9');

// ----- AWS SES を使う場合（MAIL_DRIVER=smtp） -----
// SES コンソールで「SMTP 設定」→「SMTP 認証情報を作成」で取得したユーザー/パスワードを設定
// define('MAIL_DRIVER', 'smtp');
// define('MAIL_SMTP_HOST', 'email-smtp.ap-northeast-1.amazonaws.com');
// define('MAIL_SMTP_PORT', 587);
// define('MAIL_SMTP_USER', 'AKIA...');  // SMTP ユーザー名
// define('MAIL_SMTP_PASS', '...');      // SMTP パスワード
// define('MAIL_SMTP_ENCRYPTION', 'tls');
// define('MAIL_FROM_EMAIL', 'noreply@yourdomain.com'); // SES で検証済みのアドレス
