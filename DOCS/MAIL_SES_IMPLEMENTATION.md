# メール送信（AWS SES）実装メモ

## 目的
EC2 では `mail()` が使えないため、AWS SES で招待メール等を送信できるようにする。

## 方針
- **config/mail.php** でメール送信方法を切り替え（php=従来の mail() / smtp=SES の SMTP）
- **SES SMTP** を使用（AWS SDK ではなく PHPMailer + SMTP で軽量に）
- 設定は **config/mail.local.php**（本番用）と **config/mail.local.example.php**（サンプル）に記載

## 進捗

| 段階 | 内容 | 状態 |
|------|------|------|
| 1 | 進捗メモ作成 | ✅ |
| 2 | config/mail.php と mail.local.example.php 追加 | ✅ |
| 3 | PHPMailer 追加と Mailer.php の SMTP 対応 | ✅ 既存 |
| 4 | DOCS に SES 設定手順を追記 | これから |

---

## 実装済みの構成

- **config/mail.php** … デフォルトは `MAIL_DRIVER=php`。`mail.local.php` があれば先に読み込む。
- **config/mail.local.example.php** … 本番用サンプル（SES SMTP の例）。
- **includes/Mailer.php** … `MAIL_DRIVER=smtp` かつ SMTP 設定ありなら PHPMailer で送信、否则は `mail()`。
- **composer** … `phpmailer/phpmailer` 済み。サーバーで `composer install` が必要。

---
（以下、SES 設定手順）
