# Social9 アーキテクチャ概要

このドキュメントは、Social9の全体構造と依存関係を可視化するためのガイドです。

**重要**: 機能を変更する前に、該当する `DEPENDENCIES.md` ファイルを必ず確認してください。

## DEPENDENCIES.md ファイル一覧

| ファイル | 内容 | 確認タイミング |
|---------|------|---------------|
| `ARCHITECTURE.md` | 全体構造（このファイル） | 最初に確認 |
| `DOCS/STANDARD_DESIGN_SPEC.md` | 標準デザイン規格（色・トークン・変更手順） | デザイン変更時 |
| `includes/DEPENDENCIES.md` | 共通機能・デザインシステム | テーマ変更時 |
| `includes/chat/DEPENDENCIES.md` | チャット機能 | チャット変更時 |
| `api/DEPENDENCIES.md` | REST API群 | API変更時 |
| `assets/css/DEPENDENCIES.md` | CSSスタイル | スタイル変更時 |
| `assets/js/DEPENDENCIES.md` | JavaScript | JS変更時 |
| `config/DEPENDENCIES.md` | 設定ファイル | 設定変更時 |
| `database/DEPENDENCIES.md` | DBスキーマ | テーブル変更時 |
| `admin/DEPENDENCIES.md` | 管理画面 | 管理機能変更時 |
| `Guild/DEPENDENCIES.md` | Guild（報酬分配） | Guild変更時 |

## ディレクトリ構造

```
nine/
├── ARCHITECTURE.md          ← このファイル（全体構造）
├── chat.php                 ← メインチャット画面
├── design.php               ← デザイン設定画面
├── index.php                ← ログイン画面
│
├── api/                     ← REST API群
│   └── DEPENDENCIES.md      ← API依存関係
│
├── includes/                ← 共通PHP
│   ├── DEPENDENCIES.md      ← 共通機能の依存関係
│   ├── chat/                ← チャット関連
│   │   └── DEPENDENCIES.md  ← チャット機能の依存関係
│   └── design_loader.php    ← テーマ/デザイン適用
│
├── assets/                  ← 静的ファイル
│   ├── css/
│   │   └── DEPENDENCIES.md  ← CSS依存関係
│   └── js/
│       └── DEPENDENCIES.md  ← JS依存関係
│
├── config/                  ← 設定ファイル
│   └── DEPENDENCIES.md      ← 設定依存関係
│
├── database/                ← SQLスキーマ/マイグレーション
│   └── DEPENDENCIES.md      ← DB依存関係
│
├── admin/                   ← 管理画面
│   └── DEPENDENCIES.md      ← 管理画面依存関係
│
├── scripts/                 ← 運用スクリプト
│   ├── README.md            ← 使い方
│   ├── setup-hosts-ec2-enable.bat   ← social9.jp を EC2 に強制向け
│   └── setup-hosts-ec2-disable.bat  ← 上記を元に戻す
│
└── Guild/                   ← Guild機能（報酬分配システム）
    └── DEPENDENCIES.md      ← Guild依存関係
```

## コンポーネント間の依存関係

```
┌─────────────────────────────────────────────────────────┐
│                      Frontend                            │
│  chat.php ─────────────────────────────────────────────  │
│      │                                                   │
│      ├── includes/chat/scripts.php  (JavaScript)        │
│      ├── includes/chat/modals.php   (モーダルHTML)      │
│      ├── includes/design_loader.php (テーマCSS生成)     │
│      └── assets/css/chat-main.css   (基本スタイル)      │
└─────────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────┐
│                      API Layer                           │
│  api/messages.php, api/conversations.php, etc.          │
│      │                                                   │
│      └── includes/api-bootstrap.php (共通初期化)        │
└─────────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────┐
│                    Data Layer                            │
│  includes/db.php ──── config/database.php               │
│      │                                                   │
│      └── MySQL (messages, users, conversations, etc.)   │
└─────────────────────────────────────────────────────────┘
```

## テーマ/デザインシステム

```
デザイン設定 (design.php)
    │
    ├── 保存先: users.background_image, users.background_color
    │
    └── 読込み: includes/design_loader.php
              │
              ├── 背景画像の有無を判定 ($isTransparent)
              ├── CSS変数を動的生成
              └── テーマ固有のスタイルを出力

【重要】テーマ別のスタイル変更時は以下を確認:
- includes/design_loader.php の条件分岐
- assets/css/chat-main.css の基本スタイル
- 各コンポーネントのDEPENDENCIES.mdファイル
```

## 変更時のチェックリスト

### チャット機能を変更する場合
→ `includes/chat/DEPENDENCIES.md` を確認

### テーマ/デザインを変更する場合
→ `includes/DEPENDENCIES.md` の「デザインシステム」セクションを確認

### APIを変更する場合
→ `api/DEPENDENCIES.md` を確認

### CSSを変更する場合
→ `assets/css/DEPENDENCIES.md` を確認

## 横断的関心事（Cross-Cutting Concerns）

以下の機能は複数のコンポーネントに影響するため、変更時は特に注意が必要です。

### 1. テーマ/デザインシステム

**影響範囲**: 全画面の見た目

**関連ファイル**:
- `includes/design_loader.php` - 動的CSS生成
- `assets/css/*.css` - 静的CSS
- `design.php` - 設定画面

**変更時の確認先**: `includes/DEPENDENCIES.md`

### 2. 認証システム

**影響範囲**: 全画面のアクセス制御

**関連ファイル**:
- `includes/auth.php` - 認証チェック
- `config/session.php` - セッション設定

**変更時の確認先**: `includes/DEPENDENCIES.md`

### 3. 多言語対応

**影響範囲**: 全テキスト

**関連ファイル**:
- `includes/lang.php` - 翻訳関数
- `api/language.php` - 言語切替API

### 4. データベース接続

**影響範囲**: 全API、全画面

**関連ファイル**:
- `includes/db.php` - 接続管理
- `config/database.php` - 接続設定

**変更時の確認先**: `config/DEPENDENCIES.md`

### 5. メモ・金庫

**影響範囲**: メモページ、金庫API・WebAuthn API・DB

**関連ファイル**:
- `memos.php` - メモページ（金庫ボタン・開錠モーダル・一覧モーダル）
- `assets/js/memo-vault.js` - 金庫フロントエンド（WebAuthn 認証・初回登録・CRUD）
- `api/vault.php` - 金庫 CRUD API（X-Vault-Token 認証）
- `api/webauthn.php` - WebAuthn 登録・認証 API（lbuchs/webauthn）
- `includes/VaultCrypto.php` - AES-256-GCM 暗号化/復号
- `database/migration_vault.sql` - テーブル定義（vault_sessions, vault_items, webauthn_credentials）

**進捗**: `DOCS/VAULT_IMPLEMENTATION_PROGRESS.md`

---

## 問題解決ガイド

### 「Cの一部だけ変更したいが、全体に影響してしまう」場合

1. まず該当コンポーネントの `DEPENDENCIES.md` を確認
2. 「横断的関心事」に該当するか確認
3. 影響範囲を特定
4. 必要に応じてスコープを限定（CSS: 特定セレクタ、PHP: 条件分岐）

### 例: 時計クローバーテーマだけ文字色を変更したい

```
1. includes/DEPENDENCIES.md を確認
   → design_loader.php が影響

2. assets/css/DEPENDENCIES.md を確認
   → .conversation-item.active が該当

3. 解決策:
   - design_loader.php で背景画像名を判定
   - 特定テーマ用のCSSを出力
```

---

## 命名規則

| カテゴリ | 規則 | 例 |
|---------|------|-----|
| CSS クラス | ケバブケース | `.message-card`, `.input-toolbar` |
| テーマ固有CSS | `.theme-{name}` プレフィックス | `.theme-clock-clover .active` |
| JavaScript関数 | キャメルケース | `renderMessage()`, `toggleEmojiPicker()` |
| API アクション | スネークケース | `action=get_messages` |
| データベースカラム | スネークケース | `created_at`, `user_id` |

---

## ドキュメント更新ワークフロー

### コード変更時の必須作業

```
┌─────────────────────────────────────────┐
│ 1. コードを変更                          │
│         ↓                               │
│ 2. 該当の DEPENDENCIES.md を更新        │
│         ↓                               │
│ 3. 必要に応じて ARCHITECTURE.md も更新  │
└─────────────────────────────────────────┘
```

### 更新が必要なケース

| 変更内容 | 更新すべきファイル |
|---------|-------------------|
| 新規ファイル追加 | 該当ディレクトリの DEPENDENCIES.md |
| 新規API追加 | `api/DEPENDENCIES.md` |
| 新規関数追加 | 該当コンポーネントの DEPENDENCIES.md |
| DBテーブル/カラム追加 | `database/DEPENDENCIES.md` |
| 新規コンポーネント追加 | `ARCHITECTURE.md` + 新規 DEPENDENCIES.md |

### Cursorルール

`.cursor/rules/update-dependencies-docs.mdc` により、
コード変更時に DEPENDENCIES.md の更新が必須化されています。

---

## PWA対応（2026-01）

モバイル端末でホーム画面にアイコンを追加できます。

### 関連ファイル

| ファイル | 役割 |
|---------|------|
| `manifest.json` | PWAマニフェスト（アプリ情報） |
| `sw.js` | Service Worker（オフライン対応、インストール要件） |
| `offline.html` | オフライン時のフォールバックページ |
| `assets/icons/` | アプリアイコン各サイズ |
| `assets/icons/generate-icons.php` | アイコン生成スクリプト |
| `assets/css/pwa-install.css` | インストールバナーのスタイル |
| `assets/js/pwa-install.js` | インストールプロンプトのロジック |

### インストールプロンプト動作

| プラットフォーム | 動作 |
|-----------------|------|
| Android Chrome | `beforeinstallprompt` で自動バナー表示 → クリックでインストール |
| iOS Safari | 3秒後にバナー表示 → クリックで手順説明モーダル |
| デスクトップ Chrome | `beforeinstallprompt` で自動バナー表示 |

### アイコン生成方法

ブラウザで以下にアクセス:
```
https://your-domain/nine/assets/icons/generate-icons.php
```

---

## パフォーマンス最適化（2026-01）

### サーバーサイド最適化

| 最適化 | 実装場所 | 効果 |
|-------|---------|------|
| Gzip圧縮 | `.htaccess` | 転送サイズ約70%削減 |
| ブラウザキャッシュ | `.htaccess` | 静的ファイルのリクエスト削減 |
| ETags | `.htaccess` | キャッシュ検証の効率化 |
| N+1クエリ解消 | `includes/chat/data.php` | DB負荷削減 |
| CSSバージョニング | `chat.php` | filemtime()でキャッシュ効率化 |

### クライアントサイド最適化

| 最適化 | 実装場所 | 効果 |
|-------|---------|------|
| preconnect | `chat.php` | 外部リソース接続高速化 |
| 動的ポーリング | `scripts.php` | 非アクティブ時の負荷90%削減 |
| 楽観的UI更新 | `scripts.php` | メッセージ送信の体感速度向上 |
| 画像lazy loading | `chat.php`, `scripts.php` | 初期読込軽量化 |
| Jitsi API遅延読込 | `scripts.php` | 未使用時のリソース節約 |
| jsQR遅延読込 | `scripts.php` | 未使用時のリソース節約 |

### 詳細ドキュメント

- チャット機能の最適化詳細: `includes/chat/DEPENDENCIES.md`

---

## JavaScript モジュールシステム（2026-01-29 追加）

### Chat 名前空間

全てのJavaScript機能は `Chat` 名前空間の下に整理されています。

```javascript
Chat.config     // 設定管理
Chat.utils      // ユーティリティ関数
Chat.debug      // デバッグログ
Chat.api        // APIクライアント
Chat.ui         // 共通UIコンポーネント
Chat.lazyLoader // 遅延読み込み
```

### 使用例

```javascript
// 設定値の取得
const userId = Chat.config.userId;

// トースト通知
Chat.ui.toast('保存しました', 'success');

// 確認ダイアログ
const confirmed = await Chat.ui.confirm('削除しますか？');

// APIリクエスト
try {
    const data = await Chat.api.get('messages.php', { action: 'list' });
} catch (error) {
    Chat.api.handleError(error);
}

// デバッグログ（本番では非表示）
Chat.debug.log('API', 'レスポンス受信', data);
```

### デバッグモード

URLに `?debug=1` を追加するか、コンソールで以下を実行：

```javascript
Chat.debug.enable();  // 有効化
Chat.debug.status();  // 状態確認
Chat.debug.disable(); // 無効化
```

### モジュール一覧

| モジュール | ファイル | 役割 |
|-----------|---------|------|
| config | `assets/js/chat/config.js` | 設定・初期化 |
| utils | `assets/js/chat/utils.js` | ユーティリティ関数 |
| debug | `assets/js/chat/debug.js` | デバッグログ |
| api | `assets/js/chat/api.js` | APIクライアント |
| ui | `assets/js/chat/ui.js` | 共通UIコンポーネント |
| lazy-loader | `assets/js/chat/lazy-loader.js` | 遅延読み込み |

詳細: `assets/js/chat/DEPENDENCIES.md`

---

## あなたの秘書：タスク・メモ検索（2026-02）

キーワードでタスク・メモを検索し、秘書が報告する機能です。

### 関連ファイル

| ファイル | 役割 |
|---------|------|
| `api/ai.php` | 秘書API（ask 時に検索結果をコンテキストに追加） |
| `includes/task_memo_search_helper.php` | キーワード抽出・検索・結果整形 |
| `config/ai_config.php` | AI_TASK_MEMO_SEARCH_INSTRUCTIONS |
| `database/migration_task_memo_soft_delete.sql` | deleted_at 追加（論理削除） |

### 検索フロー

1. ユーザーが「2025年度の怪我をまとめて報告して」と入力
2. `extractTaskMemoSearchParams()` でキーワード・年を抽出
3. `searchTasksAndMemos()` で tasks / memos を LIKE 検索（削除済みも含む）
4. 結果をAIに渡して自然な報告文を生成
5. Gemini失敗時は検索結果をそのままフォールバック返答

### 論理削除

- `deleted_at` カラムがあれば検索結果に「(削除済み)」を表示
- マイグレーション未実行のDBでも後方互換で動作

詳細: `DOCS/AI_SECRETARY_TASK_MEMO_SEARCH_OPTIONS.md`

---

## 検索機能アーキテクチャ

Social9 には複数の検索機能があります。種類ごとのAPI・UI・フローを整理したドキュメントを参照してください。

| 検索種別 | 主なAPI | 用途 |
|----------|---------|------|
| グローバル検索 | `api/messages.php?action=search` | メッセージ・ユーザー・グループ横断検索 |
| グループメンバー | `api/friends.php?action=group_members` | DM開始用（友達追加モーダル） |
| ユーザー検索 | `api/users.php`, `api/friends.php` | グループ追加・友達検索 |
| GIF検索 | `api/gif.php` | メッセージ添付用GIF |
| タスク・メモ検索 | `includes/task_memo_search_helper.php` | AI秘書・tasks/memos画面 |
| 場所検索 | `api/ai.php`（Places API） | 近くのお店検索 |

詳細: `DOCS/SEARCH_ARCHITECTURE.md`

---

## このドキュメントの更新

新しいコンポーネントを追加した場合:
1. 該当ディレクトリに `DEPENDENCIES.md` を作成
2. このファイルの「DEPENDENCIES.md ファイル一覧」に追記
3. 関連する既存の `DEPENDENCIES.md` も更新
