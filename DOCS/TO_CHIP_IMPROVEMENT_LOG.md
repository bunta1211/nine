# Toチップ表示改善ログ（小分け記録）

## 実施した変更（完了分）

### 1. JS側（includes/chat/scripts.php）
- **contentWithToChips**: `<span class="msg-to-chip">` ネスト構造 → 単一 `<b data-to="ID">` + インラインスタイル（display:inline-block, background:#7cb342 等）に簡素化
- **buildToChipsFromMentionIds**: 同様に `<b data-to="...">` に統一
- **デバッグ**: セレクタを `.msg-to-chip` から `b[data-to]` に変更。TO_COMPUTED/TO_RECT の冗長ログを整理
- **一時カード**: 送信直後の楽観的UIでも To チップを表示（contentWithToChips を使用）
- **ポーリング競合**: API応答時に既存カード（ポーリングで追加されたもの）を削除してから再描画するよう修正

### 2. PHP側（chat.php）
- **$formatMessageTextHtml**: 本文の `[To:ID]名前` を `<b data-to="ID" style="...">TO 名前</b>` に変換（既に適用済み）
- **$renderToInfoChips**: to_info から生成するチップも `<b data-to="...">` に統一（既に適用済み）
- **CSS**: `.message-card .content b[data-to]` に display/visibility/opacity/background/color 等を !important で指定（表示保証）

### 3. アップロード対象
- `includes/chat/scripts.php`
- `chat.php`
- （必要に応じて）`assets/css/chat-main.css`（.msg-to-chip 等の旧ルールは残っていても b[data-to] が優先される想定）

## チップの新構造（共通）
- タグ: `<b data-to="ID"` または `data-to="all"`
- スタイル: `display:inline-block; background:#7cb342; color:#fff; padding:1px 8px; border-radius:4px; font-size:12px; font-weight:600; margin:2px 4px 2px 0; vertical-align:middle; line-height:1.6`
- クラス名なし・ネストなしで、拡張やキャッシュの影響を避ける

## 次の確認事項
- 本番で To 付きメッセージ送信 → チップが「TO 名前」の緑バッジとして表示されるか
- 問題解決後は [TO_DOM_DEBUG] / [TO_AFTER_DEBUG] / [TO_CHIP_DEBUG] 等の console.log を削除可能
