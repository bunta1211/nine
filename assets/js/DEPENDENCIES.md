# JavaScript 依存関係

このディレクトリには、Social9のフロントエンドJavaScriptが含まれています。

## ファイル一覧

| ファイル | 役割 | 読込み元 |
|---------|------|---------|
| `chat.js` | チャット画面メインJS | chat.php |
| `chat-mobile.js` | モバイル対応。**v1.7.0**: パネル閉じるボタン（`mobile-panel-close-btn`）を左右パネル上部に動的追加。z-indexを100に統一。スタートメニュー廃止に対応。パネル操作: `toggleMobileLeftPanel` / `closeMobileAllPanels` / `toggleMobileRightPanelFn` をグローバル公開。 | chat.php |
| `chat-call.js` | 通話機能（Jitsi統合） | chat.php（オプション） |
| `common.js` | 共通ユーティリティ | 複数ページ |
| `design-settings.js` | デザイン設定画面 | design.php |
| `admin-members.js` | 管理：メンバー管理 | admin/members.php |
| `admin-groups.js` | 管理：グループ管理 | admin/groups.php |
| `admin-restrictions.js` | 管理：制限設定 | admin/members.php |
| `memo-vault.js` | 金庫機能（WebAuthn 開錠・初回登録・一覧・追加・削除） | memos.php |
| `ai-personality.js` | AI秘書 性格設定パネル（7項目入力・熟慮時間・自動話しかけ設定・インポート/エクスポート）。window.showAISettings を上書き | chat.php |
| `ai-deliberation.js` | 熟慮モード フロント（進行表示ボックス・ポーリング・タイムアウト管理）。sendAIMessage 内で deliberation_mode 判定時に呼び出し | chat.php |
| `admin-sidebar-sort.js` | 管理画面サイドバーのドラッグ＆ドロップ並び替え（localStorage保存） | admin/*.php |
| `admin-ai-memories.js` | AI記憶管理ページJS（検索・ページネーション・追加・編集・削除・履歴表示） | admin/ai_memories.php |
| `admin-ai-specialists.js` | 専門AI管理ページJS（専門AIカード読込・編集・プロビジョニング・機能フラグ更新） | admin/ai_specialist_admin.php |
| `admin-ai-safety.js` | AI安全通報管理ページJS（統計・通報リスト・詳細表示・ステータス変更・秘書への質問） | admin/ai_safety_reports.php |
| `secretary-rightpanel.js` | AI秘書（AIクローン）専用右パネルロジック（SecRP）。判断材料フォルダ/アイテムCRUD、会話記憶表示、訓練言語保存、自動返信トグル。api/ai-judgment.php と api/ai.php を使用 | chat.php |
| `ai-reply-suggest.js` | AIクローン返信提案ロジック（AIReplySuggest）。メンション付きメッセージへの返信提案生成→編集→送信→修正記録。**To機能**: 提案カードに「To: 全員 / メンバー名」ボタンを表示し、クリックで本文に `[To:all]全員` または `[To:id]名前さん` を挿入（api/messages.php の Phase C でパース）。api/ai.php suggest_reply（members 返却） / record_reply_correction と api/messages.php send を使用。モバイル(768px以下): カードを body 直下のオーバーレイに表示し飛ばされ防止、body に ai-reply-suggest-open を付与して入力欄非表示、textarea は高さを伸ばさず CSS でスクロール | chat.php |
| `storage.js` | 共有フォルダ（エクスプローラー風一覧、フォルダナビ、**複数一括アップロード**・**アルバムで追加 最大50枚**・日時題名フォルダ自動作成、S3アップロード、**表示切替** テキスト/画像グリッド localStorage `storageViewMode`、プレビュー、D&D、検索、ゴミ箱） | chat.php |
| `error-collector.js` | エラー自動収集（onerror / unhandledrejection / fetch インターセプト / console.error）。api/error-log.php に送信。オプションAPI（未読数・翻訳予算・**error-log.php** 等）の fetch 失敗は送信しない（optionalFetchUrlPatterns）。未読数・翻訳予算のコンソールメッセージは ignorePatterns で送信抑制 | チャット等（共通） |
| `push-notifications.js` | Web Push バッジ更新。未読数取得失敗時は console.warn（エラー収集に送らない） | chat.php 等 |
| `topbar-standalone.js` | 非チャットページの上パネル制御（ドロップダウン・ハンバーガー）。PC版（>768px）ではタスク/通知アイコンクリックで直接ページ遷移（tasks.php / notifications.php） | settings.php, design.php, tasks.php, notifications.php |

---

## 重要な注意点

### インラインJS vs 外部JS

Social9では2種類のJavaScriptが使用されています：

```
1. インラインJS（PHP内に埋め込み）
   └── includes/chat/scripts.php
       - サーバー変数を直接利用可能
       - テーマ設定に応じた動的生成
       - 主要なチャット機能

2. 外部JS（このディレクトリ）
   └── assets/js/*.js
       - 静的なJavaScript
       - 再利用可能なユーティリティ
       - 管理画面用機能
```

---

## ファイル別依存関係

### chat.js

**役割**: チャット画面の補助機能

**注意**: 主要なチャット機能は `includes/chat/scripts.php` にあります

**依存関係**:
| カテゴリ | 依存先 |
|---------|-------|
| HTML | chat.php の DOM構造 |
| CSS | assets/css/chat-*.css |
| API | api/messages.php, api/conversations.php |

**To機能 Phase B 実施済み**: チャットのTo入力UIは無効化（to-selector.js は読込コメントアウト）。DOCS/TO_FEATURE_SPEC_AND_REBUILD_PLAN.md 参照。

### chat-mobile.js

**役割**: モバイルデバイス用の UI 調整。Phase 3.1 の `initMobilePagesStripScroll()` で、携帯の3ページストリップは **常にグループ一覧（左パネル）を最初に表示**（メッセージ数を先に見せるため統一）。`scrollLeft=0` は条件なしで設定（ストリップ有無にかかわらず）。複数回の遅延実行（0/100/300/800/1500ms および load 後）と `pageshow`（bfcache 復元時）・`visibilitychange`（タブ表示復帰時）で確実に左パネルを表示。chat.php のインラインスクリプトも同様に scrollLeft=0 を複数タイミング（load 後 2000ms まで）で設定。縁の光のすき間（エッジグロー）関連の処理は削除済み。

**依存関係**:
| カテゴリ | 依存先 |
|---------|-------|
| HTML | chat.php |
| CSS | レスポンシブCSS |
| API | api/conversations.php（list_with_unread：FAB「共有フォルダ」でグループ一覧取得） |

**主要機能**:
- タッチイベント処理
- サイドバー開閉
- 仮想キーボード対応
- LINE風メッセージメニュー（ワンタップで編集等）：表示時にタップしたメッセージがボトムシート上に見えるようスクロール、編集タップでチャット入力欄を開く
- FABメニュー：ラベル「チャット」「共有フォルダ」「タスク/メモ」等。共有フォルダタップでグループ選択モーダル→選択時に chat.php?c=ID#storage へ遷移。メニューは画面中央に表示。
- 戻るボタン（.mobile-chat-back-btn）：会話選択時のみ表示。タップで toggleLeftPanel() を呼び、左パネル（会話リスト）を固定オーバーレイで表示。
- **v1.6.0**: toggleLeftPanel/toggleRightPanel/closeAllPanels からストリップスクロール分岐を全削除。すべて fixed overlay（mobile-open クラス）方式に統一。setupTopPanelTapToReveal は廃止。
- FABメニューに「強制リロード」を追加（携帯アプリでリロードできない場合用）
- 入力欄スクロール非表示時（`setupScrollHideInput`）に `messages-area` の `padding-bottom` を動的に調整し、メッセージが最下部まで表示されるようにする

### chat-call.js（NEW - 2026-01）

**役割**: 通話機能（Jitsi Meet 統合）

**特徴**:
- Jitsi API を遅延読み込み（初期表示高速化）
- 通話開始時にのみ外部スクリプトを読み込む

**依存関係**:
| カテゴリ | 依存先 |
|---------|-------|
| 外部API | Jitsi Meet External API（動的読込） |
| HTML | `includes/chat/call-ui.php` |
| data属性 | `body[data-display-name]` |

**主要関数**:
| 関数名 | 用途 |
|-------|------|
| `loadJitsiApi()` | Jitsi API の遅延読み込み |
| `startCall(type)` | 通話開始（audio/video） |
| `endCall()` | 通話終了 |
| `toggleMic()` | マイクON/OFF |
| `toggleVideo()` | カメラON/OFF |

**注意**: 現在、通話機能は `includes/chat/scripts.php` にも含まれています。
将来的に完全分離を検討中。

### common.js

**役割**: 複数ページで使用する共通関数

**提供する関数**:
| 関数名 | 用途 |
|-------|------|
| `escapeHtml()` | HTMLエスケープ |
| `formatDate()` | 日付フォーマット |
| `debounce()` | デバウンス処理 |
| `throttle()` | スロットル処理 |

### design-settings.js

**役割**: デザイン設定画面のインタラクション

**依存関係**:
| カテゴリ | 依存先 |
|---------|-------|
| HTML | design.php |
| API | api/settings.php |
| CSS | 設定画面CSS |

**主要機能**:
- 背景画像プレビュー
- カラーピッカー
- 設定保存（`saveSettingsAndReload` → api/settings.php `update_design`）
- `getApiUrl()`: サブディレクトリ対応、絶対URLでfetch（セッションクッキー確実に送信）
- テーマ選択時の即時プレビュー: `applyDesignTokensToRoot(themeId)` で `themesData` の `dtLeftBg`/`dtInputBg` 等を `:root` の `--dt-*` に反映し、左パネル・チャット入力欄をデザインページで正しく表示
- 枠線プレビュー: `applyStyle(styleId)` で `--ui-*` を **document.body** に設定。`includes/design_loader.php` の `generateUIStyleCSS` / `generateStyleCSS` は `body.style-{id}` に --ui-* を出力するため、上書きは body のインラインスタイルで行う（:root に設定すると body のスタイルが優先されて反映されない）

---

## 管理画面用JS

### admin-members.js

**読込み元**: admin/members.php

**依存API**: admin/api/members.php

**主要関数**:
```javascript
// メンバー一覧読込
function loadMembers() { ... }

// ロール変更
function updateMemberRole(memberId, newRole) { ... }

// メンバー検索
function searchMembers(query) { ... }
```

### admin-groups.js

**読込み元**: admin/groups.php

**依存API**: admin/api/groups.php

**主要関数**:
```javascript
// グループ一覧読込
function loadGroups() { ... }

// グループ作成
function createGroup(data) { ... }

// グループ削除
function deleteGroup(groupId) { ... }

// メンバー追加
function addMemberToGroup(groupId, userId) { ... }
```

### admin-restrictions.js

**読込み元**: admin/members.php

**依存API**: admin/api/member-restrictions.php

**主要関数**:
```javascript
// 制限一覧読込
function loadRestrictions() { ... }

// 制限追加
function addRestriction(data) { ... }

// 制限削除
function removeRestriction(id) { ... }
```

---

## includes/chat/scripts.php との関係

```
┌─────────────────────────────────────────────────────────────┐
│ chat.php                                                    │
│                                                             │
│  ┌───────────────────────────────────────────────────────┐ │
│  │ includes/chat/scripts.php（インラインJS）              │ │
│  │                                                       │ │
│  │  - PHP変数にアクセス可能（$currentUserId 等）         │ │
│  │  - 動的生成（言語設定に応じた翻訳）                   │ │
│  │  - 主要機能（renderMessages, sendMessage 等）         │ │
│  │                                                       │ │
│  │  依存関係は includes/chat/DEPENDENCIES.md を参照     │ │
│  └───────────────────────────────────────────────────────┘ │
│                                                             │
│  ┌───────────────────────────────────────────────────────┐ │
│  │ assets/js/chat.js（外部JS）                           │ │
│  │                                                       │ │
│  │  - 静的な補助機能                                     │ │
│  │  - ユーティリティ関数                                 │ │
│  └───────────────────────────────────────────────────────┘ │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## グローバル関数の命名規則

| プレフィックス | 用途 | 例 |
|---------------|------|-----|
| `render*` | DOM描画 | `renderMessages()` |
| `load*` | データ読込 | `loadMembers()` |
| `send*` | データ送信 | `sendMessage()` |
| `toggle*` | 表示切替 | `toggleEmojiPicker()` |
| `handle*` | イベントハンドラ | `handleSubmit()` |
| `update*` | データ更新 | `updateRole()` |
| `delete*` | データ削除 | `deleteGroup()` |

---

## 変更時のチェックリスト

### チャット機能を変更する場合
- [ ] まず `includes/chat/scripts.php` を確認（主要機能はそちら）
- [ ] `chat.js` は補助機能のみ
- [ ] `includes/chat/DEPENDENCIES.md` を参照

### 管理画面JSを変更する場合
- [ ] 対応するAPIのレスポンス形式を確認
- [ ] エラーハンドリングを適切に実装
- [ ] ローディング表示を実装

### 新規JSファイルを追加する場合
- [ ] このDEPENDENCIES.mdに追記
- [ ] 命名規則に従う
- [ ] グローバル名前空間の汚染を避ける（可能ならモジュール化）
