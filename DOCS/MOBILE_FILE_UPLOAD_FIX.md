# モバイルファイル添付・スクショ送信の改善記録

## 実施した修正（2026-02）

### 1. HTML応答の防止
- **interceptor.php**: 既に `/api/` リクエストは常にJSONで返す実装済み
- **scripts.php**: アイコン/アバター変更時の `fetch('/api/upload.php')` に `Accept: application/json` を追加
- **sendPastedImage**: 既に `Accept: application/json` 設定済み

### 2. HEIC対応（iOS標準形式）
- **api/upload.php**: `image/heic`, `image/heif` を許可リストに追加済み
- **api/upload.php**: `mime_content_type` が未対応時のフォールバック（拡張子 heic/heif で判定）を追加
- **api/messages.php**: `allowedExts` に `heic`, `heif` を追加
- **chat.php**: ファイル入力の `accept` に `image/heic,.heic,.heif` を追加

### 3. アップロードURLの絶対パス化
- **scripts.php**: `api/upload.php` → `/api/upload.php`、`api/messages.php` → `/api/messages.php`
- **media.js**: `api/upload.php` → `/api/upload.php`
- モバイル・PWA環境で相対パスが正しく解決されない問題を防止

### 4. エラーハンドリング強化
- **media.js**: `response.json()` の代わりに `response.text()` + `JSON.parse` で、HTMLエラーページが返った場合にユーザー向けメッセージを表示
- **scripts.php sendPastedImage**: 既にHTMLレスポンス時の分かりやすいエラーメッセージを実装済み

## 変更ファイル一覧

| ファイル | 変更内容 |
|----------|----------|
| `api/upload.php` | HEIC mime fallback、$extension 変数整理 |
| `api/messages.php` | allowedExts に heic/heif 追加 |
| `chat.php` | ファイル入力 accept に HEIC 追加 |
| `includes/chat/scripts.php` | Accept ヘッダ、絶対URL |
| `assets/js/chat/media.js` | 絶対URL、credentials、JSONパースエラー処理 |

## サーバー側で確認すべき設定

- **upload_max_filesize**: 10MB以上推奨（php.ini）
- **post_max_size**: 10MB以上推奨（php.ini）
- **memory_limit**: 128MB以上推奨
