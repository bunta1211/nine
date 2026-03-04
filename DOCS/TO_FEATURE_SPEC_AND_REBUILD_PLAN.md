# To機能 削除・作り直し 計画書

## 1. 現在のTo機能の仕様（言語化）

### 1.1 概要
- **目的**: メッセージの「宛先」を指定し、指定されたメンバーに通知・視覚的に伝える（Chatwork風のTo機能）。
- **種類**: 「全員」または「個別メンバー」を選択可能。

### 1.2 クライアント（UI）
- **Toボタン**: 入力欄ツールバーに「To」ボタン（`#toBtn`）。クリックで宛先選択UIを開く。
- **To行バー**: 選択中の宛先をチップ表示するバー（`#toRowBar`、`.to-row-bar`）。チップごとに削除ボタンあり。「全員」選択時は「ALL」チップ1つのみ。
- **宛先選択UI**: ポップアップ（`#toSelectorPopup`）は includes/chat/scripts.php 内で動的生成。ヘッダー「宛先を選択」、閉じるボタン、メンバーリスト（`#toSelectorList`）。「全員」＋会話メンバー（自分除く）を表示し、選択/解除でトグル。
- **入力欄との連携**: 選択時に `[To:all]全員` または `[To:ID]名前さん` を入力欄に挿入（`insertToMentionLine`）。送信時に `window.chatSelectedToIds`（および `Chat.toSelector.getSelected()`）を `mention_ids` としてAPIに送る。
- **表示時**: メッセージ本文の `[To:ID]` を緑のTOチップ（`.msg-to-chip`）に変換。本文に[To:ID]が無くても `to_info` / `show_to_all_badge` / `to_member_ids_list` があればチップを表示（`buildToChipsFromMentionIds` / `$renderToInfoChips`）。

### 1.3 API・データ
- **送信**: api/messages.php の `send` で `mention_ids`（JSON配列: `['all']` または数値IDの配列）を受け取る。本文が `[To:` で始まっていない場合、先頭に `[To:all]全員` または `[To:ID]名前さん` の行を付与してから保存。
- **保存**: `message_mentions` テーブルに `message_id`, `mentioned_user_id`, `mention_type`（`'to_all'` / `'to'`）を挿入。全員の場合は会話メンバー全員分の行を挿入。
- **取得・表示用**: includes/chat/data.php の `enrichMessagesWithMentionsAndReactions` で `message_mentions` を取得し、各メッセージに `to_info`（`type`, `user_ids`, `users`）、`is_mentioned_me`、`mention_type` を付与。APIの list/get では `has_to_all` / `to_member_ids` / `to_member_ids_list` を付与。

### 1.4 依存ファイル（削除・修正対象の一覧）
| 種別 | ファイル |
|------|----------|
| PHP | chat.php（Toチップ表示・to_info）、includes/chat/data.php（to_info 付与）、includes/chat/scripts.php（To行・ポップアップ・送信時のmention_ids・表示用buildToChips）、api/messages.php（send/upload_file/edit の mention_ids と本文先頭付与・返却） |
| JS | assets/js/chat/to-selector.js（To選択モジュール） |
| CSS | assets/css/chat-main.css（.to-row-bar, .msg-to-chip 等） |
| DB | `message_mentions` テーブル（mention_type 'to'/'to_all'）は残す（既存メッセージの表示のため）。新規Toは「作り直し」で再登録。 |

---

## 2. フェーズ分けと進め方（小分け・記録しながら）

### Phase A: 仕様書の固定と記録
- **A1**: 本計画書を DOCS/TO_FEATURE_SPEC_AND_REBUILD_PLAN.md として保存する（仕様の言語化＋削除/作り直し手順を一覧化）。 → 完了
- **A2**: 上記ドキュメントに「削除チェックリスト」「再実装チェックリスト」を追記し、実施時にチェックを入れて記録する。 → 下記セクション参照

### Phase B: To機能の削除（影響を切る）
- **B1（フロント・UI）**: chat.php / includes/chat/scripts.php / to-selector.js のTo関連を削除または無効化。
- **B2（API）**: api/messages.php の mention_ids 受付・本文先頭付与・返却を削除。
- **B3（データ・表示）**: data.php の to_info は既存表示用に残すか最小限に。
- **B4（スタイル）**: chat-main.css の To 用スタイルを削除またはコメントアウト。
- **B5**: 各 DEPENDENCIES に「To機能を一時削除したこと」を記録。

### Phase C: To機能の再実装（シンプル版）
- **C1**: 送信は「本文先頭に [To:...] を挿入」に統一。API は本文からパースして message_mentions に保存。
- **C2**: Toボタン・To行バー・宛先選択を再追加（1か所・シンプルに）。
- **C3**: send は本文のみ受け取り、[To:all]/[To:ID] を正規表現で抽出して message_mentions に保存。
- **C4**: 表示は「本文の [To:ID] をTOチップに変換」に統一。to_info は data.php を流用。
- **C5**: 再実装内容を本ドキュメントと DEPENDENCIES に記録。

### Phase D: テストと記録
- **D1**: To未選択／To全員／To個別の送信確認。
- **D2**: 既存To付きメッセージの表示確認。
- **D3**: 実施ログを本ドキュメント末尾に追記。

---

## 3. 削除チェックリスト（Phase B 実施時）

| 項目 | 完了日 | メモ |
|------|--------|------|
| B1 chat.php: Toチップ表示・to-row-bar・Toボタン無効化 | 実施済 | to-row-bar/toBtn は display:none。表示は既存メッセージ用に残置 |
| B1 scripts.php: updateToRowBar, insertToMentionLine, Toポップアップ, chatSelectedToIds, buildToChipsFromMentionIds, mention_ids 付与 | 実施済 | mention_ids 送信なし。ペーストuploadもコメントアウト |
| B1 to-selector.js: 読み込み外すか no-op | 実施済 | chat.php でコメント内のため未読込 |
| B2 messages.php: send の mention_ids 受付・本文先頭付与削除 | 実施済 | mention_ids=[], upload/edit も同様 |
| B2 messages.php: upload_file / edit の mention 保存削除 | 実施済 | 同上 |
| B3 data.php: to_info は残すか null に（方針メモ） | 実施済 | 既存メッセージ表示のため to_info 付与はそのまま残置 |
| B4 chat-main.css: .to-row-bar, .msg-to-chip 等コメントアウト | 実施済 | .to-feature-removed で非表示。スタイルは再実装用に残置 |
| B5 includes/chat/DEPENDENCIES.md 記録 | 実施済 | To機能 Phase B 実施済みを追記 |
| B5 api/DEPENDENCIES.md 記録 | 実施済 | messages.php 行に既記載 |
| B5 assets/js/DEPENDENCIES.md 記録 | 実施済 | chat.js に既記載 |

---

## 4. 再実装チェックリスト（Phase C 実施時）

| 項目 | 完了日 | メモ |
|------|--------|------|
| C1 仕様確定: 本文先頭 [To:...] のみ、API は本文パース | 実施済 | 送信は本文のみ。API が本文から抽出して message_mentions に保存 |
| C2 クライアント: Toボタン・To行バー・宛先選択UI 再追加 | 実施済 | to-feature-removed 解除。openToSelector の早期 return 削除 |
| C3 API send: 本文から [To:all]/[To:(\d+)] 抽出 → message_mentions | 実施済 | api/messages.php send にパースブロック追加。upload_file は既存でパース済み |
| C4 表示: 本文の [To:ID] をTOチップに変換、to_info は data.php 流用 | 実施済 | chat.php の $renderToInfoChips と [To:ID] 変換は維持 |
| C5 DOCS/TO_FEATURE_SPEC_AND_REBUILD_PLAN.md 更新 | 実施済 | 本チェックリスト・実施ログを更新 |
| C5 各 DEPENDENCIES 更新 | 実施済 | Phase C 完了を追記 |

---

## 5. 実施ログ（Phase D および随時追記）

| 日付 | 内容 |
|------|------|
| （記録用） | Phase A 完了: 本ドキュメント作成・チェックリスト追加 |
| （記録用） | Phase B 完了: B1 入力To非表示・mention_ids送信なし・to-selector未読込。B2 APIはmention_ids空で処理。B3 data.phpはto_info維持。B4 CSSは再実装用に残置。B5 各DEPENDENCIESに記録済み。 |
| （記録用） | Phase B 完了: To機能一時削除（B1〜B5）。入力UI非表示・mention_ids 送信なし・to_info 表示は維持・DEPENDENCIES 記録 |
| （記録用） | Phase C 完了: To再実装（シンプル版）。API send/upload_file で本文から [To:all]/[To:ID] をパースして message_mentions に保存。chat.php で To ボタン・to-row-bar 再表示。scripts.php で openToSelector 有効化。表示は既存の to_info・チップ変換を維持。 |
| （記録用） | Phase D: To未選択／To全員／To個別の送信確認、既存To付きメッセージ表示確認は手動で実施。本ドキュメント・DEPENDENCIES に記録済み。 |
| （記録用） | **mention_type カラム欠如対応**: message_mentions に mention_type が無い環境で INSERT/SELECT が失敗し To が保存・表示されない問題を修正。api/messages.php に ensureMessageMentionsMentionType() を追加（カラムが無ければ ALTER で追加）。send/edit の INSERT を mention_type 有無で分岐（$hasMentionTypeCol）。includes/chat/data.php の enrichMessagesWithMentionsAndReactions を mention_type 有無で SELECT 分岐。get アクションは既に mention_type 有無で取得分岐済み。 |

