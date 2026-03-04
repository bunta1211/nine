# 全体を上パネル同様に立体化する計画書

上パネルで採用している**ニューモーフィズム・メタリック**のデザイン言語を、左パネル・中央エリア・右パネル・入力欄に一貫して適用する計画です。

---

## 1. 前提とするデザインルール（上パネルと同一）

- **光源**: 左上。すべての影・ハイライトで一貫させる。
- **凸（convex）**: ボタン・選択中アイテム・カードなど「手前に出ている」要素  
  - 背景をやや明るく。`inset 1px 1px 2px rgba(255,255,255,0.8)`（左上ハイライト）＋ `2px 2px 4px rgba(0,0,0,0.12)`（右下ドロップシャドウ）。
- **凹（concave）**: 入力欄・コンテナなど「窪んでいる」要素  
  - 背景をやや青みグレー（`#e8f0f8` 系）。`inset 2px 2px 5px rgba(0,0,0,0.12)`（上左暗）＋ `inset -2px -2px 5px rgba(255,255,255,0.7)`（下右明）。
- **パネル本体**: 微細グラデーション（左上明・右下暗）で曲面・光沢感を付与。
- **影**: 白と黒の両方を使い、blur 4px〜6px で柔らかく拡散。単層の黒影だけにしない。

---

## 2. 対象エリアと方針

| エリア | 現状 | 立体化の方針 | 主な対象ファイル |
|--------|------|--------------|------------------|
| **左パネル** | フラットな背景・ボタン・リスト | パネル外枠にベゼル風の影、ヘッダー・フィルターを凹/凸で整理、会話アイテムを凸カード化 | [assets/css/layout/sidebar.css](assets/css/layout/sidebar.css), [assets/css/chat-main.css](assets/css/chat-main.css) |
| **中央パネル** | フラットなヘッダー・メッセージ・タスクカード | チャットヘッダーを凹み、メッセージ吹き出し・タスクカードを凸、入力欄を凹 | [assets/css/layout/center-panel.css](assets/css/layout/center-panel.css), [assets/css/components/message-card.css](assets/css/components/message-card.css), [assets/css/chat-main.css](assets/css/chat-main.css) |
| **右パネル** | フラットなセクション・リスト | パネルに微細グラデ、セクション見出しを凸または凹で区別、コンテンツブロックを軽い凸 | [assets/css/layout/right-panel.css](assets/css/layout/right-panel.css), [includes/chat/rightpanel.php](includes/chat/rightpanel.php) 関連スタイル |
| **入力エリア** | フラットな入力欄・ツールバー・送信ボタン | 入力ラッパーを凹、送信ボタン・ツールアイコンを凸 | [assets/css/components/input-area.css](assets/css/components/input-area.css), [assets/css/chat-main.css](assets/css/chat-main.css) |

---

## 3. 左パネル

### 3.1 パネルコンテナ（.left-panel）

- 背景: 単色ではなく **微細グラデ**（例: `linear-gradient(135deg, #fafbfc 0%, #f5f6f8 50%, #eef0f3 100%)`）。
- 外枠: 角丸のまま、**ベゼル風**に  
  - 外側: 下・右に白い広がり影＋黒のソフト影。  
  - 内側: 上・左にごく薄い inset 暗影で縁を強調（任意）。

### 3.2 ヘッダー（.conv-header, .conv-tabs, .new-conv-btn）

- **グループ追加 / 空議論追加**: 凸ボタン（左上ハイライト＋右下ドロップシャドウ）。背景は `var(--dt-header-convex-btn-bg, #f0f2f5)` 相当。
- **タブ（.conv-tab）**: 非選択はフラットまたはごく軽い凹、選択（.active）は凸。

### 3.3 フィルター（.conv-filter, .left-panel-filter-trigger）

- 入力またはトリガー領域を **凹**（上パネル検索バーと同様の inset 影＋青みグレー背景）。

### 3.4 会話アイテム（.conv-item, .conversation-item）

- **通常**: 軽い凸（白〜明るいグレー背景、ソフトな左上ハイライト＋右下影）。またはフラットのまま境界のみ線で区切る。
- **選択中（.active）**: 明確な **凸**（上パネルの凸ボタンと同様の box-shadow）。
- **ホバー**: 凸を少し強める、または背景を少し明るく。
- 既存の `design_loader.php` による `.left-panel .conv-item` の `border` / `background` 指定は、立体用の影・背景に置き換える。

---

## 4. 中央パネル

### 4.1 チャットヘッダー（.chat-header, .center-panel__header）

- 背景を **凹** 表現に（微細グラデ＋上左 inset 暗影＋下右 inset ハイライト）。色は `var(--dt-header-recess-bg)` 相当または少し明るめの青みグレー。

### 4.2 メッセージエリア（.messages-area）

- 背景は現状維持（グラデーション等）または微細グラデに統一。メッセージ単位で立体化。

### 4.3 メッセージカード（.message-card, .message-card__bubble）

- **相手メッセージ（.message-card__bubble）**: **凸**（白〜オフホワイト背景、左上ハイライト＋右下ドロップシャドウ）。`--dt-msg-other-bg` を活かしつつ box-shadow を追加。
- **自分メッセージ（.message-card--own .message-card__bubble）**: 同様に **凸**。`--dt-msg-self-bg` ＋ 立体影。
- タスクカード・ファイル添付・システムメッセージなども同様に凸カードとして統一。

### 4.4 入力エリア（.input-area）

- **ラッパー（.input-area__wrapper）**: **凹**（上パネル検索バーと同様。inset 影＋青みグレー背景）。
- **送信ボタン（.input-area__send-btn）**: **凸**。
- **ツールバーアイコン（To, GIF 等）**: アイコン単位またはまとめて軽い凸、またはフラットのまま（優先度低）。

---

## 5. 右パネル

### 5.1 パネルコンテナ（.right-panel）

- 左パネルと同様に **微細グラデ** 背景。必要ならベゼル風の外枠影。

### 5.2 ヘッダー（.right-panel-header）

- 軽い **凹** またはフラットのまま下線で区切り。凹にする場合は上左 inset 暗影＋下右ハイライト。

### 5.3 詳細セクション（.detail-section, .detail-section-title）

- **見出し（.detail-section-title または .right-panel 内のセクションタイトル）**: クリック可能な場合は **凸** ボタン風、そうでなければ軽いテキスト＋下線または凹の区切り線。
- **コンテンツブロック（.detail-section-content）**: 軽い **凸**（白〜明るいグレー＋ソフトな影）でカード化。

---

## 6. トークン・設定の拡張

- **design_config.php**  
  - `getDefaultDesignTokens()` の `panels` に、左・中央・右用の立体化トークン（例: `leftPanelInnerGradient`, `rightPanelInnerGradient`, `panelConvexBg`, `panelRecessBg`）を追加するか、既存の `--dt-left-bg` 等をグラデーションに変更可能にする。
- **design_loader.php**  
  - 標準デザイン適用ブロックで、`.left-panel`, `.right-panel`, `.chat-header`, `.conv-item`, `.message-card__bubble`, `.input-area__wrapper` などに、上パネルと一貫した **色・影** を `!important` で上書きする。既存の `conv-item.active` の border/background 指定を立体用に差し替える。
- **STANDARD_DESIGN_SPEC.md**  
  - 「上パネル立体デザイン」の次に「全体の立体化」セクションを追加し、左・中央・右・入力の凸/凹ルールと使用トークンを記載する。
- **DESIGN_ASSET_VERSION**  
  - 変更のたびに 1 つ上げ、キャッシュ対策とする。

---

## 7. 変更対象ファイル一覧

| ファイル | 変更内容 |
|----------|----------|
| [includes/design_config.php](includes/design_config.php) | パネル用立体トークン追加（必要に応じて）、`DESIGN_ASSET_VERSION` 更新 |
| [assets/css/layout/sidebar.css](assets/css/layout/sidebar.css) | 左パネル・ヘッダー・タブ・ボタン・フィルター・会話アイテムの凸/凹・グラデ・影 |
| [assets/css/chat-main.css](assets/css/chat-main.css) | `.conv-item` 等の左パネルスタイル、チャットヘッダー、メッセージエリア、入力まわり（既存セレクタを立体用に調整） |
| [assets/css/layout/center-panel.css](assets/css/layout/center-panel.css) | `.chat-header` の凹み、中央パネル背景の微細グラデ（必要なら） |
| [assets/css/components/message-card.css](assets/css/components/message-card.css) | `.message-card__bubble` の凸影（自分/相手両方）、タスクカード等の凸 |
| [assets/css/components/input-area.css](assets/css/components/input-area.css) | `.input-area__wrapper` の凹、`.input-area__send-btn` の凸 |
| [assets/css/layout/right-panel.css](assets/css/layout/right-panel.css) | 右パネル背景グラデ、ヘッダー・セクション・コンテンツブロックの凸/凹 |
| [includes/design_loader.php](includes/design_loader.php) | 左・中央・右・入力の立体スタイルを `!important` で統一 |
| [DOCS/STANDARD_DESIGN_SPEC.md](DOCS/STANDARD_DESIGN_SPEC.md) | 「全体の立体化」仕様・トークンの追記 |
| [assets/css/DEPENDENCIES.md](assets/css/DEPENDENCIES.md) | 立体化に伴う変更の一行メモ（任意） |

---

## 8. 実装時の注意

- 上パネルは **既存の立体仕様を維持** し、他エリアをそれに合わせる。
- 既存のセレクタ名を変えず、**追加・上書き**で立体化する。他ページ（メモ・タスク・通知・デザイン等）で同じクラスを使っている場合は、`body.page-chat` 等でスコープを絞るか、全体で一貫して立体化する。
- アクセシビリティ: フォーカス枠・コントラストは維持する。影だけ強くしすぎてテキストの可読性を落とさない。
- レスポンシブ・モバイル: 現行のメディアクエリを維持しつつ、同じ凸/凹ルールを適用する（必要なら blur や padding を少し抑える）。

以上で、上パネルと同一の「質感・メタリック・実物感」を画面全体に広げるための計画とする。
