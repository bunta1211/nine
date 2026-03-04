# icon_style マイグレーションの実行方法（EC2・本番サーバー）

## 注意
- **ALTER 文はターミナルに直接貼らないでください。** シェルが解釈してエラーになります。
- 必ず **MySQL に接続したあと**、`mysql>` プロンプトが出ている状態で SQL を貼り付けます。

---

## 手順

### 1. MySQL に接続する

プロジェクトの DB 設定を確認してから接続する場合（プロジェクトのフォルダで）:

```bash
cd /var/www/html/nine
php config/show_mysql_connection.php
```

表示された「コピー用」の行をコピーし、ターミナルに貼り付けて Enter。  
例: `mysql -h xxx.rds.amazonaws.com -u admin -p social9`  
パスワードを聞かれたら入力して Enter。

※ プロジェクトの場所が違う場合は、次のように探してください。

```bash
find /var/www -name "database.php" -path "*/config/*" 2>/dev/null | head -1
```

出たパスの親がプロジェクトルートです（例: `/var/www/html/nine/config/database.php` → ルートは `/var/www/html/nine`）。

---

### 2. 接続コマンドが分かっている場合

DB のホスト・ユーザー・データベース名が分かっていれば、そのまま接続できます。

```bash
mysql -h ホスト名 -u ユーザー名 -p データベース名
```

例（RDS のとき）:

```bash
mysql -h database-1.xxxxx.ap-northeast-1.rds.amazonaws.com -u admin -p social9
```

`-p` のあとでパスワードを聞かれるので入力して Enter。  
接続できると次のようにプロンプトが変わります:

```
mysql>
```

---

### 3. MySQL の中で SQL を実行する

`mysql>` が出ている状態で、**次の4行をまとめてコピーして貼り付け**、Enter を押します。

```sql
ALTER TABLE conversations ADD COLUMN icon_style VARCHAR(50) DEFAULT 'default' AFTER icon_path;
ALTER TABLE conversations ADD COLUMN icon_pos_x FLOAT DEFAULT 0 AFTER icon_style;
ALTER TABLE conversations ADD COLUMN icon_pos_y FLOAT DEFAULT 0 AFTER icon_pos_x;
ALTER TABLE conversations ADD COLUMN icon_size INT DEFAULT 100 AFTER icon_pos_y;
```

すでにカラムがある場合は `Duplicate column name` と出ますが、そのカラムはスキップされるだけなので問題ありません。

---

### 4. MySQL から抜ける

```sql
exit
```

---

## 一発で実行する場合（パスワードをコマンドに含めない）

接続情報が分かっているときは、次のように 1 行で実行することもできます。  
**`ホスト名`・`ユーザー名`・`データベース名` を自分の環境に置き換えてください。**

```bash
mysql -h ホスト名 -u ユーザー名 -p データベース名 -e "ALTER TABLE conversations ADD COLUMN icon_style VARCHAR(50) DEFAULT 'default' AFTER icon_path; ALTER TABLE conversations ADD COLUMN icon_pos_x FLOAT DEFAULT 0 AFTER icon_style; ALTER TABLE conversations ADD COLUMN icon_pos_y FLOAT DEFAULT 0 AFTER icon_pos_x; ALTER TABLE conversations ADD COLUMN icon_size INT DEFAULT 100 AFTER icon_pos_y;"
```

実行するとパスワード入力のプロンプトが出るので、パスワードを入力して Enter で実行されます。
