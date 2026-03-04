# 透明デザインのテーマカラー統一ルール（カスタム用）

透明デザインは **7種類**（おすすめ6種＋カスタム1種）あります。  
**指定グレーで統一するのは「カスタム」のときだけ**です。おすすめ6種は各デザインの配色を使用します。  
詳細は [TRANSPARENT_DESIGN_7_RULES.md](./TRANSPARENT_DESIGN_7_RULES.md) を参照してください。

---

## カスタム時のルール概要

- **指定グレー**: `rgba(95, 100, 110, 0.95)`（CSS変数 `--transparent-theme-body-bg`）
- **文字色**: `#ffffff`（CSS変数 `--transparent-theme-body-text`）
- **適用条件**: body に `data-bg-design="custom"` が付いているときのみ（おすすめ6種では適用しない）

---

## 対象となる要素

| 対象 | 説明 |
|------|------|
| **中央パネル・グループ名バー** | `.center-panel .chat-header`, `.center-panel .room-header`（チャット上部のグループ名「アプリ開発 (5)」などが乗るバー） |
| **右パネル「詳細」** | `.right-panel .right-header`, 各セクション見出し（概要・メディア・タスク・グループ設定）、`.menu-item`, `.detail-item` など |
| **左パネル・選択中グループ** | `.conv-item.active`（選択中の会話「アプリ開発 (5)」の行） |

---

## 実装場所

- **定義**: `assets/css/panel-panels-unified.css` の `:root` で `--transparent-theme-body-bg`, `--transparent-theme-body-text` を定義。
- **適用**: 同ファイル内で **`body[data-bg-design="custom"]`** を付けたセレクタでのみ指定グレーを適用。おすすめ6種（`data-bg-design="recommended"`）のときは指定グレーは使わず、design_loader のトークン配色が効く。
- **読み込み順**: `panel-panels-unified.css` は `generateDesignCSS()`（インライン）の**後**に読み込むため、カスタム時のみ指定グレーで上書きされる。

---

## 注意

- 透明**ライト**（明るい背景）のとき、左パネル全体や吹き出しなどは別ルールで薄緑・黒文字になることがある。上記「対象となる要素」はその場合でも指定グレー＋白で統一する。
- 指定グレーの値を変える場合は `--transparent-theme-body-bg` を変更すればよい。
