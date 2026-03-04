# Docker Desktop で social9 をローカル実行する

Docker Desktop を使って、social9 をローカルで動かす手順です。開発開始時の流れは [DEVELOPER_GUIDE.md](./DEVELOPER_GUIDE.md) の「開発開始時の手順」を参照してください。

## 前提

- Docker Desktop がインストールされ、起動した状態（WSL 利用時は `wsl --update` 済み）
- コマンドは PowerShell または Git Bash で実行

## 1. ブランチと Docker 起動

```powershell
# main を最新にして bunta/01 に切り替え
git checkout main
git pull origin main
git checkout bunta/01   # まだなければ git checkout -b bunta/01

# Docker 起動（初回はビルドで数分かかることがあります）
docker compose up -d
```

## 2. 初回のみ: Composer のインストール

```powershell
docker compose exec web composer install --no-interaction
```

## 3. アクセス

- **アプリ**: http://localhost:9000/
- **MySQL**: ホストから `localhost:13306`、ユーザー `root` / パスワード `social9_dev`、DB 名 `social9`

## 4. ローカル DB にデータが必要な場合

- **EC2 に SSH できる場合**: 本番 DB から mysqldump でエクスポートし、ローカル DB に流し込む。接続情報は EC2 の `config/database.aws.php` を参照。
- **EC2 にアクセスできない場合**: 空の DB のまま新規登録で動作確認するか、データのエクスポートを依頼する。
- **初回のみ（テーブルがない場合）**: `docker/init-db` では文字セットのみ設定され、テーブルは作成されない。スキーマを流す:
  ```powershell
  docker compose exec -T db mysql -u root -psocial9_dev social9 < database/schema_complete.sql
  ```

## 5. よく使うコマンド

| 目的 | コマンド |
|------|----------|
| 起動 | `docker compose up -d` |
| 停止 | `docker compose down` |
| ログ確認 | `docker compose logs -f web` |
| DB に入る | `docker compose exec db mysql -u root -psocial9_dev social9` |
| Web シェル | `docker compose exec web bash` |

## 6. 設定の注意

- **DB 接続**: `docker-compose.yml` の環境変数（`DB_HOST=db`, `DB_USER=root`, `DB_PASS=social9_dev` 等）が使われます。`config/database.aws.php` は置かず、環境変数で動作します。
- **APP_URL**: コンテナ内で `APP_URL=http://localhost:9000` が設定されています。
- **tmp/sessions**: セッション保存先。書き込みエラーが出る場合は、コンテナ内で `chown -R www-data:www-data /var/www/html/tmp/sessions` を実行してください。

## 7. マイグレーションを追加で流す場合

```powershell
docker compose exec -T db mysql -u root -psocial9_dev social9 < database/migration_xxx.sql
```

## 8. データボリュームをリセットしたい場合

```powershell
docker compose down -v
docker compose up -d
```

`docker/init-db` のスクリプトが再度実行され、DB が初期状態になります。
