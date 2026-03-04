# 透明デザイン 7種類のルール

透明テーマでは **7種類** のデザインがあり、それぞれ配色ルールが異なります。

---

## 1. 基本方針

- **ボディ・ボタン・背景のカラー**は、背景デザインに馴染む色で統一する。
- **フォント色**は読みやすいものを選ぶ。

---

## 2. 7種類の内訳

| 種類 | 数 | 説明 | 配色 |
|------|----|------|------|
| **おすすめデザイン** | 6種 | 背景画像が「おすすめ」のいずれかのとき | 各デザインごとの配色で **上・右・中央・左パネルを同配色** にそろえる |
| **カスタム** | 1種 | ユーザーがアップロードした背景など、上記以外 | **指定グレー**（`rgba(95,100,110,0.95)`）でデザインを統一する |

---

## 3. おすすめ6種（背景画像キー）

次のいずれかのときは「おすすめデザイン」として、そのデザイン用の配色を適用する。

| キー | デザイン名 |
|------|------------|
| `sample_city01.jpg` | シティ |
| `sample_suika01.jpg` | スイカ |
| `sample_yukidaruma01.jpg` | 雪だるま |
| `sample_fuji.jpg` | 富士山 |
| `sample_snow.jpg` | 雪山 |
| `sample_tokei_clover01.jpg` | 時計クローバー |

- 配色は `includes/design_config.php` の `getBackgroundDesignOverrides()` で定義。
- チャットでは `generateDesignCSS()` が「新システム」としてトークンCSSを出力し、**上パネル・左パネル・中央グループ名バー・右パネル** に同じパレット（`--dt-header-bg`, `--dt-left-bg`, `--dt-center-header-bg`, `--dt-right-bg` 等）を適用する。
- 判定: `getBackgroundDesignOverrides(背景画像)` が `null` でない → おすすめ。body に `data-bg-design="recommended"` を付与。

---

## 4. カスタム（1種）

- 背景画像が上記6種のどれでもないとき（独自アップロード等）は「カスタム」。
- 判定: `getBackgroundDesignOverrides(背景画像)` が `null` → カスタム。body に `data-bg-design="custom"` を付与。
- **指定グレー** `rgba(95, 100, 110, 0.95)` ＋ 文字色 `#ffffff` で、中央パネルのグループ名バー・右パネル「詳細」・左パネル選択中グループ名を統一する。
- 実装: `assets/css/panel-panels-unified.css` の「data-bg-design="custom"」のブロックで適用。

---

## 5. 実装上のポイント

- **chat.php**: 透明テーマ時に `getBackgroundDesignOverrides()` の結果で `data-bg-design="recommended"` または `data-bg-design="custom"` を body に付与。
- **panel-panels-unified.css**: 指定グレーで上書きするセレクタは **`[data-bg-design="custom"]` を付けたときだけ** に限定し、おすすめ6種ではトークン配色が効くようにする。
- **design_config.php**: `getTransparentRecommendedBackgroundKeys()` でおすすめ6種のキー一覧を取得可能。

---

## 6. 雪だるまデザイン（例）

- キー: `sample_yukidaruma01.jpg`
- おすすめのため `data-bg-design="recommended"` となり、トークンで **青と白** のパレット（例: `rgba(240,249,255,0.95)`, `#0c4a6e`）が上・左・中央ヘッダー・右に適用される。
- 指定グレーは適用しない（カスタム時のみ）。

---

## 7. 解決記録（透明テーマでグループ名・右パネルがトークンに変わらない問題）

### 現象
- おすすめ6種（スイカ等）を選んでも、**中央のグループ名バー**と**右パネル**が指定グレー（暗い色）のままになり、左パネル・入力欄のようにトークン配色（薄いピンク等）に変わらなかった。

### 右パネルが変更できた理由（先行対応）
- `chat-main.css` と `includes/design_loader.php` で、透明テーマ時の「右パネルを指定グレーで上書き」するルールのセレクタに **`[data-bg-design="custom"]`** を付与した。
- これにより `data-bg-design="recommended"` のときは当該ルールが当たらず、インラインのトークン（`--dt-right-bg` 等）が効くようになった。

### グループ名バーが変更できなかった理由（後から判明）
次の2つが、おすすめ6種でもグループ名バーに暗色を当てていた。

1. **chat-main.css**  
   - `body[data-theme="transparent"] .chat-header` に **暗色**（`rgba(20,25,30,0.85)`）を指定するルールがあり、**透明テーマ全体**に適用されていた。  
   - おすすめのときもこのルールが効き、トークン（`--dt-center-header-bg`）を上書きしていた。

2. **design_loader.php**  
   - `$isTransparent` が真のときに出力するブロック内で、`.center-panel .chat-header` / `.center-panel .room-header` に **指定グレー**（`rgba(95,100,110,0.95)`）を**無条件**で指定していた。  
   - トークン用のルール（同じファイル内の前方）より**後**に出力されるため、おすすめ6種でもグレーで上書きされていた。

### 解決内容（グループ名バー対応）
- **chat-main.css**  
  - 上記の「透明時の .chat-header 暗色」を、**カスタム時のみ**適用するように変更。  
  - セレクタを `body[data-theme="transparent"][data-bg-design="custom"] .chat-header` 等にし、`[data-bg-design="custom"]` を付与。
- **design_loader.php**  
  - 透明テーマ用ブロック内の「中央パネルヘッダーを指定グレー＋白文字」にするルールを、**カスタム時のみ**適用するように変更。  
  - セレクタを `body[data-theme="transparent"][data-bg-design="custom"] .center-panel .chat-header` 等に変更。

この結果、おすすめ6種ではグループ名バーにもトークン（`--dt-center-header-bg` / `--dt-center-header-text`）が効き、右パネルと同様にデザインに合わせた色になる。

### デザイン富士山で中央・右を左パネルに合わせる（2026-02）

- **要望**: デザイン富士山のとき、中央パネル（グループ名バー・チャットエリア・入力欄）と右パネルを、左パネルと同じ半透明の青みがかったグレーにする。
- **前提**: 上記「グループ名が変更できなかった理由」の修正が入っていること。`data-bg-design="recommended"` のとき指定グレーが当たらないため、トークン配色が効く。
- **実施**: `includes/design_config.php` の `getBackgroundDesignOverrides()` 内、`sample_fuji.jpg` の `panels` を左パネルと統一した。
  - `center.headerBg` を `rgba(40,45,60,0.75)` に（従来は `rgba(50,55,70,0.8)`）。
  - `right.sectionBg` を `rgba(40,45,60,0.75)` に（従来は `rgba(50,55,70,0.7)`）。
  - `header.bg` をグラデーションから `rgba(40,45,60,0.75)` に変更し、左・中央・右で同じトーンに。
- **確認**: テーマが「透明」かつ背景画像が「富士山」（`sample_fuji.jpg`）のとき、body に `data-bg-design="recommended"` が付き、トークン経路で上記パネル色が適用される。中央・右がまだ不透明な場合は、キャッシュのハードリロードまたは「グループ名が変更できなかった理由」の修正（chat-main.css / design_loader.php の custom 限定）のデプロイを確認する。
