# 今日の話題 実装メモ（小分け進捗）

計画書: [PLAN_TODAYS_TOPICS.md](PLAN_TODAYS_TOPICS.md)

## 完了したもの

| 日付 | 内容 |
|------|------|
| - | **DB**: `database/migration_today_topics.sql`（today_topic_clicks, user_topic_interests, user_ai_settings カラム追加） |
| - | **API**: `api/ai.php` で get_settings / update_settings に today_topics_morning_enabled, today_topics_evening_enabled, today_topics_morning_hour を追加済み |
| - | **ヘルパー**: `includes/today_topics_helper.php`（RSS取得・キャッシュ・年代別本文・保存） |
| - | **Cron**: `cron/ai_proactive_daily.php` で 6時・7時に「本日のニューストピックス」配信、それ以外は挨拶のみ |

## 次のステップ（未実装）

1. [x] Cron: 6/7時の「挨拶のみ」対象に today_topics_morning_enabled=0 を明示的に含める（済）
2. [x] storage/cache の存在確認と .gitkeep（済: storage/cache/.gitkeep 作成）
3. [x] includes/DEPENDENCIES.md に today_topics_helper.php を追記（済・既存）
4. [x] チャット画面で「本日のニューストピックス」を識別表示（済: includes/chat/scripts.php で TODAY_TOPICS_QUESTION により 📰 表示）
5. [ ] クリック記録: today_topic_clicks に保存する API とフロントの「詳細を見る」
   - [x] API: api/ai.php に action=today_topic_click を追加（topic_id / external_url / source / category_or_keywords）
   - [ ] フロント: ニューストピックス本文内のリンクを「詳細を見る」としてクリック時に API を呼ぶ
6. [ ] 夜の興味レポート（cron・有料枠・お試し期間ロジック）は別タスク

---
*最終更新: 実装継続時に更新。再起動時はこのファイルと PLAN_TODAYS_TOPICS.md を参照して続きから進める。*
