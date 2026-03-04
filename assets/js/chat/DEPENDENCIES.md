# Chat JavaScript モジュール依存関係

このディレクトリにはチャット機能のJavaScriptモジュールが含まれています。

## ファイル一覧

| ファイル | 役割 | 依存関係 |
|---------|------|---------|
| `config.js` | 設定・初期化 | なし |
| `utils.js` | ユーティリティ関数 | なし |
| `debug.js` | デバッグログ | なし |
| `api.js` | APIクライアント | debug.js, ui.js |
| `ui.js` | 共通UIコンポーネント | utils.js |
| `to-selector.js` | TO（宛先指定）機能 | utils.js |
| `reactions.js` | リアクション機能（add_reaction/remove_reaction で API に保存。保存失敗時は Toast 表示） | utils.js |
| `tasks.js` | タスク・メモ機能 | utils.js, config.js |
| `messages.js` | メッセージ送信・編集 | utils.js, config.js, to-selector.js |
| `translation.js` | 翻訳機能 | utils.js |
| `media.js` | メディア管理・GIF | utils.js |
| `polling.js` | ポーリング | config.js, messages.js |
| `call.js` | 通話機能（Jitsi） | config.js, polling.js |
| `panel-resize.js` | 左右パネル幅リサイズ | なし |
| `ui-sounds.js` | UI効果音（パネル収納ボタン押下時に book1 再生） | なし |
| `input-area-resize.js` | チャット入力欄の上下高さリサイズ（ドラッグで2行〜9行相当）。グループチャット・AI秘書の両方で利用。AI秘書はパネル動的生成のため `window.initInputAreaResize()` で再初期化する。 | なし |
| `lazy-loader.js` | 遅延読み込み | なし |
| `index.js` | エントリーポイント | 全モジュール |

## 読み込み順序

```html
<!-- 正しい読み込み順序 -->
<script src="assets/js/chat/config.js"></script>
<script src="assets/js/chat/utils.js"></script>
<script src="assets/js/chat/to-selector.js"></script>
<script src="assets/js/chat/reactions.js"></script>
<script src="assets/js/chat/tasks.js"></script>
<script src="assets/js/chat/index.js"></script>
```

## 名前空間

全てのモジュールは `Chat` 名前空間の下に配置されます：

```javascript
Chat.config    // 設定
Chat.utils     // ユーティリティ
Chat.toSelector // TO機能
Chat.reactions  // リアクション
Chat.tasks      // タスク
Chat.memos      // メモ
```

## 初期化

```javascript
// PHPから設定を渡す
window.ChatInitOptions = {
    userId: <?= $user_id ?>,
    conversationId: <?= $conversation_id ?>,
    lang: '<?= $lang ?>',
    members: <?= json_encode($members) ?>
};

// 自動初期化（DOMContentLoaded時）
// または明示的に初期化
Chat.init(window.ChatInitOptions);
```

## 後方互換性

グローバル関数との互換性を維持しています：

| 旧関数 | 新API |
|-------|-------|
| `toggleToSelector()` | `Chat.toSelector.toggle()` |
| `selectToMember(id)` | `Chat.toSelector.select(id)` |
| `toggleReactionPicker(id, e)` | `Chat.reactions.showPicker(id, e)` |
| `addToTask(id)` | `Chat.tasks.addFromMessage(id)` |
| `addToMemo(id)` | `Chat.memos.addFromMessage(id)` |
| `escapeHtml(str)` | `Chat.utils.escapeHtml(str)` |

## 移行状況

| モジュール | 状態 |
|-----------|------|
| config.js | ✅ 完了 |
| utils.js | ✅ 完了 |
| to-selector.js | ✅ 完了 |
| reactions.js | ✅ 完了 |
| tasks.js | ✅ 完了 |
| messages.js | ✅ 完了 |
| translation.js | ✅ 完了 |
| media.js | ✅ 完了 |
| polling.js | ✅ 完了 |
| call.js | ✅ 完了 |

## 注意事項

これらのモジュールは現在 **未読み込み** です。
scripts.php の関数と重複するため、段階的に移行する必要があります。

### 移行手順

1. chat.phpにJSモジュールの読み込みを追加
2. scripts.phpから対応する関数を削除
3. テストして動作確認
4. 次のモジュールに進む
