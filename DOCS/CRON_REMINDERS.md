# リマインダー処理（cron）の設定手順

AIリマインダーの通知を送るために、`cron/process_reminders.php` を定期実行する必要があります。  
サーバー移転後（EC2 等）では、新サーバーで cron を設定してください。

---

## 前提

- **database.aws.php** が配置されていること（CLI 実行時も RDS に接続するため）
- **logs/** が存在し書けること（ログ出力用）

---

## EC2（Amazon Linux）での設定例

### 1. 実行方法

**PHP CLI で直接実行（推奨）:**

```bash
# プロジェクトルートに移動
cd /var/www/html

# 手動で1回実行して動作確認
php cron/process_reminders.php
```

**cron に登録（毎分実行）:**

```bash
crontab -e
```

次の1行を追加（パスは環境に合わせて変更）:

```cron
* * * * * cd /var/www/html && php cron/process_reminders.php >> /var/www/html/logs/cron_reminders.log 2>&1
```

※ このスクリプトは CLI 専用です。Web から呼ばないでください。

### 2. ログ確認

```bash
tail -f /var/www/html/logs/cron_reminders.log
```

---

## 旧サーバー（heteml 等）で cron を設定していた場合

- 旧サーバーの crontab は新サーバーには引き継がれません。
- 上記のとおり、EC2 で新たに crontab を設定してください。

---

*リマインダー用テーブル・仕様は database の ai_reminders を参照*
