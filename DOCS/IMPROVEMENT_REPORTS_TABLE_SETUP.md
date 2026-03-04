# 本番で improvement_reports テーブルを作る手順（phpMyAdmin / PowerShell）

アップロード後、本番で「改善・デバッグログ」を使うには、先にデータベースにテーブルを作成する必要があります。

**phpMyAdmin が 403 Forbidden で使えない場合**は、下記「PowerShell（SSH + mysql）で実行する方法」を使ってください。過去ログでもこの方法で本番 SQL を実行しています。

---

## PowerShell（SSH + mysql）で実行する方法（推奨）

いつも使っている PowerShell の scp / ssh で、EC2 に SQL を送ってから EC2 上で `mysql` を実行します。

### ステップ1: SQL ファイルを EC2 に送る

PowerShell で、次のコマンドを実行します。**鍵のパス**は実際の .pem の場所に合わせて書き換えてください（例: `C:\Users\narak\Desktop\social9-key.pem`）。

```powershell
cd c:\xampp\htdocs\nine
```

```powershell
scp -i "C:\Users\narak\Desktop\social9-key.pem" c:\xampp\htdocs\nine\database\improvement_reports.sql ec2-user@54.95.86.79:/home/ec2-user/
```

（初回は「continue connecting (yes/no)?」と出たら **yes** と入力して Enter。）

### ステップ2: EC2 に SSH で入る

```powershell
ssh -i "C:\Users\narak\Desktop\social9-key.pem" ec2-user@54.95.86.79
```

プロンプトが `[ec2-user@... ~]$` のようになったら EC2 に入れています。

### ステップ3: EC2 上で MySQL に接続して SQL を流し込む

EC2 のプロンプトのまま、次のコマンドを実行します。

MySQL クライアントが未導入の場合（`mysql: command not found` が出た場合）:

```bash
sudo dnf install -y mariadb105
```

RDS に接続して SQL ファイルを実行（**パスワード**は `/etc/httpd/conf.d/db-env.conf` または `/var/www/html/config/database.aws.php` の `DB_PASS`）:

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < /home/ec2-user/improvement_reports.sql
```

パスワード入力後、エラーが出ずにプロンプトが戻れば成功です。

### ステップ4: テーブルができたか確認する（任意）

```bash
mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 -e "SHOW TABLES LIKE 'improvement_reports';"
```

`improvement_reports` と表示されれば OK です。

### ステップ5: SSH から抜ける

```bash
exit
```

---

## phpMyAdmin で実行する方法（設置している場合のみ）

**403 Forbidden の場合は上記 PowerShell 手順を使ってください。**

### 手順1：phpMyAdmin を開く

本番サーバーで phpMyAdmin にアクセスします。

- 例: `https://あなたのドメイン/phpmyadmin/` または EC2 の phpMyAdmin URL

ブラウザで開き、必要ならログインしてください。

---

## 手順2：データベースを選ぶ

左側の一覧から、**social9** というデータベースをクリックして選択します。

（別名のデータベースを使っている場合は、その名前に読み替えてください。）

---

## 手順3：SQL タブを開く

画面上部のタブのうち、**「SQL」** をクリックします。

---

## 手順4：次の SQL をコピーする

下の枠内をすべて選択してコピーしてください。

```
USE social9;

CREATE TABLE IF NOT EXISTS improvement_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL COMMENT '報告者（NULL=管理者手動作成）',
    title VARCHAR(255) NOT NULL,
    problem_summary TEXT NOT NULL,
    suspected_location TEXT NULL COMMENT '想定される原因・場所（ファイル名・処理名など）',
    suggested_fix TEXT NULL COMMENT '望ましい対応・修正方針',
    related_files VARCHAR(500) NULL COMMENT '関連しそうなファイル（カンマ区切りまたはJSON）',
    ui_location VARCHAR(255) NULL COMMENT '問題の場所（上パネル／左／中央／右、携帯の場合はそれに沿った表現）',
    status ENUM('pending','done','cancelled') NOT NULL DEFAULT 'pending',
    source VARCHAR(50) NOT NULL DEFAULT 'manual' COMMENT 'ai_chat / manual',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_source (source),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at DESC),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='改善・デバッグ提案（管理者がCursor用にコピーして開発に利用）';
```

---

## 手順5：SQL を貼り付けて実行する

1. phpMyAdmin の SQL タブ内の入力欄をクリックします。
2. いまコピーした SQL を貼り付けます（Ctrl+V または Cmd+V）。
3. 右下の **「実行」**（または「Go」）ボタンをクリックします。

---

## 手順6：結果を確認する

- 「クエリが正常に実行されました」のようなメッセージが出れば成功です。
- 左の「social9」の下のテーブル一覧に **improvement_reports** が増えていれば、テーブル作成は完了です。

ここまでできたら、管理画面の「改善・デバッグログ」が本番でも使えます。

---

## 注意（データベース名が social9 でない場合）

本番のデータベース名が **social9** ではない場合は、手順4の SQL の 1 行目を、実際のデータベース名に書き換えてから実行してください。

例：データベース名が `mydb` の場合

```
USE mydb;
```

のあと、2 行目以降の `CREATE TABLE ...` はそのままでかまいません。
