# チャット画面レイアウト不具合の解決記録（上パネル重なり・最下部余白）

同じような問題が起きたときに参照するための記録です。

---

## 1. 症状

- **上パネルとその下のパネルが重なって見える**  
  固定表示のヘッダー（Social9・検索・アプリ等）の下にある、左パネル・中央チャット・右パネルの上端が、ヘッダーの下に隠れる／透けて重なって見える。
- **画面一番下に不要な空白ができる**  
  チャット入力欄やパネルの下端より下に、背景だけの余白が残る。コンテンツがビューポート下端まで届いていない。

透明テーマで顕著でしたが、レイアウトの仕組み上はテーマに限らない問題です。

---

## 2. 想定していた原因

- 上パネルが `position: fixed; top: 8px; height: 48px` で **8px～56px** を占有しているのに、その下のメインコンテンツ用の「上スペース」が不足していた。
- その上スペースを **`.main-container` の `margin-top`** だけで賄っていたため、  
  デザイン用インラインCSS（design_loader）や `body { height: 100% }` など、**読み込み順・詳細度・キャッシュ**の影響で `margin-top` が効いていないように見えるケースがあった。
- 下端余白は、**`body` の高さ指定**と **`.main-container` の `height: calc(100vh - 64px)`** の組み合わせが、環境によって期待どおりにならず、コンテンツが 100vh まで伸びていなかった。

---

## 3. 試したが不十分だった対応

- `.main-container` の `margin-top` を 64px（8+48+8）にし、`height: calc(100vh - 64px)` に統一する。
- `:root` に `--main-offset-top: 64px` を定義し、詳細度を上げる（`body .main-container`）。
- `!important` で margin/height を強制する。
- 透明テーマ時だけ design_loader のインラインで `.main-container` に同じ値を出力する。

これらだけでは、**「どのスタイルが実際に効くか」が読みにくく、環境によっては重なり・下端余白が残る**状態でした。

---

## 4. 解決した方法（今回の結論）

**「上スペースは body の padding で確保する」「チャット画面だけを body クラスで識別する」** ように変更しました。

### 4.1 チャット画面の body に専用クラスを付与（chat.php）

- `body` に **`page-chat`** クラスを追加。
- チャット画面だけを `body.page-chat` で一意に指定できるようにする。

```html
<body class="page-chat style-... " ...>
```

### 4.2 上スペースを body の padding で確保（panel-panels-unified.css）

- **`.main-container` の `margin-top` に依存しない**ようにする。
- **`body.page-chat { padding-top: 64px !important; }`** で、上パネル分のスペースを body 側で確保する。
- `.main-container` は **`margin-top: 0 !important`** にし、「上の空き」は body の padding のみで制御する。

これにより、他CSSで `.main-container` の margin が上書きされても、**重なりは body の padding で防げる**ようになります。

### 4.3 下端まで埋める（panel-panels-unified.css）

- **`body.page-chat { min-height: 100vh !important; height: auto !important; box-sizing: border-box !important; }`**  
  design_loader の `body { height: 100% }` を上書きしつつ、高さは中身に合わせつつ最低 100vh を確保。
- **`body.page-chat .main-container { height: calc(100vh - 64px) !important; max-height: calc(100vh - 64px) !important; }`**  
  メインエリアの高さを 100vh 基準で固定し、下端の余白が出ないようにする。

### 4.4 モバイル（768px 以下）

- `body.page-chat` の **`padding-top`** を `var(--mobile-header-height, 56px)` に変更。
- `.main-container` の高さも `calc(100vh - var(--mobile-header-height, 56px))` に統一。

### 4.5 他ページとの共存

- `body:not(.page-chat) .main-container` で、tasks / memos など他ページでは従来どおり `margin-top` と `height` を指定するフォールバックを残す。

---

## 5. 関係するファイル

| ファイル | 役割 |
|----------|------|
| `chat.php` | body に `page-chat` クラスを付与 |
| `assets/css/panel-panels-unified.css` | `body.page-chat` の padding-top / height と `.main-container` の margin-top: 0, height |
| `includes/design_loader.php` | 透明テーマ用の .main-container 指定は残してよい（`body.page-chat` 側が優先される） |

---

## 6. 同様の問題が再発したときのチェックポイント

1. **チャット画面の body に `page-chat` が付いているか**  
   - 付いていなければ、上スペース用の padding が効かない。
2. **`panel-panels-unified.css` がチャットで読み込まれているか・キャッシュが古くないか**  
   - 読み込み順は chat.php の link 順を確認。必要ならハードリロード（Ctrl+Shift+R）。
3. **他CSSで `body` の padding や `body .main-container` の margin/height を強く上書きしていないか**  
   - 上書きする場合は、`body.page-chat` 用のルールをそれより後で・必要なら `!important` で再指定する。
4. **上パネルの実際の高さ**  
   - `top` + `height` が 48px 以外（例: 56px）に変わっていれば、64px の部分をその値に合わせて調整する（例: 8+56+8=72px）。

---

## 7. まとめ

- **「上パネルとの重なり」** → **body の `padding-top` で上スペースを確保**し、`.main-container` の `margin-top` だけに頼らない。
- **「最下部の余白」** → **`body.page-chat` の高さと `.main-container` の `height: calc(100vh - 64px)`** をセットで指定し、`height: auto` / `min-height: 100vh` / `box-sizing: border-box` で 100vh まで確実に伸ばす。
- **「どのページで効かせるか」** → **body に `page-chat` を付与**し、チャット専用のレイアウトだけを対象にする。

この方針で、今回の重なりと最下部余白の問題は解消しました（2026年2月時点）。

---

## 8. 携帯版：入力欄を開いたときのメッセージ表示（追記）

**現象**: チャット入力欄（キーボード）を開くと画面の半分以上が隠れ、最新メッセージの最後まで読めない。

**対応**:
- **body に `mobile-input-focused` クラス**を付与（入力エリアの focusin、または visualViewport の resize でキーボード表示を検知したとき）。
- **`body.page-chat.mobile-input-focused .center-panel`** の高さを **`calc(var(--visual-viewport-height) - var(--mobile-header-height) - var(--mobile-input-height))`** に設定（`chat-mobile.css`）。キーボード表示時は `--visual-viewport-height` が縮むため、中央パネルが「見えている範囲 − ヘッダ − 入力欄」になり、**入力欄とキーボードの間に空白が出ない**。
- JS（`chat-mobile.js`）で `applyInputAreaAboveKeyboard()` により、入力欄の `bottom` をキーボード高さ分に設定し、フォーカス時は 100/300/500ms の遅延で再適用してキーボードアニメーション完了後に密着させる。
- **空白が「ときどき」出る対策**: キーボード表示のアニメーション中に `visualViewport` の resize が遅れる端末があるため、フォーカス後 40ms 間隔で約 1 秒間 `updateVisualViewportHeight` / `applyInputAreaAboveKeyboard` / `scrollMessagesToBottom` をループ実行（`startMobileKeyboardSyncLoop`）。あわせて `mobile-input-focused` 時に中央パネルの `max-height` を `min(..., 70vh - header)` でキャップし、変数未更新時の一瞬の大きな空白を防ぐ。

**注意**: 他CSSで `.center-panel` の高さを上書きしないこと。`panel-panels-unified.css` の `.main-container` の高さはそのままでよく、中央パネルだけ `!important` で上書きしている。

---

## 9. 携帯版：チャット入力欄が3行までしか表示されない（追記）

**現象**: 携帯でチャット入力中、表示が3行目までで止まり、最大9行に増やしても変わらない。

**原因**:
- `chat-mobile.css` の **同じファイル内の後続** で、`.message-input` に **`max-height: 120px`**（約3行分）が指定されていた。
- 先頭付近の `body.page-chat #messageInput` で `max-height: 180px !important` を指定していても、**同じスタイルシート内で後に出てくる** `.message-input` の `max-height: 120px` が、詳細度は低くとも「同じ優先度の別プロパティ」として効き、または環境によっては上書きして 3 行表示に制限されていた。

**対応**（`assets/css/chat-mobile.css`）:
1. **`body.page-chat .message-input`** で **`max-height: 180px !important`** を明示し、チャット画面では後続ルールより優先させる。
2. 後続の **`.message-input`** の `max-height` を **120px → 180px** に変更し、どこで効いても最大9行になるようにする。

**今後のポイント**:
- 入力欄の行数・高さを変えるときは、**同一ファイル内の後ろにある `.message-input` や `#messageInput` の `max-height`／`min-height`** もまとめて確認する。
- チャット専用の指定は **`body.page-chat`** と **`!important`** で確実に上書きする（本ドキュメント「4. 解決した方法」の方針に同じ）。

**追記（3行のまま変わらない場合の追加原因）**
- **JS側の上限**: `scripts.php` の AI 用入力で **120px** キャップ → 携帯時 **180px** に変更。`chat/utils.js` の `autoResizeInput` のデフォルト **130px** → 携帯時 **180px**。`chat/config.js` の `autoResizeMaxHeight` を **180** に変更。
- **CSS の最終優先**: `chat-mobile.css` 末尾に **`body.page-chat .center-panel .input-area ...`** で `#messageInput`／textarea の **max-height: 180px !important** を追加。`.input-area` 自体に **max-height: 260px !important** を追加（9行＋ツールバー分を確保）。
- **根本原因: CSS `!important` が JS inline style に勝つ問題**:
  CSS の `min-height: 52px !important` や `max-height: 180px !important` が、JS の `textarea.style.height = '0px'`（!important なし）を上書きする。そのため textarea が 0px にならず scrollHeight 計測が不正確になる。
  **対策**: JS で **`textarea.style.setProperty('height', 'auto', 'important')`** を使い、CSS `!important` を確実に上書きする。全ての autoResizeInput 系関数（`scripts.php`、`chat/utils.js`、`chat.js`）で統一。
- **design_loader.php の `overflow: hidden !important`**:
  `.input-wrapper` と `.input-container` に `overflow: hidden !important` が出力され、親要素でテキストエリアの表示がクリップされる。
  **対策**: `chat-mobile.css` 末尾で `body.page-chat .input-wrapper { overflow: visible !important }` と `body.page-chat .input-container { overflow: visible !important }` を追加。
