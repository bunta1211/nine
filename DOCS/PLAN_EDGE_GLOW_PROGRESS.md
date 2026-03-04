# 実装進捗：スワイプ3ページ＋縁の光のすき間

## Phase 1: 携帯で左右収納ボタン廃止 … 完了

- **1.1–1.2** chat-mobile.css で携帯時に `.toggle-left-btn` / `.toggle-right-btn` を `display: none`（1159–1161, 2756–2758, 2932 付近で実施済み）。
- **1.3** chat-mobile.js の `addMobileMenuButtons()` は左用 `.mobile-menu-btn` を追加せず、設定用ボタンのみ追加（コメント「携帯はスワイプでページ移動するため左用⇒ボタンは追加しない」）。
- **1.4** `body.mobile-panel-open` 時に上記ボタンを表示するルールは削除済み（119–124 で `display: none` にしている）。

**変更ファイル**: 既存の chat-mobile.css / chat-mobile.js で対応済み。追加変更なし。

---

## Phase 2: 縁の「光のすき間」 … 完了

- chat.php に `.edge-glow-left` / `.edge-glow-right` の div を追加済み。
- chat-mobile.css で固定位置・幅10px・グラデーション・pointer-events:none を指定。`.edge-glow--hidden` で非表示。
- chat-mobile.js の `updateEdgeGlow()` で、左パネル開放時は左縁を隠し、右パネル開放時は右縁を隠す。closeAllPanels / toggleLeftPanel / toggleRightPanel から呼び出し済み。

---

## Phase 3: スワイプで3ページ移動 … 実施中

- **3.1 完了** 携帯用に3カラム横並びラッパーを追加した。
  - **chat.php**: `.main-container` 内に `.mobile-pages-strip#mobilePagesStrip` を追加。左パネル・リサイズ左・中央・リサイズ右・右パネルをラップ。
  - **chat-mobile.css**: PC では `.mobile-pages-strip { display: contents }`。携帯では `.main-container` に `overflow-x: auto` / `scroll-snap-type: x mandatory`、ストリップを `display: flex; width: 300vw`、各パネルを `100vw`・`scroll-snap-align: start`。ストリップ内の左右パネルは `position: relative` にし、`.mobile-open` 時のみ `position: fixed` で従来のオーバーレイ表示に戻す。
  - **chat-mobile.js**: `initMobilePagesStripScroll()` を追加し、携帯時のみ `mainContainer.scrollLeft = window.innerWidth` で初期表示を中央パネルに。`initPhase3()` から呼び出し。
- **3.2 完了** スワイプでページ切り替え。
  - **getMobileStripCurrentPage()**: ストリップ有効時は `mainContainer.scrollLeft` から現在ページ（0=左, 1=中央, 2=右）を返す。
  - **updateEdgeGlow()**: ストリップ有効時は上記ページで左縁／右縁の表示を出し分け（左ページで左縁非表示、右ページで右縁非表示）。
  - **initMobilePagesStripScroll()**: 初期スクロール後に `updateEdgeGlow()` を呼び、`mainContainer` に `scroll` リスナーを追加してスクロールのたびにエッジグローを同期。
  - **handleTouchEnd**: ストリップ有効時は、従来の「端スワイプでパネル開閉」を「端スワイプでストリップをスクロール」に変更（左端→右スワイプで左ページへ、右端→左スワイプで右ページへ、左/右ページから逆スワイプで中央へ）。従来のオーバーレイ開閉はストリップ非適用時のみ。
- **3.3 完了** スナップ位置と縦スクロールの調整。main-container は横のみスクロール・scroll-snap で1ページ分スナップ。各パネル（.left-panel, .center-panel, .right-panel）に overflow-y: auto; overflow-x: hidden を追加し、縦スクロールは各パネル内で処理。コメントで Phase 3.3 を明記。
- **3.4 完了** 既存のオーバーレイ開閉とストリップの整合。ストリップ有効時は toggleLeftPanel → 左ページへスクロール、toggleRightPanel → 右ページへスクロール、closeAllPanels → 中央へスクロールしオーバーレイを閉じる。ボタン・オーバーレイタップで「開く」はすべてストリップスクロールに統一。

## Phase 4: 左・右パネルに設定＋アカウント … 完了

- **共通パーツ** `includes/chat/settings-account-bar.php`: 設定リンク（歯車）＋個人アカウント（表示名＋▼でドロップダウン）。変数 `$user`, `$display_name`, `$currentLang`, `$userOrganizations`, `$account_bar_suffix`（例: LeftPanel, RightPanel）でドロップダウン id を分離。
- **左パネル** `includes/chat/sidebar.php`: `.left-header-actions` 内で `$account_bar_suffix = 'LeftPanel'` として上記 include。
- **右パネル** `includes/chat/rightpanel.php`: `.right-header` 内で `$account_bar_suffix = 'RightPanel'` として上記 include。
- **JS** `includes/chat/scripts.php`: `toggleUserMenu(e)` は `e.target.closest('.user-menu-container')` 内の `.user-dropdown` をトグルし、他を閉じる。`closeUserMenu()` は全 `.user-dropdown.show` を閉じる。
- **CSS**: `chat-mobile.css` に Phase 4 用の `.panel-account-bar` レイアウト（携帯）。`chat-main.css` に `.left-header-actions` と `.panel-account-bar` の共通レイアウト（PC・携帯両方）を追加。

## Phase 5: 上パネル整理・光のすき間 z-index … 完了

- **5.1 上パネル構成**: 携帯でも現状維持（1本の固定上パネル。3ページストリップはメインコンテナ内のみで、上パネルは共通）。topbar の簡略化は行わず。
- **5.2 光のすき間とヘッダーの重なり**: z-index を確認し、chat-mobile.css にコメントで明記。上パネル `z-index: 1000`、光のすき間 `z-index: 500` かつ `top: var(--mobile-header-height)` のため、ヘッダーが光より前面で、光はヘッダーと重ならない。

---

## 追加変更: 緑のすき間削除・左パネル・会話表記・ロゴ … 完了

- **緑のすき間（エッジグロー）削除**: chat.php から `#edgeGlowLeft` / `#edgeGlowRight` を削除。chat-mobile.css のエッジグロー用スタイルおよび chat-mobile.js の `updateEdgeGlow` / `getMobileStripCurrentPage` / `setupEdgeGlowTouch` とその呼び出しを削除済み。
- **左パネル先頭タブ**: 「グループ」→「会話」に変更。`includes/lang.php` の `conversation` を利用し、sidebar の先頭ボタンは `__('conversation')` で表示。
- **左パネルヘッダー**: 個人表示（Ken＋▼）を廃止し、`$account_bar_variant = 'left_panel'` 時に設定リンク＋「グループ詳細」ボタン（`toggleRightPanel`）のみ表示。`settings-account-bar.php` で分岐、`.panel-details-btn` のスタイルを chat-main.css / chat-mobile.css に追加。
- **上パネルロゴ「Social9」の 9 欠け**: chat-mobile.css の `.top-panel .top-left .logo` で `min-width: 7em`、`max-width: none`、`overflow: visible` を指定し、欠けないように修正。
