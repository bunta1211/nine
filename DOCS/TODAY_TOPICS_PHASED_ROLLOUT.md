# 今日の話題：配信対象とスケジュール

**目的**: 朝の「本日のニューストピックス」を、固定メンバー（KEN, Yusei, Naomi）と過去1週間アクティブなユーザーに**毎朝7時**で配信する。

---

## 朝の配信（毎朝7時のみ）

| 項目 | 内容 |
|------|------|
| **実行時刻** | **毎朝 7 時のみ**。cron 例: `0 7 * * * php /var/www/html/cron/run_today_topics_morning_per_user.php ...` |
| **対象** | (1) **固定ユーザー**: `TODAY_TOPICS_MORNING_FIXED_USER_IDS` で指定した user_id（例: KEN=6, Yusei, Naomi）。<br>(2) **過去1週間アクティブ**: `today_topics_morning_enabled = 1` かつ希望時刻 7 時で、`users.last_seen` が過去7日以内のユーザー（`TODAY_TOPICS_MORNING_ALSO_ACTIVE_WEEK` が true のとき）。 |
| **設定** | [config/app.php](../config/app.php)。<br>・`TODAY_TOPICS_MORNING_FIXED_USER_IDS`: JSON 配列（例: `'[6]'` または `'[6, id_yusei, id_naomi]'`）。app.local.php で Yusei・Naomi の ID を追加可能。<br>・`TODAY_TOPICS_MORNING_ALSO_ACTIVE_WEEK`: `true` で「過去1週間アクティブ」を対象に含める。 |
| **スクリプト** | [run_today_topics_morning_per_user.php](../cron/run_today_topics_morning_per_user.php) が 7 時のときだけ動作し、固定 ID とアクティブ週の user_id をマージして重複除き、各ユーザーごとに [send_today_topics_to_user.php](../cron/send_today_topics_to_user.php) を実行。 |

---

## 実装まとめ（完了済み）

| 項目 | 内容 |
|------|------|
| **朝の配信対象** | 固定（KEN, Yusei, Naomi 等）＋過去1週間アクティブで朝7時希望のユーザー。定数 `TODAY_TOPICS_MORNING_FIXED_USER_IDS` と `TODAY_TOPICS_MORNING_ALSO_ACTIVE_WEEK` で制御。 |
| **設定** | [config/app.php](../config/app.php) で上記定数を定義。Yusei・Naomi を追加する場合は `app.local.php` で `TODAY_TOPICS_MORNING_FIXED_USER_IDS = '[6, id_yusei, id_naomi]'` を設定。 |
| **朝の配信** | [run_today_topics_morning_per_user.php](../cron/run_today_topics_morning_per_user.php) が **7 時のみ**実行。固定 ID とアクティブ週の user_id をマージして配信。 |
| **夜の配信** | [today_topics_helper.php](../includes/today_topics_helper.php) の `getEveningReportTargetUserIds` と `getEveningReportBulkTargetUserIds` の返却を、従来どおり `TODAY_TOPICS_LIMIT_USER_IDS` でフィルタ可能（夜は従来仕様のまま）。 |
| **デプロイ後すぐのテスト** | [run_today_topics_test_once.php](../cron/run_today_topics_test_once.php) を本番で 1 回実行すると、ニュースを取得して指定ユーザーに「本日のニューストピックス」を 1 通送信（`--force` で本日受信済みでも再送）。 |

**デプロイ後の手順（本番サーバーで 1 回だけ）**

- 事前に **send_today_topics_to_user.php** を本番の `/var/www/html/cron/` にアップロードしておく（run_today_topics_test_once がこのファイルを呼び出すため）。

```bash
php /var/www/html/cron/run_today_topics_test_once.php
```

成功すると KEN の AI 秘書チャットに「本日のニューストピックス」が表示される。

---

## 1. 現在の検索範囲（参考）

実装の前提として、以下は現状のまま参照する。

### 1.1 朝「本日のニューストピックス」の対象

- **実行**: 毎朝 **7 時のみ**。[run_today_topics_morning_per_user.php](../cron/run_today_topics_morning_per_user.php)
- **対象の決め方**: (1) 固定 user_id（`TODAY_TOPICS_MORNING_FIXED_USER_IDS`）(2) 過去1週間で `last_seen` があり、`user_ai_settings.today_topics_morning_enabled = 1` かつ `COALESCE(today_topics_morning_hour, 7) = 7` のユーザー。両方をマージして重複を除いた user_id に配信。
- **並び**: `(user_id = 6) DESC, user_id ASC` で user_id=6（Ken）を先頭にしている

### 1.2 ニュース・RSS の取得範囲

- **ソース**: [today_topics_helper.php](includes/today_topics_helper.php) の `fetchTodayTopicsFromRss()`
- **方針**: 各カテゴリで**日本のニュース2件＋海外のニュース2件**（計4件/カテゴリ）。ひとつのサイトに偏らず複数サイトから取得。
- **採用サイト**: Yahoo!ニュース、毎日新聞RSS、CNN.co.jp、ロイター（assets.wor.jp）。**NHK は一切使用しない**（`isLinkFromNhK()` で除外）。
- **表示**: シニアは2件/カテゴリ、それ以外は4件/カテゴリ。キャッシュ: `storage/cache/today_topics_YYYYMMDD.json`、TTL 24 時間。

**候補RSSサイト（今後の追加・差し替え用）**

| サイト | 概要 | RSS/備考 |
|--------|------|----------|
| **BBC News 日本語** | 英国放送協会の日本語版。国際・経済など翻訳記事が読める。 | 公式RSSは要確認。https://www.bbc.com/japanese |
| **朝日新聞デジタル** | 国内総合。RSS1.0で配信。記事の一部は会員制。 | https://cdn-ssl.asahi.com/ 配下のRSS（利用規約要確認） |
| **マイナビニュース** | IT・テック系に強い。企業IT、テクノロジー等のRSSあり。 | https://news.mynavi.jp/ のRSS一覧 |
| **The Guardian / AFP** | 英語メディア。日本語版や配信契約があれば翻訳記事のRSSを利用可能。 | 非公式RSSやAPIの要確認 |

### 1.3 夜のレポートで参照するコンテキスト

- [ai_proactive_helper.php](includes/ai_proactive_helper.php) の `collectUserContext($pdo, $userId, 15)`  
  - 直近 3 日間のメッセージ 最大 15 件、タスク 5 件、メモ 5 件、AI 記憶 10 件
- `user_topic_interests` 直近 10 件
- 当日ニュースキャッシュの先頭 15 件

---

## 2. 朝の配信対象の構成

- **固定ユーザー**: KEN（user_id=6）を必須とし、Yusei・Naomi の user_id を本番で確認のうえ `TODAY_TOPICS_MORNING_FIXED_USER_IDS` に追加する（例: `'[6, 10, 12]'`）。`config/app.local.php` で上書き可能。
- **過去1週間アクティブ**: `users.last_seen` が過去7日以内で、かつ「今日の話題」朝配信を有効・7時希望にしているユーザーも対象に含める（`TODAY_TOPICS_MORNING_ALSO_ACTIVE_WEEK = true` のとき）。
- **設定**: [config/app.php](../config/app.php) で上記定数を定義。朝の配信は 7 時のみ実行する。

---

## 3. 配信テストの順序

### Step 1: 朝のニュース配信テスト（KEN のみ）

- **内容**: 従来「朝」に設定していた「本日のニューストピックス」を、**KEN 1 人だけ**に配信する。
- **方法**:  
  - 対象取得を KEN（user_id=6）のみに限定する（`TODAY_TOPICS_LIMIT_USER_IDS` で実施済み）。  
  - **デプロイ後すぐにテストする**: 本番サーバーで次を 1 回実行する。  
    `php /var/www/html/cron/run_today_topics_test_once.php`  
    ニュースを取得し、KEN に 1 通送信する（本日受信済みでも `--force` で再送）。
  - 通常の cron（6 時・7 時）でも KEN のみに配信される。手動テストは `php send_today_topics_to_user.php 6` または上記スクリプト。
- **成功の目安**: KEN の AI 秘書チャットに「本日のニューストピックス」が 1 通保存され、画面で表示されること。

### Step 2: 夜の配信テスト（Step 1 成功後）

- **前提**: Step 1（朝の配信テスト）が成功したあとに行う。
- **内容**: 従来「夜」に配信予定だった「興味トピックレポート」を、**KEN 1 人だけ**に配信する。
- **方法**: 夜の対象取得を KEN（user_id=6）のみに限定し、cron または手動で 1 回だけ実行してテストする。
- **成功の目安**: KEN の AI 秘書チャットに「興味トピックレポート」が 1 通保存され、画面で表示されること。

---

## 4. 実装で触るファイル（案）

| 種別 | ファイル | 変更内容 |
|------|----------|----------|
| 定数・設定 | [config/app.php](../config/app.php) | `TODAY_TOPICS_MORNING_FIXED_USER_IDS`（固定ユーザー）, `TODAY_TOPICS_MORNING_ALSO_ACTIVE_WEEK`（過去1週間アクティブを含める）。Yusei・Naomi は app.local.php で追加可。 |
| 朝 | [run_today_topics_morning_per_user.php](../cron/run_today_topics_morning_per_user.php) | **7 時のみ**実行。固定 ID ＋ 過去1週間アクティブで朝7時希望の user_id をマージして配信。 |
| 夜 | [today_topics_helper.php](../includes/today_topics_helper.php) | `getEveningReportTargetUserIds` / `getEveningReportBulkTargetUserIds` で `TODAY_TOPICS_LIMIT_USER_IDS` フィルタ（従来どおり）。 |
| デプロイ後テスト | [run_today_topics_test_once.php](../cron/run_today_topics_test_once.php) | デプロイ後に 1 回実行するとニュース取得＋指定ユーザーへ配信 |
| 依存 | [send_today_topics_to_user.php](../cron/send_today_topics_to_user.php) | 上記テスト・朝 cron から呼ばれるため、**本番へ必ずアップロードする** |

---

## 5. 注意

- 朝の配信は **毎朝 7 時のみ**。cron は `0 7 * * *` で設定する（6 時実行は廃止）。
- 対象は **固定ユーザー（KEN, Yusei, Naomi 等）＋過去1週間アクティブで朝7時希望のユーザー**。Yusei・Naomi の user_id は本番で確認し、`TODAY_TOPICS_MORNING_FIXED_USER_IDS`（または app.local.php）に追加する。
- 夜の cron スケジュールや、RSS・キャッシュ・`ai_conversations` への保存形式は従来どおり。配信対象の拡張が必要な場合はこのドキュメントを更新する。
