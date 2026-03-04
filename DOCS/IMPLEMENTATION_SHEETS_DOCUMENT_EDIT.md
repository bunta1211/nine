# スプレッドシート・Excel/Word 編集機能 実装メモ

## 実装済み

### Google スプレッドシート
| ファイル | 役割 |
|---------|------|
| `database/migration_google_sheets_accounts.sql` | 連携アカウント用テーブル |
| `config/google_sheets.php` | 設定（Client ID/Secret） |
| `config/google_sheets.local.example.php` | ローカル設定例 |
| `api/google-sheets-auth.php` | OAuth 開始 |
| `api/google-sheets-callback.php` | OAuth コールバック |
| `api/sheets-edit.php` | 編集API（POST: spreadsheet_id, instruction） |
| `api/sheets-disconnect.php` | 連携解除（存在確認済み） |
| `includes/google_sheets_helper.php` | 読み書き・トークン更新 |
| `includes/gemini_helper.php` | `geminiParseSheetEditInstruction()` |

### Excel/Word（Social9 内ファイル）
| ファイル | 役割 |
|---------|------|
| `api/document-edit.php` | 編集API（POST: file_id, instruction） |
| `includes/document_edit_helper.php` | 読み書き・権限チェック |
| `includes/gemini_helper.php` | `geminiParseExcelEditInstruction()`, `geminiParseWordEditInstruction()` |

### 依存関係
- **composer**: `phpoffice/phpspreadsheet`, `phpoffice/phpword` は `suggest` に記載。  
  `ext-gd` がない環境では `composer require phpoffice/phpspreadsheet --ignore-platform-reqs` で導入可能。

## 残タスク（小分け）

1. ~~**設定画面にスプレッドシート連携を追加**~~ — 済。
2. ~~**AI秘書から編集を呼び出す**~~ — 済。`AI_SHEETS_INSTRUCTIONS` / `AI_DOCUMENT_INSTRUCTIONS` をプロンプトに追加。`[SHEETS_EDIT:...]` / `[DOCUMENT_EDIT:...]` をフロントで検出し API 呼び出し。
3. ~~**DEPENDENCIES.md**~~ — api/DEPENDENCIES.md に追記済み。

## 検証時の修正（実施済み）

- **document_edit_helper.php**: `getEditableFilePath` で DB の `file_path` が `uploads/...` のとき、`UPLOAD_DIR` と二重にならないよう `uploads/` プレフィックスを除去。Word 置換の不要な `toUTF8` 呼び出しを削除。
- **settings.php**: スプレッドシート設定のリダイレクトURI表示を `getGoogleSheetsRedirectUri()` で統一（APP_URL 未定義時も表示されるように）。
- **google-sheets-callback.php**: マイグレーション読み込み失敗時にインラインで `google_sheets_accounts` テーブルを作成するフォールバックを追加。

## 使い方

- **スプレッドシート**: 設定で Google 連携後、AI秘書に「このスプレッドシート（URLを貼る）のA1に〇〇を入れて」と指示。または `api/sheets-edit.php` に POST（spreadsheet_id は URL の `/d/SPREADSHEET_ID/` 部分）。
- **Excel/Word**: ユーザーがアップロードしたファイル（files テーブル）の id を指定し、AI秘書に「ファイルID 123 のExcelのA1に売上を入れて」などと指示。または `api/document-edit.php` に POST（file_id, instruction）。
