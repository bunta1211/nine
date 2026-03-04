# To機能 UI 改善（宛先一覧オレンジ表示・「To Nao×」行削除）

## 依頼内容
- 宛先を選択でメンバー一覧を表示し、**名前を選択するとその名前が薄いオレンジ色**で選択状態を表現する。
- **「To Nao×」のように新しい行で表現している欄は不要**なので削除する。

## 進捗（小分け・再起動後も続き可）

| ステップ | 内容 | 状態 |
|----------|------|------|
| 1 | 宛先一覧の選択を薄いオレンジで表示（CSS） | 済: chat-main.css の `.to-selector-item.selected` を `rgba(230,126,34,0.25)` 等で設定済み |
| 2 | 「To Nao×」表示行を非表示にする | 未: 次に実施 |
| 3 | 改善内容を本DOCSに記録 | 未 |

## 次の1歩（再起動後にここから）
- **やること**: 「To Nao×」行を非表示にする。
- **場所**: includes/chat/scripts.php の `updateToSelectorSelected()` が `id="toSelectorSelected"` の要素を更新している。この要素（またはその親の「選択中:」行）を **CSS で非表示** にするか、**HTML で出力しない**ようにする。
- **手順案**: (A) chat-main.css に `.to-selector-selected` または `#toSelectorSelected` を `display: none !important;` で非表示にする。または (B) scripts.php で toSelectorSelected を表示しているHTMLを探し、そのブロックを削除/コメントアウトし、updateToSelectorSelected は空の要素への更新のままにする（動作はそのまま、表示だけ消す）。

## メモリ節約のため
- 作業は **1ステップずつ** 進め、各ステップ後にこのファイルを更新してから次へ。
- 再起動後は「DOCS/TO_FUNCTION_UI_IMPROVEMENT.md の次の1歩を実行して」と依頼すると続きから進めやすい。
