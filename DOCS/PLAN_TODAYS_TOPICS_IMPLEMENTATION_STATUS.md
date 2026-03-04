# 今日の話題 実装進捗（再開時はこのファイルを開く）

**計画書**: [DOCS/PLAN_TODAYS_TOPICS.md](PLAN_TODAYS_TOPICS.md)  
**最終更新**: 実装チャンクごとに更新。メモリ制限で切れた場合は「未実装」から継続すること。

---

## 一覧（ここを見ればどこまでかすぐわかる）

| # | 項目 | 状態 | ファイル・メモ |
|---|------|------|----------------|
| 1 | 朝: 設定API（get/update_settings） | **実装済み** | api/ai.php |
| 2 | 朝: DB マイグレーション | **実装済み** | database/migration_today_topics.sql |
| 3 | 朝: 今日の話題ヘルパー（RSS・キャッシュ・本文・保存） | **実装済み** | includes/today_topics_helper.php |
| 4 | 朝: cron 6時・7時統合 | **実装済み** | cron/ai_proactive_daily.php |
| 5 | 挨拶のみユーザー条件（6・7時） | **実装済み** | cron/ai_proactive_daily.php |
| 6 | storage/cache・.gitkeep | **実装済み** | storage/cache/.gitkeep, .gitignore |
| 7 | チャット画面で「本日のニューストピックス」表示 | **実装済み** | includes/chat/scripts.php（📰 表記） |
| 8 | クリック記録API | **実装済み** | api/today_topic_click.php |
| 9 | クリック記録フロント（リンククリックで record 呼び出し） | **実装済み** | includes/chat/scripts.php: 本日トピックス時URLを「詳細を見る」にし、クリックで today_topic_click.php を呼んでから新しいタブで開く |
| 10 | 興味希望の記録（3.6） | **実装済み** | api/ai.php: 直前に本日トピックスなら返信を興味として抽出→user_topic_interests。includes/today_topics_helper.php に isLastConversationTodayTopics, extractAndSaveTopicInterestsFromReply を追加 |
| 11 | 夜の興味レポート | **実装済み** | cron/ai_today_topics_evening.php（16〜20時）。today_topics_helper に 2日連続未クリック判定・対象取得・生成・保存。チャットで「📋 興味トピックレポート」表示。 |
| 12 | 推し登録・有料枠 | **実装済み** | api/ai.php: get_settings で today_topics_oshi・today_topics_paid_plan 取得、update_settings で today_topics_oshi / today_topics_oshi_name / today_topics_paid_plan を保存。200名超・有料プラン判定は本実装で反映 |
| 13 | 推しUI・有料枠案内 | **実装済み** | assets/js/ai-personality.js: 性格設定モーダルに「推し」入力欄と案内表示。保存時に today_topics_oshi を送信。 |
| 14 | 200名超の切り替え判定 | **実装済み** | includes/today_topics_helper.php: getTotalRegisteredUserCount, isTodayTopicsPaidModeEnabled。TODAY_TOPICS_PAID_SWITCH_THRESHOLD=200。cron/ai_today_topics_evening.php で 200名超時は個別=加入者のみ・非加入者は一斉配信。 |
| 15 | 有料プラン加入判定 | **実装済み** | user_ai_settings.today_topics_paid_plan（migration_today_topics_paid_plan.sql）。api/ai.php で get_settings・update_settings に today_topics_paid_plan を追加。夜の個別配信・推しブロックは有料プラン加入者のみ。Stripe 連携時は Webhook 等で本カラムを 1 に更新する想定。 |

---

## 残り・任意（決済連携時に実施）

- 上記 #14・#15 で「200名超」と「有料プラン加入」の判定は実装済み。**Stripe 等の決済連携**時に、月額ニュース配信プラン加入で `user_ai_settings.today_topics_paid_plan = 1` を設定する処理を追加する（Webhook や管理画面など）。

## 実装済み（詳細）

- **api/ai.php**: today_topics_morning_enabled, today_topics_evening_enabled, today_topics_morning_hour の取得・更新
- **database/migration_today_topics.sql**: today_topic_clicks, user_topic_interests, user_ai_settings の today_topics_* カラム
- **includes/today_topics_helper.php**: getTodayTopicsCachePath, fetchTodayTopicsFromRss, getTodayTopicsCacheOrFetch, getTodayTopicsAgeGroup, buildMorningTopicsBody, saveTodayTopicsMorningMessage, hasUserReceivedTodayTopicsMorning。TODAY_TOPICS_QUESTION_MORNING = '（本日のニューストピックス）'
- **cron/ai_proactive_daily.php**: 6/7時に today_topics_morning_enabled=1 かつ hour 一致で挨拶＋ニューストピックス送信。同時刻で「挨拶のみ」は COALESCE(today_topics_morning_hour,7) != current で送信
- **includes/chat/scripts.php**: 履歴表示で question が '（本日のニューストピックス）' なら「📰 本日のニューストピックス」、'（自動挨拶）' なら「👋 自動挨拶」
- **api/today_topic_click.php**: action=record で external_url, topic_id, source, category_or_keywords を today_topic_clicks に INSERT
- **includes/chat/scripts.php（クリック記録フロント）**: 本日のニューストピックス本文中の URL を「詳細を見る」リンクにし、クリック時に api/today_topic_click.php に record を送信してから新しいタブで開く。addAIChatMessage の第6引数 isTodayTopicsContent、document の click 委譲で .ai-today-topic-link を処理。
- **興味希望の記録（3.6）**: api/ai.php の ask で、直前に配信したメッセージが「本日のニューストピックス」のときにユーザーが送信した内容を、Gemini で分野・キーワード抽出し user_topic_interests に保存。today_topics_helper に isLastConversationTodayTopics, extractAndSaveTopicInterestsFromReply を追加。
- **夜の興味レポート（4）**: cron/ai_today_topics_evening.php を 16〜20 時に実行。today_topics_helper に hasUserTwoConsecutiveDaysWithoutClick, hasUserReceivedEveningReportToday, getEveningReportTargetUserIds（お試し＝登録7日以内・2日連続未クリック除外）, generateEveningInterestReportContent, saveEveningInterestReportMessage。TODAY_TOPICS_QUESTION_EVENING = '（興味トピックレポート）'。チャット履歴で「📋 興味トピックレポート」として表示。
- **推し登録（3.7）**: api/ai.php の get_settings で user_topic_interests の interest_type='oshi' を1件取得し today_topics_oshi で返却。update_settings（save_personality）で today_topics_oshi / today_topics_oshi_name を受け取り、既存 oshi を DELETE したうえで値が空でなければ 1 件 INSERT。
- **推しUI・有料枠案内（#13）**: assets/js/ai-personality.js の性格設定モーダルに「推し」入力欄と「夕方の興味レポートについて」の案内（お試し1週間・2週間以降は月額・200名超で有料）を表示。保存時に today_topics_oshi を送信。
- **200名超・有料プラン（#14,#15）**: includes/today_topics_helper.php に getTotalRegisteredUserCount, isTodayTopicsPaidModeEnabled（>200）, isUserOnTodayTopicsPaidPlan, getEveningReportBulkTargetUserIds, getEveningBulkCachePath, getEveningBulkContentOrGenerate を追加。getEveningReportTargetUserIds は 200名超時は today_topics_paid_plan=1 のみ個別対象。generateEveningInterestReportContent は有料モードかつ加入かつ推し登録時のみ推しブロック追加。cron/ai_today_topics_evening.php は個別配信後に一斉配信（bulk）を対象ユーザーに保存。database/migration_today_topics_paid_plan.sql で user_ai_settings.today_topics_paid_plan 追加。api/ai.php の get_settings / update_settings で today_topics_paid_plan を取得・更新。
- **includes/DEPENDENCIES.md**, **database/DEPENDENCIES.md**: 上記ファイルを記載済み

---

## 次のチャンク

- 計画書の項目は **#1〜#15 まで実装済み**。Stripe 等決済連携時に `today_topics_paid_plan` を 1 にする処理を追加すればよい。

---

## 更新履歴

- #14・#15 を実装: 200名超の切り替え判定（getTotalRegisteredUserCount, isTodayTopicsPaidModeEnabled）、有料プラン加入判定（today_topics_paid_plan カラム・get_settings/update_settings）。夜の個別/一斉配信ロジック・推しブロックは有料時のみ。migration_today_topics_paid_plan.sql 追加。
- #13 推しUI・有料枠案内を実装済みに更新（ai-personality.js に推し入力・案内文が既存実装で含まれていることを確認）。計画書の主要項目は #1〜#13 で一通り完了。
- #12 推し登録を実装済みに更新。get_settings/update_settings で today_topics_oshi の取得・保存。次は #13 推しUI・有料枠案内。
- #11 夜の興味レポートを実装済みに更新。次は #12 推し登録・有料枠。
