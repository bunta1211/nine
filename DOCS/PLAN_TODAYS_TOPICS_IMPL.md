# 今日の話題 実装進捗メモ

計画書: `DOCS/PLAN_TODAYS_TOPICS.md`

## 完了済み

| 項目 | ファイル・内容 |
|------|----------------|
| 設定API | api/ai.php: get_settings / update_settings に today_topics_morning_enabled, today_topics_evening_enabled, today_topics_morning_hour を追加済み |
| DBマイグレーション | database/migration_today_topics.sql: today_topic_clicks, user_topic_interests, user_ai_settings カラム追加 |
| 今日の話題ヘルパー | includes/today_topics_helper.php: RSS取得・キャッシュ・年代別本文・保存・重複チェック |
| cron 朝 6/7時 | cron/ai_proactive_daily.php: 6時・7時に today_topics_morning_enabled=1 かつ hour 一致ユーザーへ挨拶＋ニューストピックス配信。挨拶のみユーザーは別クエリで送信 |
| 依存関係 | includes/DEPENDENCIES.md に today_topics_helper.php を記載済み |
| チャット表示 | includes/chat/scripts.php: 履歴読み込み時 question が（本日のニューストピックス）→「📰 本日のニューストピックス」、（自動挨拶）→「👋 自動挨拶」と表示 |
| クリック記録API | api/today_topic_click.php: action=record で external_url / topic_id, source, category_or_keywords を today_topic_clicks に挿入 |

## 次の作業（小分け）

1. **設定画面**  
   今日の話題 ON/OFF・朝の時刻（6 or 7）を設定できる項目を AI 設定または設定ページに追加。

2. **夜の興味レポート**  
   計画書 4 節。別 cron・有料枠・お試し期間の条件あり。後続で実装。

---
更新: 実装継続時に追記する。
