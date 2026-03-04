# デザイン関連 残作業まとめ

最終更新: 2026-02-07

## 実施済み（今回の改善で完了したもの）

| 項目 | 状態 |
|------|------|
| transparent-light / transparent-dark テーマ定義 | ✅ design_config.php に追加 |
| タスクドロップダウン視認性 | ✅ 不透明背景 |
| 透明テーマのヘッダー・パネル | ✅ light/dark 別スタイル |
| 保存ボタン（transparent-light） | ✅ 黒文字・緑背景 |
| 左パネル（transparent-light） | ✅ 明るい背景・濃い文字 |
| 右パネル menu-item（transparent-light） | ✅ 明るい背景・濃い文字 |
| トップバー ドロップダウン | ✅ user/language/app 不透明化 |
| モーダル（desktop） | ✅ 透明テーマで不透明 |
| GIF・絵文字ピッカー | ✅ 不透明背景 |
| design_loader 除外条件 | ✅ transparent* 全般で除外 |
| 多言語対応 | ✅ theme_transparent_light/dark |
| design.php 背景画像選択 | ✅ transparent-light/dark 対応 |
| 他ページ transparent-light | ✅ tasks, memos, notifications |
| GIF/絵文字ピッカー（transparent-dark） | ✅ 内部テキスト色 |
| メンバーモーダル（transparent-dark） | ✅ 内部テキスト色 |
| 画像プレビューモーダル | ✅ 透明テーマで backdrop 不透明化 |
| design-settings.js selectTheme | ✅ customBgSection を transparent-light/dark でも表示 |
| design-settings.js selectRecommendedDesign | ✅ 透明系テーマの維持 |
| design-settings.js uploadBackground | ✅ 透明系テーマの維持 |
| 編集モーダル（transparent-dark） | ✅ textarea, h3, edit-to 等の視認性 |

---

## 残作業（優先度順）

※ 上記の実施済みにより、残作業は解消済み

### 1. 高優先度

#### 1.1 design.php: 透明-light/dark の背景画像選択

**現状**: カスタム背景ピッカーは `theme === 'transparent'` のときのみ表示される。  
**問題**: transparent-light / transparent-dark を選択した場合、背景画像の選択UIが表示されない。

**対応案**:
- 表示条件を `in_array($design_settings['theme'], ['transparent', 'transparent-light', 'transparent-dark'])` に拡張
- 推奨デザイン（富士山・雪等）の active 判定も同様に拡張  
  - `$isFujiActive` 等を `strpos($themeId, 'transparent') !== false` で判定

**対象ファイル**: `design.php`

---

#### 1.2 他ページ（tasks / memos / notifications）の transparent-light 未対応

**現状**: tasks.php, memos.php, notifications.php は `transparent-dark` のみスタイル定義済み。  
**問題**: transparent-light 選択時、左/右スペーサー・カード・見出しがデフォルト inherit となり、背景画像が透けると視認性が低下する可能性。

**対応案**:
- 各ページに `body[data-theme="transparent-light"]` 用のスタイルを追加
- left-spacer, right-spacer: 明るい背景
- task-card, memo-card, notification-item: 白系背景・濃い文字

**対象ファイル**: `tasks.php`, `memos.php`, `notifications.php`

---

### 2. 中優先度

#### 2.1 GIF・絵文字ピッカー（transparent-dark）の内部要素

**現状**: 背景は `#1e1e1e` に変更済み。  
**懸念**: 検索欄・タブ・カテゴリタイトル等のテキスト色が暗いままの可能性。

**対応案**:
- `body[data-theme="transparent-dark"] .gif-picker *` 等でテキスト色を `#f0f0f0` に
- 必要に応じて input の placeholder も調整

**対象ファイル**: `assets/css/chat-main.css` または `components/gif-picker.css`, `components/emoji-picker.css`

---

#### 2.2 メンバーモーダル（transparent-dark）の内部要素

**現状**: member-modal-redesign の背景は `#1e1e1e` に変更済み。  
**懸念**: モーダル内のテキスト・ボタン・リスト項目の色が不十分な可能性。

**対応案**:
- `body[data-theme="transparent-dark"] .member-modal-redesign .member-modal-title`
- `body[data-theme="transparent-dark"] .member-modal-body` 等の文字色を明るく指定

**対象ファイル**: `assets/css/chat-main.css`

---

### 3. 低優先度・検証

#### 3.1 画像プレビュー（image-preview）

**確認項目**: 透明テーマで開いた画像プレビューオーバーレイの視認性。  
**対応**: 必要であれば `components/image-preview.css` に transparent 用オーバーライドを追加。

---

#### 3.2 設定ページ（settings.php）

**現状**: data-theme は反映されるが、ページ固有の透明テーマ対策は未確認。  
**対応**: 設定ページは `body.page-settings` で transparent を無効化している可能性を確認（includes/DEPENDENCIES.md に記載あり）。

---

#### 3.3 design.php の selectTheme JS

**確認項目**: transparent-light / transparent-dark 選択時に `data-is-transparent="1"` が渡され、背景画像選択フローが正しく動作するか。  
**対応**: JS の `selectTheme` で isTransparent に応じた背景画像表示の切り替えを確認。

---

## 作業チェックリスト

- [x] design.php: 背景画像選択を transparent-light/dark に対応
- [x] design.php: 推奨デザイン active 判定を transparent* に拡張
- [x] tasks.php: transparent-light 用スタイル追加
- [x] memos.php: transparent-light 用スタイル追加
- [x] notifications.php: transparent-light 用スタイル追加
- [x] transparent-dark: GIF/絵文字ピッカー内部テキスト色
- [x] transparent-dark: メンバーモーダル内部テキスト色
- [x] image-preview: 透明テーマでの視認性確認
- [x] design.php selectTheme: 背景画像フローの動作確認

---

## 関連ファイル一覧

| ファイル | 役割 |
|----------|------|
| `includes/design_config.php` | テーマ定義 |
| `includes/design_loader.php` | 動的CSS生成 |
| `design.php` | デザイン設定画面 |
| `chat.php` | チャット画面（メイン） |
| `assets/css/chat-main.css` | メインスタイル |
| `assets/css/chat-mobile.css` | モバイルスタイル |
| `assets/css/components/task-card.css` | タスクカード |
| `tasks.php` | タスク画面 |
| `memos.php` | メモ画面 |
| `notifications.php` | 通知画面 |
