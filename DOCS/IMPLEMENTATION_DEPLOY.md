# 実装デプロイ（記録）

実装完了時の本番反映について、**必要な情報**と**運用方針**をまとめた記録です。

---

## 運用方針

- **実装が完了したら、毎回エージェントが自動でサーバーにファイルを送信する。**
- ユーザーが「送って」「デプロイして」と依頼するまでもなく、**実装完了時点で**エージェントは scp を実行し、変更・追加したファイルを EC2 にアップロードする。
- SQL マイグレーションがある場合は、SQL ファイルも EC2 に送り、実行コマンドを案内する（パスワードは本番のみのため、実行はユーザーが EC2 に SSH して行う場合あり）。

---

## 実行に必要な情報（毎回参照）

| 項目 | 値 |
|------|-----|
| **PEM キー（Mac）** | `~/.ssh/social9-key.pem` |
| **PEM キー（Windows）** | `C:\Users\narak\Desktop\social9-key.pem` |
| **EC2** | `ec2-user@54.95.86.79` |
| **Web ドキュメントルート** | `/var/www/html/` |
| **ローカルプロジェクトルート（Mac）** | `/Users/yusei/Documents/Project/nine` |
| **ローカルプロジェクトルート（Windows）** | `c:\xampp\htdocs\nine` |

### パス対応（ローカル → リモート）

| ローカル（c:\xampp\htdocs\nine\ 以下） | リモート |
|----------------------------------------|----------|
| `api\*` | `/var/www/html/api/` |
| `includes\*` | `/var/www/html/includes/` |
| `includes\chat\*` | `/var/www/html/includes/chat/` |
| `config\*` | `/var/www/html/config/` |
| `assets\js\*` | `/var/www/html/assets/js/` |
| `assets\css\*` | `/var/www/html/assets/css/` |
| `admin\*` | `/var/www/html/admin/` |
| `cron\*` | `/var/www/html/cron/` |
| `database\*.sql` | `/var/www/html/database/` |
| ルートの `*.php`（chat.php 等） | `/var/www/html/` |
| `DOCS\*` | `/var/www/html/DOCS/` |

### scp 実行例（Mac / Linux）

```bash
cd /Users/yusei/Documents/Project/nine
./deploy.sh api/ai.php includes/today_topics_helper.php cron/ai_today_topics_evening.php
```

または手動:

```bash
KEY="$HOME/.ssh/social9-key.pem"
EC2="ec2-user@54.95.86.79"
scp -i "$KEY" api/ai.php "$EC2:/var/www/html/api/"
scp -i "$KEY" includes/today_topics_helper.php "$EC2:/var/www/html/includes/"
```

### scp 実行例（Windows PowerShell）

```powershell
cd c:\xampp\htdocs\nine
$key = "C:\Users\narak\Desktop\social9-key.pem"
$ec2 = "ec2-user@54.95.86.79"
$root = "c:\xampp\htdocs\nine"

scp -i $key "$root\api\ai.php" "${ec2}:/var/www/html/api/"
scp -i $key "$root\includes\today_topics_helper.php" "${ec2}:/var/www/html/includes/"
```

複数ファイルは上記を並べるか、Mac は `deploy.sh`、Windows は `deploy-bulk.ps1` で一括実行。

---

## SQL がある場合

1. **SQL ファイルを EC2 に送る**  
   `database/*.sql` → `/var/www/html/database/` に scp。
2. **実行はユーザーが EC2 に SSH して行う**  
   パスワードは EC2 の `/etc/httpd/conf.d/db-env.conf` または `/var/www/html/config/database.aws.php` の `DB_PASS`。

   ```bash
   mysql -h database-1.cjgimse22md1.ap-northeast-1.rds.amazonaws.com -P 3306 -u admin -p social9 < /var/www/html/database/migration_xxx.sql
   ```

詳細: [PRODUCTION_DB_ACCESS.md](./PRODUCTION_DB_ACCESS.md) / [PRODUCTION_SQL_STEP_BY_STEP.md](./PRODUCTION_SQL_STEP_BY_STEP.md)

---

## 設定・シークレット（記録のみ・値は書かない）

- **YouTube Data API キー**: ローカルは `config/ai_config.local.php`、本番は `/var/www/html/config/ai_config.local.php` の `YOUTUBE_DATA_API_KEY` に設定済み。朝のニュース動画で使用。

---

## エージェントが毎回やること（チェックリスト）

- [ ] 実装で変更・追加したファイルを洗い出す。
- [ ] 上記の情報で **scp を自ら実行**し、該当ファイルを EC2 にアップロードする。
- [ ] マイグレーション SQL がある場合は、SQL ファイルも EC2 に送り、実行コマンド（または手順）をユーザーに案内する。
- [ ] **サーバーに自動でファイルを送信した場合は、ユーザーに「サーバーに実装ファイルを自動で送信しました」とその旨をお知らせする。**

---

## 関連ドキュメント・ルール

- **詳細手順・SQL 流し込み**: [SERVER_DEPLOY_AND_SQL.md](./SERVER_DEPLOY_AND_SQL.md)
- **scp の詳細・一括送信**: [DEPLOY_POWERSHELL_SCP.md](./DEPLOY_POWERSHELL_SCP.md)
- **本番 DB 接続**: [PRODUCTION_DB_ACCESS.md](./PRODUCTION_DB_ACCESS.md)
- **ルール（必読）**: `.cursor/rules/deploy-execute-on-complete.mdc` — 実装完了時は必ずファイル送信と SQL 対応を実行する。
