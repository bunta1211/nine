# 標準デザイン規格（Standard Design Spec）

アプリ全体で使用する**標準デザイン**の定義と参照先。デザイン変更時はこのドキュメントと `includes/design_config.php` を基準にすること。

---

## 1. 基本情報

| 項目 | 値 | 定義場所 |
|------|-----|----------|
| **テーマID** | `lavender` | `includes/design_config.php` 定数 `DESIGN_DEFAULT_THEME` |
| **表示名** | 標準デザイン | `getThemeConfigs()['lavender']['name']` |
| **背景画像** | 使用しない（`none`） | 全画面で固定 |
| **body の data-theme** | `lavender` | chat.php, design.php など |

---

## 2. カラーパレット（標準デザイン lavender）

**方針**: 白〜グレーで統一し、目立たせる部分にのみオレンジを使用。可読性を確保（本文 4.5:1 以上）。  
詳細計画: `DOCS/STANDARD_DESIGN_REDESIGN_PLAN.md`

### グレースケール（背景・枠・テキスト）

| 用途 | 色 |
|------|-----|
| 純白（カード・入力・ヘッダー） | `#ffffff` |
| 背景 L1 | `#fafafa` |
| 背景 L2 | `#f5f5f5` |
| 背景 L3 | `#eeeeee` |
| 枠線・区切り | `#e0e0e0` |
| 本文（濃） | `#1a1a1a` |
| 本文（中） | `#424242` |
| 補助テキスト | `#616161` |
| プレースホルダー・hint | `#757575` |

### オレンジアクセント（目立たせる部分のみ）

| トークン | 値 | 用途 |
|----------|-----|------|
| accent | `#e67e22` | 主要ボタン・リンク・アクティブタブ |
| accentHover | `#d35400` | ホバー・フォーカス |
| filterBtnActiveBg | `#e67e22` | フィルタータブ選択時 |
| inputFocus | `#e67e22` | 入力フォーカス枠 |
| mentionMsgBorder | `#e67e22` | @メンション左線 |

### ヘッダー・背景

| トークン | 値 | 用途 |
|----------|-----|------|
| headerGradient | `linear-gradient(180deg, #e6e8ec, #dcdee2)` | 上パネル外枠ベゼル（立体用） |
| headerText | `#1a1a1a` | ヘッダー文字色 |
| bgColor | `#f5f5f5` | ページ背景 |
| panelBg | `#fafafa` | 左右パネル |
| panelBgCenter | `#f5f5f5` | 中央パネル |

上パネル立体・メタリック用（`panels.header`）:

| トークン | 値 | 用途 |
|----------|-----|------|
| innerBgGradient | `linear-gradient(135deg, #f2f4f6 0%, #eceff3 50%, #e8eaef 100%)` | 内側凹みの背景（微細グラデ） |
| recessBg | `#e8f0f8` | 検索バー・アイコン群コンテナ（凹み） |
| logoBgGradient | `linear-gradient(90deg, #d4dce8, #d8e4f0, #d4dce8)` | ロゴ背景 |
| logoText | `#345678` | ロゴ文字色 |
| convexBtnBg | `#f0f2f5` | 凸ボタン（戻る・Ken・設定・⇒） |
| bezelBorder | `#e0e0e0` | 外枠ベゼルの枠線 |

#### 上パネル立体デザイン（ニューモーフィズム・メタリック）

上パネルは立体ニューモーフィズムで、**質感・メタリック・実物感**を意識して統一する。光源は**左上**とし、すべての影・ハイライトで一貫させる。

**質感・メタリック仕様（参考デザインB）**

- **光源**: 左上。影は右下方向、ハイライトは左上方向に統一する。
- **凸と凹の使い分け**  
  - **凸**: 戻る（⇐/←）、ロゴ、Ken（ユーザー）、設定、⇒（収納）。左上に白の inset ハイライト、右下にドロップシャドウ。  
  - **凹**: 検索バー、右側アイコン群コンテナ（`.task-memo-buttons`）。上・左に inset 暗影、下・右に inset 明るいハイライト。
- **影のルール**: 白と黒の両方を使い、柔らかく拡散（blur 4px〜6px）。ベゼルは外側に白い広がり影を足して金属の縁取りを表現する。
- **色・トークン**（`getDefaultDesignTokens()['panels']['header']` および `--dt-header-*`）:
  - 内側パネル: `innerBgGradient`（微細グラデーション、左上明・右下暗）
  - 凹み要素: `recessBg`（青みがかった薄いグレー `#e8f0f8`）
  - ロゴ: `logoBgGradient`（淡いブルー横グラデ）、`logoText`（`#345678`）
  - 凸ボタン: `convexBtnBg`（`#f0f2f5`）
  - ベゼル枠: `bezelBorder`（`#e0e0e0`）

**実装の要点**

- **外枠ベゼル** (`.top-panel`): 背景 `linear-gradient(180deg, #e6e8ec, #dcdee2)`、ボーダー `var(--dt-header-bezel-border)`、角丸 18px。外側落ち影 ＋ 下・右に白い広がり影 ＋ 上・左内側ハイライト ＋ ごく薄い内側暗影。
- **内側凹み** (`.top-panel-inner`): 背景 `var(--dt-header-inner-bg-gradient)`、角丸 12px。inset 上左 `2px 2px 5px rgba(0,0,0,0.12)`、下右 `-2px -2px 5px rgba(255,255,255,0.7)`。
- **凸ボタン** (⇐, ⇒, Ken, 設定, ≡): 背景 `var(--dt-header-convex-btn-bg)`、inset 左上ハイライト、ドロップシャドウ右下。
- **凹み** (検索バー、`.task-memo-buttons`): 背景 `var(--dt-header-recess-bg)`、inset 影で窪み強調。
- **ロゴ** (`.logo`): 背景 `var(--dt-header-logo-bg-gradient)`、文字色 `var(--dt-header-logo-text)`、凸の box-shadow。子要素は背景 transparent。
- **右側アイコン群**: `.task-memo-buttons` が凹みコンテナ。内側の各 `.top-btn` はアイコン左・ラベル右の横並び、背景透明、文字色 `#555`。
- 定義: `assets/css/layout/header.css`。`includes/design_loader.php` の統一デザインブロックで `!important` により立体・質感スタイルを適用。

### カード・入力

| トークン | 値 | 用途 |
|----------|-----|------|
| cardBg | `#ffffff` | カード背景 |
| cardBorder | `#e0e0e0` | カード枠 |
| inputBg | `#ffffff` | 入力欄背景 |
| inputBorder | `#e0e0e0` | 入力欄枠 |

### メッセージ吹き出し

| トークン | 値 | 用途 |
|----------|-----|------|
| selfMsgBg | `#eeeeee` | 自分のメッセージ（グレー） |
| selfMsgText | `#1a1a1a` | 自分のメッセージ文字 |
| otherMsgBg | `#ffffff` | 相手のメッセージ |
| otherMsgText | `#1a1a1a` | 相手のメッセージ文字 |
| mentionMsgBg | `#fafafa` | @メンション（左線オレンジ） |

**チャット吹き出しの実装ルール**

- `.message-card` の色は CSS 変数 `--dt-msg-*` のみ使用する。紫（#8b5cf6, #7c3aed, #f3edff 等）は使用しない。
- フォールバックは白/グレー（`#ffffff`, `#eeeeee`, `#1a1a1a`, `#616161`, `#e0e0e0`）に統一。`assets/css/chat-main.css` の `.message-card` 系はデザイントークンと上記フォールバックのみ。

**ユーザーアバターの統一ルール**

- 会話リスト・友達リスト・メンバー表示のユーザーアイコンは**グレーで統一**する。
- クラスは `avatar-grey` を使用。多色（green, blue, orange, pink, purple）のクラスは標準デザインでは使用しない（CSS で同一グレーにマッピングしているが、新規実装では `avatar-grey` を付与すること）。
- 参照: `includes/chat/sidebar.php`（会話リスト）, `includes/chat/scripts.php`（友達リスト `friendColors`）, `includes/chat/modals.php`（モーダル内アバター）, `assets/css/chat-main.css`（`.conv-avatar`, `.member-avatars .avatar`）。

### その他

| トークン | 値 |
|----------|-----|
| scrollTrack | `#f5f5f5` |
| scrollThumb | `#bdbdbd` |
| scrollThumbHover | `#9e9e9e` |
| filterBtnBg | `#f5f5f5` |
| filterBtnActiveBg | `#e67e22` |

※ 上記は `includes/design_config.php` の `getThemeConfigs()['lavender']` および `getDefaultDesignTokens()` と一致させる。

---

## 3. 枠線スタイル（ユーザーが選択可能）

| ID | 名前 | 角丸 | 定義場所 |
|----|------|------|----------|
| frame_square | 直角 | 0 | `getStyleConfigs()` |
| frame_round1 | 丸み1 | 8px | デフォルト `DESIGN_DEFAULT_STYLE` |
| frame_round2 | 丸み2 | 16px | `getStyleConfigs()` |

---

## 4. フォント（ユーザーが選択可能）

| ID | 名前 | 定義場所 |
|----|------|----------|
| default | 標準 | `getFontConfigs()` デフォルト |
| zen-maru | まるゴシック | 同上 |
| yomogi | よもぎ | 同上 |
| その他 | 一覧は design_config.php 参照 | 同上 |

---

## 5. コード上の参照先

| 目的 | 参照先 |
|------|--------|
| テーマIDの定数 | `includes/design_config.php` の `DESIGN_DEFAULT_THEME` |
| 色・グラデーションの実体 | `includes/design_config.php` の `getThemeConfigs()['lavender']` |
| CSS変数（:root）の出力 | `includes/design_loader.php` の `generateDesignCSS()` |
| 画面での data-theme | 常に `lavender`（chat.php は `$themeId = DESIGN_DEFAULT_THEME` で固定） |

---

## 6. 今後のデザイン変更時の手順（整理済み）

- **色・グラデーションを変える**  
  `includes/design_config.php` の `getThemeConfigs()['lavender']` のみ編集。他テーマは廃止済みのため触らない。
- **CSSで色を使う**  
  直接 hex を書かず、`var(--dt-〇〇)` を優先する。一覧は design_loader が出力する `:root` を参照。
- **新規ページを追加する**  
  body に `data-theme="lavender"` または `data-theme="<?= DESIGN_DEFAULT_THEME ?>"`、必要なら `style-<?= getEffectiveStyleId(...) ?>` を付与。背景画像は使わない。
- **枠線・フォントの選択肢を増やす**  
  `getStyleConfigs()` または `getFontConfigs()` に項目を追加し、`getStylesForUI()` / `getFontsForUI()` に含まれることを確認する。
- **デザイン関連の定数**  
  `DESIGN_DEFAULT_ACCENT`、`DESIGN_DEFAULT_FONT_SIZE` などは `includes/design_config.php` 先頭で一元管理。

---

## 7. 上パネル共有アーキテクチャ

トップページ（chat.php）の上パネルを以下4ページで共有する統一規格。

### 対象ページと body クラス

| ページ | body クラス |
|--------|-------------|
| `settings.php` | `page-settings` |
| `design.php` | `design-page` |
| `tasks.php` | `tasks-page` |
| `notifications.php` | `notifications-page` |

### 責任分離（CSS の役割）

| ファイル | PC 担当 | モバイル担当 | 備考 |
|----------|---------|-------------|------|
| `assets/css/layout/header.css` | 上パネル構造・ドロップダウン共通定義 | - | `.top-panel` の `position: fixed`, `overflow: visible` など |
| `assets/css/panel-panels-unified.css` | `body` の `padding-top`, `.main-container` の高さ・余白 | 768px 以下のレイアウト | 4ページすべてがセレクタ対象 |
| `includes/design_loader.php` | 上パネル立体スタイル（`!important`）、`body` の `overflow: visible` | - | 4ページ共通ブロック（L1914〜） |
| 各ページのインライン `<style>` | ページ固有コンテンツ（パネル配色、スクロールバー等） | ページ固有の `@media` ブロック | 上パネル・`body`・`.main-container` は触らない |
| `assets/css/pages-mobile.css` | - | 非統一対象ページの `.main-container` | `:not()` で4ページを除外 |

### CSS 読み込み順序（必須）

```
1. common.css, mobile.css, pages-mobile.css
2. layout/header.css
3. panel-panels-unified.css
4. ページ内 <style>（ページ固有スタイルのみ）
5. generateDesignCSS()（design_loader.php の動的CSS。最後に読み込み、!important で統一）
```

### インラインで守るルール

- `--header-height: 70px` を `:root` に設定（48px は使わない）
- `html, body { overflow: hidden }` は書かない（`design_loader.php` が `overflow: visible !important` で管理）
- `.top-panel { position: fixed; ... }` は書かない（`header.css` が管理）
- ドロップダウン（`.user-dropdown`, `.language-dropdown`, `.task-dropdown-menu`, `.notification-dropdown`）は書かない（`header.css` が共通定義）

### PC版タスク/通知ボタンの動作

画面幅 > 768px では、上パネルのタスクアイコンと通知アイコンをクリックすると、ドロップダウンではなく `tasks.php` / `notifications.php` へ直接ページ遷移する。モバイル（768px 以下）では従来のドロップダウン動作を維持。

実装箇所: `assets/js/topbar-standalone.js`（非チャットページ）、`includes/chat/scripts.php`（チャットページ）。

---

## 8. 関連ドキュメント

- `DOCS/STANDARD_DESIGN_REDESIGN_PLAN.md` … 白〜グレー＋オレンジアクセントのリデザイン計画（場面別・可読性）
- `includes/DEPENDENCIES.md` … デザインシステムの依存関係
- `includes/design_config.php` … 規格の実体（テーマ・スタイル・フォント）
- `includes/design_loader.php` … 動的CSS生成
- `database/migration_standard_design_only.sql` … DB の theme / background_image を標準に統一するマイグレーション

---

## 9. デザインをしやすくするための整理（運用のコツ）

- **色の変更は1か所だけ**  
  `design_config.php` の `getThemeConfigs()['lavender']` を変えれば、`design_loader` 経由で全画面に反映される。CSSに直接 hex を増やさない。
- **CSSでは変数を優先**  
  新規スタイルでは `var(--dt-〇〇)` を使う。一覧はブラウザの開発者ツールで `:root` を確認するか、`design_loader.php` の `:root` 出力を参照。
- **新規ページの body**  
  `data-theme="lavender"` または `DESIGN_DEFAULT_THEME` 定数を使う。スタイルは `getEffectiveStyleId()` で解決した id を `style-〇〇` で付与。
- **枠線・フォントの追加**  
  `getStyleConfigs()` / `getFontConfigs()` に追加し、既存の `getStylesForUI()` / `getFontsForUI()` で返ることを確認。デザイン設定画面（design.php）に自動で並ぶ。
- **規格ドキュメントを更新する**  
  カラーパレットやトークンを増減したら、この `STANDARD_DESIGN_SPEC.md` の該当セクションを更新し、参照先をずらさない。

---

## 10. デザイン規格化の助言

### 10.1 変更時の流れ（守ること）

1. **色・テーマの変更**  
   `includes/design_config.php` の `getThemeConfigs()['lavender']` または `getDefaultDesignTokens()` を編集する。CSS に直接 hex/rgba を増やさない。
2. **CSS 変数（--dt-*）の追加**  
   design_loader の `:root` 出力に追加し、`design_config` のトークンから渡す。命名は `--dt-〇〇`（dt = design token）を踏襲する。
3. **新規ページ・コンポーネント**  
   body に `data-theme="<?= DESIGN_DEFAULT_THEME ?>"` を付与。スタイルは `getEffectiveStyleId()` で解決した id を `style-〇〇` で付与する。
4. **規格・DEPENDENCIES の更新**  
   トークンや色を増減したら `DOCS/STANDARD_DESIGN_SPEC.md` の該当セクションを更新し、必要なら `includes/DEPENDENCIES.md` も更新する。

### 10.2 キャッシュの更新

- 色やトークンを変えたら、`design_config.php` の **`DESIGN_ASSET_VERSION`** を 1 つ上げる。  
  これにより `assetVersion()` 経由のクエリが変わり、ブラウザが新しい CSS を読み直す。

### 10.3 CSS セレクタの命名

- 新規クラスは**既存セレクタと重複しない名前**にする（プロジェクトルール）。  
  コンポーネント単位で接頭辞（例: `.chat-to-selector-〇〇`）をつけると衝突しにくい。

### 10.4 アクセシビリティ

- 標準デザインのテキスト色（`#374151` 等）と背景のコントラストは、そのままでも読みやすい組み合わせになっている。  
  新しく色を足すときは、背景とのコントラスト比（WCAG 2.1 の AA 目安: 4.5:1 以上）を意識する。

### 10.5 レスポンシブ

- チャット周りは `assets/css/chat-main.css`（PC）と `assets/css/chat-mobile.css`（モバイル）で役割を分けている。  
  新規スタイルを追加するときは、必要に応じて両方で同じクラスを調整する。
- 非チャットページ（settings / design / tasks / notifications）のモバイルレイアウトは `assets/css/pages-mobile.css` が担当。  
  `pages-mobile.css` の `.main-container` ルールは `:not()` で上記4ページを除外しており、各ページのモバイルレイアウトはページ内インラインの `@media` ブロックが責任を持つ。

### 10.6 デザイン変更後の確認

- 変更後は **chat.php / design.php / settings.php / tasks.php / notifications.php** を開き、表示が崩れていないか確認する。  
  必要ならブラウザキャッシュを無効化するか、シークレットウィンドウで確認する。

### 10.7 トークン名のルール（採用）

- 新規の CSS 変数は **`--dt-〇〇`**（dt = design token）に揃える。  
  一覧性と検索のしやすさのため、既存の `--dt-header-bg` や `--dt-accent` と同じ命名規則を踏襲する。

### 10.8 変更の順序（採用）

- 変更は **「config → 規格ドキュメント」の順** で行う。  
  1) `includes/design_config.php` を先に編集し、2) そのあと `DOCS/STANDARD_DESIGN_SPEC.md` の該当表・セクションを更新する。  
  実装とドキュメントのずれを防ぐため、この順を守る。

### 9.9 PR／コミット時のチェック（採用）

- デザイン関連の変更をコミット・PR する前に、次を確認する。  
  - 色を変えた → **`DESIGN_ASSET_VERSION` を 1 つ上げたか**  
  - トークンを増やした／減らした → **`STANDARD_DESIGN_SPEC.md` に追記・修正したか**  
  これにより、あとから変更内容を追いかけやすくなる。
