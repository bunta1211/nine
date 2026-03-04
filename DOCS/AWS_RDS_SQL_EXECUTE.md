# AWS RDS で SQL を実行する方法

heteml では phpMyAdmin 等で SQL を実行していましたが、AWS では以下の方法があります。

**初めて本番で SQL を実行するとき**は、**「本番で SQL を実行する手順（ステップ形式）」** [PRODUCTION_SQL_STEP_BY_STEP.md](./PRODUCTION_SQL_STEP_BY_STEP.md) に、指示→実行→結果報告の流れで手順をまとめてあります。同じやり方で次回から実行できます。

**次のSQLの追加があったとき**は、**「次のSQL追加時にも使える共通手順」** を参照すると、同じ流れで実行できます。

---

## 方法1: EC2 から mysql コマンドで実行（推奨）

EC2 は RDS に接続できるので、SSH で EC2 に入り、mysql クライアントから SQL を実行します。

### 手順

#### 1. EC2 に SSH 接続

```powershell
ssh -i "C:\Users\user\Downloads\social9-key.pem" ec2-user@54.95.86.79
```

#### 2. MySQL クライアントをインストール（未導入の場合）

```bash
sudo dnf install -y mariadb105
```

#### 3. SQL ファイルをアップロードして実行

**A) WinSCP で SQL ファイルをアップロードする場合**

1. WinSCP で EC2 に接続
2. `database/migration_error_logs.sql` を `/home/ec2-user/` にアップロード
3. SSH で以下を実行（パスワードは db-env.conf と同じ）：

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < /home/ec2-user/migration_error_logs.sql
```

**B) SQL を直接コピペする場合**

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9
```

パスワード入力後、mysql プロンプト `mysql>` が表示されたら、以下の SQL を貼り付けて Enter：

```sql
CREATE TABLE IF NOT EXISTS error_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    error_type ENUM('js', 'api', 'php') DEFAULT 'js' COMMENT 'エラー種別',
    error_message TEXT NOT NULL COMMENT 'エラーメッセージ',
    error_stack TEXT COMMENT 'スタックトレース',
    url VARCHAR(500) COMMENT '発生URL',
    user_agent VARCHAR(500) COMMENT 'ブラウザ情報',
    user_id INT COMMENT 'ユーザーID（ログイン時）',
    ip_address VARCHAR(45) COMMENT 'IPアドレス',
    extra_data JSON COMMENT '追加情報',
    occurrence_count INT DEFAULT 1 COMMENT '発生回数',
    first_occurred_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '初回発生日時',
    last_occurred_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最終発生日時',
    is_resolved TINYINT(1) DEFAULT 0 COMMENT '解決済みフラグ',
    resolved_at DATETIME COMMENT '解決日時',
    resolved_by INT COMMENT '解決者ID',
    notes TEXT COMMENT 'メモ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_error_type (error_type),
    INDEX idx_user_id (user_id),
    INDEX idx_is_resolved (is_resolved),
    INDEX idx_last_occurred (last_occurred_at),
    INDEX idx_error_hash (error_message(100), url(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

終了は `exit` と入力。

---

## 方法2: AWS RDS Query Editor（ブラウザから実行）

AWS マネジメントコンソールの RDS に、ブラウザで SQL を実行できる機能があります。

### 手順

1. **AWS マネジメントコンソール**にログイン
2. **RDS** サービスを開く
3. 左メニューから **「Query Editor」** を選択
4. 接続情報を入力：
   - **DB インスタンス**: 対象の RDS を選択
   - **認証**: ユーザー名 / パスワード
   - **データベース**: `social9`
5. **「Connect」** で接続
6. クエリ欄に上記の `CREATE TABLE` 文を貼り付け
7. **「Run」** で実行

※ RDS のバージョンや IAM 権限によっては Query Editor が利用できない場合があります。

---

## 方法3: phpMyAdmin を EC2 に設置する場合

プロジェクトに `phpmyadmin` フォルダがあります。これらを EC2 の `/var/www/html/phpmyadmin/` にアップロードし、`config/database.aws.php` の内容で接続設定をすれば、ブラウザから https://social9.jp/phpmyadmin でアクセスできます。

**注意**: セキュリティのため、アクセス制限（IP 制限や Basic 認証）をかけることを推奨します。

---

## 翻訳テーブルを一括作成する（コピー用）

### ステップ1: SQL ファイルを EC2 に置く

**「No such file or directory」が出る場合は、先にファイルを EC2 に送ってください。**

**A) 手元の PC（PowerShell）から SCP で送る（推奨）**

プロジェクトのフォルダ（`nine` がある場所）で、鍵と EC2 の IP を自分の環境に合わせて実行：

```powershell
scp -i "C:\Users\user\Downloads\social9-key.pem" database/translation_tables_for_production.sql ec2-user@54.95.86.79:/home/ec2-user/
```

※ `54.95.86.79` は EC2 のパブリック IP に読み替えてください。

**B) WinSCP で送る**

1. WinSCP で EC2 に接続
2. 左側で `c:\xampp\htdocs\nine\database\` を開く
3. `translation_tables_for_production.sql` を右側の `/home/ec2-user/` にドラッグでアップロード

### ステップ2: EC2 で mysql を実行

SSH で EC2 に入ったあと、以下を実行（パスワードのみ入力）：

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < /home/ec2-user/translation_tables_for_production.sql
```

※ ホスト・ユーザー名・DB名が違う場合は、下記「接続情報の確認」の値に合わせて `-h` / `-u` / 末尾の DB 名を書き換えてください。

---

## 次のSQL追加時にも使える共通手順

本番で**新しいマイグレーションやテーブル追加のSQL**を実行するときは、次のどちらかで行います。接続コマンドは共通なので、下記「接続情報の確認」の値（ホスト・ユーザー名・DB名）をそのまま使えます。

### 手順A: SQLファイルを用意して実行（ファイルがある場合）

1. プロジェクトの `database/` に実行したい `.sql` を置く（例: `database/○○_migration.sql`）。
2. 手元のPCからEC2へファイルを送る。送り先: `/home/ec2-user/`。
   - **SCP（PowerShellでプロジェクトフォルダから）:**
   ```powershell
   scp -i "C:\Users\user\Downloads\social9-key.pem" database/○○.sql ec2-user@54.95.86.79:/home/ec2-user/
   ```
   - **WinSCP:** 左で `c:\xampp\htdocs\nine\database\` を開き、該当 `.sql` を右の `/home/ec2-user/` にドラッグ。
3. EC2にSSHで入り、以下を実行（`○○.sql` を実際のファイル名に読み替える）:
   ```bash
   mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < /home/ec2-user/○○.sql
   ```

### 手順B: ファイルを作らずに貼り付けで実行（手軽な方法）

1. EC2にSSHで入り、MySQLに接続:
   ```bash
   mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9
   ```
2. パスワード入力後、プロンプトが `MySQL [social9]>` になったら、実行したいSQLをそのまま貼り付けて Enter。
3. 終了するときは `exit` と入力。

※ 接続情報が違う場合は下記「接続情報の確認」を参照。

---

## 接続情報の確認

- **ホスト**: `database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com`
- **ポート**: 3306
- **データベース名**: `social9`
- **ユーザー名**: `admin`
- **パスワード**: `db-env.conf` または `config/database.aws.php` に設定した値

---

## 実行後の確認

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 -e "SHOW TABLES LIKE 'error_logs';"
```

`error_logs` が表示されれば、テーブル作成は成功しています。
