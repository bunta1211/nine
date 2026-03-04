# 改善用コンテキスト（画面・機能ごとの主要ファイル）

AI秘書が改善提案を記録する際、関連ファイル・想定原因を提案しやすくするための参照用ドキュメントです。  
**秘密情報（APIキー・パスワード・.env・個人データ）は記載しないでください。**

---

## チャット画面（メイン）

| 領域 | 主要ファイル | 役割 |
|------|-------------|------|
| ページ全体 | `chat.php` | メインチャット画面。初期化・レイアウト |
| 上パネル | `includes/chat/topbar.php` | ヘッダー・検索・通知・ロゴ |
| 左パネル | `includes/chat/sidebar.php` | 会話リスト。`settings-account-bar.php` を include |
| 中央パネル | `chat.php` 内 | メッセージ一覧・入力欄 |
| 右パネル | `includes/chat/rightpanel.php` | 詳細・タスク・メモ。`settings-account-bar.php` を include |
| データ取得 | `includes/chat/data.php` | getChatPageData, getSelectedConversationData。DB最適化済み |
| メインJS | `includes/chat/scripts.php` | メッセージ表示・送信・リアクション・Toチップ |
| モバイル | `assets/js/chat-mobile.js` | モバイル用ストリップ・ページ切替 |
| スタイル | `assets/css/chat-main.css` | チャット共通CSS |
| モーダル | `includes/chat/modals.php` | 各種モーダルHTML |
| 通話UI | `includes/chat/call-ui.php` | 通話関連UI |

---

## API（メッセージ・会話・認証）

| 機能 | 主要ファイル | 役割 |
|------|-------------|------|
| メッセージ | `api/messages.php` | 送信・取得・編集・削除・リアクション・To機能・長文PDF化 |
| 会話 | `api/conversations.php` | 会話/グループ管理 |
| AI秘書 | `api/ai.php` | ask, 性格設定, 熟慮モード, 改善提案記録(extract_improvement_report) |
| 認証 | `api/auth.php`, `includes/auth.php` | ログイン・認証チェック |
| 設定 | `api/settings.php` | ユーザー設定 |
| 通知 | `api/notifications.php` | 通知一覧・既読 |
| タスク・メモ | `api/tasks.php`, `api/memos.php` | タスク・メモCRUD |

---

## 管理画面

| 機能 | 主要ファイル | 役割 |
|------|-------------|------|
| 改善・デバッグログ | `admin/improvement_reports.php` | 改善提案一覧・Cursor用コピー・改善完了通知 |
| 願望パターン | `admin/wish_patterns.php` | 願望パターン管理 |
| ログ | `admin/logs.php` | 各種ログ閲覧 |

---

## 共通・デザイン・DB

| カテゴリ | 主要ファイル | 役割 |
|----------|-------------|------|
| DB接続 | `includes/db.php`, `config/database.php` | 接続管理 |
| API共通 | `includes/api-bootstrap.php` | セッション・DB・エラーハンドリング |
| デザイン | `includes/design_loader.php`, `includes/design_config.php` | テーマ・CSS変数。標準デザイン(lavender) |
| 多言語 | `includes/lang.php` | 翻訳 |
| 構成概要 | `ARCHITECTURE.md` | 全体構造・DEPENDENCIES一覧 |
| 依存関係 | `includes/chat/DEPENDENCIES.md`, `api/DEPENDENCIES.md` 等 | 機能別のファイル一覧・依存 |

---

## パネル位置の対応（ユーザー報告用）

- **PC**: 上パネル / 左パネル / 中央パネル / 右パネル
- **携帯**: 上部検索・ヘッダー / 会話一覧（左に相当）/ メッセージ表示・入力（中央）/ 詳細・タスク（右に相当）
