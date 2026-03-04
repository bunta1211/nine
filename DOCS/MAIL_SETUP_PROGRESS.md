# メール設定 実装進捗

## 完了した作業

| Step | 内容 | ファイル |
|------|------|----------|
| 1 | メール設定の追加 | `config/mail.php`, `config/mail.local.example.php` |
| 2 | SMTP/SES 対応 | `includes/Mailer.php`（config 読み込み・SMTP 対応済み）, `includes/SmtpSender.php`（PHPMailer なし時のフォールバック） |
| 3 | 手順ドキュメント | `DOCS/AWS_SES_MAIL_SETUP.md` |

## サーバーに送るファイル（メール設定用）

- `config/mail.php`
- `config/mail.local.example.php`（参考用）
- `includes/Mailer.php`
- `includes/SmtpSender.php`
- （本番で SES を使う場合）サーバー上で `config/mail.local.php` を上記 example を元に作成し、SES の SMTP 認証情報を設定

## 次の作業（任意）

- サーバーで `config/mail.local.php` を作成し、SES の SMTP 情報を設定
- メンバー管理の「再送」で送信テスト
