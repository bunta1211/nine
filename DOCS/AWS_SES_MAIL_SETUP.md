# AWS SES メール送信設定

本番（EC2）で招待メール等を送るには、AWS SES の SMTP を使います。

## 1. SES で送信元メールを検証

1. AWS コンソール → **Amazon SES** → メールアドレス／ドメインを「検証」する。
2. 送信に使うアドレス（例: `noreply@social9.jp`）を検証済みにしておく。

## 2. SMTP 認証情報を作成

1. SES → **SMTP 設定** → **SMTP 認証情報を作成**。
2. IAM ユーザーが作成され、**SMTP ユーザー名**と**SMTP パスワード**が表示される（パスワードはここでしか表示されないので保存する）。

## 3. サーバーに mail.local.php を置く

`config/mail.local.example.php` をコピーして `config/mail.local.php` にし、以下を設定する。

```php
define('MAIL_DRIVER', 'smtp');
define('MAIL_SMTP_HOST', 'email-smtp.ap-northeast-1.amazonaws.com');
define('MAIL_SMTP_PORT', 587);
define('MAIL_SMTP_USER', 'AKIA...');   // SMTP ユーザー名
define('MAIL_SMTP_PASS', '...');       // SMTP パスワード
define('MAIL_SMTP_ENCRYPTION', 'tls');
define('MAIL_FROM_EMAIL', 'noreply@social9.jp'); // SES で検証済みのアドレス
define('MAIL_FROM_NAME', 'Social9');
```

- リージョンが東京でない場合はホストを変更（例: `email-smtp.us-east-1.amazonaws.com`）。
- `mail.local.php` は Git にコミットしない（.gitignore に追加推奨）。

## 4. 送信テスト

メンバー管理の「再送」で招待メールが送れるか確認する。

## 「メールの送信に失敗しましたが、リンクは発行済みです」と出る場合

この表示は **メール送信だけが失敗している** 状態です。次を確認してください。

1. **サーバーに `config/mail.local.php` があるか**  
   無い場合、上記「3. サーバーに mail.local.php を置く」のとおり作成し、SES の SMTP 認証情報を設定する。
2. **`MAIL_DRIVER` が `smtp` か**  
   `mail.local.php` の先頭で `define('MAIL_DRIVER', 'smtp');` になっているか確認する。
3. **SES で送信元メールを検証済みか**  
   未検証のアドレスからは送信できない。SES コンソールで「検証済み」になっているか確認する。
4. **サーバーのエラーログ**  
   PHP の error_log に `Mailer: PHP mail() failed...` や `Mailer SMTP error: ...` が出ていないか確認する。

## 新規登録で認証メールが届かない場合

認証コード送信APIは、**送信に失敗した場合は「メールの送信に失敗しました」とエラーを返す**ようになっています。届かないのに成功メッセージが出る場合は、本番サーバーに最新の `api/auth_otp.php` が反映されているか確認してください。

届かないうえに「メールの送信に失敗しました」と表示される場合は、以下を確認してください。

1. **本番サーバー（EC2）に `config/mail.local.php` が存在するか**  
   存在しない場合、`MAIL_DRIVER` は `php`（PHP の `mail()`）のままになり、多くの EC2 環境では送信されません。必ず `mail.local.php` を配置し、`MAIL_DRIVER=smtp` と SES の SMTP 認証情報を設定してください。
2. **SES の送信元（From）が検証済みか**  
   `MAIL_FROM_EMAIL` に指定しているアドレス（またはそのドメイン）を、SES コンソールの「検証済みの ID」で検証してください。サンドボックス解除後も、送信元の検証は必要です。
3. **SMTP 認証情報**  
   IAM の API キーではなく、SES の「SMTP 設定」で作成した **SMTP ユーザー名・SMTP パスワード** を `MAIL_SMTP_USER` / `MAIL_SMTP_PASS` に設定してください。
4. **リージョン**  
   東京リージョンなら `MAIL_SMTP_HOST` は `email-smtp.ap-northeast-1.amazonaws.com`、ポート 587、`MAIL_SMTP_ENCRYPTION` は `tls` にしてください。
5. **エラーログ**  
   本番の PHP error_log に `OTP Send: ... result=FAIL` や `SmtpSender: MAIL FROM rejected` 等が出ていないか確認し、出ている場合はその内容で原因を切り分けてください。

## 参考

- [SES SMTP エンドポイント一覧](https://docs.aws.amazon.com/ses/latest/dg/smtp-connect.html)
- 移転・メール方針: `DOCS/MIGRATION_ISSUES_AND_PLAN.md`
