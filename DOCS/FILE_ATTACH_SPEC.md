# ファイル送信の統一規格

パソコン・携帯・AI秘書で同じ送信フローに統一し、変更を一箇所で行えるようにするための規格です。

---

## 1. 統一フロー（全端末・全コンテキスト共通）

```
[添付ボタン／シート選択] → ファイル選択ダイアログ → ユーザーが1件選択
    → onAttachFileSelected(files) が呼ばれる
    → showFilePreview(files[0]) でプレビュー画面を表示
    → ユーザーが「送信」を押す → sendPastedImage() が実行
    → 宛先に応じて API 分岐（AI: api/upload.php + api/ai.php / グループ: api/messages.php upload_file）
```

- **1件送信**: 複数選択されていても先頭1件のみプレビュー・送信する。
- **プレビュー**: 共通の `#pastePreview` / `#pastePreviewBackdrop`（`ensurePastePreviewElements` で未作成時は自動作成）。

---

## 2. 入口の統一

| 入口 | 呼び出し方 | 備考 |
|------|------------|------|
| 通常グループの ⊕ ボタン | `openUnifiedAttachFilePicker({ imageOnly: isMobile })` | PC=全種対応、携帯=画像のみ |
| AI秘書の ⊕ ボタン | `openUnifiedAttachFilePicker({ imageOnly: true })` | 画像のみ |
| 携帯の添付シート「最近使用したファイル」 | `unifiedAttachInput` の accept をドキュメント用に設定して click、または `recentFileInput` の onchange で `onAttachFileSelected(this.files)` | 同一プレビュー・送信フローに合流 |
| 携帯の添付シート「カメラ・写真・動画」 | `openUnifiedAttachFilePicker({ imageOnly: true })` | 画像のみ |
| ドラッグ＆ドロップ / Ctrl+V 貼り付け | 直接 `showFilePreview(files[0])`（`_pendingFiles = []` 済み） | プレビュー以降は同じ |

いずれも「ファイルが選ばれたら」**必ず** `onAttachFileSelected(files)` を経由し、その後は **常に** `showFilePreview` → 「送信」で `sendPastedImage()` とする。

---

## 3. 共通定数・関数（scripts.php 内で一元定義）

| 名前 | 役割 |
|------|------|
| `ATTACH_ACCEPT_IMAGE` | 画像のみの accept 文字列（携帯・AI秘書で使用） |
| `ATTACH_ACCEPT_ALL` | 画像＋PDF・Office・動画等の accept 文字列（PC グループで使用） |
| `onAttachFileSelected(files)` | ファイル選択時の共通ハンドラ。`_pendingFiles = []` のうえで `showFilePreview(files[0])` を呼ぶ。全入口からここに集約する。 |
| `openUnifiedAttachFilePicker(opts)` | 統一ファイル input の `accept` を設定して `click()`。`opts.imageOnly === true` のとき画像のみ、否则は ALL。 |
| `unifiedAttachInput` | body 直下の単一 `<input type="file">`。グループ・AI・携帯シートで共有。 |

---

## 4. 送信 API 分岐（sendPastedImage 内）

- **AI秘書モード** (`isAISecretaryActive()` が true):  
  `api/upload.php` でアップロード → `sendAIMessage(メッセージ, path)`（画像のみ対応）。
- **通常グループ**:  
  `api/messages.php` の `action=upload_file` で 1 件送信（画像は `compressImageForUpload` 済み）。

プレビュー表示・キャンセル・TO 選択などの UI は共通。変更する場合は `showFilePreview` / `showPastePreviewUI` / `sendPastedImage` を触る。

---

## 5. 変更時のポイント

- **accept を変えたい**: `ATTACH_ACCEPT_IMAGE` / `ATTACH_ACCEPT_ALL` のみ変更。
- **プレビュー文言・項目を変えたい**: `ensurePastePreviewElements` の innerHTML と `showPastePreviewUI` を変更。
- **送信先ロジックを変えたい**: `sendPastedImage` 内の AI 分岐とグループ分岐のみ変更。
- **新たな入口を追加したい**: その入口から `openUnifiedAttachFilePicker` または `onAttachFileSelected` を呼ぶ。
