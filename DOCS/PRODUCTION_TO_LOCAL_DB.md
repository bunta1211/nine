# 本番 DB をローカル（Docker）に取り込む手順

本番環境（RDS）のデータをエクスポートし、ローカルの Docker MySQL に取り込む手順です。

---

## 前提

- EC2 に SSH できること（本番 DB は RDS で EC2 から接続）
- ローカルで Docker が起動していること（`docker compose up -d`）
- パスワードは EC2 の `/etc/httpd/conf.d/db-env.conf` または `/var/www/html/config/database.aws.php` の `DB_PASS`

---

## 手順 1: EC2 に SSH して本番 DB をダンプする

### 1-1. EC2 に SSH

```powershell
ssh -i "C:\Users\narak\Desktop\social9-key.pem" ec2-user@54.95.86.79
```

### 1-2. パスワードを確認（未確認の場合）

```bash
# db-env.conf から DB_PASS を確認（表示された値をメモ）
sudo grep DB_PASS /etc/httpd/conf.d/db-env.conf
```

### 1-3. ダンプを取得

**方法 A: スクリプトを使う（プロジェクトに配置済みの場合）**

```bash
cd /var/www/html
chmod +x database/scripts/export_production_for_local.sh
# パスワードを環境変数で渡す場合（DB_PASS を実際の値に置き換え）
export DB_PASS='ここに本番DBのパスワード'
./database/scripts/export_production_for_local.sh
# またはパスワードをプロンプトで入力する場合は DB_PASS を設定せずに実行
./database/scripts/export_production_for_local.sh
```

**方法 B: 手動で mysqldump**

```bash
mysqldump -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 \
  --single-transaction --routines --triggers --set-gtid-purged=OFF \
  > /home/ec2-user/social9_production_$(date +%Y%m%d_%H%M%S).sql
gzip /home/ec2-user/social9_production_*.sql
```

実行後、`/home/ec2-user/social9_production_YYYYMMDD_HHMMSS.sql.gz` ができます。

### 1-4. EC2 からログアウト

```bash
exit
```

---

## 手順 2: ダンプファイルをローカルにダウンロードする

PowerShell で（ファイル名は実際の名前に合わせる）:

```powershell
# database\backup フォルダを作成
New-Item -ItemType Directory -Force -Path c:\xampp\htdocs\nine\database\backup

# EC2 からダンプを取得（.gz のまま）
scp -i "C:\Users\narak\Desktop\social9-key.pem" ec2-user@54.95.86.79:/home/ec2-user/social9_production_*.sql.gz c:\xampp\htdocs\nine\database\backup\
```

`.gz` の場合は解凍して `.sql` にする（7-Zip や WSL の `gunzip` など）:

```powershell
# 例: 7-Zip がある場合
& "C:\Program Files\7-Zip\7z.exe" e c:\xampp\htdocs\nine\database\backup\social9_production_*.sql.gz -oc:\xampp\htdocs\nine\database\backup
```

解凍後、`social9_production_YYYYMMDD_HHMMSS.sql` を用意してください。

---

## 手順 3: ローカル Docker にインポートする

プロジェクトルートで PowerShell を開き:

```powershell
cd c:\xampp\htdocs\nine

# ダンプファイルを指定してインポート（.sql のパスを実際の名前に）
.\database\scripts\import_production_to_local.ps1 .\database\backup\social9_production_YYYYMMDD_HHMMSS.sql
```

`database\backup` に `social9_production_*.sql` が 1 つだけある場合は、パスを省略できます:

```powershell
.\database\scripts\import_production_to_local.ps1
```

---

## 完了後

- ブラウザで **http://localhost:9000/** を開き、本番と同じアカウントでログインできます。
- 本番のメールアドレス・パスワードでログインしてください。

---

## トラブルシュート

| 現象 | 対処 |
|------|------|
| EC2 で `mysqldump: command not found` | `sudo dnf install -y mariadb105` でクライアントをインストール |
| インポートで文字化け | ダンプ取得時に `--default-character-set=utf8mb4` を付けて再ダンプ |
| インポートが途中で止まる | ダンプファイルが大きい場合は `docker compose exec -T db mysql ... < file.sql` の代わりに、ファイルをコンテナにコピーしてから `source` で実行（スクリプトはその方式を使用） |

---

## 関連

- [PRODUCTION_DB_ACCESS.md](./PRODUCTION_DB_ACCESS.md) — 本番 DB 接続情報
- [DOCKER_LOCAL.md](./DOCKER_LOCAL.md) — ローカル Docker の起動方法
