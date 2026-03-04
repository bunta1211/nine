# チャット機能 依存関係

このディレクトリ内のファイルを変更する際は、以下の依存関係を確認してください。

## ファイル構成

| ファイル | 役割 | 行数 |
|---------|------|------|
| `data.php` | データ取得ロジック（DB最適化済み） | ~280行 |
| `topbar.php` | 上パネル（ヘッダー）HTML。`.top-panel-inner` で内側凹みラッパー、各ボタンに `.btn-label`。タスク/メモ・通知は `<a href="tasks.php">` / `<a href="notifications.php">` のリンクで即ページ遷移（JSに依存しない）。`top-divider` は削除。chat.php のほか design.php / tasks.php / notifications.php から include（$topbar_back_url, $topbar_header_id 等で制御）。立体デザインは assets/css/layout/header.css と design_loader.php で適用。 | ~206行 |
| `sidebar.php` | 左パネル（会話リスト）HTML。**モバイル友達追加**: placeholder「Email/携帯番号で検索」、説明文、未登録時の「このメールアドレスに友達申請を送る」用 `#mobileFriendInviteRow`、ヘッダーに「QRコード」ボタン（openAddFriendModalForQR）、フォーム下に「QRコードを表示」ボタンと `#mobileMyQRContainer`（showMyQRCodeMobile） | ~240行 |
| `rightpanel.php` | 右パネル（詳細）HTML | ~100行 |
| `rightpanel_secretary.php` | AI秘書（AIクローン）専用右パネルHTML。訓練言語選択・判断材料フォルダツリー・会話記憶表示・自動返信トグル＋統計。secretary-rightpanel.js（SecRP）と連携 | ~100行 |
| `settings-account-bar.php` | 設定＋アカウントドロップダウン共通。`$account_bar_variant = 'left_panel'` のときは左パネル用に「グループ管理」ボタンのみ（歯車なし・クリックで右パネル＝詳細を開く） | ~95行 |
| `call-ui.php` | 通話関連UI HTML | ~70行 |
| `member-popup.php` | メンバーポップアップ HTML | ~70行 |
| `modals.php` | モーダルウィンドウHTML。手動タスク追加（manualWishModal）では「元のメッセージ」を textarea（wishOriginalText）で編集可能 | 既存 |
| `scripts.php` | JavaScript（メッセージ表示・送信・返信引用等）。1000文字以上送信時はコンソールで「長文はテキストのまま保存され、検索・AI学習に利用されます」と案内。**朝のニュース動画**: answer に「（朝のニュース動画）」が含まれる場合は JSON 動画リストを解析し、一覧＋小窓埋め込み（YouTube IFrame API）で表示。再生終了で次を自動再生。一覧クリックでその動画を再生。**AI秘書入力欄**: グループチャット同様にドラッグで高さ変更可能。パネル描画時に `input-area-resize-handle` を差し込み、`initInputAreaResize()` でリサイズを有効化。 | 既存 |

---

## アーキテクチャ

- **To機能 Phase C 再実装済み**: 入力欄のToボタン・To行バーを再表示。送信は本文のみ（mention_ids は送らない）。API が本文から [To:all]/[To:ID] をパースして message_mentions に保存。表示は chat.php の [To:ID]→チップ変換と data.php の to_info を維持。詳細は DOCS/TO_FEATURE_SPEC_AND_REBUILD_PLAN.md 参照。
- **初期表示**: `chat.php` で `?c=` が無い場合、PCでは `$_SESSION['last_conversation_id']` があればその会話へリダイレクト。**携帯・モバイル**（User-Agent で判定）ではリダイレクトせず、常に**グループ一覧を最初に表示**（メッセージ数を先に見せるため）。モバイルの3ページストリップでは `assets/js/chat-mobile.js` の `initMobilePagesStripScroll()` で `main.scrollLeft = 0` とし、左パネル（グループ一覧）を初期表示。複数回の遅延実行と `pageshow`（bfcache 復元時）・`visibilitychange`（タブ表示復帰時）で確実に一覧を表示。chat.php のインラインスクリプトも scrollLeft=0 を load 後 1500ms 含む複数タイミングで設定。

```
chat.php (317行)
    │
    ├── 初期化・データ取得
    │   ├── config/session.php
    │   ├── config/database.php
    │   ├── includes/design_loader.php
    │   ├── includes/lang.php
    │   └── includes/chat/data.php ← データ取得ロジック
    │
    ├── HTMLテンプレート
    │   ├── includes/chat/topbar.php      ← 上パネル
    │   ├── includes/chat/sidebar.php     ← 左パネル（settings-account-bar include）
    │   ├── 中央パネル（chat.php内）
    │   ├── includes/chat/rightpanel.php  ← 右パネル（settings-account-bar include）
    │   ├── includes/chat/call-ui.php     ← 通話UI
    │   ├── includes/chat/member-popup.php ← メンバーポップアップ
    │   └── includes/chat/modals.php      ← モーダル
    │
    └── JavaScript
        ├── assets/js/chat/ui-sounds.js   ← パネル収納時効果音（book1）
        ├── includes/chat/scripts.php     ← メインJS
        └── assets/js/chat-mobile.js      ← モバイル用
```

---

## data.php 依存関係

### 提供する関数

| 関数名 | 役割 | DB依存 |
|-------|------|--------|
| `getChatPageData()` | ページ全体のデータ取得 | users, organizations, conversations, messages |
| `getSelectedConversationData()` | 選択中会話のデータ取得 | conversations, messages, conversation_members |
| `getDmPartnerNames()` | DM相手名を一括取得（N+1解消） | conversation_members, users |
| `enrichMessagesWithMentionsAndReactions()` | メンション・リアクション付加 | message_mentions, message_reactions |
| `enrichMessagesWithTaskDetails()` | タスク詳細付加（カード表示用） | tasks, users |
| `updateLastReadAt()` | 既読更新 | conversation_members |

### 最適化ポイント

```
✅ N+1問題の解消:
   - 旧: 各会話ごとにDM相手名を個別クエリ
   - 新: getDmPartnerNames() で一括取得
```

---

## topbar.php 依存関係

### 必要な変数（chat.phpから渡される）

| 変数 | 型 | 用途 |
|-----|-----|------|
| `$display_name` | string | ユーザー表示名 |
| `$user` | array | ユーザー情報 |
| `$userOrganizations` | array | 所属組織リスト |
| `$currentLang` | string | 現在の言語 |
| `$topbar_back_url` | string (任意) | 指定時は左に「戻る」リンクを表示（例: memos.php では `chat.php`） |

### 利用ページ

- **chat.php**: 左にパネル切替ボタン（⇐）、右に右パネル切替（⇒）
- **memos.php**: `$topbar_back_url = 'chat.php'` で左に戻るリンク（←）、右パネルに金庫。`assets/js/topbar-standalone.js` を読み込み

- ロゴ・検索ボックス
- アプリメニュー（Guild等）
- 言語切替
- ユーザーメニュー

---

## sidebar.php 依存関係

### 必要な変数

| 変数 | 型 | 用途 |
|-----|-----|------|
| `$conversations` | array | 会話リスト（各要素に icon_path, icon_style, icon_pos_x, icon_pos_y, icon_size が必要） |
| `$selected_conversation_id` | int|null | 選択中の会話ID |
| `$totalConversations` | int | 会話総数 |
| `$currentLang` | string | 現在の言語 |

### conv-item の data 属性（アイコン変更モーダル用）

| 属性 | 用途 |
|------|------|
| `data-conv-icon-path` | 現在のアイコン画像パス。openIconChangeModal でプレビュー初期化に使用 |
| `data-conv-icon-style` | スタイルID（default / white / red 等） |
| `data-conv-icon-pos-x`, `data-conv-icon-pos-y` | 位置（%） |
| `data-conv-icon-size` | サイズ（50–150） |
| `data-is-pinned` | ピン留め状態（`'1'` / `'0'`）。`toggleConvPin()` で更新 |

### ピン留め機能

| 要素 | 役割 |
|------|------|
| `.conv-pin-btn` | 📌ボタン。PC: ホバーで表示、クリックでトグル |
| `.is-pinned` | ピン留め中の `.conv-item` に付与（CSS で常時📌表示） |
| `toggleConvPin(convId)` | API呼出 → `data-is-pinned` 更新 → `reorderConvList()` で並び替え |
| `reorderConvList()` | ピン留め済みを上部にソート |
| 長押し（モバイル） | `.conv-item` を600ms長押しでピン留めトグル |
| DB | `conversation_members.is_pinned` (`TINYINT(1)`) |
| API | `api/conversations.php` `action=pin` (`conversation_id`, `is_pinned`) |

### グループ追加・組織選択（左パネルヘッダー）

| 要素 | 役割 |
|------|------|
| 第1ボタン | 表示は `__('add_group')`（グループ追加）。クリックで `handleCreateGroupClick()` → 新規会話モーダル（グループタブ＋組織選択） |
| 第2ボタン | `__('add_friend')`（+ 友達追加） |
| 組織選択 | `#newConversationModal` のグループフォーム内で `#newConversationOrganizationId`。`createConversation()` で `organization_id` を `api/conversations.php` action=create に送信 |
| 翻訳 | `lang.php` の `add_group`（ja: グループ追加, en: Add Group, zh: 添加群组） |

**実装メモ**: 翻訳(lang.php)・サイドバー(sidebar.php)・モーダル組織選択(modals.php)・API送信(scripts.php createConversation)はすべて実装済み。確認時は「グループ追加」クリック→グループタブで組織を選び→作成で organization_id が送られることを確認。

---

## rightpanel.php 依存関係

### 必要な変数

| 変数 | 型 | 用途 |
|-----|-----|------|
| `$selected_conversation` | array|null | 選択中の会話 |
| `$currentLang` | string | 現在の言語 |

### 右パネル・概要・共有フォルダ・メディア

| 機能 | 役割 |
|------|------|
| `#overviewList` | 概要（Overview）複数エントリ。保存済みは `div.overview-body-readonly` でURLをリンク化表示（`.overview-link`）。クリックで編集、リンククリックで別タブ開く。改善提案 ID:5 でリンクのアクティブ表示を追加。 |
| **タスク** | 右パネルからは削除済み。タスクは上パネルの「タスク/メモ」リンク（tasks.php）で利用する。`loadConversationTasks()` は `#taskListPanel` が無い場合は何もしない。 |

---

## member-popup.php 依存関係

### 必要な変数

| 変数 | 型 | 用途 |
|-----|-----|------|
| `$selected_conversation` | array|null | 選択中の会話 |
| `$members` | array | メンバーリスト |
| `$user_id` | int | 現在のユーザーID |
| `$currentLang` | string | 現在の言語 |

---

## scripts.php 依存関係

**現在の行数**: ~5,000行（将来的に分割検討）

### セクション構成（将来の分割候補）

| 行範囲（概算） | セクション | 分割可能性 | 備考 |
|--------------|-----------|-----------|------|
| 1-130 | オンライン状態・設定 | △ | 初期化処理、依存多い |
| 130-390 | メッセージポーリング・翻訳 | ○ | 独立性高い |
| 390-1000 | **通話機能** | ✅済 | Jitsi遅延読込済 |
| 1000-1250 | ドラッグ＆ドロップ | ○ | UI依存あり |
| 1250-1610 | **メディアビューアー** | ○ | LocalStorage使用 |
| 1610-2100 | **メッセージ送信・編集** | △ | コア機能、依存多い |
| 2100-2440 | メモ・タスク・リアクション | ○ | API呼び出し中心 |
| 2440-2620 | TO選択機能 | ○ | メンバー読込依存 |
| 2620-2900 | **絵文字/GIFピッカー** | ○ | インラインスタイル |
| 2900-3140 | モーダル・グループ作成 | △ | 複数機能混在 |
| 3140-3830 | **グループ管理** | ○ | 管理者機能 |
| 3830-4100 | **友達追加・QRスキャン** | ✅済 | jsQR遅延読込済 |
| 4100-5000 | UI操作・検索・その他 | △ | 多数の小機能。検索詳細は `DOCS/SEARCH_ARCHITECTURE.md` / `DOCS/SEARCH_DESIGN_V2.md` |

### タスクメッセージのカード表示

- タスク依頼・タスク完了のシステムメッセージは `scripts.php` でパースされ、カード形式のHTMLに変換される
- フォーマット: `📋 **タスク依頼**` または `✅ **タスク完了**` で始まり、`**ラベル**: 値` 形式の行が続く
- 出力: `.task-card` 構造（ヘッダー・本文・フッター）

### 最適化済みの項目

```
✅ Jitsi API 遅延読み込み (通話機能)
   - loadJitsiApi() 関数を追加
   - 通話開始時にのみ外部スクリプトを読み込む

✅ jsQR 遅延読み込み (QRスキャン)
   - loadJsQR() 関数を追加
   - QRスキャン開始時にのみ外部スクリプトを読み込む

✅ 画像遅延読み込み
   - loading="lazy" 属性を追加

✅ ポーリング最適化（2026-01）
   - isPageVisible フラグでページ可視状態を管理
   - タブ非表示時はポーリングを停止
   - 再表示時に即座にデータ更新
   - 効果: バッテリー消費・サーバー負荷を軽減

✅ ポーリング安全化（2026-02）
   - JSON parse をtry-catchで囲み空レスポンス/不完全JSONに対応（SyntaxError防止）
   - 連続エラー時の指数バックオフ（5秒→10秒→20秒→…最大120秒）
   - 連続10回エラーでポーリング自動停止、ユーザー操作検出時に自動再開
   - 会話リスト更新・Push未読数取得も同様に安全化

✅ 動的ポーリング間隔（2026-01）
   - trackUserActivity() でユーザー操作を追跡
   - getPollingInterval() でアイドル時間に応じて間隔を調整
   - アクティブ: 3秒 → 30秒操作なし: 8秒 → 1分操作なし: 15秒 → 2分操作なし: 30秒
   - 効果: 非アクティブ時のサーバー負荷を最大90%削減

✅ AI確認トースト（記憶・リマインダー）
   - showMemoryConfirmation / showReminderConfirmation を body 直下のトースト表示に変更
   - messagesArea に追加しないことで、表示・削除時のレイアウト崩れを防止

✅ 秘書の名前変更（会話から自動適用）
   - ユーザーが「ナインという名前で」などと命名したとき、AIが [SECRETARY_NAME:名前] を出力
   - processSecretaryNameTag() で API 保存し、サイドバー・ヘッダーの表示名を即時更新
   - extractSecretaryNameFromUserMessage(): カレンダー・リマインダー文脈では抽出しない（「雑巾購入と予定を入れて」の「雑巾購入」はイベントタイトル）

✅ 秘書選択の永続化（リロード・ログアウト対策）
   - loadAISecretarySettings() / selectAISecretary() 内で、サーバーが未選択でも localStorage の aiCharacterSelected / aiCharacterType は削除しない（removeItem は呼ばない）
   - 強制リロード対策: chat.php が秘書モード時に `user_ai_settings` から character_type / secretary_name を取得し、`window.__AI_SECRETARY_PREFILL` として head 内に出力。scripts.php の初期化でこれを最優先で読み、localStorage にも書き戻す
   - サーバーが未選択のときは hadLocal で localStorage からメモリに復元し、選択画面に戻らないようにする
   - 履歴読み込み失敗時: showAIHistoryLoadError() で「履歴を読み込めませんでした。🔄で再読み込み」を表示。loadAIHistory() は成功/失敗を返し、reloadAIHistory でトースト表示を切り替え
   - 会話履歴クリア: clearAIHistory() の確認メッセージを「本当にすべての会話履歴を削除しますか？この操作は取り消せません。」に強化（誤削除防止）

✅ AI秘書パネル・常時起動表示の強固化（他会話から戻ったとき）
   - showAIMessages() は「messagesArea が無い」だけでなく「AI用パネルが無い」（#aiTranscribeBar / #aiAlwaysOnBtn が無い）場合も center-panel をAI用に再構築する。他会話表示時はグループ用の center-panel になるため、AI秘書に戻った際に必ずパネル差し替えが走る
   - **1回タップ/クリックで開く**: selectAISecretary() の先頭で、fetch 前に同期的に center-panel を「読み込み中...」表示に差し替え、携帯時はここで closeMobileLeftPanel() を実行。その後 await fetch → showAIMessages() で本表示。sidebar の AI 秘書アイテムは onclick で event.stopPropagation(); event.preventDefault(); を付与しタップが他で消費されないようにする
   - 常時起動の表示同期: `window.__aiAlwaysOn.syncPanelUI()` でバー・ボタンを「常時起動中」に合わせる。loadAIHistory() 完了後に即時・500ms・1000ms・1500ms で呼び出し、履歴描画後も表示が消えないようにする
   - 依存: 常時起動の状態は __aiAlwaysOn（と localStorage）のみが保持。表示は showAIMessages() で作る DOM（#aiTranscribeBar, #aiAlwaysOnBtn）に syncPanelUI で反映。**グループチャットの入力欄**（chat.php）にも常時起動ボタン・バーを配置し、`wireAlwaysOnUI()` で DOM 読込時および AI パネル表示時に接続するため、どの会話表示中でも ON/OFF 可能
   - 常時起動の音声拾い改善: 開始前に navigator.mediaDevices.getUserMedia({ audio: true }) でマイク許可を取得。onerror で not-allowed / audio-capture 時にトースト表示・停止。onend 再起動は 250ms 遅延し、start() 失敗時は SpeechRecognition インスタンスを再生成して再開。start() が Promise を返す場合に doStart/自動再開で .then して UI 同期
   - 常時起動「実行」時のLLM解釈: キーワード「実行」検出時に発話全文を api/ai.php action=execute_voice_command に送り、高度なLLMで意図を判定。返却アクション（send_to_group / add_memo / add_task / chat）に応じて実行。解釈できなければ従来の exec(instruction) にフォールバック。executeVoiceCommandViaLLM(fullTranscript, fallbackInstruction)
   - 送信文の編集フロー: send_to_group 時はLLMが「どんな文章をどのグループに送るか」を考え、content を挨拶・敬語・宛名を整えた送信用文として返す。フロントは即送信せず __pendingSendToGroup をセットし、編集済み文を入力欄に表示。ユーザーが内容を確認・編集して送信ボタンで送信。sendMessage() 内で __pendingSendToGroup があればその conversation_id に送り、未設定時は従来のAI秘書送信
   - To（宛先）機能: execute_voice_command で send_to_group 時に LLM が to_recipient_names（例: なおちゃん宛）を返す。API側で当該会話のメンバー（display_name）と照合し mention_ids に解決。フロントは sendToGroup(..., mentionIds) および __pendingSendToGroup.mention_ids で api/messages.php の mention_ids に渡し、To付きで送信

✅ AI秘書から他会話へのメッセージ送信（2026-02）
   - 指示例: 「事務局におはようございますというメッセージを送って」「〇〇に「本文」送信」等。音声（常時起動の exec）とテキスト送信（sendMessage）の両方で検出
   - **AI解釈優先**: sendMessage() で AI秘書モード時、まず interpretAndSendToGroup(content) が api/ai.php action=interpret_send_to_group を呼ぶ。バックエンドは参加会話をDBから取得し、Gemini で「送信意図・送信先・本文」を判定。detected かつ group_name/content があれば conversation_id も返却（DBの名前→id マップで解決）。フロントは conversation_id があればそれで api/messages.php に送信し、なければ getConversationIdByName で送信。未検出時は trySendToGroup(content)（正規表現）にフォールバック
   - parseSendToGroupInstruction(): 返信・「」付き送信・「YYを送信」・「YYという/っていうメッセージを送って」をパース。trySendToGroup() で __aiAlwaysOn から公開
   - 実際の送信は api/messages.php action=send（conversation_id は interpret_send_to_group の返却値、または getConversationIdByName でサイドバーの data-conv-id / data-conv-name から取得）
   - 依存: sidebar.php の data-conv-name（会話名）、api/ai.php interpret_send_to_group（Gemini）、api/messages.php send

✅ 改善提案記録（improvement_reports）- 聞き取り確認フロー（v2026.02.27.2）
   - **フロー**: ユーザーが改善希望・不具合を報告 → AI秘書が場所・現状・望ましい状態を聞き取り → 「こういう改善希望でよかったでしょうか？」と確認 → ユーザーが肯定 → AI秘書が `[IMPROVEMENT_CONFIRMED]` タグを出力 → フロントエンドが検出し、直近の会話コンテキスト（最大12件のメッセージ）を `api/ai.php action=extract_improvement_report` に送信 → Geminiが改善計画を含む構造化データを生成 → `improvement_reports` テーブルに保存。
   - **AI側指示**: `config/ai_config.php` の `AI_IMPROVEMENT_HEARING_INSTRUCTIONS` でAI秘書に聞き取り手順・確認フォーマット・タグ出力ルールを指示。
   - **フロントエンド**: `scripts.php` の `processImprovementConfirmed()` が `[IMPROVEMENT_CONFIRMED]` タグ検出時に「📋 提案を送信」ボタンを表示。ボタンクリックで `submitImprovementReport()` が会話コンテキスト（AI確認サマリー重点）を収集してAPIに送信。CSS: `.improvement-submit-card`, `.improvement-submit-btn`, `.improvement-submit-done`。
   - 管理者は admin/improvement_reports.php で一覧・「Cursor用にコピー」・改善完了通知。詳細は DOCS/IMPROVEMENT_REPORTS_FLOW.md。

✅ 本日のニューストピックス表示（2026-02）
   - addAIChatMessage(..., isTodayTopicsContent=true) で「本日のニューストピックス」を枠・色付きで表示
   - 題名は [題名](URL) 形式で配信され、JSで題名のみクリック可能リンクに変換（URLは非表示）
   - スタイル: .ai-today-topics-frame（枠・背景）、.ai-today-topic-item（大きめ文字・アクセント色）
   - 本文組み立て: includes/today_topics_helper.php の buildMorningTopicsBody()

✅ 楽観的UI更新（2026-01）
   - sendMessage() でメッセージ即座表示
   - 送信中は `.sending` クラスでローディング表示
   - 成功時に `.sent` クラスで✓マーク表示
   - 失敗時はメッセージを削除してテキストを復元
   - 効果: 体感レスポンス向上

✅ 画像+テキスト同時表示・ポーリング最適化（2026-02）
   - appendMessageToUI(): 画像/動画添付時に getTextBeforeFile() でテキストを抽出して両方表示
   - ポーリング間隔: アクティブ時1.5秒（旧3秒）、fetch cache: 'no-store'、初回500msで実行
   - visibilitychange/focus 時に即座に checkNewMessages を実行

✅ 返信の引用機能（reply_to_id）（2026-02 不具合修正）
   - **UI**: scripts.php の replyToMessage() / 送信時の一時カードで返信プレビュー表示。appendMessageToUI() でメッセージカードに reply_preview（reply_to_content / reply_to_sender_name）を表示。data-sender-name で自分メッセージへの返信時も送信者名を表示。
   - **送信**: messageData.reply_to_id を整数で送信。API は reply_to_id を正規化（0/空は null）して messages に保存。
   - **取得**: api/messages.php の get・send レスポンスで reply_to_id / reply_to_content / reply_to_sender_name を常に返す（数値型キャスト済み）。includes/chat/data.php の getSelectedConversationData() でも reply_to_id をクライアント用に (int) に統一。
   - **表示判定**: フロントは reply_to_id を parseInt して正の整数の場合のみ引用ブロックを描画（PDO が文字列で返す環境対策）。
   - 関連: api/messages.php（send/get/upload_file）、includes/chat/scripts.php、includes/chat/data.php、assets/css/chat-main.css（.reply-preview）

✅ 自分宛メッセージ通知（着信音・バイブ）（2026-02）
   - playMessageNotification(): 設定 notification_sound に応じて assets/sounds/{id}.mp3 を1回再生（window.__RINGTONE_PATHS）。パスが無い場合は Web Audio 合成音にフォールバック。Vibration API でバイブ。
   - checkAndPlayMessageNotification(isToMe): 着信音が鳴る条件に従って判定・再生
   - appendMessageToUI() 内で、他人メッセージ受信時に checkAndPlayMessageNotification(toMe) を呼び出し
   - **AI返信提案バー（自分宛メンション・3日以内）**: chat.php 初回描画で、自分宛メンションかつ自分以外の送信・システム以外のメッセージで、かつ created_at が3日以内の場合に `.ai-reply-suggest-bar`（🤖 AI返信提案を生成）をメッセージ直下に表示。**全グループチャットで利用可能**（労務・事務局・その他グループに共通）。ポーリングで追加される新着メッセージは常に3日以内のため scripts.php では日付チェックなしで挿入。**JSフォールバック**: `assets/js/ai-reply-suggest.js` の `injectBarsForInitialMessages()` が DOMContentLoaded と load 後 150ms で実行。自分宛判定は `.mentioned-me`、`data-to-users`（JSON）、`data-content` の `[To:ID]`、および**表示上のTOチップ**（カード内 `[data-to="ユーザーID"]` または `[data-to="all"]`）で行い、事務局など全グループで表示されるようにしている。右パネル「会話記憶」「自動返信」アイコンは `assets/icons/line/brain.svg`, `assets/icons/line/zap.svg` を参照。
   - 通話着信: getNotificationSettings().call_ringtone で assets/sounds/{id}.mp3 をループ再生（応答/拒否で停止）。パスが無い場合は playMessageNotification を間隔で呼ぶフォールバック。
   - 自分宛・To全員を含むメンション時はすべて .mention-frame で枠表示（改善提案「TO機能のメンション通知改善」に沿い、to_all も枠で囲む）。
   - 着信音の種類: config/ringtone_sounds.php の RINGTONE_SOUNDS_LIST + 旧プリセット（default/gentle 等）と silent。chat.php で window.__RINGTONE_PATHS を出力。api/settings.php?action=get で notification_sound / call_ringtone を取得。
   - 自分宛通知時、プッシュ許可がオフなら PushNotifications.showNotificationPermissionHop() を表示

✅ リアクション表示の簡素化（2026-02）
   - リアクションバッジは絵文字のみ表示（名前は非表示）
   - ホバー時の title 属性で「誰がリアクションしたか」を表示
   - 人数が多いグループでも見やすく

✅ タスクバーバッジ即時更新（2026-02）
   - メッセージ読み込み時（既読更新後）にPushNotifications.updateBadgeFromServer()を呼び出し
   - 会話リスト更新時にもタスクバーバッジを同期更新
   - api/notifications.php?action=count にキャッシュ防止（cache: 'no-store'、タイムスタンプ付与）
   - 効果: 既読後すぐにタスクバーのバッジ数字が減少する

✅ 会話を開いたときの未読・最新表示の優先（2026-03）
   - 未読区切り（#unreadDivider）があるときは URL ハッシュ（#message-xxx）によるスクロールを行わず、常に未読ラインを表示。事務局などで「過去の添付付きメッセージから始まる」ことを防止。
   - 未読あり時: 未読へ 150ms / 500ms / 1200ms（ハッシュ後上書き）/ 1500ms / 2500ms でスクロール。画像遅延読込後も未読位置を維持。
   - 未読なし時: 最下部へ即時・500ms・1500ms。ハッシュ指定時は 1000ms で該当メッセージへスクロール（上書きしない）。

✅ チャット内タスク依頼（2026-02）
   - 📋ボタンでタスクモーダルを表示（開いているグループのメンバーのみ選択可）
   - 作業者をチェックボックスで複数選択可能
   - 担当者欄UI改善（2026-02）: アバター・オンライン表示・役割バッジ、ホバー・選択状態の視覚的フィードバック
   - タスク管理画面（tasks.php）でもグループ選択→メンバー複数選択で依頼可能
   - タスク作成時・完了時にチャットにシステムメッセージを投稿
   - api/tasks.php: assignee_ids 配列で複数人に一括依頼（1人1タスク作成）

✅ AI秘書への画像貼り付け・添付・分析（2026-02）
   - Ctrl+V によるクリップボード画像貼り付け
   - ドラッグ＆ドロップでの画像添付
   - ⊕ボタンからの画像選択（画像のみ）
   - api/ai.php の ask アクションに image_path パラメータ対応

✅ Googleカレンダーへのスケジュール追加（秘書連携・2026-02 堅牢化）
   - AIが [CALENDAR_CREATE:カレンダー名:開始日時:終了日時:タイトル] を応答に含めると、scripts.php が検出して api/google-calendar.php action=create_event を呼び出し、Googleカレンダーにイベントを追加する。
   - processCalendarCreateTag(tagContent): タグ内容をパース（日時は YYYY-MM-DDTHH:MM または YYYY-MM-DD HH:MM、前後の空白を許容）。複数件は CALENDAR_CREATE を複数行で検出し、それぞれ processCalendarCreateTag で登録。
   - AI指示は config/ai_config.php の AI_CALENDAR_INSTRUCTIONS。api/ai.php で getCalendarAccountsForPrompt() により連携カレンダー一覧をプロンプトに埋め込み。連携が「まだ連携されていません」の場合はタグを出力せず設定案内するよう指示。

✅ ファイル添付時の表示名指定・変更（2026-02）
   - 貼り付け／⊕ボタンでファイル選択時、「ファイル名（任意・変更可）」入力欄を表示
   - 表示名は api/messages.php upload_file で display_name として受け取り、content に `絵文字 表示名\nパス` 形式で保存
   - chat.php / scripts.php の表示ロジックで表示名を反映（PDF・Office）
   - 送信時: api/upload.php でアップロード → api/ai.php で Gemini に画像付き質問
   - TO選択はAI秘書モードでは非表示
   - Gemini API連携: gemini_helper.php で inlineData 形式（camelCase）で画像を送信
   - パス解決: 相対パス/絶対パス/UPLOAD_DIR からの複数候補を realpath() で検証
   - 画像添付時はフォールバック（ai-ask-fallback.php）を使用せず、エラー表示
   - addAIChatMessage(): ユーザーメッセージに画像がある場合はプレビュー表示
```

### このファイルが依存しているもの

| カテゴリ | ファイル/リソース | 関連箇所 |
|---------|------------------|---------|
| CSS | `assets/css/chat-main.css` | `.message-card`, `.input-toolbar`, `.emoji-picker` 等 |
| CSS | `includes/design_loader.php` | テーマ変数（`--primary`, `--bg-main`等） |
| API | `api/messages.php` | メッセージ取得・送信・編集・削除 |
| API | `api/gif.php` | GIF検索 |
| API | `api/conversations.php` | 会話一覧・作成 |
| DB | `messages` テーブル | `id`, `content`, `sender_id`, `is_edited`, `edited_at` |
| DB | `users` テーブル | `id`, `name`, `avatar` |
| 変数 | `$currentUserId` | ログインユーザーID |
| 変数 | `$currentLang` | 現在の言語設定 |

**デザイン規格（標準デザイン）**: チャット吹き出しは `--dt-msg-*` と白/グレーのみ（紫は使用しない）。アバターはグレー統一（クラス `avatar-grey`）。詳細は `DOCS/STANDARD_DESIGN_SPEC.md`。

### このファイルが提供する主要関数

| 関数名 | 役割 | 依存CSS |
|-------|------|---------|
| `playMessageNotification()` | 着信音・バイブの再生 | - |
| `checkAndPlayMessageNotification(isToMe)` | 着信音が鳴る条件を判定して必要時のみ再生 | - |
| `renderMessages()` | メッセージ一覧を描画 | `.message-card`, `.message-reactions` |
| `sendMessage()` | メッセージ送信 | `.input-area` |
| `toggleEmojiPicker()` | 絵文字/GIFピッカー表示 | `#masterPickerPopup` (インラインスタイル) |
| `renderCurrentMembersList()` | メンバー管理モーダル | `.member-modal-redesign` |
| `editMessage()` | メッセージ編集開始 | `.edit-mode` |
| `updateMessage()` | メッセージ編集確定 | - |
| `openFriendRequestModal(userId, name)` | 友達申請モーダルを開く（検索結果から呼出） | `#friendRequestModal` |
| `closeFriendRequestModal()` | 友達申請モーダルを閉じる | - |
| `submitFriendRequest()` | 友達申請を送信（message/source付き） | - |
| `loadAddFriendContacts()` | 友達追加モーダル「連絡先」タブ: Contact Picker → check_contacts → 一覧表示 | api/friends.php check_contacts |
| `renderAddFriendContactsList()` | 連絡先タブのマッチ一覧を描画（友達申請/DM/招待ボタン） | .add-friend-contact-* |
| `addFriendFromContactInModal(userId)` | 連絡先タブから友達申請を送信 | - |
| `sendInviteFromAddFriendModal(contact, type, contactId)` | 連絡先タブから招待を送信 | - |

### 通話機能（Jitsi統合）

```
✅ 最適化済み（2026-01）:
- Jitsi API は通話開始時に遅延読み込み
- loadJitsiApi() 関数で動的にスクリプト追加
- 初期表示時の外部リソース読み込みを削減
```

**関連関数**:
| 関数名 | 役割 |
|-------|------|
| `loadJitsiApi()` | Jitsi API の遅延読み込み |
| `initJitsiMeet()` | Jitsi Meet の初期化 |
| `startCall()` | 通話開始 |
| `endCall()` | 通話終了 |

### テーマ/デザインとの干渉ポイント

```
⚠️ 以下の箇所はテーマによって見え方が変わる可能性あり:

1. メッセージカードの色
   - 影響: renderMessages() 内の contentHtml 生成部分
   - 確認: design_loader.php の $isTransparent 条件

2. 絵文字ピッカーの背景
   - 影響: toggleEmojiPicker() 内のインラインスタイル
   - 現状: 白背景固定（テーマ非依存）

3. リアクションバッジの色
   - 影響: .reaction-badge クラス
   - 確認: chat-main.css

4. クリップボード画像ペースト（全デザイン共通）
   - 影響: document.addEventListener('paste', ..., true)
   - 条件: フォーカスが messageInput / inputArea / pastePreview / center-panel 内
   - スタイル: chat-main.css .paste-preview にフォールバック値付き

5. 会話ドラフトの保存・復元
   - switchToConversation(newConvId): 会話切り替え時にドラフト保存→遷移
   - restoreChatDraft(convId): ページ読み込み時に localStorage から復元
   - clearChatDraft(convId): 送信完了時にドラフト削除
   - 保存先: localStorage key `social9_chat_draft_${convId}`、値は { text, toMembers }
```

---

## modals.php 依存関係

### このファイルが依存しているもの

| カテゴリ | ファイル/リソース | 関連箇所 |
|---------|------------------|---------|
| CSS | `assets/css/chat-main.css` | `.modal`, `.reaction-picker-v2` |
| JS | `scripts.php` | モーダル制御関数 |

### 提供するモーダル

| モーダルID | 役割 | 関連JS関数 |
|-----------|------|-----------|
| `#newConversationModal` | 新規会話（DM/グループ）。グループ時は組織選択 `#newConversationOrganizationId`（`$userOrganizations` 使用）。**DM/グループタブ**は `.conv-type-tabs` / `.conv-type-tabs__btn` で専用デザイン（chat-main.css） | `openCreateGroupModal()`, `switchConversationType()`, `createConversation()` |
| `#addFriendModal` | 友達追加・DM。タブ: メンバー／招待／QR／Mail／**連絡先**。連絡先タブは Contact Picker → `check_contacts` でマッチ一覧を表示し、友達申請・DM・招待ボタンを提供 | `switchAddFriendTab()`, `loadAddFriendContacts()`, `renderAddFriendContactsList()`, `addFriendFromContactInModal()`, `sendInviteFromAddFriendModal()` |
| `#imageModal` | 画像表示 | `openMediaViewer()` |
| `#reactionPicker` | リアクション選択 | `showReactionPicker()` |
| `#iconChangeModal` | アイコン変更（共通） | `openIconChangeModal()` |
| `#editFileDisplayNameModal` | ファイル表示名変更 | `openEditFileDisplayNameModal()`, `saveFileDisplayNameEdit()`, `closeEditFileDisplayNameModal()` |

※ `#editFileDisplayNameModal` は `#editMessageModal` の外に独立配置すること（親が display:none だと子も表示されないため）。

**端末連絡先で友達候補を表示（実装メモ）**: 計画に沿い (1) check_contacts は users.phone で電話マッチ、(2) import_contacts で contact_phone を正規化し users.phone と照合、(3) 友達追加モーダルに「連絡先」タブを追加。loadAddFriendContacts → Contact Picker → check_contacts → renderAddFriendContactsList。非対応時は設定へのリンク、エラー時は初期画面に戻す。CSS は chat-main.css の .add-friend-contacts-* / .add-friend-contact-*。**改善**: check_contacts で user_privacy_settings.exclude_from_search および blocked_users（こちらをブロックしているユーザー）を考慮して候補から除外。renderAddFriendContactsList で登録済みを user_id で重複排除。DM ボタンは onclick 内 JS 文字列用に `escapeJsString`（`\` と `'` のエスケープ）で表示名を安全に渡す。

**小分け記録**: c1 連絡先タブ用 loadAddFriendContacts 実装済み。c2 一覧描画・友達申請/DM/招待ボタン（renderAddFriendContactsList, addFriendFromContactInModal, sendInviteFromAddFriendModal）実装済み。c3 CSS・DEPENDENCIES 記録済み。loadAddFriendContacts の catch でエラー時に初期表示＋ボタン再表示するよう修正済み。**追加改善**: (1) check_contacts の success: false または !response.ok 時は初期表示に戻し、data.message / data.error または汎用メッセージを alert してリトライ可能に。(2) 招待ボタンの data-contact は HTML 属性用に & " < > をエスケープ（safeContactAttr）。

---

## アイコン変更機能（共通）

グループアイコンとユーザーアバターの変更を統一した共通機能です。

### モーダル

| ID | ファイル |
|----|---------|
| `#iconChangeModal` | `modals.php` |

### 主要関数（scripts.php）

| 関数名 | 役割 |
|-------|------|
| `openIconChangeModal(type, targetId, defaultIcon)` | モーダルを開く。グループ時は DOM の data-conv-icon-* から現在値を読み `iconChangeCurrentIconPath` に保持 |
| `openGroupIconModal()` | グループアイコン変更用ラッパー |
| `openUserAvatarModal()` | ユーザーアイコン変更用ラッパー |
| `saveIconChange()` | アイコンを保存（スタイルのみの場合は `iconChangeCurrentIconPath` を送信して既存画像を維持） |
| `closeIconChangeModal()` | モーダルを閉じる |
| `selectIconChangeSample(iconName)` | サンプルアイコンを選択 |
| `selectIconChangeStyle(styleId)` | アイコンスタイルを選択 |
| `previewIconChange(input)` | アップロード画像をプレビュー |

### 呼び出し元

| ファイル | 関数/要素 | 用途 |
|---------|----------|------|
| `topbar.php` | ユーザーアイコンクリック | ユーザーアイコン変更 |
| `rightpanel.php` | アイコン変更ボタン | グループアイコン変更 |

### API依存

| 用途 | API | アクション |
|-----|-----|-----------|
| グループアイコン | `api/conversations.php` | `update_icon` |
| ユーザーアバター | `api/settings.php` | `update_avatar` |

### サンプルアイコン

```
assets/icons/samples/*.svg（41ファイル）
```

### アイコンスタイル

11種類（default, white, black, blue, green, orange, pink, purple, red, yellow, gray）

---

## 添付ピッカー（携帯・3行程・LINE 同様）

**目標フロー（LINE 同様）**: 添付ボタン → 写真を選ぶ（ギャラリー／最近の写真が直接出る）→ 送信（3行程）。  
「カメラ or ファイル」の余分な選択を挟まないため、**携帯では画像専用 input のみを開く**。

### ファイル送信の統一規格

- **規格**: `DOCS/FILE_ATTACH_SPEC.md`（パソコン・携帯・AI秘書で同一フロー）
- **共通フロー**: ファイル選択 → `onAttachFileSelected(files)` → `showFilePreview(files[0])` → 「送信」→ `sendPastedImage()`（AI: api/upload.php+ai.php / グループ: api/messages.php upload_file）

### 共通定数・関数（scripts.php）

| 名前 | 役割 |
|------|------|
| `ATTACH_ACCEPT_IMAGE` | 画像のみの accept（携帯・AI秘書） |
| `ATTACH_ACCEPT_ALL` | 画像＋PDF・Office・動画等（PC グループ） |
| `onAttachFileSelected(files)` | ファイル選択時の共通ハンドラ（全入口から集約） |
| `openUnifiedAttachFilePicker(opts)` | 統一 input を開く。`opts.imageOnly: true` で画像のみ。 |
| `unifiedAttachInput` | body 直下の単一ファイル input（グループ・AI・携帯で共有） |

### 入口

| 入口 | 呼び出し |
|------|----------|
| 通常グループの ⊕ | `openUnifiedAttachFilePicker({ imageOnly: isMobile })` |
| AI秘書の ⊕ | `openUnifiedAttachFilePicker({ imageOnly: true })` |
| 携帯シート「最近使用したファイル」 | `recentFileInput.click()` → onchange で `onAttachFileSelected` |
| 携帯シート「カメラ・写真・動画」 | `openUnifiedAttachFilePicker({ imageOnly: true })` |

### 関連（scripts.php）

| 名前 | 役割 |
|------|------|
| `openAttachPicker()` | 幅≤768 なら `showAttachSheetMobile()`、それ以外は `openUnifiedAttachFilePicker`（メイン添付では未使用） |
| `showAttachSheetMobile()` | オーバーレイ＋ボトムシート。「最近のファイル」→ `recentFileInput.click()`、「カメラ・写真」→ `openUnifiedAttachFilePicker({ imageOnly: true })` |
| `ensureRecentFileInput()` | ドキュメント用隠し input。onchange で `onAttachFileSelected` に合流。 |
| `handleFileSelect(input)` | 既存 input 用ラッパー。`onAttachFileSelected(input.files)` を呼ぶ。 |

### 注意

- **画像送信時**: `compressImageForUpload` でリサイズ・圧縮（**上限5MB**、LINE準拠）。`window.compressImageForUpload` として公開され、共有フォルダ（storage.js）の画像アップロードでも利用可能。変更時は `DOCS/FILE_ATTACH_SPEC.md` を参照。

---

## TO（宛先指定）機能

**Phase B 実施済み（一時削除）**: 入力欄のToボタン・To行バーは非表示、mention_ids は送信しない。既存メッセージのToチップ表示（本文の [To:ID] 変換・to_info）は残存。再実装は DOCS/TO_FEATURE_SPEC_AND_REBUILD_PLAN.md の Phase C 参照。

メッセージ送信時に特定のメンバーまたは全員を宛先に指定する機能です。

### グローバル変数（scripts.php先頭で定義）

| 変数 | 型 | 用途 |
|-----|-----|------|
| `window._toSelectorMembers` | array | 会話メンバー一覧 |
| `window._toSelectedMembers` | array | 選択中のメンバーID |
| `window._currentUserId` | int | 現在のユーザーID |

### グローバル関数（scripts.php先頭で定義）

| 関数名 | 役割 |
|-------|------|
| `openToSelector()` | Toポップアップを開く（async、メンバー取得後に表示） |
| `closeToSelectorPopup()` | ポップアップを閉じる（Escapeでも解除） |
| `removeToMember(uid)` | 宛先から1人除外し To バーを更新 |
| `updateToRowBar()` | 宛先チップ表示バー（toRowBar）の表示/非表示・内容を更新 |
| `window.chatSelectedToIds` | 選択中宛先IDの配列（`'all'` または ユーザーID。送信時に mention_ids に渡す） |

### HTML要素（chat.php）

| ID | 役割 |
|----|------|
| `toRowBar` | To宛先表示バー（チップ＋削除ボタン） |
| `toRowChips` | 宛先チップを並べる領域 |
| `toBtn` | Toボタン（クリックで openToSelector） |
| `toSelectorPopup` | 宛先選択ポップアップ（scripts で動的生成） |
| `toSelectorList` | メンバー候補リスト |
| `toSelectorSelected` | 選択中チップ表示領域 |

### 依存関係

```
chat.php
├── toRowBar / toRowChips（返信バー直後）
├── toBtn（ツールバー内）
└── scripts.php
    ├── updateToRowBar, removeToMember, closeToSelectorPopup, openToSelector
    ├── openToSelector → メンバークリックで本文に [To:ID]表示名 を挿入（Chatwork風）、送信時に parseToIdsFromContent で mention_ids に含める
    ├── リスト先頭に「ALL」項目（'all' → 全員宛メンション、api側で to_all 処理）
    ├── sendMessage で messageData.mention_ids = [To:ID]解析 + To選択 + 「To 名前」行 をマージ
    └── 表示: contentWithToChips / getContentDisplayHtml で [To:ID]名前 → .msg-to-chip（TOバッジ＋アバター＋名前）に変換
```

### CSS（chat-main.css）

| クラス | 役割 |
|-------|------|
| `.to-row-bar`, `.to-row-chips`, `.to-chip`, `.to-chip-remove` | To宛先バー・チップ |
| `.msg-to-chip`, `.msg-to-badge`, `.msg-to-name`, `.msg-to-chip-avatar` | 文中Toチップ（Chatwork風: TOバッジ＋アバター＋名前） |
| `.to-selector-popup`, `.to-selector-open` | ポップアップ（閉時は visibility: hidden） |
| `.to-selector-item`, `.to-selector-item.selected` | メンバー候補・選択状態 |
| `.to-selector-item-all` | 「ALL」全員宛て項目（リスト先頭・太字） |
| `.to-selector-chip`, `.to-selector-selected` | ポップアップ内の選択チップ |
| `.mention-frame`, `.mentioned-me` | メンションされたメッセージの目立つ表示 |
| `.to-subject-input` | 題名入力欄（PC: ツールバー内 / 携帯: mobile-to-row 内は .to-subject-input-mobile） |

---

## 変更時のチェックリスト

### chat.php の構造を変更する場合
- [ ] 各テンプレートファイルに渡す変数を確認
- [ ] `data.php` の関数シグネチャを確認
- [ ] include順序を確認（scripts.php は最後）

### メッセージ表示を変更する場合
- [ ] `scripts.php` の `renderMessages()` を確認
- [ ] `chat-main.css` の `.message-card` を確認
- [ ] `design_loader.php` のテーマ変数を確認
- [ ] `api/messages.php` のレスポンス形式を確認

### 絵文字/GIFピッカーを変更する場合
- [ ] `scripts.php` の `toggleEmojiPicker()` を確認
- [ ] `scripts.php` の `emojiData` オブジェクトを確認
- [ ] `api/gif.php` のレスポンス形式を確認

### メンバー管理モーダルを変更する場合
- [ ] `scripts.php` の `renderCurrentMembersList()` を確認
- [ ] `chat-main.css` の `.member-modal-redesign` を確認
- [ ] `api/conversations.php` の `members` アクションを確認
- [ ] 権限チェック: `$isAdmin` 変数

### リアクション機能を変更する場合
- [ ] `modals.php` の `#reactionPicker` を確認
- [ ] `scripts.php` の `toggleReaction()` を確認
- [ ] `api/messages.php` の `react` アクションを確認
- [ ] `chat-main.css` の `.reaction-picker-v2`, `.reaction-badge` を確認

### 上パネルを変更する場合
- [ ] `topbar.php` を編集
- [ ] `chat-main.css` の `.top-panel` 関連を確認
- [ ] 言語切替は `api/language.php` に依存

### 左パネル（会話リスト）を変更する場合
- [ ] `sidebar.php` を編集
- [ ] `chat-main.css` の `.left-panel`, `.conv-item`, `.left-panel-filter*` を確認
- [ ] `data.php` の `getChatPageData()` を確認
- [ ] **フィルター（単一選択）**: `#leftPanelFilter` でトリガークリックでドロップダウン表示。選択肢は**どれか1つのみ**: すべて／未読／グループ／友達／組織名…（`data-filter="all"|"unread"|"group"|"dm"|"org-5"`）。「すべての組織」はなし（すべてと重複）。`window.currentLeftPanelFilter` のみで状態管理。`applyLeftPanelFilter(filter)`: 組織選択時は**その組織のグループのみ**表示（DM・AIは表示しない）。ポーリングで `data-unread` 更新後に `applyLeftPanelFilter(currentLeftPanelFilter)` を再実行。

**左パネルフィルター統合（小分け記録）**: 単一選択に変更。選択肢: すべて／未読／グループ／友達／組織名…（data-filter で統一）。「すべての組織」は削除。組織選択時はその組織のグループのみ表示（DM・友達は表示しない）。state は currentLeftPanelFilter のみ。applyLeftPanelFilter(filter)、ポーリングは applyLeftPanelFilter(currentLeftPanelFilter) を参照。**携帯版**: モバイルCSSで .conv-item { display: flex !important } があるため、非表示はクラスで強制。フィルタで非表示の会話に conv-item-filtered-out を付与し、#conversationList .conv-item.conv-item-filtered-out { display: none !important; } で確実に隠す。init 時に applyLeftPanelFilter を1回実行して初期状態を反映。

### 右パネルを変更する場合
- [ ] `rightpanel.php` を編集
- [ ] `chat-main.css` の `.right-panel` 関連を確認

### 通話機能を変更する場合
- [ ] `call-ui.php` を編集
- [ ] Jitsi API の読み込みを確認
- [ ] `scripts.php` の通話関連関数を確認
