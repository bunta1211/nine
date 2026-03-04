# EC2 への接続と環境設定 手順書

SSH 接続から環境変数・DB テストまで、**コピー＆ペーストで実行できる**手順です。

---

## 前提

- EC2 パブリック IP（Elastic IP）: `54.95.86.79`
- キーファイル: `C:\Users\user\Downloads\social9-key.pem`
- RDS エンドポイント: `database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com`

---

## ステップ1: PowerShell を開く

1. Windows の **スタートメニュー** を開く
2. **「PowerShell」** と入力
3. **Windows PowerShell** を起動

---

## ステップ2: EC2 に SSH 接続する

PowerShell に以下を **1行ずつ** 入力して Enter を押す：

```powershell
ssh -i "C:\Users\user\Downloads\social9-key.pem" ec2-user@54.95.86.79
```

初回は `yes` と入力して Enter。  
接続できると、次のようなプロンプトが表示されます：

```
[ec2-user@ip-172-31-6-133 ~]$
```

---

## ステップ3: EC2 上で実行するコマンド（コピー用）

**接続後、以下を順番にコピーして貼り付け、 Enter を押してください。**

---

### ① まずこれ（MySQL クライアント）

※ Amazon Linux 2023 では `mariadb105` パッケージを使用します。

```
sudo dnf install -y mariadb105
```

---

### ② 環境変数設定（コピー用）

**⚠️ このファイルはパスワードを含みます。Git にコミットしないでください。設定後は本セクションを削除するか、.gitignore に追加してください。**

```
sudo tee /etc/httpd/conf.d/db-env.conf << 'ENVEOF'
SetEnv DB_HOST database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com
SetEnv DB_NAME social9
SetEnv DB_USER admin
SetEnv DB_PASS Narakenn3bunta
SetEnv APP_ENV production
ENVEOF
sudo systemctl restart httpd
```

---

### ③ RDS 接続テスト（実行後、パスワード入力が求められます）

```
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p -e "SELECT 1"
```

正しく接続できれば `1` が表示されます。

---

### ③-2. PHP-FPM で DB 接続エラーが出る場合（database.aws.php を作成）

`SetEnv` が PHP-FPM に渡らない場合は、`config/database.aws.php` を作成する：

```bash
# EC2 上で実行（DB_PASS は db-env.conf と同じ値）
sudo tee /var/www/html/config/database.aws.php << 'DBCONF'
<?php
if (!defined('DB_HOST')) {
    define('DB_HOST', 'database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com');
    define('DB_NAME', 'social9');
    define('DB_USER', 'admin');
    define('DB_PASS', 'Narakenn3bunta');
}
DBCONF

sudo chmod 644 /var/www/html/config/database.aws.php
```

※ パスワードは db-env.conf の値と同じにする。本番では強力なパスワードを使用すること。

---

### ③-3. データベース接続エラー「Permission denied」の場合

PHP から RDS への接続が SELinux でブロックされている場合：

```bash
# Apache が外部へネットワーク接続できるようにする（RDS 接続用）
sudo setsebool -P httpd_can_network_connect 1

sudo systemctl restart httpd
```

---

## ステップ4: パスワードを別途入力する場合

パスワードをコマンドに含めたくない場合：

```bash
# 1. まずホスト・DB名・ユーザーだけ設定
sudo tee /etc/httpd/conf.d/db-env.conf << 'EOF'
SetEnv DB_HOST database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com
SetEnv DB_NAME social9
SetEnv DB_USER admin
SetEnv DB_PASS 
SetEnv APP_ENV production
EOF

# 2. エディタでパスワードを追加
sudo nano /etc/httpd/conf.d/db-env.conf
# 「SetEnv DB_PASS 」の後ろにパスワードを追記
# 保存: Ctrl+O → Enter → Ctrl+X

# 3. Apache 再起動
sudo systemctl restart httpd
```

---

## ステップ5: WinSCP でアップロードできるようにする（初回のみ）

**今後も WinSCP でファイルを直接アップロードできるよう、一度だけ実行してください。**

SSH 接続後、以下を実行：

```bash
# /var/www/html を ec2-user 所有にする（WinSCP で上書き可能に）
sudo chown -R ec2-user:apache /var/www/html/

# uploads, tmp, logs は Apache が書き込むため、グループに書き込み権限を付与
sudo chmod -R g+w /var/www/html/uploads /var/www/html/tmp /var/www/html/logs
```

これで以降、WinSCP からファイルをドラッグ＆ドロップするだけで反映されます。

> **補足**: `apache` は Apache のグループ名（Amazon Linux 2023）。Apache がファイルを読みつつ、ec2-user がアップロードできる状態になります。

---

## ステップ6: デプロイ反映の確認（ファイルが反映される体制）

**アップロードしたファイルが正しく反映されているか確認する手順です。**

### 6-1. server-check.php をアップロード

1. ローカル `api/server-check.php` を WinSCP で `/var/www/html/api/` にアップロード
2. ブラウザで次の2つを開いて比較：
   - `http://social9.jp/api/server-check.php`
   - `http://54.95.86.79/api/server-check.php`

### 6-2. 判定

| 結果 | 意味 | 対処 |
|------|------|------|
| social9.jp で 404（IP では表示される） | Apache VirtualHost が別 DocumentRoot を参照 | 6-5 へ |
| social9.jp で 404 や接続不可 | DNS が EC2 を指していない | Route 53 / ムームー DNS 設定を確認 |
| 2つのURLで `server_addr` が**異なる** | social9.jp が旧サーバーを指している | DNS の切り替え待ち or 設定見直し |
| 2つのURLで**同じ** かつ `topbar_mtime` が今日 | 反映OK | ブラウザで Ctrl+Shift+R で強制リロード |
| 2つのURLで**同じ** だが mtime が古い | ファイル未アップロード | WinSCP で該当ファイルを再アップロード |

### 6-3. 一時的に IP 直接で確認する

DNS がまだ切り替わっていない場合、`http://54.95.86.79/chat.php` で直接アクセスすると、最新のファイルが反映されているか確認できます。

### 6-4. 403 Forbidden が出る場合

EC2 に SSH 接続して実行：

```bash
sudo chcon -R -t httpd_sys_content_t /var/www/html/
sudo systemctl restart httpd
```

SELinux のコンテキストが原因で Apache がファイルを読み取れない場合に有効です。

### 6-5. social9.jp で 404 が出る場合（IP では表示される）

**重要：まずプロトコルを確認**

ブラウザが自動で HTTPS に切り替わっている場合、**旧サーバー（heteml）** に接続している可能性があります。旧サーバーには SSL があり、EC2 にはまだ SSL がないためです。

**テスト：** アドレスバーに **`http://`** を明示してアクセス：

```
http://social9.jp/api/server-check.php
```

- **200 OK（JSON 表示）** → social9.jp は EC2 を参照。Chrome の「安全でない通信」警告は無視して続行
- **404 のまま** → 下記の Chrome DNS 設定を確認

**Chrome の DNS 設定を確認（DoH の影響）**

Chrome の「プライバシーとセキュリティ」で DNS  over HTTPS が有効だと、nslookup と異なる結果になることがあります。

1. Chrome の **設定** → **プライバシーとセキュリティ** → **セキュリティ**
2. **「セキュア DNS を使用する」** を **オフ** にする
3. ブラウザを再起動してから、`http://social9.jp/api/server-check.php` を再試行

### 6-6. Apache VirtualHost の確認（6-5 でも解決しない場合）

**nslookup で 54.95.86.79 を指しているのに** social9.jp だけ 404 になる場合、Apache の **VirtualHost** が social9.jp 用に別の DocumentRoot を指定している可能性があります。

EC2 に SSH 接続して、以下を実行：

```bash
# Apache 設定で social9.jp や DocumentRoot を検索
sudo grep -r "social9\|DocumentRoot" /etc/httpd/
```

**表示例：**
- `DocumentRoot /var/www/html` → 正しい（両方同じ）
- `DocumentRoot /var/www/social9` など別パス → **social9.jp が別ディレクトリを参照している**

**対処：** social9.jp 用 VirtualHost の DocumentRoot を `/var/www/html` に統一する。

```bash
# 設定ファイルを編集（ファイル名は環境により異なる）
sudo nano /etc/httpd/conf.d/ssl.conf
# または
sudo nano /etc/httpd/conf.d/vhost.conf
```

`<VirtualHost *:443>` または `*:80` 内の `DocumentRoot` を次のように変更：

```
DocumentRoot /var/www/html
```

保存後、Apache を再起動：

```bash
sudo systemctl restart httpd
```

---

## 補足

### 私（AI）が代わりにできないこと

- **SSH 接続の実行** … お使いの PC と EC2 の間のネットワーク接続が必要です
- **EC2 上でのコマンド実行** … サーバー上でコマンドを実行する権限がこちらにはありません

### できること

- 上記の手順書やスクリプトの作成
- 実行内容の説明
- エラーが出た場合の対処方法の案内

---

## 次のステップ（環境変数設定後）

1. **DB の作成** … RDS に `social9` データベースを作成
2. **データのインポート** … バックアップした SQL を RDS にインポート
3. **アプリのデプロイ** … Social9 のソースを EC2 に配置
4. **HTTPS の設定** … [AWS_HTTPS_SETUP_STEPS.md](./AWS_HTTPS_SETUP_STEPS.md) を参照

---

*関連: [AWS移転 起動手順](./AWS_MIGRATION_STEPS.md)*
