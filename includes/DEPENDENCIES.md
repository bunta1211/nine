# includes/ 共通機能 依存関係

このディレクトリには、アプリケーション全体で使用される共通PHPファイルが含まれています。

## ファイル一覧と役割

| ファイル | 役割 | 影響範囲 |
|---------|------|---------|
| `asset_helper.php` | アセットバージョン取得（filemtimeの安全版） | chat.php, design.php |
| `design_loader.php` | テーマ/デザインCSS生成（標準デザイン固定・透明テーマ・背景画像・ダークモードは廃止）。上パネルは立体ニューモーフィズム用に統一デザインブロック（L1909付近）でベゼル・凹み・影を `!important` 適用。 | **全画面** |
| `design_config.php` | デザイン設定値（getThemeConfigs は lavender のみ。headerGradient は立体用グラデーション。DESIGN_ASSET_VERSION でキャッシュ破棄）。getBackgroundDesignOverrides は廃止。 | design_loader.php, design.php |
| `auth.php` | 認証チェック | **全画面** |
| `db.php` | データベース接続 | **全API/画面** |
| `lang.php` | 多言語対応 | **全画面** |
| `api-bootstrap.php` | API共通初期化。php://input を1回読んでキャッシュ（multipart 時は読まない・携帯の$_FILES空問題を防ぐ） | **全API** |
| `api-helpers.php` | APIヘルパー関数 | **全API** |
| `permissions.php` | 権限チェック | グループ管理 |
| `roles.php` | ロール定義。組織メンバー系は `organization_members.left_at` の有無でクエリを分岐（本番スキーマ互換） | 権限システム, admin/api/members.php |
| `friend_request_mail.php` | 友達申請通知メール送信（sendFriendRequestNotification）。相手メールに「承諾する」リンク付き案内 | api/friends.php |
| `logger.php` | ログ出力 | デバッグ |
| `gemini_helper.php` | Gemini AI API連携（テキスト・画像・PDF対応）。`geminiChat`は`$imagePath`で画像またはPDFを`inlineData`形式で送信。PDFは application/pdf で送りスキャンPDFも解釈可能。パス解決は相対/絶対/UPLOAD_DIR/DOCUMENT_ROOT を試行。**APIキー未設定・無効時**: `getGeminiUnavailableMessage()` でユーザー向け共通メッセージを返却（api/ai.php で利用）。 | AI相談室, 翻訳, 画像分析, 自動返信提案 |
| `task_memo_search_helper.php` | タスク・メモ・メッセージ検索（tableHasColumn, extractTaskMemoSearchParams, searchTasksAndMemos, formatTaskMemoSearchResultsForAI, extractTopicKeyword, searchMessagesForContext）。メモ検索は tasks テーブル（type='memo'）を参照（type カラムが無い場合は memos テーブルにフォールバック）。PDFのextracted_textも検索対象 | api/ai.php（秘書のまとめ報告＋コンテキスト検索） |
| `google_calendar_helper.php` | Googleカレンダー連携（OAuth, イベント作成・更新・削除）。`getCalendarAccountByTarget`は名前変更に強く、完全一致→コア一致→部分一致→単一/デフォルトの順で照合 | api/google-calendar.php, api/ai.php |
| `pdf_helper.php` | テキストをPDFに変換（textToPdf）＋PDFからテキスト抽出（extractPdfText, **extractPdfTextFromPath**：絶対パス用・AI秘書から利用, extractPdfTextFallback）。TCPDF使用。smalot/pdfparser対応 | api/messages.php, admin/extract_pdf_text.php, **includes/ai_file_reader.php** |
| `emoji_usage_helper.php` | 絵文字学習（extractEmojisFromText, recordEmojiUsage, getTopEmojis）。user_emoji_usage テーブル利用 | api/messages.php（送信時記録）, api/ai.php（ask で参照） |
| `VaultCrypto.php` | AES-256-GCM 暗号化/復号（金庫アイテム用）。VAULT_MASTER_KEY + user_id でキー導出 | api/vault.php |
| `deliberation_helper.php` | 熟慮モードヘルパー。Gemini Google Search grounding を使用した検索→推論→実行。ログ記録・読取り（deliberationLog/deliberationReadLog）、結果保存（deliberationComplete/deliberationReadResult）、geminiWithSearch、runDeliberation | api/ai.php |
| `improvement_context_helper.php` | 改善提案記録用プロジェクトコンテキスト（A: ARCHITECTURE+DEPENDENCIES, B: DOCS/IMPROVEMENT_CONTEXT.md, C: DOCS/*.md 走査）を組み立て。getImprovementContextForGemini()。api/ai.php の extract_improvement_report で利用 | api/ai.php |
| `ai_proactive_helper.php` | AI秘書 毎日自動話しかけヘルパー。collectUserContext（直近メッセージ・タスク・メモ・記憶を収集）、shouldIncludeImprovementHint（3日に1回の改善希望案内判定）、generateProactiveMessage（Gemini で挨拶文生成、改善案内付き対応）、saveProactiveMessage（ai_conversations に is_proactive=1 で記録） | cron/ai_proactive_daily.php |
| `today_topics_helper.php` | 今日の話題ヘルパー。朝: **動画形式**（getTodayTopicsVideosCacheOrFetch + buildMorningTopicsVideoBody）を優先。RSS はフォールバック（fetchTodayTopicsFromRss, buildMorningTopicsBody）。夜: （略） | cron/ai_proactive_daily.php, cron/send_today_topics_to_user.php, cron/ai_today_topics_evening.php |
| `today_topics_youtube_helper.php` | 朝のニュース動画用 YouTube Data API v3 取得。プレイリスト・チャンネル（forHandle）から最新動画を取得、キャッシュ（today_topics_videos_YYYYMMDD.json）、getTodayTopicsVideosCacheOrFetch | cron/send_today_topics_to_user.php, cron/ai_proactive_daily.php, includes/today_topics_helper.php（buildMorningTopicsVideoBody） |
| `storage_s3_helper.php` | 共有フォルダS3ヘルパー（S3操作、署名付きURL、容量管理、**組織別無制限** STORAGE_UNLIMITED_ORGANIZATION_IDS で quota_bytes 無制限・unlimited フラグ返却、権限チェック） | api/storage.php, cron/storage_usage_check.php |
| `zengin_helper.php` | 全銀データ生成ヘルパー（JIS全銀協フォーマット準拠、口座振替データ出力） | admin/storage_billing.php |
| `ai_billing_rates.php` | AI利用料金表・その他サービス料金表の算出と表示（getAiBillingRates, getOtherServiceRates, renderAiBillingTable, renderOtherServiceTable）。config/ai_config.php の単価定数を使用 | admin/storage_billing.php, admin/ai_usage.php |
| `ai_specialist_router.php` | 専門AI振り分けルーター（classifyIntent, callSpecialist, searchOrgMemories, provisionSpecialistsForOrg）。キーワード+LLM分類で7種の専門AIに振り分け、組織ナレッジを参照して応答 | api/ai.php, api/ai-memories.php, admin/ai_specialist_admin.php |
| `ai_user_profiler.php` | ユーザー性格・行動分析プロファイラー（getUserAiProfile, updateProfileFromConversation, deepAnalyzePersonality, buildProfilePromptAddition, getPersonalitySnapshot）。会話から自動学習し秘書の応答スタイルを調整 | api/ai.php, cron/ai_profile_analyze.php |
| `ai_safety_reporter.php` | AI安全通報機能（checkAndReport, createSafetyReport, askSecretaryQuestion, answerSecretaryQuestion, getSafetyReports）。社会通念違反・生命の危機・いじめ等を自動検知し運営に通報 | api/ai.php, api/ai-safety.php, admin/ai_safety_reports.php |
| `ai_file_reader.php` | AI秘書用ファイル読み取り（extractFileText, isAiFileAllowed, isImageFile）。テキスト・CSV・JSON・PDF・DOCX・XLSX・PPTXからテキスト抽出。PDFは readPdfFile でストリーム＋括弧/hex フォールバック後に filterPdfExtractedText でノイズ除去。**抽出が空のときは pdf_helper の extractPdfTextFromPath（smalot/pdfparser）で再試行**。パス解決は絶対パス（DOCUMENT_ROOT 結合フォールバック）・相対パス（プロジェクトルート・DOCUMENT_ROOT・UPLOAD_DIR 親・getcwd）を試行。実行ファイルブロック | api/ai.php |
| `ai_memory_batch.php` | グループチャット自動検証・分類・記憶バッチ（processOrgChatMemories, extractAndClassifyChunk, runAllOrgMemoryBatch）。LLM/ルールベースで情報抽出し専門AI記憶ストアに蓄積 | cron/ai_memory_batch_run.php |

---

## デザインシステム（最重要）

**規格の正式定義**: `DOCS/STANDARD_DESIGN_SPEC.md`（標準デザインの色・トークン・参照先。枠線・ホバー・チャット入力欄のレイアウト・ドロップダウン・モーダルの色固定も同ドキュメントの該当セクションを参照）

### design_loader.php

**役割**: ユーザーのデザイン設定に基づいてCSSを動的生成

**影響範囲**: chat.php, その他の全ページ

### 依存関係マップ

```
┌────────────────────────────────────────────────────────────┐
│ design_loader.php                                          │
├────────────────────────────────────────────────────────────┤
│                                                            │
│  入力:                                                     │
│  ├── DB: users.background_image (背景画像パス)            │
│  ├── DB: users.background_color (背景色)                  │
│  └── design_config.php (プリセット設定)                    │
│                                                            │
│  出力 (CSS変数):                                           │
│  ├── --primary          : メインカラー                    │
│  ├── --bg-main          : 背景色                          │
│  ├── --theme-header-text : 上パネル文字色（チェリー規格：フォレスト含め明るい背景＋#1a1a1a） │
│  ├── --dt-header-text   : 上パネル・ボタン文字色（トークン時）          │
│  ├── --dt-btn-secondary-bg : 上パネルメニューボタン背景   │
│  ├── --text-primary     : テキスト色                      │
│  └── etc.                                                  │
│  上パネル .top-btn はトークンで視認性確保（チェリー等）    │
│                                                            │
│  チャットレイアウト: chat.php の body に class="page-chat" を付与。     │
│  panel-panels-unified.css で body.page-chat { padding-top:64px } と     │
│  body.page-chat .main-container { margin-top:0; height:calc(100vh-64px) } │
│  → 上パネル重なり防止・最下部余白なし（padding で確実に適用）           │
│                                                            │
│  重要な分岐:                                               │
│  └── $isTransparent (背景画像がある場合 true)             │
│      ├── true  → 半透明スタイル適用                       │
│      └── false → 通常スタイル適用                         │
│  ダークモード時: 背景画像/透明テーマのときは右・左・中央パネルを半透明にし背景が見える │
│  中央ヘッダー: 可読性のため text-shadow を付与（透明デザイン統一規格）              │
│                                                            │
│  上書き: settings.php は body.page-settings で透明を無効化 │
│         （設定ページのみ不透明パネル・ラベルで読みやすく）  │
│  設定ページ上パネル: チャットと同じ topbar.php + header.css + │
│         panel-panels-unified.css + topbar-standalone.js を使用。│
│                                                            │
└────────────────────────────────────────────────────────────┘
```

### テーマ固有のスタイル変更方法

**問題**: 特定のテーマ（例: 時計クローバー）だけ見た目を変えたい

**解決策**: design_loader.php 内で条件分岐を追加

```php
// 例: 時計クローバーテーマで選択中の会話の文字色を黒にする
if ($isTransparent) {
    // 背景画像ありのテーマ共通
    echo ".conversation-item.active { background: rgba(255,255,255,0.3); }";
    
    // さらに特定の背景画像の場合
    if (strpos($backgroundImage, 'tokei_clover') !== false) {
        echo ".conversation-item.active { color: #333 !important; }";
    }
}
```

### 変更時のチェックリスト

- [ ] `$isTransparent` の条件を確認
- [ ] 生成されるCSS変数を確認
- [ ] `assets/css/chat-main.css` との優先度競合を確認
- [ ] ブラウザのDevToolsでCSS適用順を確認

### 背景画像使用時の特別処理（2026-01-28追加）

背景画像がある場合（`$hasBgImage === true`）、テーマに関係なく以下のスタイルが強制適用されます：

1. **ドロップダウンメニュー**: 白背景・黒文字を維持
2. **暗い背景画像の場合**: 右パネルを暗い背景・白文字に設定

```php
// 暗い背景画像リスト
$darkBgImages = ['sample_fuji.jpg', 'sample_night.jpg', 'sample_galaxy.jpg'];
```

---

## 認証システム

### auth.php

**依存関係**:
- `config/session.php` - セッション設定
- `db.php` - ユーザー情報取得
- DB: `users` テーブル

**提供変数**:
- `$currentUser` - ログインユーザー情報
- `$currentUserId` - ログインユーザーID
- `$isLoggedIn` - ログイン状態

---

## 多言語システム

### lang.php

**依存関係**:
- セッション: `$_SESSION['lang']`
- クッキー: `lang`

**提供関数**:
- `__($key)` - 翻訳テキスト取得
- `setLanguage($lang)` - 言語設定

**対応言語**:
- `ja` - 日本語
- `en` - English
- `zh` - 中文

---

## API共通

### api-bootstrap.php

**役割**: 全APIの共通初期化処理

**依存関係**:
- `config/app.php`
- `config/session.php`
- `config/database.php`
- `api-helpers.php`
- `roles.php`

**提供関数**:
- `requireLogin()` - ログイン必須チェック
- `getDB()` - PDO取得
- `getJsonInput()` - JSON入力パース
- `jsonResponse($data)` - JSONレスポンス
- `errorResponse($message)` - エラーレスポンス

---

## 変更時の影響度マトリックス

| 変更対象 | 影響範囲 | リスク |
|---------|---------|-------|
| design_loader.php | 全画面の見た目 | 🔴 高 |
| auth.php | 全画面のアクセス | 🔴 高 |
| db.php | 全機能 | 🔴 高 |
| lang.php | 全テキスト | 🟡 中 |
| api-bootstrap.php | 全API | 🔴 高 |
| permissions.php | グループ権限 | 🟡 中 |
| logger.php | ログ出力のみ | 🟢 低 |
