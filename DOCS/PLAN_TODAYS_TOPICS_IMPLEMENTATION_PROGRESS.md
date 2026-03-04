# 今日の話題 実装進捗メモ

計画書: `DOCS/PLAN_TODAYS_TOPICS.md`  
再起動等で消えないよう小分けで記録する。

---

## 完了済み

- [x] **DB マイグレーション**  
  `database/migration_today_topics.sql`  
  - today_topic_clicks, user_topic_interests, user_ai_settings の today_topics_* カラム

- [x] **API 設定**  
  `api/ai.php`  
  - get_settings / update_settings で today_topics_morning_enabled, today_topics_evening_enabled, today_topics_morning_hour を返却・更新

- [x] **今日の話題ヘルパー**  
  `includes/today_topics_helper.php`  
  - RSS 取得・キャッシュ (storage/cache/today_topics_YYYYMMDD.json)  
  - 年代帯取得 (getTodayTopicsAgeGroup)  
  - 本文組み立て (buildMorningTopicsBody)  
  - 保存 (saveTodayTopicsMorningMessage)、重複チェック (hasUserReceivedTodayTopicsMorning)

- [x] **cron 朝 6時・7時**  
  `cron/ai_proactive_daily.php`  
  - 6/7 時に today_topics_morning_enabled=1 かつ today_topics_morning_hour=その時刻のユーザーに挨拶＋ニューストピックスを 1 通で送信  
  - 同じ時刻で「挨拶のみ」のユーザーは従来どおり

---

## 次のステップ（小分け）

1. [x] **proactive-only の条件修正**  
   6/7 時に「今日の話題 OFF」のユーザーも挨拶のみ送るよう SQL 条件を明示（today_topics_morning_enabled=0 を含める）

2. [x] **storage/cache の存在確認と .gitignore**  
   storage/cache/.gitkeep 追加、.gitignore に storage/cache/*.json 追加

3. [x] **DEPENDENCIES.md 更新**  
   includes/DEPENDENCIES.md に today_topics_helper.php 記載済み。database/DEPENDENCIES.md に migration_today_topics.sql 記載済み。

4. [ ] **クリック記録 API**  
   today_topic_clicks に保存する API（フロントから「詳細を見る」で呼ぶ）

5. [ ] **チャット画面で「本日のニューストピックス」表示**  
   question = '（本日のニューストピックス）' のメッセージを識別して表示

6. [ ] **設定画面**  
   朝/夜 ON-OFF、朝の時刻 6/7 選択（既存設定 API はあるので UI のみの場合は軽い）

7. [ ] **夜の興味レポート**  
   別 cron・有料枠・お試し 1 週間などは後続

---

*最終更新: ステップ1〜3まで実施。次はクリック記録API or チャット表示。*
