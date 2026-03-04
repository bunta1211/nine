# 今日の話題：KEN 限定で配信テストから再開する計画

**目的**: データ処理が重く捌ききれていないため、まず **KEN 1 人に限定**して配信テストを行い、成功したら夜のテストへ進む。

---

## 実装まとめ（完了済み）

| 項目 | 内容 |
|------|------|
| **対象ユーザー** | KEN（user_id=6）のみ。定数 `TODAY_TOPICS_LIMIT_USER_IDS` で制御。 |
| **設定** | [config/app.php](config/app.php) で `TODAY_TOPICS_LIMIT_USER_IDS = '[6]'` を定義。空または未定義なら従来どおり全員対象。 |
| **朝の配信** | [run_today_topics_morning_per_user.php](cron/run_today_topics_morning_per_user.php) が 6 時・7 時に実行する際、取得した user_id 一覧を上記定数でフィルタし、KEN のみに配信。 |
| **夜の配信** | [today_topics_helper.php](includes/today_topics_helper.php) の `getEveningReportTargetUserIds` と `getEveningReportBulkTargetUserIds` の返却を、同じ定数でフィルタ。夜の cron でも KEN のみが対象。 |
| **デプロイ後すぐのテスト** | [run_today_topics_test_once.php](cron/run_today_topics_test_once.php) を本番で 1 回実行すると、ニュースを取得して KEN に「本日のニューストピックス」を 1 通送信（`--force` で本日受信済みでも再送）。 |

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

- **取得**: [run_today_topics_morning_per_user.php](cron/run_today_topics_morning_per_user.php) で `user_ai_settings` JOIN `users`
- **条件**: `today_topics_morning_enabled = 1` かつ `COALESCE(today_topics_morning_hour, 7) = 現在時（6 or 7）`
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

## 2. KEN 1 人限定にする

- **KEN**: コード上のコメントより `user_id = 6` を想定（本番で異なる場合は設定で変更可能にする）。
- 朝・夜とも、**対象ユーザーを user_id = 6 のみ**に限定して動作させる。
- **設定**: [config/app.php](../config/app.php) で `TODAY_TOPICS_LIMIT_USER_IDS = '[6]'` を定義済み。空文字または未定義にすると全員対象になる。

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
| 定数・設定 | [config/app.php](../config/app.php) | `TODAY_TOPICS_LIMIT_USER_IDS = '[6]'` を定義（KEN のみ）。空で全員対象 |
| 朝 | [run_today_topics_morning_per_user.php](../cron/run_today_topics_morning_per_user.php) | 対象 user_id 取得後に定数でフィルタ（実装済み） |
| 夜 | [today_topics_helper.php](../includes/today_topics_helper.php) | `getEveningReportTargetUserIds` / `getEveningReportBulkTargetUserIds` で定数フィルタ（実装済み） |
| デプロイ後テスト | [run_today_topics_test_once.php](../cron/run_today_topics_test_once.php) | デプロイ後に 1 回実行するとニュース取得＋KEN へ配信 |
| 依存 | [send_today_topics_to_user.php](../cron/send_today_topics_to_user.php) | 上記テストから呼ばれるため、**本番へ必ずアップロードする** |

---

## 5. 注意

- 現行の「朝 6/7 時」「夜 16〜20 時」の cron スケジュールや、RSS・キャッシュ・`ai_conversations` への保存形式は変えず、**対象ユーザーを KEN 1 人に絞る**だけとする。
- 配信テストが両方成功したあと、必要に応じて対象を段階的に広げる際は、このドキュメントに Step 3 以降を追記する。
