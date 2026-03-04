# サーバーでメール送信を SMTP（AWS SES）にする手順

EC2 では PHP の `mail()` が使えないため、**AWS SES の SMTP** で送信する設定が必要です。

---

## 手順 A: AWS コンソールで SES を設定する

### A-1. 送信元メールアドレスを検証する

1. [AWS コンソール](https://console.aws.amazon.com/) にログインする。
2. リージョンを **アジアパシフィック (東京) ap-northeast-1** にする（画面上部）。
3. 検索で **「SES」** または **「Amazon Simple Email Service」** を開く。
4. 左メニュー **「Identities」**（アイデンティティ）をクリック。
5. **「Create identity」** をクリック。
6. **「Email address」** を選び、送信に使うアドレスを入力（例: `noreply@social9.jp`）。
7. **「Create identity」** をクリック。
8. そのアドレスにメールが届くので、**メール内のリンクをクリック**して検証を完了する。
9. SES の Identities 一覧で、該当アドレスが **「Verified」** になっていることを確認する。

※ 送信元に使うアドレスは、必ずここで「検証済み」にしたものにしてください。

---

### A-2. SMTP 認証情報を作成する

1. SES の左メニューで **「SMTP settings」**（SMTP 設定）をクリック。
2. **「Create SMTP credentials」**（SMTP 認証情報を作成）をクリック。
3. IAM ユーザー名はそのままで **「Create user」** をクリック。
4. 画面に **「SMTP user name」** と **「SMTP password」** が表示される。
   - **SMTP パスワードはこの画面でしか表示されません。必ずコピーして安全な場所に保存してください。**
5. この **SMTP ユーザー名** と **SMTP パスワード** を、次の「手順 B」で使います。

---

## 手順 B: サーバーに mail.local.php を置く

### B-1. 送信元が東京リージョンの場合（ap-northeast-1）

サーバーの **`config`** フォルダに、次の内容で **`mail.local.php`** という名前のファイルを作成します。

```php
<?php
/**
 * メール送信設定（AWS SES SMTP）
 * このファイルは Git にコミットしないでください。
 */
define('MAIL_DRIVER', 'smtp');
define('MAIL_SMTP_HOST', 'email-smtp.ap-northeast-1.amazonaws.com');
define('MAIL_SMTP_PORT', 587);
define('MAIL_SMTP_USER', 'ここにSMTPユーザー名を貼り付け');
define('MAIL_SMTP_PASS', 'ここにSMTPパスワードを貼り付け');
define('MAIL_SMTP_ENCRYPTION', 'tls');
define('MAIL_FROM_EMAIL', 'noreply@social9.jp');  // A-1 で検証したアドレス
define('MAIL_FROM_NAME', 'Social9');
```

- **SMTP ユーザー名** … 手順 A-2 で表示された「SMTP user name」を貼り付け。
- **SMTP パスワード** … 手順 A-2 で表示された「SMTP password」を貼り付け。
- **MAIL_FROM_EMAIL** … 手順 A-1 で検証した送信元メールアドレスに合わせる。

### B-2. 別リージョンを使っている場合

SES を別リージョンで作成している場合は、`MAIL_SMTP_HOST` だけ変更します。

| リージョン | ホスト |
|------------|--------|
| 東京 ap-northeast-1 | `email-smtp.ap-northeast-1.amazonaws.com` |
| バージニア北部 us-east-1 | `email-smtp.us-east-1.amazonaws.com` |
| 大阪 ap-northeast-3 | `email-smtp.ap-northeast-3.amazonaws.com` |

### B-3. サーバーに配置する方法

**方法 1: 手元でファイルを作成してアップロード**

1. 上記の内容で `mail.local.php` を保存する（SMTP ユーザー名・パスワードを実際の値に置き換える）。
2. WinSCP などでサーバーに接続し、**`/var/www/html/config/`** に `mail.local.php` をアップロードする。

**方法 2: サーバー上で直接作成**

1. SSH で EC2 に接続する。
2. 次のコマンドで編集する（パスは環境に合わせて変更）。

```bash
sudo nano /var/www/html/config/mail.local.php
```

3. 上記の PHP の内容を貼り付け、SMTP ユーザー名・パスワードを入力する。
4. 保存して終了（Ctrl+O → Enter → Ctrl+X）。

---

## 手順 C: 動作確認

1. ブラウザで **メンバー管理** を開く。
2. 承諾前のメンバー（一覧で「登録済み」と表示）の **「送信」** をクリックする。
3. 「招待メールを送信しました」と表示され、相手にメールが届けば成功です。

---

## うまくいかないとき

- **「メールの送信に失敗しましたが、リンクは発行済みです」のまま**  
  - `config/mail.local.php` がサーバーに存在するか確認する。
  - `MAIL_DRIVER` が `smtp` になっているか確認する。
  - 送信元メールアドレスが SES で「Verified」か確認する。
- **SMTP エラーが出る**  
  - サーバーの PHP エラーログ（`error_log`）に `Mailer SMTP error:` や `SmtpSender:` のメッセージを確認する。
  - SMTP ユーザー名・パスワードのコピーミス、リージョンとホストの不一致がないか確認する。

詳細は `DOCS/AWS_SES_MAIL_SETUP.md` も参照してください。
