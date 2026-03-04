# 本番（AWS RDS）で SQL を実行する手順 — ステップ形式

本番データベース（AWS RDS）に SQL を実行するとき、**指示 → 実行 → 結果報告 → 次の指示** の形で進められるよう、手順を記録したものです。次回から同じやり方で実行できます。

---

## 前提

- **本番 DB**: AWS RDS（MySQL）。EC2 から接続する。
- **接続情報**: [本番データベースへの接続方法](./PRODUCTION_DB_ACCESS.md) の「接続情報のまとめ」を参照。
- **パスワード**: EC2 の `/etc/httpd/conf.d/db-env.conf` または `/var/www/html/config/database.aws.php` の `DB_PASS`。

---

## 手順一覧（次回から使うときの流れ）

| 順番 | やること | 確認・報告 |
|------|----------|------------|
| 1 | EC2 に SSH 接続する | プロンプト `[ec2-user@... ~]$` が出たら成功。エラーなら内容を記録 |
| 2 | RDS に接続できるかテストする | `SELECT 1` が表示されれば OK。`mysql: command not found` ならクライアントをインストール |
| 3 | SQL ファイルを EC2 にアップロードする | アップロード先パスをメモ（`/home/ec2-user/` か `/home/ec2-user/database/`） |
| 4 | EC2 上で `mysql < ファイルパス` を実行する | 成功メッセージまたはエラー全文を記録 |
| 5 | エラーが出た場合は内容に応じて SQL を修正し、3〜4 を繰り返す | — |
| 6 | （必要なら）PHP ファイルを本番に反映する | — |

---

## ステップ 1: EC2 に SSH 接続する

### 実行

1. **PowerShell** を開く。
2. 次を実行する（**鍵のパスは実際の .pem の場所に書き換える**）。

```powershell
ssh -i "C:\Users\<あなたのユーザー名>\Desktop\social9-key.pem" ec2-user@54.95.86.79
```

- 例: 鍵がデスクトップにある場合  
  `C:\Users\narak\Desktop\social9-key.pem`
- 初回のみ「`Are you sure you want to continue connecting (yes/no)?`」と出たら、**`yes`** と入力して Enter（`y` だけでは不可）。

### 成功の目安

プロンプトが次のようになれば成功です。

```
[ec2-user@ip-172-31-6-133 ~]$
```

### よくある失敗

- **`Identity file ... not accessible: No such file or directory`**  
  → `-i` で指定している .pem のパスが違う。実際にファイルがあるフォルダを指定する。
- **`Host key verification failed`**  
  → ホスト確認の質問に `yes` と入力していない。もう一度 SSH し、`yes` と打って Enter。

---

## ステップ 2: RDS に接続できるか確認する

### 実行

EC2 に接続した状態で、次を実行する（パスワードを聞かれたら RDS のパスワードを入力）。

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 -e "SELECT 1 AS test;"
```

### 成功の目安

次のように表示されれば成功です。

```
+------+
| test |
+------+
|    1 |
+------+
```

### よくある失敗

- **`mysql: command not found`**  
  → MySQL クライアントを入れてから、もう一度上記コマンドを実行する。

  ```bash
  sudo dnf install -y mariadb105
  ```

---

## ステップ 3: SQL ファイルを EC2 にアップロードする

### 実行（WinSCP の場合）

1. **WinSCP** で `ec2-user@54.95.86.79` に接続する。
2. **左（ローカル）**: `c:\xampp\htdocs\nine\database\` を開く。
3. **右（リモート）**: `/home/ec2-user/` を開く（または `/home/ec2-user/database/` を作ってそこにアップロード）。
4. 実行したい **`.sql` ファイル**を右側にドラッグしてアップロードする。

### 重要: アップロード先のパスを覚えておく

- 右側で **`/home/ec2-user/`** を開いてアップロードした場合  
  → ファイルのフルパスは **`/home/ec2-user/ファイル名.sql`**
- 右側で **`/home/ec2-user/database/`** を開いてアップロードした場合  
  → ファイルのフルパスは **`/home/ec2-user/database/ファイル名.sql`**

ステップ 4 の `mysql < ...` では、この**フルパス**をそのまま使う。

---

## ステップ 4: EC2 上で SQL を実行する

### 実行

EC2 に SSH した状態で、次を実行する（**ファイル名とパスはアップロード先に合わせる**）。

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < /home/ec2-user/database/○○.sql
```

- `○○.sql` を実際のファイル名に変える。
- ファイルを `/home/ec2-user/` に置いた場合は  
  `/home/ec2-user/○○.sql` にする。

パスワードを聞かれたら RDS のパスワードを入力する。

### 成功の目安

- SQL 内に `SELECT '...' AS message;` がある場合、そのメッセージが表示される。
- エラーが何も出ずにプロンプトに戻れば成功。

### よくあるエラーと対処

| エラー | 原因の例 | 対処 |
|--------|----------|------|
| **`No such file or directory`** | `mysql <` のパスが違う | アップロード先を確認し、`/home/ec2-user/` か `/home/ec2-user/database/` を含めた正しいパスを指定する |
| **`ERROR 3780 ... Referencing column ... and referenced column ... are incompatible`** | 外部キーで参照先の型と一致していない（例: 参照先が `INT` なのに参照元が `INT UNSIGNED`） | 参照先テーブル（例: `messages`, `users`）の `id` の型を確認し、SQL の参照元カラムを同じ型（`INT` または `INT UNSIGNED`）に合わせる |
| **`ERROR 1067 ... Invalid default value for '...'`** | デフォルト値が本番 MySQL で許可されていない（例: 絵文字を `DEFAULT` に指定） | 該当カラムの `DEFAULT` を空文字 `''` や ASCII の値に変更し、SQL を修正してから再度アップロード・実行する |

エラーが出た場合は、**表示されたエラー全文**を控え、SQL を修正してからステップ 3（アップロード）とステップ 4 をやり直す。

---

## ステップ 5: 実行結果を確認する（任意）

テーブルを作成した場合は、次のように存在を確認できる。

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 -e "SHOW TABLES LIKE 'テーブル名';"
```

参照先の型を確認したい場合（外部キーエラー対策）:

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 -e "DESCRIBE messages;" -e "DESCRIBE users;"
```

---

## ステップ 6: 関連する PHP を本番に反映する（必要な場合）

DB の変更に合わせて PHP を更新している場合は、本番にファイルを反映する。

- 手順: [本番（EC2）へのファイル反映 — PowerShell で scp](./DEPLOY_POWERSHELL_SCP.md) または WinSCP で該当ファイルを `/var/www/html/` 配下にアップロード。
- アップロード後、必要に応じて EC2 上で `sudo chown apache:apache /var/www/html/...` を実行する。

---

## 接続情報（コピー用）

| 項目 | 値 |
|------|-----|
| EC2 | `ec2-user@54.95.86.79` |
| RDS ホスト | `database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com` |
| ポート | 3306 |
| データベース名 | `social9` |
| ユーザー名 | `admin` |
| パスワード | `db-env.conf` または `config/database.aws.php` の `DB_PASS` |

### 接続テスト（1 行）

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 -e "SELECT 1 AS test;"
```

### SQL ファイルを流し込む（1 行・パスは要変更）

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < /home/ec2-user/database/○○.sql
```

---

## 関連ドキュメント

- [本番データベースへの接続方法](./PRODUCTION_DB_ACCESS.md)
- [AWS RDS で SQL を実行する方法](./AWS_RDS_SQL_EXECUTE.md)
- [本番（EC2）へのファイル反映 — PowerShell で scp](./DEPLOY_POWERSHELL_SCP.md)
