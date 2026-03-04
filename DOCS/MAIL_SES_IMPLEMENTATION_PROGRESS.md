# メール送信（AWS SES）実装 進捗メモ

## 目的
- EC2 では `mail()` が使えないため、AWS SES でメール送信できるようにする
- 招待再送・組織招待メール等が届くようにする

## 進捗

| # | 項目 | 状態 | 備考 |
|---|------|------|------|
| 1 | 進捗メモ作成 | 完了 | 本ファイル |
| 2 | メール設定ファイル | 完了 | config/mail.php, mail.local.example.php 済み |
| 3 | Mailer.php ドライバー | 完了 | php / smtp 切替・PHPMailer 使用済み |
| 4 | composer PHPMailer | 完了 | phpmailer/phpmailer ^6.9 |
| 5 | DOCS SES 設定手順 | 完了 | DOCS/AWS_SES_MAIL_SETUP.md |
| 6 | .gitignore に mail.local.php | 完了 | パスワード含むため除外 |

## サーバーに送るファイル（メール送信を有効にする場合）

- `config/mail.php` … 既存
- `config/mail.local.example.php` … 参考用（本番では mail.local.php を配置）
- **本番では** `config/mail.local.php` を手動で作成し、SES SMTP の値を設定してアップロード
- `includes/Mailer.php` … SMTP 対応済み
- `vendor/` … `composer install` で PHPMailer を含む（未実施なら EC2 で実行）

## 次の作業（任意）
- 本番 EC2 で SES の手順に従い mail.local.php を配置し、招待再送でメールが届くか確認する。
