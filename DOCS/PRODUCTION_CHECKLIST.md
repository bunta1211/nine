# 本番環境（AWS 等）デプロイ後 設定チェックリスト

サーバー移転後、および **main マージで GitHub Actions デプロイ後**、以下を1回ずつ確認してください。

---

## デプロイ直後に必要な作業（GitHub 経由デプロイ時）

rsync では **tmp/sessions/** ・ **logs/** ・ **uploads/** は除外されるため、本番では存在しないことがあります。初回または必要に応じて EC2 で次を実行してください。

| 作業 | コマンド（EC2 上で実行） |
|------|---------------------------|
| **ディレクトリ作成** | `cd /var/www/html && sudo WEB_USER=apache bash scripts/ensure_dirs.sh` |
| **vendor 不足で 500 になる場合** | `cd /var/www/html && sudo rm -rf vendor && sudo COMPOSER_ALLOW_SUPERUSER=1 /usr/local/bin/composer install --no-dev --prefer-dist && sudo chown -R apache:apache vendor` |

詳細: [PRODUCTION_500_ROOT_CAUSE.md](./PRODUCTION_500_ROOT_CAUSE.md)、[CI_CD_SETUP.md](./CI_CD_SETUP.md) の「デプロイ後の手順」。

---

## 必須

| # | 項目 | 確認方法 | 参照 |
|---|------|----------|------|
| 1 | **APP_URL** | 本番のベースURL（例: `https://social9.jp`）になっているか | config/app.local.php または環境変数 APP_URL |
| 2 | **DB 接続** | database.aws.php を配置し、RDS のエンドポイント・ユーザー・パスワードが正しいか | config/database.aws.php |
| 3 | **tmp/sessions** | ディレクトリが存在し、Web サーバーが書けるか | [LOGIN_FIX_AFTER_MIGRATION.md](./LOGIN_FIX_AFTER_MIGRATION.md)、scripts/ensure_dirs.sh（WEB_USER=apache） |
| 4 | **logs/** | 存在し、書込可能か | scripts/ensure_dirs.sh で一括作成可 |
| 5 | **uploads/** | 存在し、uploads/backgrounds/ と uploads/messages/ も書込可能か | scripts/ensure_dirs.sh で一括作成可 |
| 6 | **vendor** | 存在し、autoload が読めるか（500 の原因になりやすい） | [PRODUCTION_500_ROOT_CAUSE.md](./PRODUCTION_500_ROOT_CAUSE.md) |

---

## 機能別（利用する場合のみ）

| # | 項目 | 確認方法 |
|---|------|----------|
| 7 | **Google ログイン** | Google Cloud Console の「認証情報」でリダイレクトURIに `https://あなたのドメイン/api/google-login-callback.php` を追加済みか |
| 8 | **Google カレンダー** | 同上、`https://あなたのドメイン/api/google-calendar-callback.php` を追加済みか |
| 9 | **Web Push** | config/push.local.php に本番用 VAPID キーを設定済みか（必要なら config/generate_vapid_keys.php で再生成） |
| 10 | **リマインダー（cron）** | EC2 で process_reminders.php を定期実行する cron を設定したか | [CRON_REMINDERS.md](./CRON_REMINDERS.md) |
| 11 | **AI秘書（会話・記憶・キャラ）** | DB に `ai_conversations`, `user_ai_settings`, `ai_user_memories` が存在するか（`schema.sql` に含まれる） | [database/SCHEMA_README.md](../database/SCHEMA_README.md) |
| 12 | **AI（自動返信提案・秘書チャット）** | `config/ai_config.local.php` に **GEMINI_API_KEY** が正しく設定されているか。未設定・無効なキーだと自動返信提案が使えません。本番では **EC2 に手動で config/ai_config.local.php を配置**する。**確認**: 管理者で `GET https://social9.jp/api/health.php?action=ai_config` を開く。**作成例**: 下記「EC2で ai_config.local.php を作成」参照。 | [config/ai_config.local.example.php](../config/ai_config.local.example.php)、[Gemini API キー取得](https://aistudio.google.com/app/apikey) |

---

### EC2で ai_config.local.php を作成（AI・自動返信提案用）

1. EC2 に SSH で入り、`sudo nano /var/www/html/config/ai_config.local.php` を実行する。
2. 以下を貼り付け、`YOUR_GEMINI_API_KEY` を [Google AI Studio](https://aistudio.google.com/app/apikey) で取得したキーに置き換えて保存する。

```php
<?php
define('GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY');
```

3. 保存後、`https://social9.jp/api/health.php?action=ai_config` でキーが読み込まれているか確認する。

---

## 確認コマンド例（EC2 上）

```bash
# ディレクトリ存在・権限
ls -la /var/www/html/tmp/sessions
ls -la /var/www/html/logs
ls -la /var/www/html/uploads
ls -la /var/www/html/uploads/backgrounds
ls -la /var/www/html/uploads/messages

# 設定ファイル（中身は伏せて確認）
test -f /var/www/html/config/database.aws.php && echo "database.aws.php OK"
test -f /var/www/html/config/app.local.php && echo "app.local.php OK"
```

---

## デプロイ後のヘルスチェック

デプロイ完了後、アプリの状態を確認するには **api/health.php** を利用します。

| 確認内容 | URL・方法 |
|----------|-----------|
| **基本（誰でも）** | `GET https://social9.jp/api/health.php` または `GET https://social9.jp/api/health.php?action=basic` → DB・セッション・uploads 等の状態が JSON で返る。`overallStatus` が `ok` か確認。 |
| **デプロイ確認（管理者のみ）** | ログインした状態で `GET https://social9.jp/api/health.php?action=deploy` → サーバーが参照しているプロジェクトルートや topbar の更新日時などが返る。FTP/rsync のアップロード先が正しいか確認するときに利用。 |

500 やログイン不可が出た場合は、上記 basic のレスポンスで `checks.database` / `checks.session` / `checks.filesystem` のいずれが `error` になっていないか確認してください。PHP のエラーログ（例: `/var/log/php-fpm/www-error.log`）もあわせて確認すると原因の切り分けがしやすくなります。

---

*移転後不具合の全体像は [MIGRATION_ISSUES_AND_PLAN.md](./MIGRATION_ISSUES_AND_PLAN.md) を参照*
