# 本番環境（AWS 等）デプロイ後 設定チェックリスト

サーバー移転後、以下を1回ずつ確認してください。

---

## 必須

| # | 項目 | 確認方法 | 参照 |
|---|------|----------|------|
| 1 | **APP_URL** | 本番のベースURL（例: `https://social9.jp`）になっているか | config/app.local.php または環境変数 APP_URL |
| 2 | **DB 接続** | database.aws.php を配置し、RDS のエンドポイント・ユーザー・パスワードが正しいか | config/database.aws.php |
| 3 | **tmp/sessions** | ディレクトリが存在し、Web サーバーが書けるか | [LOGIN_FIX_AFTER_MIGRATION.md](./LOGIN_FIX_AFTER_MIGRATION.md)、scripts/ensure_tmp_sessions.sh |
| 4 | **logs/** | 存在し、書込可能か | 手動で mkdir、または scripts/ensure_dirs.sh |
| 5 | **uploads/** | 存在し、uploads/backgrounds/ と uploads/messages/ も書込可能か | scripts/ensure_dirs.sh で一括作成可 |

---

## 機能別（利用する場合のみ）

| # | 項目 | 確認方法 |
|---|------|----------|
| 6 | **Google ログイン** | Google Cloud Console の「認証情報」でリダイレクトURIに `https://あなたのドメイン/api/google-login-callback.php` を追加済みか |
| 7 | **Google カレンダー** | 同上、`https://あなたのドメイン/api/google-calendar-callback.php` を追加済みか |
| 8 | **Web Push** | config/push.local.php に本番用 VAPID キーを設定済みか（必要なら config/generate_vapid_keys.php で再生成） |
| 9 | **リマインダー（cron）** | EC2 で process_reminders.php を定期実行する cron を設定したか | [CRON_REMINDERS.md](./CRON_REMINDERS.md) |
| 10 | **AI秘書（会話・記憶・キャラ）** | DB に `ai_conversations`, `user_ai_settings`, `ai_user_memories` が存在するか（`schema.sql` に含まれる） | [database/SCHEMA_README.md](../database/SCHEMA_README.md) |

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

*移転後不具合の全体像は [MIGRATION_ISSUES_AND_PLAN.md](./MIGRATION_ISSUES_AND_PLAN.md) を参照*
