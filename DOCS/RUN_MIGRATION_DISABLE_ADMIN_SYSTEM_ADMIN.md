# 本番 DB で migration_disable_admin_system_admin.sql を実行する手順

旧システム管理者（admin@social9.jp）を無効化し、システム管理を Bunta（saitanibunta@social9.jp）に統一するための SQL を、本番データベースで実行する手順です。

---

## 実行する SQL の内容

- **対象**: `users` テーブルのうち `email = 'admin@social9.jp'` かつ `status != 'deleted'` の行
- **処理**: `status` を `'deleted'` にし、`email` を `_deleted_<id>_admin@social9.jp` に変更
- **回数**: 本番で **1 回だけ** 実行。既に無効化済みの場合は 0 行更新で問題ありません。

---

## 事前に用意するもの

| 項目 | 内容 |
|------|------|
| **PC** | PowerShell が使える Windows |
| **鍵ファイル** | `C:\Users\narak\Desktop\social9-key.pem`（実際のパスに合わせて読み替えてください） |
| **DB パスワード** | EC2 の `/etc/httpd/conf.d/db-env.conf` または `/var/www/html/config/database.aws.php` に書かれている **DB_PASS** の値 |

DB パスワードが分からない場合は、先に EC2 に SSH して次のコマンドで確認できます。

```bash
sudo cat /etc/httpd/conf.d/db-env.conf
```

または

```bash
grep DB_PASS /var/www/html/config/database.aws.php
```

---

## 方法 A: EC2 に SSH して SQL ファイルを流し込む（推奨）

### ステップ 1: PowerShell を開く

- スタートメニューで「PowerShell」と検索し、**Windows PowerShell** を開きます。

---

### ステップ 2: プロジェクトフォルダに移動

次のコマンドをコピーして貼り付け、Enter を押します。

```powershell
cd c:\xampp\htdocs\nine
```

---

### ステップ 3: SQL ファイルを EC2 に送る

次のコマンドをそのままコピーして実行します。  
（鍵のパスが `C:\Users\narak\Desktop\social9-key.pem` でない場合は、その部分だけ書き換えてください。）

```powershell
scp -i "C:\Users\narak\Desktop\social9-key.pem" c:\xampp\htdocs\nine\database\migration_disable_admin_system_admin.sql ec2-user@54.95.86.79:/home/ec2-user/
```

- **初回のみ**: 「Are you sure you want to continue connecting?」と出たら `yes` と入力して Enter。
- 成功すると、何もメッセージが出ないか、「migration_disable_admin_system_admin.sql」のような表示だけになります。
- **失敗例**: `No such file or directory` → 鍵のパスやファイルパスを確認してください。

---

### ステップ 4: EC2 に SSH で接続する

次のコマンドをコピーして実行します。

```powershell
ssh -i "C:\Users\narak\Desktop\social9-key.pem" ec2-user@54.95.86.79
```

- 接続できると、プロンプトが `[ec2-user@ip-xxx-xxx-xxx-xxx ~]$` のようになります。  
  ここから先は **EC2 の中** での操作です。

---

### ステップ 5: EC2 上で MySQL に SQL を流し込む

EC2 に接続した状態で、次のコマンドを **1 行まるごと** コピーして貼り付け、Enter を押します。

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < /home/ec2-user/migration_disable_admin_system_admin.sql
```

- 直後に **`Enter password:`** と表示されます。
- **DB_PASS** の値を入力します（入力しても画面には表示されません）。入力し終えたら Enter を押します。
- パスワードが正しければ、エラーが出ずにコマンドが終了します。
- **「Query OK, 1 row affected」** のような表示が出れば、admin が 1 件無効化されたことを意味します。  
  **「Query OK, 0 rows affected」** の場合は、もともと該当行がなかったか、既に無効化済みです。

---

### ステップ 6: EC2 から抜ける

次のコマンドを実行します。

```bash
exit
```

PowerShell のプロンプトに戻れば完了です。

---

## 方法 B: すでに EC2 にファイルがある場合（デプロイ済み）

main にマージ済みで、GitHub 経由で本番にデプロイされている場合は、SQL ファイルが `/var/www/html/database/` に既にあることがあります。

その場合は、**ステップ 3（scp）は不要**です。

1. **ステップ 4** まで同じ（EC2 に SSH）。
2. EC2 上で、次のどちらかを実行します。

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < /var/www/html/database/migration_disable_admin_system_admin.sql
```

または、いったんディレクトリに移動してから実行する場合:

```bash
cd /var/www/html
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < database/migration_disable_admin_system_admin.sql
```

3. パスワードを入力し、**ステップ 6** で `exit` して終了。

---

## 方法 C: AWS RDS Query Editor（ブラウザ）で実行する場合

1. **AWS マネジメントコンソール** にログインし、**RDS** を開く。
2. 左メニューから **「Query Editor」** を選択。
3. 接続設定で次を入力し、接続する。
   - **データベース**: `social9`
   - **ユーザー名**: `admin`
   - **パスワード**: 上記の **DB_PASS**
4. クエリ欄に、次の SQL を貼り付けて **Run** をクリック。

```sql
UPDATE users
SET
    status = 'deleted',
    email = CONCAT('_deleted_', id, '_', email),
    updated_at = NOW()
WHERE email = 'admin@social9.jp'
  AND status != 'deleted';
```

5. 実行結果で「1 row affected」または「0 rows affected」が表示されれば完了です。

※ RDS のバージョンや IAM 設定によっては Query Editor が使えない場合があります。その場合は方法 A または B を使ってください。

---

## 実行後の確認（任意）

admin が無効化されたか確認したい場合は、EC2 に SSH した状態で次のように実行します。

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 -e "SELECT id, email, status FROM users WHERE email LIKE '%admin%social9%' OR email LIKE '%_deleted_%';"
```

- `admin@social9.jp` の行がなくなり、`_deleted_<id>_admin@social9.jp` で `status = deleted` の行があれば無効化されています。
- 終了するときは `exit` で mysql を抜け、もう一度 `exit` で SSH を抜けます。

---

## よくあるエラーと対処

| 現象 | 対処 |
|------|------|
| `Permission denied (publickey)` | 鍵のパスが正しいか、`-i "..."` の指定を確認する。 |
| `No such file or directory` | 鍵ファイルや SQL ファイルのパスが実際の環境と一致しているか確認する。 |
| `Access denied for user 'admin'@'...'` | DB パスワード（DB_PASS）が誤っている。EC2 の db-env.conf 等で再確認する。 |
| `Unknown database 'social9'` | データベース名が本番で `social9` か確認する。 |
| `mysql: command not found` | EC2 に MySQL クライアントが入っていない。`sudo dnf install -y mariadb105` で導入する。 |

---

## 関連ドキュメント

- [本番 DB への接続方法](PRODUCTION_DB_ACCESS.md)
- [本番で SQL を実行する手順](SERVER_DEPLOY_AND_SQL.md)
