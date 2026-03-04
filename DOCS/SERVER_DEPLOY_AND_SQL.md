# 実装完了時のサーバー反映 — ファイル送信と SQL 実行（必読）

実装が完了したときに、**エージェントがサーバーへファイル送信と SQL 実行まで行う**ための情報をまとめたドキュメントです。  
**エージェントは実装完了時に必ずこのファイルを参照し、以下に従って scp と SQL を実行すること。**

**実装デプロイの記録（概要・チェックリスト）**: [IMPLEMENTATION_DEPLOY.md](./IMPLEMENTATION_DEPLOY.md)

---

## 1. 共通情報

| 項目 | 値 |
|------|-----|
| **PEM キー** | `C:\Users\narak\Desktop\social9-key.pem`（デスクトップに置いてあり、ユーザーは動かしていない） |
| **EC2 ホスト** | `ec2-user@54.95.86.79` |
| **Web ドキュメントルート** | `/var/www/html/` |
| **ローカルプロジェクトルート** | `c:\xampp\htdocs\nine` |

---

## 2. ファイル送信（scp）

### 2.1 基本形

PowerShell でプロジェクトルートに移動したうえで、変更・追加したファイルごとに scp を実行する。

```powershell
cd c:\xampp\htdocs\nine
$key = "C:\Users\narak\Desktop\social9-key.pem"
$ec2 = "ec2-user@54.95.86.79"
```

**1 ファイル送信の例:**

```powershell
scp -i $key c:\xampp\htdocs\nine\api\messages.php ${ec2}:/var/www/html/api/
scp -i $key c:\xampp\htdocs\nine\includes\chat\scripts.php ${ec2}:/var/www/html/includes/chat/
scp -i $key c:\xampp\htdocs\nine\assets\css\chat-main.css ${ec2}:/var/www/html/assets/css/
```

### 2.2 パス対応表（ローカル → リモート）

| ローカル（c:\xampp\htdocs\nine\ 以下） | リモート（/var/www/html/ 以下） |
|----------------------------------------|----------------------------------|
| `api\*.php` | `api/` |
| `includes\*.php` | `includes/` |
| `includes\chat\*.php` | `includes/chat/` |
| `config\*.php` | `config/` |
| `assets\js\*.js` | `assets/js/` |
| `assets\css\*.css` | `assets/css/` |
| `admin\*.php` | `admin/` |
| `database\*.sql` | `database/` または `/home/ec2-user/`（SQL 実行用） |
| ルートの `*.php`（例: chat.php） | `/var/www/html/` |

### 2.3 実装完了時にエージェントがやること

1. 実装で変更・追加したファイルの一覧を把握する。
2. 上記のパス対応に従い、各ファイルに対して `scp -i "C:\Users\narak\Desktop\social9-key.pem" <ローカルパス> ec2-user@54.95.86.79:<リモートディレクトリ>` を実行する。
3. 複数ファイルの場合は、PowerShell で上記を連続で実行する（または `deploy-bulk.ps1` の `$files` に今回のファイルを追加して実行する）。

---

## 3. SQL の実行

マイグレーションやテーブル追加・変更用の SQL がある場合の手順。

### 3.1 本番 DB 接続情報（RDS）

| 項目 | 値 |
|------|-----|
| ホスト | `database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com` |
| ポート | 3306 |
| データベース名 | `social9` |
| ユーザー名 | `admin` |
| パスワード | EC2 の `/etc/httpd/conf.d/db-env.conf` または `/var/www/html/config/database.aws.php` の `DB_PASS` |

### 3.2 手順（エージェントが行うこと）

1. **SQL ファイルを EC2 に送る**  
   例: プロジェクトの `database/migration_xxx.sql` を EC2 の `/home/ec2-user/` に送る。

   ```powershell
   scp -i "C:\Users\narak\Desktop\social9-key.pem" c:\xampp\htdocs\nine\database\migration_xxx.sql ec2-user@54.95.86.79:/home/ec2-user/
   ```

2. **EC2 上で MySQL に流し込む**  
   パスワードは EC2 上の `db-env.conf` 等で確認する必要があるため、次のいずれかとする。
   - **エージェントが SSH で EC2 に入り、mysql コマンドを実行する**: パスワード入力が必要な場合は、ユーザーに「EC2 に SSH して以下を実行してください」と案内する。
   - **実行コマンドの案内**: ユーザーが EC2 に SSH したうえで実行するコマンドを明示する。

   **EC2 に SSH したあとで実行するコマンド例:**

   ```bash
   mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < /home/ec2-user/migration_xxx.sql
   ```

   プロンプトでパスワード（`db-env.conf` の `DB_PASS`）を入力する。

3. **詳細**: 本番 DB への接続・SQL の流し込み方法の詳細は **`DOCS/PRODUCTION_DB_ACCESS.md`** を参照する。

### 3.3 実装完了時にエージェントがやること（SQL あり）

1. SQL ファイルを上記のとおり scp で EC2 に送る。
2. 「EC2 に SSH のうえで、上記の `mysql ... < /home/ec2-user/xxx.sql` を実行してください。パスワードは db-env.conf の DB_PASS です」と案内する。  
   または、ユーザー環境でパスワードなし実行（例: EC2 上の `.my.cnf` 設定）が済んでいる場合は、エージェントが `ssh -i ... ec2-user@54.95.86.79 "mysql -h ... < /home/ec2-user/xxx.sql"` を実行する。

---

## 4. まとめチェックリスト（実装完了時）

- [ ] **DOCS/SERVER_DEPLOY_AND_SQL.md** を読み、PEM・EC2・パスを確認した。
- [ ] 変更・追加した**ファイル**をすべて scp で EC2 の `/var/www/html/` 以下に送った。
- [ ] **SQL** がある場合は、SQL ファイルを EC2 に送り、本番 DB での実行手順を実行したか、またはユーザーに実行コマンドを案内した。

---

## 5. 関連ドキュメント

- **実装デプロイの記録（概要・毎回自動で送る方針）**: [IMPLEMENTATION_DEPLOY.md](./IMPLEMENTATION_DEPLOY.md)
- **本番 DB 接続・SQL 実行の詳細**: [PRODUCTION_DB_ACCESS.md](./PRODUCTION_DB_ACCESS.md)
- **scp の詳細・一括送信**: [DEPLOY_POWERSHELL_SCP.md](./DEPLOY_POWERSHELL_SCP.md)
- **ルール（エージェント向け）**: `.cursor/rules/deploy-execute-on-complete.mdc`
