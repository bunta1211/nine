# 枠線・白背景・ホバー薄オレンジ 変更計画

## 目標

- **枠線**: 太めのグレー（2px solid #9ca3af）
- **内側**: 白背景（#ffffff）、グレーのラインで囲む
- **ホバー**: 内側の背景を**薄いオレンジ**に変化（#ffedd5 等）
- **テキスト・アイコン**: グレーフォント・グレー色の絵（#424242 / #6b7280）

## 変更が効かない主な理由

1. **優先度**: `includes/design_loader.php` が動的CSSを出力し、多くのルールに `!important` を付けている。  
   → **design_config.php のトークン**と **design_loader.php** を必ずセットで変更する必要がある。
2. **ホバー色の不一致**: 以前は「薄いグレー」（#f3f4f6）で統一していたが、今回の要望は「**薄いオレンジ**」。  
   → 全フェーズでホバー色を **#ffedd5**（薄いオレンジ）に統一する。
3. **読み込み順**: chat-new.css → layout/header.css, components/input-area.css の後に design_loader が出力されるため、トークンと design_loader を変えないとファイルCSSだけでは上書きされない。

## 使用する色（統一）

| 用途       | 色       | 備考           |
|------------|----------|----------------|
| 枠線       | #9ca3af  | 太め 2px       |
| 背景（通常）| #ffffff  | 白             |
| 背景（ホバー）| #ffedd5 | 薄いオレンジ   |
| テキスト   | #424242  | グレーフォント |
| アイコン   | #6b7280 / #424242 | グレー系 |

---

## フェーズ1: デザイントークン（単一の定義）

**ファイル**: `includes/design_config.php`

- `buttons.secondary.hover`: `#f3f4f6` → **`#ffedd5`**
- `buttons.filter.hover`: 同様に **`#ffedd5`**（あれば）
- 必要なら `misc` や新キーで `frameHover: '#ffedd5'` を追加し、design_loader で `--dt-frame-hover` として出力

**確認**: design_loader 内の `$btnSecondaryHover` が design_config の secondary.hover を参照しているか確認。

**成果**: トークン由来の「ホバー＝薄いオレンジ」が design_loader 経由で効く土台ができる。

---

## フェーズ2: design_loader.php（上パネル・検索・フィルター）

**ファイル**: `includes/design_loader.php`

- `--dt-btn-secondary-hover` のフォールバック: `#f3f4f6` → **`#ffedd5`**
- `.top-panel .search-box:hover` の background: **`#ffedd5`**
- `.top-panel .top-btn:hover` 等（既に var(--dt-btn-secondary-hover) 使用）→ フェーズ1でトークン変更すれば自動で薄オレンジに。
- フィルターボタン `.filter-tabs button:hover:not(.active)` の background: `#f3f4f6` → **`#ffedd5`**
- その他、同じ「枠・白・ホバー」対象で `#f3f4f6` が使われている箇所を **#ffedd5** に置換。

**成果**: ヘッダー・検索枠・左パネルフィルターが「白＋太グレー枠＋ホバー薄オレンジ」で揃う。

---

## フェーズ3: 左パネル（会話リスト・アイコン）

**ファイル**: `assets/css/chat-main.css`

- `.conv-item:hover` の background: `#f3f4f6` → **`#ffedd5`**
- `.conv-item.has-unread:not(.active):hover` の background: **`#ffedd5`**
- `.conv-item:hover .conv-avatar` の background: **`#ffedd5`**
- 必要なら `.conv-item` / `.conv-avatar` の通常時の border・background を再確認（白・2px #9ca3af）。
- 左パネル内のテキストが `.left-panel *` 等で上書きされていないか確認。グレー（#424242）で統一。

**ファイル**: `assets/css/chat-mobile.css`

- 左パネル用でホバーや背景を指定している箇所があれば、同様に **#ffedd5** に統一。

**成果**: 会話リストの「グループ名表示枠」とアイコンが、白・太グレー枠・ホバー薄オレンジ・グレー文字で揃う。

---

## フェーズ4: ヘッダー（header.css）

**ファイル**: `assets/css/layout/header.css`

- `.toggle-left-btn:hover`, `.toggle-right-btn:hover` の background: `#f3f4f6` → **`#ffedd5`**
- `.search-box:hover` の background: **`#ffedd5`**
- その他、ヘッダー内のボタン・枠でホバー指定があれば **#ffedd5** に統一。
- ボタン・検索枠の通常時は「白・2px #9ca3af」のまま。テキスト・アイコンはグレー。

**成果**: ヘッダー単体のCSSでも薄オレンジホバーになり、design_loader と二重で効く場合は同じ色で一貫する。

---

## フェーズ5: 入力エリア（ツールバー・送信・テキストエリア）

**ファイル**: `assets/css/components/input-area.css`

- `.input-area__btn:hover` の background: **`#ffedd5`**
- `.input-area__action-btn:hover` の background: **`#ffedd5`**
- `.input-area__toggle-btn:hover` の background: **`#ffedd5`**
- `.input-area__send-btn:hover` の background: **`#ffedd5`**
- テキストエリアは通常「白・太グレー枠」のまま。ホバーは任意（入力欄はフォーカスが主なので、必要なら軽く薄オレンジやそのままでも可）。
- 内側のテキスト・アイコンはグレー（既存の var(--text-muted) / --dt-btn-secondary-text で統一）。

**成果**: To / GIF / 電話 / プラス / マイク / オプション / 送信ボタンが「白・太グレー枠・ホバー薄オレンジ・グレー」で揃う。

---

## フェーズ6: その他・確認

- **design_loader.php**: グループ設定ボタン `.group-setting-item:hover` など、まだ `#f3f4f6` や別のホバー色が残っていないか検索して、対象なら **#ffedd5** に。
- **キャッシュ**: 変更が画面に反映されない場合は、`config/app.php` の `DESIGN_ASSET_VERSION` を 1 つ上げ、必要ならブラウザのハードリロード（Ctrl+Shift+R）を案内。
- **DEPENDENCIES.md**: 変更したCSS/コンポーネントがあれば、`assets/css/DEPENDENCIES.md` や関連DOCSに「枠線・白・ホバー薄オレンジ」の仕様を一行メモしておく。

---

## 実施順序（メモリ節約のため1フェーズずつ）

1. フェーズ1 → 保存・確認  
2. フェーズ2 → 保存・確認  
3. フェーズ3 → 保存・確認  
4. フェーズ4 → 保存・確認  
5. フェーズ5 → 保存・確認  
6. フェーズ6 → 最終確認

各フェーズ完了時に「フェーズn 完了。次はフェーズn+1」と伝えれば、途中で切れても続きから再開しやすい。
