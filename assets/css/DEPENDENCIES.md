# CSS 依存関係

このディレクトリには、Social9のスタイルシートが含まれています。

## ファイル構造（2026-01-29 リファクタリング）

```
assets/css/
├── tokens/
│   └── base.css              # CSS変数・デザイントークン
├── layout/
│   └── center-panel.css      # 中央パネルレイアウト
├── components/
│   └── input-area.css        # 入力エリアコンポーネント
├── chat-new.css              # 新CSSエントリーポイント（@import）
├── chat-main.css             # レガシー（段階的に移行中）
├── chat-mobile.css           # モバイル用
├── common.css                # 共通スタイル
└── ...
```

## ファイル一覧

| ファイル | 役割 | 読込み元 |
|---------|------|---------|
| `chat-main.css` | チャット画面メインスタイル（レガシー）。メッセージカード・conv-avatar は標準デザイン（DOCS/STANDARD_DESIGN_SPEC.md）に準拠。会話リストのAI秘書を最上部固定（order: -9999）。左パネル会話リストは `.conversation-list` に `min-height: 0` を指定し「他○件を表示」展開時に下にスクロール可能にしている。右パネル概要欄は保存後も表示を左上揃え（`.overview-body-readonly` に `display:flex; justify-content:flex-start; align-items:flex-start`）。右パネル概要欄のリンク（`.overview-link`）は :link/:visited/:hover/:active/:focus でアクティブ表示（改善提案 ID:5）。**朝のニュース動画**: `.ai-morning-news-embed`（16:9・max-width 560px）、`.ai-morning-news-video-list`／`.ai-morning-news-video-item`／`-active`、`.ai-morning-news-greeting`／`.ai-morning-news-no-videos` | chat.php |
| `ai-voice-input.css` | AI秘書ツールバー・常時起動ボタン・入力行（送信ボタン右配置をグループチャットと統一） | chat.php |
| `panel-resize.css` | 左右パネルリサイズハンドル | chat.php |
| `panel-panels-unified.css` | パネル間空間の統一・page-chatレイアウト。上パネル共有4ページ（settings/design/tasks/notifications）の `body padding-top` と `.main-container` も管理 | chat.php, settings.php, design.php, tasks.php, notifications.php |
| `chat-new.css` | 新コンポーネント分離CSS | chat.php |
| `tokens/base.css` | CSS変数・デザイントークン | chat-new.css |
| `layout/main-container.css` | メインコンテナレイアウト | chat-new.css |
| `layout/header.css` | ヘッダー（上パネル）構造・ドロップダウン共通定義（`.user-dropdown`, `.language-dropdown`, `.task-dropdown-menu`, `.notification-dropdown`）。立体ニューモーフィズム・メタリック。`--dt-header-*` トークン使用。`.top-panel a.top-btn` でタスク/メモ・通知リンクの見た目統一（text-decoration: none; color: #555）。上パネル共有4ページで共通利用 | chat-new.css, settings.php, design.php, tasks.php, notifications.php |
| `layout/sidebar.css` | 左パネル（サイドバー） | chat-new.css |
| `layout/center-panel.css` | 中央パネルレイアウト。入力エリアは position:sticky; bottom:0 で下枠を固定。padding-bottom は safe-area のみ。チャット用テキストエリアは min-height:96px, max-height:200px で入力欄を拡大。 | chat-new.css |
| `layout/right-panel.css` | 右パネル（詳細） | chat-new.css |
| `components/input-area.css` | 入力エリア（BEM命名） | chat-new.css |
| `components/message-card.css` | メッセージカード（BEM命名） | chat-new.css |
| `components/task-card.css` | タスクカード（横長レイアウト） | chat.php |
| `components/gif-picker.css` | GIFピッカー（遅延読込） | lazy-loader.js |
| `components/emoji-picker.css` | 絵文字ピッカー（遅延読込） | lazy-loader.js |
| `components/image-preview.css` | 画像プレビュー（遅延読込） | lazy-loader.js |
| `components/line-icons.css` | グレー1本ラインアイコン（透明背景・ボタン用） | common.css |
| `common.css` | 共通スタイル。**全デバイス表示計画**: 768px 以下で `.btn` の min-height: 44px、touch-action、-webkit-tap-highlight-color、フォーム要素の font-size: 16px / min-height: 44px を適用 | 多数 |
| `mobile.css` | 共通モバイル（768px/480px）。ヘッダー・パネル・モーダル・タッチターゲット等。**全デバイス表示計画**: 認証・入口ページ（register, forgot_password, reset_password, verify_email, accept_org_invite, call）で common.css と併せて読込 | chat.php, settings/tasks/notifications/design.php, register.php, forgot_password.php, reset_password.php, verify_email.php, accept_org_invite.php, call.php |
| `ai-personality.css` | AI秘書 性格設定パネル・熟慮モード進行表示ボックスのスタイル | chat.php |
| `themes/*.css` | 静的テーマCSS | 任意 |
| `BEM-GUIDE.md` | BEM命名規則ガイド | - |
| `chat-mobile.css` | モバイル用上書き。**グループ追加フォーム表示中**: `.left-panel.left-panel-group-form-open` のとき `.left-panel-filter` と `.conversation-list`／`#conversationList` を非表示。**友達追加フォーム**: `.mobile-friend-qr-scan-btn`（QRコードボタン）、`.mobile-friend-search-desc`（説明文）、`.mobile-friend-invite-row`／`.mobile-friend-invite-btn`（未登録時招待）、`.mobile-show-my-qr-btn`、`.mobile-my-qr-container`（自分のQR表示）。Phase 3 で携帯用3ページストリップ。**チャット入力欄**: 携帯は `padding-bottom: env(safe-area-inset-bottom)` のみでキーボードに接着、PC（769px以上）は `padding-bottom: 0` で画面下辺に接着。 | chat.php |
| `pages-mobile.css` | 非チャットページのモバイルレイアウト。`.main-container` ルールは `:not()` で上パネル共有4ページ（settings/design/tasks/notifications）を除外。**全デバイス表示計画**: FAB に `env(safe-area-inset-bottom/right)` を追加（ノッチ・ホームインジケータ対応） | settings.php, design.php, tasks.php, notifications.php |
| `admin.css` | 管理画面共通（組織作成ページの `.create-org-card`, `.create-org-error` 含む） | admin/*.php |
| `admin-ai-memories.css` | AI記憶管理ページ（`aimem-*` プレフィクス。テーブル・フィルタ・モーダル・履歴表示） | admin/ai_memories.php |
| `admin-ai-specialists.css` | 専門AI管理ページ（`aisp-*` プレフィクス。カードグリッド・編集モーダル・機能フラグ行） | admin/ai_specialist_admin.php |
| `admin-ai-safety.css` | AI安全通報管理ページ（`aisf-*` プレフィクス。統計カード・通報リスト・詳細モーダル・質問フォーム） | admin/ai_safety_reports.php |
| `secretary-rightpanel.css` | AI秘書（AIクローン）専用右パネル（訓練言語・判断材料ツリー・会話記憶・自動返信統計・アイテム編集モーダル） | chat.php |
| `ai-reply-suggest.css` | AIクローン返信提案カードUI（提案ボタン・ローディング・**To行**（.ai-reply-suggest-to-row / .ai-reply-suggest-to-btn）・テキストエリア・送信/閉じる・送信完了）。モバイル: body.ai-reply-suggest-open で入力欄・input-show-btn 非表示、textarea は max-height+overflow-y でスクロール可能、.ai-reply-suggest-overlay で固定オーバーレイ表示 | chat.php |
| `storage.css` | 共有フォルダUI（エクスプローラー風一覧、**表示切替** テキストリスト/画像サムネイルグリッド `.sv-grid-mode`・`.sv-file-card-grid`、プレビュー、共有モーダル、容量バー） | chat.php |

## 移行状況

| コンポーネント | 旧ファイル | 新ファイル | 状態 |
|--------------|-----------|-----------|------|
| CSS変数 | chat-main.css | tokens/base.css | ✅ 完了 |
| メインコンテナ | chat-main.css | layout/main-container.css | ✅ 完了 |
| ヘッダー | chat-main.css | layout/header.css | ✅ 完了 |
| サイドバー | chat-main.css | layout/sidebar.css | ✅ 完了 |
| 中央パネル | chat-main.css | layout/center-panel.css | ✅ 完了 |
| 右パネル | chat-main.css | layout/right-panel.css | ✅ 完了 |
| 入力エリア | chat-main.css | components/input-area.css | ✅ 完了 |
| メッセージ | chat-main.css | components/message-card.css | ✅ 完了 |
| GIFピッカー | chat-main.css | components/gif-picker.css | ✅ 完了（遅延読込） |
| 絵文字ピッカー | chat-main.css | components/emoji-picker.css | ✅ 完了（遅延読込） |
| 画像プレビュー | chat-main.css | components/image-preview.css | ✅ 完了（遅延読込） |
| テーマCSS | design_loader.php | themes/*.css | ✅ 完了（静的化） |
| モーダル | chat-main.css | (未作成) | 予定 |

---

## CSS優先度階層

```
1. ブラウザデフォルト
    ↓
2. common.css, mobile.css (基本CSS)
    ↓
3. chat-main.css (レガシー)
    ↓
4. chat-new.css → tokens/base.css, layout/*.css, components/*.css
    ↓
5. includes/design_loader.php (動的CSS) ← テーマ依存
    ↓
6. chat-mobile.css (モバイル上書き)
    ↓
7. インラインスタイル (style属性)
    ↓
8. !important 付きスタイル
```

**重要**: 
- `design_loader.php` は静的CSSの後に読み込まれるため、同じセレクタでは動的CSSが優先されます。
- `tokens/base.css` はデフォルト値を定義し、`design_loader.php` で上書きされます。

---

## chat-main.css 詳細

- **透明テーマの右パネル・中央グループ名**: `data-bg-design="recommended"`（おすすめ6種）のときは `var(--dt-right-bg)` / `var(--dt-center-header-bg)` が効く。`data-bg-design="custom"` のときのみ `chat-main.css` と `design_loader.php` の指定グレー（`rgba(95,100,110,0.95)`）が適用される（詳細度で上書きしないようセレクタに `[data-bg-design="custom"]` を付与）。

### 主要セレクタと用途

| セレクタ | 用途 | 関連JS |
|---------|------|--------|
| `.message-card` | メッセージカード | `renderMessages()` |
| `.message-card.own` | 自分のメッセージ（枠なし） | - |
| `.message-card.mentioned-me` | 自分宛のメッセージ | - |
| `.message-card.mention-frame` | 自分宛のみ枠あり（テーマ色）。To全員は枠なし | appendMessageToUI |
| `.message-card.to-all` | To全員宛（枠なし） | - |
| `.conversation-item` | 会話リスト項目 | - |
| `.conversation-item.active` | 選択中の会話 | - |
| `.input-toolbar` | 入力ツールバー | - |
| `.toolbar-btn` | ツールバーボタン | - |
| `.reaction-picker-v2` | リアクション選択 | `showReactionPicker()` |
| `.reaction-badge` | リアクションバッジ | - |
| `.task-card` | タスク依頼・完了メッセージカード | `renderMessages()` in scripts.php |
| `.ai-today-topics-frame`, `.ai-today-topic-link`, `.ai-today-topic-item` | 本日のニューストピックスブロック（枠・題名リンク・大きめ文字） | `addAIChatMessage(..., true)` in scripts.php |
| `.system-message` | システムメッセージ（招待・管理者任命など） | `renderMessages()` in scripts.php |
| `.member-modal-redesign` | メンバー管理モーダル | `renderCurrentMembersList()` |
| `.language-dropdown` | 言語選択ドロップダウン | `toggleLanguageMenu()` |
| `.user-dropdown` | ユーザーメニュードロップダウン | `toggleUserMenu()` |

### 透明テーマ（transparent）対応

- ドロップダウン、モーダル、GIF/絵文字ピッカー、フローティングメニュー: 不透明背景で視認性確保
- タスクカード、conv-avatar、右パネル: テーマ別スタイル適用
- チャット内タスク依頼モーダル担当者欄: `.chat-task-assignee-list`, `.chat-task-assignee-item`（chat-main.css）
- **時計クローバー等の背景をはっきり表示**: `chat-main.css` の `.center-panel::before`（装飾オーバーレイ）は、透明テーマ時に `includes/design_loader.php` の動的CSSで `display: none` に上書きされる。本番でも反映されるよう、`design_loader.php` をデプロイ先に配置すること（静的CSSのみのアップロードでは不十分な場合がある）。
- **時計クローバー等の左パネル**: 明るい背景画像（`data-bg-light="1"` / `.bg-light`）のとき、左パネルは右パネルと同じボディ色（`--theme-right-panel-bg`）、ボタン・文字は黒系（`#1a3d1a`）。透明ダーク用のスタイルは `:not(.bg-light):not([data-bg-light="1"])` で除外している（`chat-main.css`）。
- **時計クローバー等の入力欄**: 同じく `data-bg-light="1"` / `.bg-light` のとき、チャット入力欄のツールバー（TO・GIF・添付等）のボタン文字は**白字**（`#ffffff`）。`chat-main.css` と `design_loader.php` の両方で指定。
- **チャット入力テキストエリア**: `.input-row textarea`, `#messageInput` の min-height / max-height は **chat-main.css**（56px→96px、300px→200px）と **chat-mobile.css**（body.page-chat .center-panel .input-area … で 96px/200px !important）で定義。**layout/center-panel.css は chat-new.css で import がコメントアウトされているためチャットでは読み込まれず**、テキストエリアの高さ変更は chat-main.css / chat-mobile.css で行う必要がある。

### デザインテーマ別メッセージ・メンション色（2026-02-12 見直し）

- **相手/自分のチャット背景**: 各テーマ（フォレスト・オーシャン・サンセット・ラベンダー・チェリー）および透明テーマの各背景画像（シティ・スイカ・雪だるま・富士山・雪山・時計クローバー）で、`--dt-msg-self-bg` / `--dt-msg-other-bg` をテーマに合わせて設定。レガシーパス（非透明テーマ）では `design_loader.php` の `:root` で `--dt-msg-*` を出力。
- **自分宛てメンション**: メッセージカードに `.mentioned-me` または `.mention-frame` が付与されたとき、背景は `--dt-msg-mention-bg`、枠線は `--dt-mention-border`（各デザインのメンション枠色）で 2px の枠で囲む（`.message-card.mention-frame`）。テーマごとに `mentionMsgBg` / `mentionMsgText` / `mentionMsgBorder` を `includes/design_config.php` の `getThemeConfigs()` で定義。

### ドロップダウンメニューの色固定（2026-01-28追加）

透明テーマや背景画像使用時でも読みやすいよう、ドロップダウンメニューの色は`!important`で固定されています：

```css
/* 白背景・黒文字を強制 */
.language-dropdown { background: #ffffff !important; }
.language-option { color: #333333 !important; }
.user-dropdown { background: #ffffff !important; color: #333333 !important; }
```

**注意**: これらのスタイルはdesign_loader.phpの透明テーマスタイルより優先度が高くなるよう設定されています。

### テーマとの干渉ポイント

```css
/* ⚠️ 以下のセレクタは design_loader.php で上書きされる可能性あり */

.conversation-item.active {
    /* design_loader.php: $isTransparent が true の場合に上書き */
    background: var(--primary);
    color: white;
}

.message-card {
    /* design_loader.php: テーマカラーに応じて変化 */
    background: var(--bg-card);
}
```

### 安全な変更方法

```css
/* 方法1: より具体的なセレクタを使う */
.chat-container .conversation-item.active {
    /* design_loader.php より優先される */
}

/* 方法2: CSS変数を使う（推奨） */
.conversation-item.active {
    color: var(--active-text-color, white);
}
/* → design_loader.php で --active-text-color を設定すれば上書き可能 */

/* 方法3: テーマ固有のセレクタ */
.theme-clock-clover .conversation-item.active {
    color: #333;
}
```

---

## CSS変数一覧

### 変数定義場所

| ファイル | 役割 | 優先度 |
|---------|------|--------|
| `tokens/base.css` | デフォルト値 | 低 |
| `design_loader.php` | テーマ動的値 | 高 |

### 基本変数（tokens/base.css で定義）

| 変数名 | 用途 | デフォルト値 |
|-------|------|-------------|
| `--primary` | メインカラー | #6b8e23 |
| `--primary-dark` | 濃いメインカラー | #556b2f |
| `--primary-light` | 薄いメインカラー | #8fbc8f |
| `--accent` | アクセントカラー | #daa520 |
| `--text` | テキスト色 | #333333 |
| `--text-muted` | 薄いテキスト色 | #999999 |
| `--bg-main` | 背景色 | #f5f5f5 |
| `--bg-panel` | パネル背景 | #ffffff |
| `--border` | ボーダー色 | #e0e0e0 |
| `--header-height` | ヘッダー高さ | 48px |
| `--left-panel-width` | 左パネル幅 | 260px |
| `--right-panel-width` | 右パネル幅 | 280px |

### デザイントークン（dt-*）

design_loader.phpで動的に生成される変数。tokens/base.cssにデフォルト値あり。

| 変数名 | 用途 |
|-------|------|
| `--dt-accent` | テーマアクセントカラー |
| `--dt-msg-self-bg` | 自分のメッセージ背景 |
| `--dt-msg-other-bg` | 相手のメッセージ背景 |
| `--dt-btn-primary-bg` | プライマリボタン背景 |
| `--dt-input-bg` | 入力欄背景 |

詳細は `tokens/base.css` を参照してください。

---

## 新規CSSクラスを追加する場合

### 命名規則

```
/* 機能ベースの命名 */
.{コンポーネント}-{要素}
.{コンポーネント}-{要素}--{状態}

例:
.message-card
.message-card--own
.message-card__content
.message-card__timestamp
```

### チェックリスト

- [ ] 既存のクラス名と重複していないか
- [ ] design_loader.php で上書きされる可能性はないか
- [ ] テーマ（$isTransparent）による影響を考慮したか
- [ ] ダークモード対応が必要か
- [ ] レスポンシブ対応が必要か

---

## 変更時のデバッグ方法

1. ブラウザのDevToolsを開く
2. 要素を選択
3. Computed タブで最終的な値を確認
4. Styles タブで適用されているCSSファイルを確認
5. `design_loader.php` からの動的CSSは `<style>` タグとして表示される

```
優先度の確認方法:
- 取り消し線 = より優先度の高いスタイルで上書きされている
- !important = 最優先（ただし多用は避ける）
```

---

## 関連ファイル

| ファイル | 役割 |
|---------|------|
| `includes/design_loader.php` | 動的CSS生成 |
| `includes/design_config.php` | デザイン設定 |
| `design.php` | デザイン設定画面 |
| `assets/js/design-settings.js` | デザイン設定JS |
