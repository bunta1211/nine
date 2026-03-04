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

## 参考

- [SES SMTP エンドポイント一覧](https://docs.aws.amazon.com/ses/latest/dg/smtp-connect.html)
- 移転・メール方針: `DOCS/MIGRATION_ISSUES_AND_PLAN.md`
