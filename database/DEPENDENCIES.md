# database/ スキーマ・マイグレーション依存関係

このディレクトリには、データベースのスキーマ定義とマイグレーションファイルが含まれています。

## ファイル構成

```
database/
├── DEPENDENCIES.md          ← このファイル
├── SCHEMA_README.md         ← スキーマ詳細説明
│
├── schema.sql               ← メインスキーマ（初期構築用）
├── schema_complete.sql      ← 完全版スキーマ
├── schema_*.sql             ← 機能別スキーマ
├── improvement_reports.sql  ← 改善提案テーブル（汎用デバッグフロー）
│
├── migration_*.sql          ← マイグレーションファイル
├── migration_ai_specialist_system.sql ← AI専門AIシステム（11テーブル: org_ai_specialists, org_ai_memories, org_ai_memory_history, org_ai_memory_permissions, user_ai_profile, ai_safety_reports, ai_safety_report_questions, org_ai_memory_batch_log, org_ai_specialist_logs, ai_feature_flags, ai_specialist_defaults）
├── migration_ai_clone_judgment_and_reply.sql ← AIクローン育成（user_ai_judgment_folders, user_ai_judgment_items, user_ai_reply_suggestions テーブル新規作成。user_ai_settings に conversation_memory_summary / clone_training_language / clone_auto_reply_enabled カラム追加）
└── seed_ai_specialist_defaults.sql ← デフォルト専門AIプロンプト・機能フラグ初期データ
```

---

## 主要テーブルと依存関係

### ユーザー・認証系

```
users
├── id (PK)
├── email (NULL可: 電話のみ登録時。migration_phone_registration.sql)
├── password
├── phone (UNIQUE。登録/設定/検索。電話のみ登録時は必須)
├── name
├── role (system_admin/org_admin/user)
├── background_image ──────────► includes/design_loader.php
├── background_color ──────────► includes/design_loader.php
└── created_at

organization_members
├── user_id (FK) ──────────────► users.id
├── organization_id (FK) ──────► organizations.id
└── role (owner/admin/member/restricted)
```

### 会話・メッセージ系

```
conversations
├── id (PK)
├── name
├── type (dm/group)
├── organization_id (FK) ──────► organizations.id
├── icon_path ─────────────────► グループアイコン画像（api/conversations.php update_icon）
├── icon_style ────────────────► 背景スタイル（migration_icon_style.sql）
├── icon_pos_x, icon_pos_y ────► アイコン位置（%）
├── icon_size ─────────────────► アイコンサイズ（50–150）
├── is_private_group ──────────► 1=プライベート（組織管理からのみ作成）。migration_private_group_settings.sql
├── allow_member_post ─────────► 1=メンバー発言許可
├── allow_data_send ───────────► 1=ファイル等送信許可
├── member_list_visible ────────► 1=メンバー一覧表示
├── allow_add_contact_from_group ► 1=グループ内から個人アドレス帳追加許可
└── created_at

conversation_members
├── conversation_id (FK) ──────► conversations.id
├── user_id (FK) ──────────────► users.id
├── role (admin/member)
└── joined_at

messages
├── id (PK)
├── conversation_id (FK) ──────► conversations.id
├── sender_id (FK) ────────────► users.id
├── content
├── is_edited ─────────────────► includes/chat/scripts.php (表示時に使用)
├── edited_at
└── created_at

message_reactions
├── message_id (FK) ───────────► messages.id
├── user_id (FK) ──────────────► users.id
└── reaction_type ─────────────► includes/chat/modals.php ($valid_reactions)
```

### AI秘書系（schema.sql に定義）

```
ai_conversations
├── user_id (FK) ──────────────► users.id
├── question / answer
├── is_proactive (TINYINT) ────► 自動話しかけ=1 (migration_personality_json.sql)
└── created_at ────────────────► api/ai.php (ask), api/ai-history.php

user_ai_settings
├── user_id (FK) ──────────────► users.id
├── secretary_name ────────────► api/ai.php (save_secretary_name), api/ai-get-settings-only.php
├── character_type ────────────► api/ai.php (save_character_type), chat.php (__AI_SECRETARY_PREFILL)
├── character_selected
├── personality_json (TEXT) ────► 7項目の性格設定JSON (migration_personality_json.sql)
├── deliberation_max_seconds ──► 熟慮モード最大秒数 (デフォルト180)
├── proactive_message_enabled ─► 毎日の自動話しかけ ON/OFF
└── proactive_message_hour ────► 話しかけ時刻 (0-23、デフォルト18)

ai_user_memories
├── user_id (FK) ──────────────► users.id
├── category / content
└── created_at ────────────────► api/ai.php (save_memory, get_memories)

user_emoji_usage（絵文字学習）
├── user_id (FK) ──────────────► users.id
├── emoji_char / cnt / updated_at
└── 作成: migration_user_emoji_usage.sql
    参照: includes/emoji_usage_helper.php (recordEmojiUsage, getTopEmojis)
    利用: api/messages.php (送信時記録), api/ai.php (ask で参照)
```

### 専門AI・記憶・通報系（migration_ai_specialist_system.sql に定義）

```
org_ai_specialists（組織別 専門AI設定）
├── organization_id (FK) ─────► organizations.id
├── specialist_type (ENUM) ───► work/people/finance/compliance/mentalcare/education/customer
├── display_name / system_prompt / custom_rules / config_json
└── is_enabled ───────────────► includes/ai_specialist_router.php

org_ai_memories（組織別 ナレッジ記憶ストア）
├── organization_id (FK) ─────► organizations.id
├── specialist_type (ENUM) ───► 振り分け先の専門AIタイプ
├── title / content (FULLTEXT)
├── source_conversation_id ───► conversations.id
├── source_type ──────────────► auto_chat/auto_batch/manual/import
└── status ───────────────────► active/archived/deleted

org_ai_memory_history（記憶の編集履歴）
├── memory_id (FK) ───────────► org_ai_memories.id
├── action ───────────────────► create/update/delete/restore
└── changed_by (FK) ──────────► users.id

org_ai_memory_permissions（記憶のアクセス権限）
├── organization_id (FK) ─────► organizations.id
├── user_id / role ───────────► 個別またはロール単位
└── permission_level ─────────► view/edit/delete

user_ai_profile（ユーザー性格プロファイル）
├── user_id (FK) ──────────────► users.id
├── personality_traits (JSON) ─► 性格特性
├── communication_style (JSON) ► コミュニケーション傾向
├── preferred_topics (JSON) ───► 関心トピック
└── behavior_patterns (JSON) ──► 行動パターン

ai_safety_reports（運営への自動通報）
├── user_id (FK) ──────────────► users.id
├── report_type (ENUM) ────────► social_norm/life_danger/bullying/other
├── severity (ENUM) ───────────► low/medium/high/critical
├── raw_context / ai_reasoning
└── user_personality_snapshot ─► 通報時点の性格分析

ai_safety_report_questions（運営→秘書 追加質問）
├── report_id (FK) ────────────► ai_safety_reports.id
├── asked_by (FK) ─────────────► users.id
└── question / answer

org_ai_memory_batch_log（バッチ処理ログ）
├── organization_id (FK) ──────► organizations.id
├── conversation_id ───────────► conversations.id
└── messages_processed / memories_created

org_ai_specialist_logs（専門AI利用ログ）
├── organization_id (FK) ──────► organizations.id
├── user_id (FK) ──────────────► users.id
└── specialist_type / query_summary / response_summary

ai_feature_flags（機能フラグ）
├── feature_number (UNIQUE) ───► 機能番号 1-33
└── status (ENUM) ─────────────► disabled/beta/enabled

ai_specialist_defaults（デフォルト専門AIプロンプト）
├── specialist_type (UNIQUE) ──► 各専門AIタイプ
└── default_prompt / intent_keywords
```

**テーブルが無い環境用**: `migration_ai_secretary_tables_ensure.sql` で3テーブルを一括作成（IF NOT EXISTS）。  
**カラム不足時**: `migration_ai_secretary_columns_add.sql` の ALTER を必要に応じて実行。改善ログ: `DOCS/AI_SECRETARY_IMPROVEMENTS_LOG.md`

### AIクローン育成系（migration_ai_clone_judgment_and_reply.sql に定義）

```
user_ai_judgment_folders（判断材料フォルダ・共有フォルダ形式）
├── user_id (FK) ──────────────► users.id
├── parent_id (FK, NULL可) ────► user_ai_judgment_folders.id（サブフォルダ）
├── name / sort_order
└── created_at / updated_at

user_ai_judgment_items（判断材料アイテム）
├── folder_id (FK) ────────────► user_ai_judgment_folders.id
├── user_id (FK) ──────────────► users.id
├── title / content / file_path
└── sort_order / created_at / updated_at

user_ai_reply_suggestions（返信提案・教材記録）
├── user_id (FK) ──────────────► users.id
├── conversation_id (FK) ──────► conversations.id
├── message_id ────────────────► messages.id（メンション元）
├── suggested_content ─────────► AI提案文
├── final_content ─────────────► ユーザー修正後の本文（NULL=未送信）
├── sent_at ───────────────────► 送信日時
└── created_at

user_ai_settings（追加カラム）
├── conversation_memory_summary ► 会話記憶の要約JSON（cron自動更新）
├── clone_training_language ────► 訓練・返信の言語 ja/en/zh
└── clone_auto_reply_enabled ──► AI自動返信 1=ON
```

**API**: api/ai-judgment.php（フォルダ/アイテムCRUD）、api/ai.php（suggest_reply, record_reply_correction, analyze_conversation_memory, save_clone_settings, get_reply_stats）  
**Cron**: cron/ai_clone_memory_update.php（毎日1回・会話記憶の全自動更新）  
**フロント**: assets/js/secretary-rightpanel.js（SecRP）、assets/js/ai-reply-suggest.js（AIReplySuggest）

**improvement_reports（改善・デバッグ提案）**: 作成は `improvement_reports.sql`。api/ai.php の extract_improvement_report で INSERT。admin/improvement_reports.php（予定）で一覧・Cursor用コピー。

### その他

```
tasks（タスク・メモ統合テーブル）
├── id (PK)
├── type (ENUM: task/memo) ────► task=タスク, memo=メモ（migration_merge_memos_into_tasks.sql）
├── title
├── description ───────────────► タスク説明
├── content ───────────────────► メモ本文（type=memo 時使用）
├── color ─────────────────────► メモ背景色（type=memo 時使用）
├── created_by (FK) ───────────► users.id
├── assigned_to (FK) ──────────► users.id（タスク担当者）
├── conversation_id (FK) ──────► conversations.id
├── message_id ────────────────► messages.id（メモ元メッセージ）
├── is_shared
├── is_pinned ─────────────────► ピン留め（type=memo 時使用）
├── status, priority, due_date
├── completed_at, deleted_at
└── created_at, updated_at

memos（deprecated: tasks テーブルに統合済み。バックアップとして残す）
├── id (PK)
├── created_by (FK) ───────────► users.id
├── conversation_id (FK) ──────► conversations.id
├── message_id (FK) ───────────► messages.id (オプション)
├── title, content, color, is_pinned
└── 新規書き込みは tasks テーブル (type='memo') に統一

friends
├── user_id (FK) ──────────────► users.id
├── friend_id (FK) ────────────► users.id
└── status
```

### 認証コード系

```
sms_verification_codes (migration_phone_registration.sql)
├── phone, code, expires_at, is_new_user, attempts, verified_at
└── api/auth_otp.php (SMS認証コード送信・検証)
```

---

## テーブル別 コード依存関係

### users テーブル

| カラム | 依存コード | 用途 |
|-------|-----------|------|
| `id` | 全API、全画面 | ユーザー識別 |
| `email` | `api/auth.php` | ログイン |
| `name` | `includes/chat/scripts.php` | 表示名 |
| `role` | `includes/roles.php` | 権限チェック |
| `background_image` | `includes/design_loader.php` | テーマ背景 |
| `background_color` | `includes/design_loader.php` | テーマ色 |

### messages テーブル

| カラム | 依存コード | 用途 |
|-------|-----------|------|
| `id` | `api/messages.php` | メッセージ識別 |
| `content` | `includes/chat/scripts.php` | 表示内容 |
| `sender_id` | `includes/chat/scripts.php` | 送信者判定 |
| `extracted_text` | `api/messages.php`, `api/ai.php`, `includes/task_memo_search_helper.php` | PDF/長文の全文（検索・AI学習用）。**貼り付け上限200M対応**のため LONGTEXT（`migration_messages_extracted_text_longtext.sql`）必須。 |
| `is_edited` | `includes/chat/scripts.php` | 編集済み表示 |
| `edited_at` | `api/messages.php` | 編集時刻記録 |

### conversations テーブル

| カラム | 依存コード | 用途 |
|-------|-----------|------|
| `id` | `chat.php`, `api/conversations.php` | 会話識別 |
| `name` | `includes/chat/scripts.php` | グループ名表示 |
| `type` | `api/conversations.php` | DM/グループ判定 |

---

## マイグレーション履歴

| ファイル | 追加内容 | 影響コード |
|---------|---------|-----------|
| `migration_add_edited_columns.sql` | `is_edited`, `edited_at` | `api/messages.php`, `scripts.php` |
| `migration_friends.sql` | `friends` テーブル | `api/friends.php` |
| `migration_app_notifications.sql` | `app_notifications` | `api/app-notifications.php` |
| `migration_wish_*.sql` | Wish関連テーブル | `api/wish_extractor.php` |
| `migration_task_memo_soft_delete.sql` | tasks/memos に deleted_at（論理削除） | `api/tasks.php`, `api/memos.php`, `includes/task_memo_search_helper.php` |
| `migration_message_type.sql` | メッセージタイプ | `api/messages.php` |
| `migration_messages_reply_to_id.sql` | `messages.reply_to_id`（返信引用・リロード後も引用を表示） | `api/messages.php`, `includes/chat/data.php`, `chat.php` |
| `migration_google_calendar_accounts.sql` | `google_calendar_accounts` | `api/google-calendar.php`, `settings.php` |
| `migration_google_login.sql` | `users.google_id` | `api/google-login-callback.php` |
| `migration_task_chat_integration.sql` | messages.task_id, tasks.notification_message_id（タスク・チャット連携） | `api/tasks.php`, `includes/chat/scripts.php` |
| `migration_push_subscriptions.sql` | `push_subscriptions`, `push_notification_logs` | `api/push.php`（※テーブル未作成時は自動作成） |
| `migration_standard_design_only.sql` | user_settings の theme='lavender', background_image='none' に統一・デフォルト変更 | `includes/design_loader.php`, `chat.php`, `design.php`, `api/settings.php` |
| `migration_privacy_search.sql` | `user_privacy_settings`, `blocked_users`（検索除外・ブロック） | `api/users.php`, `api/messages.php`, `api/settings.php`, `includes/auth/Auth.php`, `api/auth_otp.php` |
| `migration_search_default_public.sql` | 既存ユーザーの `exclude_from_search` を 0 に（携帯番号検索でヒットするように） | 上記に同じ |
| `migration_vault.sql` | `vault_sessions`, `vault_items`, `webauthn_credentials`（金庫機能・WebAuthn 認証） | `api/vault.php`, `api/webauthn.php`, `includes/VaultCrypto.php` |
| `migration_access_log.sql` | `access_log`（本日のアクセス・検索経由・離脱率集計用。visitor_key, path, referer_host, ip_address） | `includes/access_logger.php`, `admin/index.php` |
| `migration_personality_json.sql` | `user_ai_settings` に personality_json, deliberation_max_seconds, proactive_message_enabled, proactive_message_hour 追加。`ai_conversations` に is_proactive 追加 | `api/ai.php`, `assets/js/ai-personality.js`, `cron/ai_proactive_daily.php` |
| `migration_today_topics.sql` | `today_topic_clicks`, `user_topic_interests` テーブル作成。`user_ai_settings` に today_topics_morning_enabled, today_topics_evening_enabled, today_topics_morning_hour 追加 | `includes/today_topics_helper.php`, `cron/ai_proactive_daily.php`, `cron/ai_today_topics_evening.php`, `api/ai.php`（get_settings / update_settings） |
| `migration_today_topics_paid_plan.sql` | `user_ai_settings.today_topics_paid_plan`（月額ニュース配信プラン加入 0/1）。200名超時の夜の個別配信・推しブロック対象判定に使用 | `includes/today_topics_helper.php`, `cron/ai_today_topics_evening.php`, `api/ai.php`（get_settings / update_settings） |
| `migration_storage_folder_password.sql` | `storage_folders.password_hash`（フォルダ閲覧用パスワード） | `api/storage.php`, `assets/js/storage.js`, `chat.php` |
| `migration_merge_memos_into_tasks.sql` | tasks に type/content/color/message_id/is_pinned 追加、memos→tasks データ移行。memos テーブルは削除せずバックアップとして残す | `api/tasks.php`, `api/memos.php`(deprecated wrapper), `tasks.php`, `includes/task_memo_search_helper.php` |
| `migration_messages_extracted_text_longtext.sql` | `messages.extracted_text` を MEDIUMTEXT→LONGTEXT に変更（貼り付け上限200M文字対応）。ft_extracted_text がある場合は DROP 後に MODIFY | `api/messages.php`, `api/ai.php`（suggest_reply で extracted_text 優先参照） |
| `migration_production_missing_columns.sql` | 本番で不足しがちなカラムを一括追加（messages.reply_to_id/message_type/is_edited/deleted_at/extracted_text, users.avatar_path, conversation_members.left_at/last_read_message_id, tasks.type/status/deleted_at/is_shared）。既存カラムはエラーになるがスキップして実行可。 | `api/messages.php`, `api/tasks.php` の耐障害化の根本対応用 |
| `migration_private_group_settings.sql` | `conversations` に is_private_group, allow_member_post, allow_data_send, member_list_visible, allow_add_contact_from_group を追加（プライベートグループ・アドレス帳制御）。DOCS/PRIVATE_GROUP_AND_ADDRESS_BOOK_MASTER_PLAN.md 準拠。 | `api/conversations.php`, `api/messages.php`, `api/upload.php`, `admin/api/groups.php`, `includes/chat/data.php`, `includes/chat/scripts.php` |
| `migration_org_invite_candidates.sql` | `org_invite_candidates` テーブル（組織一斉招待候補。organization_id, email, display_name, status 等） | `admin/api/members.php`（bulk_invite で利用予定） |

---

## カラム追加時のチェックリスト

### 新しいカラムを追加する場合

1. **マイグレーションファイル作成**
   ```sql
   -- migration_xxx.sql
   ALTER TABLE table_name ADD COLUMN column_name TYPE DEFAULT value;
   ```

2. **関連コードの更新**
   - [ ] API（SELECT文、INSERT文、UPDATE文）
   - [ ] フロントエンド（表示ロジック）
   - [ ] このDEPENDENCIES.mdに追記

3. **本番適用手順**
   - [ ] マイグレーションSQLを実行
   - [ ] 更新したPHPファイルをアップロード
   - [ ] 動作確認

---

## テーブル追加時のチェックリスト

1. **スキーマファイル作成**
   - `schema_xxx.sql` に新テーブル定義
   - または既存の `schema.sql` に追記

2. **関連ファイル**
   - [ ] 新規API作成 → `api/DEPENDENCIES.md` に追記
   - [ ] 新規画面作成 → 該当のDEPENDENCIES.mdに追記

3. **外部キー**
   - [ ] 参照先テーブルが存在するか確認
   - [ ] ON DELETE / ON UPDATE の動作を決定

---

## 命名規則

| 要素 | 規則 | 例 |
|-----|------|-----|
| テーブル名 | 複数形、スネークケース | `users`, `message_reactions` |
| カラム名 | スネークケース | `created_at`, `user_id` |
| 外部キー | `{参照テーブル単数}_id` | `user_id`, `conversation_id` |
| 日時カラム | `*_at` | `created_at`, `edited_at` |
| フラグカラム | `is_*` / `has_*` | `is_edited`, `has_password` |

---

## 注意事項

### 本番環境でのマイグレーション

```
⚠️ 本番環境でALTER TABLEを実行する際の注意:

1. バックアップを取得
2. メンテナンスモードに切替（maintenance.html）
3. マイグレーション実行
4. 動作確認
5. メンテナンスモード解除
```

### カラム削除について

```
⚠️ カラム削除は慎重に:

1. 依存コードをすべて確認
2. 先にコードから参照を削除
3. デプロイ後、十分な期間を置いてからカラム削除
```

---

### 共有フォルダ系

```
storage_plans
├── id (PK)
├── name, quota_bytes, monthly_price, description, is_active, created_at

storage_subscriptions
├── id (PK)
├── entity_type (organization/user), entity_id → plan_id → storage_plans
├── status, started_at, expires_at

storage_folders
├── id (PK)
├── conversation_id, parent_id (self-ref), name, created_by

storage_files
├── id (PK)
├── folder_id → storage_folders
├── original_name, s3_key, file_size, mime_type, status (pending/active/deleted)
├── deleted_at, deleted_by, uploaded_by

storage_folder_shares
├── folder_id → storage_folders, shared_with_conversation_id, permission, shared_by

storage_billing_records
├── subscription_id → storage_subscriptions, billing_month, amount, status

storage_usage_logs
├── entity_type, entity_id, used_bytes, quota_bytes, notification_type

storage_member_permissions
├── conversation_id, user_id, can_create_folder, can_delete_folder, can_upload, can_delete_file

storage_bank_accounts
├── entity_type, entity_id, bank_code, branch_code, account_number, account_holder_kana
```
