# チャット ファイル・スクショ送信の改善

## 実施した修正（2025-02）

### 1. multipart リクエスト時の php://input 読み込みをスキップ
**ファイル**: `includes/api-bootstrap.php`

- multipart/form-data の場合は `php://input` を読まないように変更
- 携帯など一部環境で `$_FILES` が空になる問題を防止

### 2. ファイルアップロード時の fetch URL を相対パスに
**ファイル**: `includes/chat/scripts.php`

- `/api/messages.php` → `api/messages.php`（相対URL）
- `/api/upload.php` → `api/upload.php`（AI秘書画像送信時）
- サブディレクトリ配置時も正しく動作

### 3. HTML エラーレスポンスの検出を強化
**ファイル**: `includes/chat/scripts.php`

- `text.includes('<!DOCTYPE')` と `text.includes('<html')` で HTML 判定を強化
- エラーメッセージに「ファイルサイズは10MB以下にしてください」を追加

---

## サーバー側で確認すべき設定

スクショ・ファイル送信が失敗する場合、以下を確認してください。

### PHP 設定（php.ini / .user.ini）

```ini
upload_max_filesize = 10M
post_max_size = 12M
```

- `post_max_size` は `upload_max_filesize` より大きくすること
- 携帯のカメラ画像は 3〜8MB になることがあるため、10MB 以上を推奨

### Apache .htaccess で設定する場合

```apache
# アップロード制限（AllowOverride FileInfo が必要）
php_value upload_max_filesize 10M
php_value post_max_size 12M
```

---

## アップロード対象ファイル一覧

| パス | 内容 |
|------|------|
| `includes/api-bootstrap.php` | multipart 時の php://input スキップ |
| `includes/chat/scripts.php` | fetch URL・エラー表示の改善 |
