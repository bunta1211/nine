# EC2 に HTTPS（SSL）を設定する手順

social9.jp で `https://` アクセスを有効にする手順です。**順番に実行**してください。

---

## 前提

- EC2（Elastic IP）: 54.95.86.79
- ドメイン: social9.jp（Route 53 で EC2 を指している）
- hosts で social9.jp → 54.95.86.79 にしている（または DNS が反映済み）

---

## ステップ1: セキュリティグループで 443 を開放

1. **AWS コンソール** → **EC2** → **インスタンス** → 該当インスタンスを選択
2. **セキュリティ** タブ → **セキュリティグループ** のリンクをクリック
3. **インバウンドルール** → **編集**
4. **ルールを追加**：
   - **タイプ**: HTTPS
   - **ソース**: 0.0.0.0/0（または任意の IP）
   - **説明**: Allow HTTPS from anywhere
5. **保存**

---

## ステップ2: social9.jp 用 VirtualHost を作成

EC2 に SSH 接続し、以下を実行：

```bash
# social9.jp 用の VirtualHost 設定を作成
sudo tee /etc/httpd/conf.d/social9-vhost.conf << 'EOF'
<VirtualHost *:80>
    ServerName social9.jp
    ServerAlias www.social9.jp
    DocumentRoot /var/www/html
    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
EOF

# Apache 設定をテスト
sudo apachectl configtest

# Apache を再起動
sudo systemctl restart httpd
```

---

## ステップ3: Certbot のインストール

```bash
# Certbot と Apache プラグインをインストール（Amazon Linux 2023）
sudo dnf install -y certbot python3-certbot-apache

# 自動更新タイマーを有効化
sudo systemctl enable --now certbot-renew.timer
```

---

## ステップ4: 証明書の取得

**※ メールアドレスは実際のアドレスに置き換えてください。**

```bash
# 対話式で証明書を取得（推奨）
sudo certbot --apache -d social9.jp -d www.social9.jp
```

実行すると以下を入力します：

1. **メールアドレス** … 証明書の有効期限通知用
2. **利用規約同意** … Y
3. **メールニュース** … Y または N
4. **リダイレクト** … 2（HTTP を HTTPS にリダイレクト）を推奨

---

## ステップ5: APP_URL の更新

```bash
# db-env.conf に APP_URL を追加
echo 'SetEnv APP_URL https://social9.jp' | sudo tee -a /etc/httpd/conf.d/db-env.conf

# Apache を再起動
sudo systemctl restart httpd
```

---

## ステップ6: アプリの APP_URL 設定

PHP-FPM で SetEnv が効かない場合、`app.local.php` で APP_URL を設定します。

**WinSCP で** `config/app.local.production.php` を `config/app.local.php` としてアップロードするか、EC2 で実行：

```bash
# app.local.production.php を app.local.php としてコピー
cp /var/www/html/config/app.local.production.php /var/www/html/config/app.local.php

# 内容確認（APP_URL が https://social9.jp になっていること）
grep APP_URL /var/www/html/config/app.local.php
```

---

## 確認

1. **https://social9.jp** をブラウザで開く
2. 鍵マークが表示され、「保護された通信」と表示されれば OK
3. ブックマークを **https://social9.jp** に更新

---

## 自動更新の確認

```bash
# 更新タイマーの状態
sudo systemctl status certbot-renew.timer

# テスト更新（実際には更新しない）
sudo certbot renew --dry-run
```

---

## トラブルシューティング

### 証明書取得でエラーになる場合

- **DNS が正しいか確認**: `dig +short social9.jp` で 54.95.86.79 が返るか
- **80 番ポートが開いているか**: セキュリティグループで HTTP を許可
- **Apache が稼働しているか**: `sudo systemctl status httpd`

### 証明書の確認

```bash
sudo certbot certificates
```

---

*関連: [AWS_DOMAIN_HTTPS_GUIDE.md](./AWS_DOMAIN_HTTPS_GUIDE.md)*
