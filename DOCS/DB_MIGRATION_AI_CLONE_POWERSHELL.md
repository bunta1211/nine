# AIクローン DBマイグレーション実行手順（PowerShell）

本番DBで以下を実行します。

- 新規テーブル: `user_ai_judgment_folders`, `user_ai_judgment_items`, `user_ai_reply_suggestions`
- `user_ai_settings` への3カラム追加: `conversation_memory_summary`, `clone_training_language`, `clone_auto_reply_enabled`

---

## 方法1: EC2 に SSH して PHP スクリプトで実行（推奨）

本番の `config/database.php` の接続先にそのまま実行されます。

### 手順1: マイグレーション用 PHP を EC2 にアップロード

```powershell
cd c:\xampp\htdocs\nine

# PEM のパスは環境に合わせて変更（例: C:\Users\narak\Desktop\social9-key.pem）
$key = "C:\Users\narak\Desktop\social9-key.pem"
scp -i $key database/run_ai_clone_migration.php ec2-user@54.95.86.79:/var/www/html/database/
```

### 手順2: EC2 に SSH してマイグレーション実行

```powershell
ssh -i $key ec2-user@54.95.86.79 "cd /var/www/html && php database/run_ai_clone_migration.php"
```

成功時は次のような出力になります。

```
=== AI Clone Migration (tables) ===
1. CREATE TABLE IF NOT EXISTS user_ai_judgment_folders... OK
2. CREATE TABLE IF NOT EXISTS user_ai_judgment_items... OK
3. CREATE TABLE IF NOT EXISTS user_ai_reply_suggestions... OK
=== AI Clone Migration (user_ai_settings columns) ===
1. ALTER TABLE user_ai_settings ADD COLUMN conversation_memory_summary... OK
2. ALTER TABLE user_ai_settings ADD COLUMN clone_training_language... OK
3. ALTER TABLE user_ai_settings ADD COLUMN clone_auto_reply_enabled... OK
=== Done ===
```

既にテーブルやカラムがある場合は `SKIP (already exists)` と出ます（問題ありません）。

---

## 方法2: mysql クライアントで SQL ファイルを流し込む

本番DBのホスト・ユーザ・パスワードが手元で分かっている場合。

```powershell
cd c:\xampp\htdocs\nine

# 次の変数を本番の値に書き換え
$dbHost = "本番DBのホスト名"   # 例: mysql.social9.jp または EC2 の RDS エンドポイント
$dbUser = "本番DBのユーザ名"
$dbName = "本番DBのデータベース名"

# パスワードは実行時に聞かれる
mysql -h $dbHost -u $dbUser -p $dbName < database/migration_ai_clone_judgment_and_reply.sql
```

`user_ai_settings` に `user_profile` カラムが無い場合、元の SQL の `AFTER user_profile` でエラーになることがあります。そのときは方法1（PHP スクリプト）を使うか、`migration_ai_clone_judgment_and_reply.sql` の ALTER を `AFTER` なしの形に編集してから実行してください。

---

## まとめ（コピー用・方法1のみ）

```powershell
cd c:\xampp\htdocs\nine
$key = "C:\Users\narak\Desktop\social9-key.pem"
scp -i $key database/run_ai_clone_migration.php ec2-user@54.95.86.79:/var/www/html/database/
ssh -i $key ec2-user@54.95.86.79 "cd /var/www/html && php database/run_ai_clone_migration.php"
```

PEM のパスは各自の環境（例: `C:\Users\user\Desktop\social9-key.pem`）に合わせて変更してください。

---

## 続き・再実行用（そのままコピー）

マイグレーションは完了済みです。同じ手順を再実行するときや、別サーバーで実行するときは以下をコピーして使えます。

```powershell
cd c:\xampp\htdocs\nine
$key = "C:\Users\narak\Desktop\social9-key.pem"
scp -i $key database/run_ai_clone_migration.php ec2-user@54.95.86.79:/var/www/html/database/
ssh -i $key ec2-user@54.95.86.79 "cd /var/www/html && php database/run_ai_clone_migration.php"
```
