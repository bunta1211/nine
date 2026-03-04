# 今日の話題「1ユーザー1プロセス」運用計画

メモリを抑えるため、**1ユーザーごとに別プロセス**で朝・夜の配信を行う運用の計画です。各プロセスは1人分だけ処理して終了するため、メモリが累積せず、小さいインスタンスでも安定しやすくなります。

---

## 1. 方針

| 項目 | 内容 |
|------|------|
| **朝（本日のニューストピックス）** | 対象ユーザーID一覧を取得し、**1人ずつ** `php send_today_topics_to_user.php {user_id}` を順に実行。各プロセス終了でメモリ解放。 |
| **夜（興味トピックレポート）** | 個別配信対象を**1人ずつ** `php send_evening_report_to_user.php {user_id}` で実行。一斉配信はラッパー内で一括実行（Gemini 1回＋DB保存のみで軽い）。 |
| **インスタンス** | 同時に動くのは「1プロセス＝1ユーザー分」なので、**1ユーザーあたりのピークメモリ（例: 9GB）＋OS 分**があれば足りる。t3.xlarge（16GB）等を想定。 |

---

## 2. 構成

### 2.1 朝（6時・7時）

```
cron (6時または7時)
  └ run_today_topics_morning_per_user.php   ← ラッパー（軽い）
       ├ 対象 user_id 一覧を DB から取得
       └ 各 user_id に対して:
            └ php send_today_topics_to_user.php {user_id}   ← 1プロセス＝1ユーザー（重い）
```

- **挨拶のみユーザー**（今日の話題 OFF で同じ時刻に挨拶だけ送る人）は、従来の `ai_proactive_daily.php` で処理する。crontab で 6・7時に **run_today_topics_morning_per_user.php** と **ai_proactive_daily.php** の両方を実行する場合、`ai_proactive_daily.php` は 6・7時では「今日の話題対象」をループせず「挨拶のみ」リストだけ処理するよう修正する（二重送信防止）。

### 2.2 夜（16〜20時）

```
cron (16〜20時のいずれか)
  └ run_today_topics_evening_per_user.php   ← ラッパー
       ├ 個別配信対象 user_id 一覧を取得
       ├ 各 user_id に対して:
       │    └ php send_evening_report_to_user.php {user_id}   ← 1プロセス＝1ユーザー（重い）
       └ 一斉配信: getEveningBulkContentOrGenerate + 対象に保存（ラッパー内で実行・軽い）
```

---

## 3. ファイル一覧

| ファイル | 役割 |
|----------|------|
| `cron/send_today_topics_to_user.php` | 既存。朝の今日の話題を **1ユーザー分** 送信（手動テスト兼用）。 |
| `cron/run_today_topics_morning_per_user.php` | 朝の **ラッパー**。対象 user_id を取得し、順に `send_today_topics_to_user.php` を実行。 |
| `cron/send_evening_report_to_user.php` | 夜の興味レポートを **1ユーザー分** 送信。 |
| `cron/run_today_topics_evening_per_user.php` | 夜の **ラッパー**。個別対象に `send_evening_report_to_user.php` を順実行し、最後に一斉配信を実行。 |

---

## 4. crontab の変更案

**1ユーザー1プロセス運用に切り替える場合:**

```bash
# 朝 6・7時: 今日の話題を1ユーザー1プロセスで送信
0 6,7 * * * php /var/www/html/cron/run_today_topics_morning_per_user.php >> /var/www/html/logs/cron_proactive.log 2>&1

# 朝 6・7時: 挨拶のみユーザー（ai_proactive_daily は 6・7時は「今日の話題」ループをスキップするよう修正が必要）
0 6,7 * * * php /var/www/html/cron/ai_proactive_daily.php >> /var/www/html/logs/cron_proactive.log 2>&1

# 朝 それ以外の時刻: 従来どおり挨拶のみ
0 * * * * php /var/www/html/cron/ai_proactive_daily.php >> /var/www/html/logs/cron_proactive.log 2>&1

# 夜: 1ユーザー1プロセスで個別配信＋一斉配信
0 16,17,18,19,20 * * * php /var/www/html/cron/run_today_topics_evening_per_user.php >> /var/www/html/logs/cron_evening_topics.log 2>&1
```

**注意**: `ai_proactive_daily.php` を 6・7時に実行する場合、内部で「今日の話題対象ユーザー」への送信ループを行わないようにする必要があります（run_today_topics_morning_per_user で送るため）。未修正のまま両方動かすと今日の話題が二重送信になります。

---

## 5. メモリ・コスト

- **1プロセスあたりのピーク**: 約 9GB（実測に依存）。
- **必要なインスタンス**: t3.xlarge（16GB）で、1ユーザーずつ順に実行するなら十分。
- **月額目安**: 約 2.4〜2.5 万円/月（東京・オンデマンド）。詳細は `DOCS/TODAY_TOPICS_CRON_MEMORY.md` 参照。

---

## 6. 関連ドキュメント

- `DOCS/TODAY_TOPICS_CRON_MEMORY.md` — メモリ・インスタンス・コストの詳細
- `DOCS/PLAN_TODAYS_TOPICS.md` — 今日の話題の全体仕様
