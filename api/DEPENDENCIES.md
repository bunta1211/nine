# API 依存関係

このディレクトリには、Social9のREST APIが含まれています。

## API一覧

| ファイル | 役割 | 認証 |
|---------|------|------|
| `messages.php` | メッセージCRUD、リアクション。送信時に絵文字学習（includes/emoji_usage_helper.php の recordEmojiUsage）。**貼り付け上限200M（2億）文字**。**長文**: バイト長>65KB または 文字数>10万 の場合は PDF にせず「長文テキストモード」（content=ラベル・extracted_text=全文で1件INSERT）。それ以外の1000文字以上は自動PDF変換。**15万文字超**は15万文字ごとに分割して複数PDF化し、複数メッセージとしてINSERT（レスポンスに `messages` 配列）。get/send/upload_file は message_type/content_type・deleted_at/is_deleted・message_reactions・message_mentions・left_at の有無を SHOW COLUMNS で判定して耐障害化。delete は deleted_at または is_deleted で論理削除、get で削除済み除外。**To機能 Phase B 実施済み**: send/upload_file/edit では mention_ids を受け取らない（空で処理）。**返信引用**: send で reply_to_id（または reply_to）を受付、正規化して保存。get/send/upload_file のレスポンスで reply_to_id / reply_to_content / reply_to_sender_name を返す。send レスポンスで DB に reply_to_id が無い場合でもリクエストに reply_to_id があれば返信元を取得して補完するフォールバックあり。 | 必須 |
| `conversations.php` | 会話/グループ管理 | 必須 |
| `gif.php` | GIF検索（GIPHY連携） | 不要 |
| `users.php` | ユーザー情報（検索: 表示名・メール・携帯電話）。**グループ追加**: for_group_add 時・list_group_members(include_dm_restricted=1) 時は**同一組織メンバー**を返却（組織未所属時は従来どおり同じグループのメンバー）。**通常検索**: 表示名でのヒットは同一組織に限定（DOCS/SEARCH_POLICY.md）。scope=org で組織内検索対応。システム管理者は誰でも検索可能 | 必須 |
| `friends.php` | 友達管理。**search**: Email または 携帯番号 のみで検索（表示名は使わない）。0件かつ有効なメール形式のとき `invite_available: true`, `contact` を返し、フロントで未登録メールに招待送信可能。申請メッセージ付き・source経路記録・未成年同士制限対応。send_invite で招待メール送信（DOCS/SEARCH_POLICY.md） | 必須 |
| `auth.php` | ログイン/ログアウト（メールまたは携帯電話番号でユーザー検索） | 不要 |
| `auth_otp.php` | OTP/SMS認証（send_code: メール or 電話で認証コード送信、verify_code、set_password）。電話の場合は SmsSender と sms_verification_codes を使用 | 不要 |
| `upload.php` | ファイルアップロード | 必須 |
| `settings.php` | ユーザー設定 | 必須 |
| `notifications.php` | 通知管理。action=count/unread_count は例外時も 500 にせず 200 で安全な値（unread_count/total: 0）を返す | 必須 |
| `translate.php` | 翻訳API | 必須 |
| `memos.php` | メモ機能（deprecated ラッパー: 内部で tasks.php に転送。新規コードでは api/tasks.php?type=memo を使用） | 必須 |
| `tasks.php` | タスク・メモ統合管理（type=task/memo 対応、タイトル自動生成、担当者通知、チャット内タスクメッセージ投稿、役割明確化、pin/count type対応） | 必須 |
| `ai.php` | AI秘書（ask, get_settings, history, save_personality, save_custom_instructions, interpret_send_to_group, execute_voice_command, refine_minutes, voice_context, **extract_improvement_report**, 絵文字学習, **suggest_reply**（AIクローン返信提案生成）, **record_reply_correction**（返信修正記録・修正率算出）, **get_reply_stats**（修正率統計）, **analyze_conversation_memory**（会話記憶自動分析）, **save_clone_settings**（訓練言語・自動返信トグル保存））。性格設定7項目のJSON保存、熟慮モード、性格自動生成。get_settings で personality/deliberation_max_seconds/proactive_message_enabled/proactive_message_hour/today_topics_oshi/today_topics_paid_plan/**clone_training_language**/**clone_auto_reply_enabled**/**conversation_memory_summary**/**reply_stats** を返却。ask のシステムプロンプトに判断材料・会話記憶を自動注入。**添付ファイル**: 画像/PDF/写真対応 | 必須 |
| `ai-judgment.php` | AIクローン判断材料CRUD（フォルダ・アイテムの list/create/rename/delete/reorder）。user_ai_judgment_folders, user_ai_judgment_items テーブルを使用 | 必須 |
| `ai-ping.php` | AI API診断（デバッグ用） | 不要 |
| `ai-get-settings-only.php` | 秘書設定取得（ai.php 500対策）。name / character_type / character_selected を返し、ログアウト後再ログイン時の秘書選択復元に利用 | 必須 |
| `ai-history.php` | AI会話履歴取得（ai.php 500対策） | 必須 |
| `ai-pending-notifications.php` | リマインダー未読通知取得（ai.php 500対策） | 必須 |
| `ai-ask-fallback.php` | ask フォールバック（ai.php 500時の代替、パターンマッチのみ） | 必須 |
| `ai-deliberation-status.php` | 熟慮モード進行状況取得（ポーリング用）。session_id でログ行を返す | 必須 |
| `wish_extractor.php` | Wish抽出 | 必須 |
| `calls.php` | 通話管理 | 必須 |
| `language.php` | 言語切替 | 不要 |
| `google-calendar.php` | Googleカレンダー連携（アカウント管理・イベント作成）。秘書が [CALENDAR_CREATE:...] を返したとき includes/chat/scripts.php の processCalendarCreateTag が create_event を呼び出し。エラー時は error_detail 付きレスポンスとサーバーログ出力 | 必須 |
| `google-sheets-auth.php` | Googleスプレッドシート OAuth認証開始 | 不要（リダイレクト） |
| `google-sheets-callback.php` | Googleスプレッドシート OAuthコールバック | 不要（リダイレクト） |
| `sheets-edit.php` | スプレッドシート編集（AI指示で改変）。POST: spreadsheet_id, instruction | 必須 |
| `sheets-disconnect.php` | スプレッドシート連携解除 | 必須 |
| `document-edit.php` | Social9内Excel/WordをAI指示で編集。POST: file_id, instruction。要 phpoffice/phpspreadsheet または phpword | 必須 |
| `push.php` | Web Push通知（購読・解除・テスト、テーブル自動作成） | vapid_public_key は不要 |
| `google-login-auth.php` | Googleログイン認証開始（OAuthリダイレクト） | 不要 |
| `google-login-callback.php` | GoogleログインOAuthコールバック（ユーザー作成/ログイン） | 不要 |
| `vault.php` | 金庫 unlock（ログインパスワード検証でトークン発行）/ list/get/create/update/delete。unlock 以外は X-Vault-Token 必須。AES-256-GCM（includes/VaultCrypto.php） | 必須（unlock 時はパスワード、それ以外は金庫トークン） |
| `webauthn.php` | WebAuthn 用（金庫では未使用。開錠は vault.php action=unlock のパスワード方式） | 必須（金庫では不使用） |
| `health.php` | ヘルスチェック。`?action=deploy` で管理者向けデプロイ確認（base_dir, topbar_has_test_badge 等） | 基本不要 / action=deploy は管理者のみ |
| `error-log.php` | クライアントJSエラー収集（POSTで message/stack/url を保存）。action（resolve/resolve_batch/resolve_all）は管理者のみ。**管理者以外が action 付きで呼んだ場合は 403 ではなく 200 + JSON** で返却し、コンソールに 403 を出さない。 | エラー報告は不要 / action は管理者 |
| `improvement_reports.php` | 改善提案API（create: 手動新規, get: 1件取得・Cursor用コピー, mark_done: 対応済み＋報告者へ通知）。管理者のみ | 必須（管理者） |
| `deploy-check.php` | デプロイ確認（DB・bootstrap 非依存）。health.php が 500 のとき用。base_dir / topbar_has_test_badge を返す | 不要 |
| `storage.php` | 共有フォルダAPI（フォルダ/ファイルCRUD、署名付きURLアップロード、共有管理、ゴミ箱、権限管理、検索、**フォルダパスワード設定** set_folder_password）。**get_usage** で無制限組織は `quota_display=無制限`, `unlimited=true` を返却。複数一括アップロードはクライアントで `request_upload`→S3→`confirm_upload` をループ。アルバム（日時題名フォルダ）はフロントで `create_folder` 後に同様にループアップロード。 | 必須 |
| `ai-memories.php` | 組織AI記憶ストア管理API（search, get, create, update, delete, restore, history）。組織メンバーのみ・権限チェック付き。FULLTEXT検索・ページネーション対応 | 必須（組織メンバー） |
| `ai-specialists.php` | 専門AI管理API（list, update, provision, flags, update_flag, defaults, update_default, stats）。組織メンバー/管理者/システム管理者で権限分離 | 必須（組織メンバー/管理者） |
| `ai-safety.php` | AI安全通報管理API（list, detail, update_status, ask_question, get_questions, stats）。運営責任者（KEN）のみアクセス可。秘書への追加質問機能付き | 必須（システム管理者） |
| `today_topic_click.php` | 今日の話題 クリック記録（action=record）。本日のニューストピックス内の「詳細を見る」クリックを today_topic_clicks に記録。計画書 DOCS/PLAN_TODAYS_TOPICS.md 3.4 | 必須 |

---

## 共通依存関係

以下は api-bootstrap を使用（IP ブロック・迎撃・共通エラーハンドリング適用）:
messages, conversations, upload, tasks, settings, notifications, memos, calls, users, friends, error-log, security, page-check, health, app-notifications, test-helper

```php
require_once __DIR__ . '/../includes/api-bootstrap.php';
```

これにより以下が自動的に読み込まれます:
- セッション管理
- データベース接続
- 共通ヘルパー関数
- エラーハンドリング
- IP ブロック・攻撃検出・自動迎撃

---

## messages.php 詳細

### アクション一覧

| action | 役割 | HTTPメソッド |
|--------|------|-------------|
| `list` | メッセージ一覧取得 | GET |
| `send` | メッセージ送信（**貼り付け上限200M文字**。長文テキストモード時はPDFにせず1貼り付け＝1メッセージ。1000字以上かつ短い長文は自動PDF変換。15万文字超は15万文字ごとに分割して複数PDF・複数メッセージで送信し、レスポンスに `messages` 配列を返す）。PDFテキスト抽出→extracted_text保存。AI秘書の「〇〇に送信」でも利用（includes/chat/scripts.php trySendToGroup） | POST |
| `edit` | メッセージ編集 | POST |
| `edit_display_name` | ファイル添付の表示名のみ編集 | POST |
| `delete` | メッセージ削除 | POST |
| `react` | リアクション追加/トグル（同一絵文字で削除） | POST |
| `add_reaction` | フロント reactions.js 用。react と同一処理（message_id, reaction） | POST |
| `remove_reaction` | フロント reactions.js 用。unreact と同一処理。削除後の `reactions` 一覧を返す | POST |
| `unreact` | リアクション削除 | POST |
| `poll` | ポーリング用。last_id を after_id に変換し get と同一処理 | GET |
| `search` | グローバル検索（メッセージ・ユーザー・グループ横断。15歳未満除外、is_friend/is_pending付与、phone検索対応、所属会話のみ） | GET |

### 依存関係

| カテゴリ | 依存先 | 用途 |
|---------|-------|------|
| DB | `messages` | メッセージ本体 |
| DB | `message_reactions` | リアクション（**テーブルが無いと保存されない**。未作成時は `database/add_new_features_tables.sql` または `database/schema_messages.sql` で作成） |
| DB | `message_mentions` | メンション情報 |
| DB | `users` | 送信者情報 |
| DB | `conversations` | 会話情報 |
| 共通 | `includes/pdf_helper.php` | 1000文字以上の長文をPDF変換（長文テキストモードでない場合）。PDFテキスト抽出（extractPdfText） |
| 共通 | `smalot/pdfparser` (composer) | PDFからテキスト抽出（extractPdfText内で使用） |
| フロント | `includes/chat/scripts.php` | `sendMessage()`, `appendMessageToUI()`, `editMessage()` がこのAPIを呼び出し |

### レスポンス形式

#### 送信時 (`action=send`)
```json
{
  "success": true,
  "message_id": 123,
  "message": {
    "id": 123,
    "content": "メッセージ本文",
    "sender_id": 1,
    "sender_name": "ユーザー名",
    "created_at": "2024-01-01 12:00:00",
    "is_edited": 0,
    "has_to_all": true,
    "to_member_ids": [2, 3]
  }
}
```

#### 取得時 (`action=get`)
```json
{
  "success": true,
  "messages": [{
    "id": 123,
    "content": "メッセージ本文",
    "sender_id": 1,
    "sender_name": "ユーザー名",
    "created_at": "2024-01-01 12:00:00",
    "is_edited": 0,
    "is_mentioned_me": true,
    "mention_type": "to_all",
    "show_to_all_badge": false,
    "show_to_badge": false,
    "to_member_ids_list": [],
    "reaction_details": [
      {"type": "👍", "count": 2, "users": [{"id": 1, "name": "Ken"}, {"id": 2, "name": "Alice"}], "is_mine": true}
    ]
  }]
}
```

### 変更時のチェックリスト

- [ ] レスポンス形式を変更する場合 → `scripts.php` の `renderMessages()` を確認
- [ ] リアクションを変更する場合 → `api/messages.php` の `$valid_reactions` と `modals.php` のピッカーを確認（人別表示・users 付与済み、🙇 ありがとう追加済み）。フロントは `add_reaction`/`remove_reaction` で送信し、API は `reactions` 配列（reaction_type, users, is_mine）を返す
- [ ] 編集機能を変更する場合 → DB: `is_edited`, `edited_at` カラムを確認

---

## conversations.php 詳細

### アクション一覧

| action | 役割 |
|--------|------|
| `list` | 会話一覧取得（icon_path, icon_style, icon_pos_x, icon_pos_y, icon_size を含む） |
| `list_with_unread` | ポーリング用軽量リスト（未読・時刻・icon 系・type・name 含む）。携帯FAB「新規」のグループ選択モーダルでも利用。 |
| `update_icon` | グループ/会話アイコン更新。icon_path が空の場合は既存パスを維持 |
| `create` | 新規会話作成 |
| `members` | メンバー一覧取得 |
| `add_member` | メンバー追加 |
| `remove_member` | メンバー削除 |
| `update_role` | 権限変更 |
| `update_settings` | グループ設定変更 |

### 依存関係

| カテゴリ | 依存先 |
|---------|-------|
| DB | `conversations` |
| DB | `conversation_members` |
| DB | `users` |
| フロント | `scripts.php` の `renderCurrentMembersList()` |

---

## gif.php 詳細

### 特徴
- 認証不要
- 外部API（GIPHY）を呼び出し
- フォールバック用プリセットGIFあり

### パラメータ

| パラメータ | 必須 | 説明 |
|-----------|------|------|
| `q` | ○ | 検索キーワード |
| `limit` | × | 取得件数（デフォルト24、最大50） |
| `test` | × | テストモード（APIステータス確認） |

### レスポンス形式

```json
{
  "results": [
    {
      "id": "abc123",
      "title": "funny cat",
      "tiny": "https://media.giphy.com/.../200.gif",
      "full": "https://media.giphy.com/.../giphy.gif"
    }
  ],
  "source": "giphy",
  "count": 24
}
```

### フロントエンドとの連携

```
scripts.php: toggleEmojiPicker()
    │
    └── GIFタブ選択時
        │
        └── searchGif(query)
            │
            └── fetch('api/gif.php?q=...')
                │
                └── 結果をグリッド表示
                    │
                    └── クリック → insertGif(url) → sendMessage()
```

---

## tasks.php 詳細

### アクション一覧

| action | 役割 | HTTPメソッド |
|--------|------|-------------|
| `list` | タスク/メモ一覧取得。type=task/memo でフィルタ。conversation_id指定時は会話メンバーなら全件表示 | GET |
| `get` | タスク詳細取得 | GET |
| `create` | タスク/メモ作成。type=memo の場合は content/color/is_pinned を受付 | POST |
| `update` | タスク/メモ更新。content/color/is_pinned にも対応 | POST |
| `toggle` | 完了/未完了切替 | POST |
| `complete` | 完了に設定 | POST |
| `reopen` | 未完了に戻す | POST |
| `delete` | タスク/メモ削除（論理削除） | POST |
| `pin` | ピン留め ON/OFF（is_pinned を更新） | POST |
| `count` | 件数取得。type=task/memo でフィルタ対応 | GET |

### 特徴

- **タスク・メモ統合**: type カラム（task/memo）で区別。メモは tasks テーブルに type='memo' で保存
- **タイトル自動生成**: タイトルが空の場合、説明文の先頭100文字から自動生成
- **担当者通知**: タスクを他者に割り当てた際、通知を自動送信
- **deleted_at**: 論理削除対応（カラムがあれば使用）
- **source**: タスクのソース（manual/ai/wish等、カラムがあれば使用）
- **後方互換**: type カラムが無い環境でも動作（tasksHasTypeColumn でチェック）

### 依存関係

| カテゴリ | 依存先 | 用途 |
|---------|-------|------|
| DB | `tasks` | タスク・メモ本体（type='task' or 'memo'） |
| DB | `users` | 担当者・作成者情報 |
| DB | `app_notifications` | 担当者への通知 |
| フロント | `tasks.php` | タスク/メモ統合管理画面 |

---

## エラーレスポンス共通形式

```json
{
  "success": false,
  "error": "エラーメッセージ"
}
```

---

## 新規APIを追加する場合

1. `includes/api-bootstrap.php` を require
2. `requireLogin()` で認証チェック（必要な場合）
3. `$pdo = getDB()` でDB接続取得
4. `$input = getJsonInput()` でPOSTデータ取得
5. `jsonResponse($data)` でレスポンス返却
6. このファイル（DEPENDENCIES.md）を更新
