# セキュリティチェックリスト

本番環境の機密情報保護の確認用です。

---

## 1. Git に含めない文件（.gitignore 済み）

| ファイル | 理由 |
|----------|------|
| `config/database.aws.php` | DB パスワード |
| `config/app.local.php` | 環境固有設定 |
| `config/ai_config.local.php` | AI API キー |
| `config/google_calendar.local.php` | OAuth シークレット |
| `config/google_login.local.php` | OAuth シークレット |
| `config/push.local.php` | VAPID 秘密鍵 |
| `*.pem` | 秘密鍵 |

---

## 2. Web からアクセス不可（.htaccess でブロック済み）

| パス | 状態 |
|------|------|
| `/config/` | 403 Forbidden |
| `/api/server-check.php` | 403 Forbidden |
| `/tmp/` | 403 Forbidden |
| `/DOCS/` | 403 Forbidden |
| `/database/` | 403 Forbidden |

---

## 3. EC2 上で確認すること

```bash
# database.aws.php が存在し、パーミッションが 644 か確認
ls -la /var/www/html/config/database.aws.php

# config ディレクトリが Web から読めないか確認（403 になること）
curl -I https://social9.jp/config/database.php

# server-check.php がブロックされているか確認（403 になること）
curl -I https://social9.jp/api/server-check.php
```

---

## 4. 運用上の注意

- **db-env.conf**（`/etc/httpd/conf.d/`）はサーバー上のみ。リポジトリには含めない
- **WinSCP でアップロードする際**、database.aws.php や *.local.php が誤って公開ディレクトリに置かれていないか確認
- **パスワード**は定期的に変更を検討
