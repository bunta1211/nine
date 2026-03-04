# 本番データベースへの接続方法

本番（social9.jp / EC2）で使っている DB に接続する方法です。

---

## 本番でどの DB を使っているか

- **EC2 に `config/database.aws.php` がある、または Apache の `db-env.conf` で DB 環境変数が設定されている場合**  
  → **AWS RDS（MySQL）** に接続しています。  
  下記「方法1: SSH + mysql」または「方法2: AWS RDS Query Editor」を使います。

- **上記がなく、かつ Host が social9.jp の場合**  
  → **heteml の MySQL**（`mysql322.phy.heteml.lan`、DB 名 `_social9`）にフォールバックしています。  
  → その場合は **heteml の管理画面から提供されている phpMyAdmin** を使います（URL は heteml のコントロールパネルで確認）。

現在の構成（AWS 移行済み）では、**RDS を使っている想定**です。

---

## 方法1: SSH + mysql（推奨）

EC2 に SSH で入り、その上から RDS に `mysql` で接続します。

### 1. EC2 に SSH 接続

**キーファイルのパスは、実際に .pem を置いている場所に書き換えてください。**  
（DOCS の例では `C:\Users\user\Downloads\social9-key.pem` や `C:\Users\user\Desktop\social9-key.pem` となっていることがありますが、存在しないパスだと「No such file or directory」になります。）

```powershell
ssh -i "C:\Users\<あなたのユーザー名>\Desktop\social9-key.pem" ec2-user@54.95.86.79
```

初回はホスト鍵の確認で `yes` と入力。

### 2. EC2 上で MySQL クライアントを入れる（未導入の場合）

```bash
sudo dnf install -y mariadb105
```

### 3. RDS に接続

パスワードは **`/etc/httpd/conf.d/db-env.conf`** または **`/var/www/html/config/database.aws.php`** に書かれている `DB_PASS` です。

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9
```

プロンプトが `mysql>` または `MySQL [social9]>` になったら、SQL を貼り付けて実行できます。終了は `exit`。

### 4. SQL ファイルを流し込む場合

1. **SQL ファイルを EC2 に置く**
   - プロジェクトの `database` フォルダを scp で送る例:
     ```powershell
     scp -i "<鍵のパス>" c:\xampp\htdocs\nine\database\migration_messages_reply_to_id.sql ec2-user@54.95.86.79:/home/ec2-user/
     ```
   - または Web ルートに database がある場合は `/var/www/html/database/` を利用。
2. EC2 に SSH した状態で、RDS に接続してファイルを流し込む:

```bash
# ホームに置いた場合
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < /home/ec2-user/migration_messages_reply_to_id.sql
```

```bash
# /var/www/html にプロジェクトがあり database フォルダがある場合
cd /var/www/html
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < database/migration_messages_reply_to_id.sql
```

プロンプトでパスワード（`db-env.conf` の `DB_PASS`）を入力します。

---

## 方法2: AWS RDS Query Editor（ブラウザ）

1. **AWS マネジメントコンソール**にログイン
2. **RDS** → 左メニュー **「Query Editor」**
3. 対象の RDS インスタンスを選択し、認証（ユーザー名 `admin`、パスワードは db-env.conf / database.aws.php の値）とデータベース名 `social9` で接続
4. クエリ欄に SQL を貼り付けて **Run**

※ RDS のバージョンや IAM により利用できない場合があります。

---

## 方法3: phpMyAdmin を EC2 に置いている場合

プロジェクトの `phpmyadmin` フォルダを EC2 の `/var/www/html/phpmyadmin/` にアップロードし、`config/database.aws.php` の内容で接続設定している場合は、次の URL でアクセスできます。

- **https://social9.jp/phpmyadmin**

※ 設置していない場合はこの URL は使えません。その場合は方法1または方法2を使用してください。

---

## 接続情報のまとめ（RDS の場合）

| 項目 | 値 |
|------|-----|
| ホスト | `database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com` |
| ポート | 3306 |
| データベース名 | `social9` |
| ユーザー名 | `admin` |
| パスワード | `db-env.conf` または `config/database.aws.php` の `DB_PASS` |

---

## 接続コマンドの確認（EC2 上で）

EC2 に SSH したあと、次のスクリプトで接続用の `mysql` コマンドを表示できます（`config/show_mysql_connection.php` を EC2 に置いている場合）。

```bash
php /var/www/html/config/show_mysql_connection.php
```

未配置の場合は、上記「接続情報のまとめ」の値で `mysql -h ... -u ... -p ...` を組み立ててください。

---

## 関連ドキュメント

- [本番で SQL を実行する手順（ステップ形式）](./PRODUCTION_SQL_STEP_BY_STEP.md) — 指示→実行→結果報告の流れで記録
- [AWS RDS で SQL を実行する方法](./AWS_RDS_SQL_EXECUTE.md)、[EC2 への接続と環境設定](./AWS_EC2_SETUP_GUIDE.md)
