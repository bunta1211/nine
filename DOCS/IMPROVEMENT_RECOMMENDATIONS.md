# ホームページ改善推奨（効果の高い順）

目的：エラー削減・バグ防止・効率化・ユーザビリティ・セキュリティ強化

---

## 実施済み（2026-02頃）

- [x] 1.1 ログインページの XSS 対策（index.php）
- [x] 1.2 デバッグ・テスト API の本番アクセス制限（.htaccess）
- [x] 2.1 chat.php の filemtime の安全性（assetVersion ヘルパー導入）
- [x] 2.3 本番環境での display_errors 無効化確認（config/app.php）

---

## 1. セキュリティ（高優先度）

### 1.1 ログインページの XSS 対策（index.php） ✅実施済み

**現状**: `showAlert()` が API レスポンス（`data.error`）を `innerHTML` にそのまま挿入している。  
サーバーから返るエラーメッセージに HTML/スクリプトが含まれると XSS の可能性がある。

**該当箇所**: `index.php` 575–577 行目

```javascript
// 現状（危険）
alertArea.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
```

**推奨**: メッセージを必ずエスケープする、または `textContent` で表示する。

```javascript
function showAlert(message, type = 'error') {
    const alertArea = document.getElementById('alertArea');
    const div = document.createElement('div');
    div.className = `alert alert-${type}`;
    div.textContent = message;
    alertArea.innerHTML = '';
    alertArea.appendChild(div);
}
```

---

### 1.2 デバッグ・テスト API の本番アクセス制限

**現状**: 以下が本番でもアクセス可能。

- `api/debug_login.php`
- `api/debug_otp.php`
- `api/test-helper.php`

**推奨**: `.htaccess` で本番環境のみアクセスを禁止する。

```apache
# 本番では APP_ENV=production のときのみ有効にする想定
# .htaccess では環境判定が難しいため、代替案として:
RewriteRule ^api/debug_login\.php$ - [F,L]
RewriteRule ^api/debug_otp\.php$ - [F,L]
RewriteRule ^api/test-helper\.php$ - [F,L]
```

---

### 1.3 error-log.php のアクセス制限 ✅実施済み

**対応**: 管理者アクション（resolve, resolve_all）にログイン必須チェックを追加。未ログイン時は 401 を返却。

---

## 2. バグ防止・エラー削減

### 2.1 chat.php の filemtime の安全性

**現状**: `filemtime('assets/css/common.css')` を参照。  
ファイルが存在しない場合、`filemtime()` は `false` を返し、`date()` に渡すと予期しない値になる。

**該当**: `chat.php` の CSS/JS 読み込み部分

**推奨**: 存在チェックを入れる。

```php
<?php
function assetVersion($path) {
    $fullPath = __DIR__ . '/' . $path;
    return file_exists($fullPath) ? filemtime($fullPath) : time();
}
?>
<link rel="stylesheet" href="assets/css/common.css?v=<?= assetVersion('assets/css/common.css') ?>">
```

---

### 2.2 API ブートストラップの統一

**現状**: `messages.php` などは独自の require チェーンで、`api-bootstrap.php` を使っていない。  
そのため、セキュリティチェック（IPブロック・迎撃）や共通エラーハンドリングが適用されない。

**推奨**: 可能な API は `api-bootstrap.php` を利用するよう段階的に移行する。

---

### 2.3 Guild の display_errors

**現状**: `Guild/index.php` では `display_errors` の明示的な設定なし。  
PHP の `display_errors` が on の場合、本番で内部エラーが画面に表示される可能性がある。

**推奨**: `config/app.php` または `php.ini` で本番では `display_errors = Off` を徹底する。

---

## 3. 効率化

### 3.1 アセット読み込みの最適化

**現状**: 多数の CSS/JS が個別に読み込まれている。

**推奨**:

- 本番では可能なものを 1 ファイルに結合・圧縮（ビルドツール使用）
- 当面は、不要な `test-runner.js` や `page-inspector.js` を本番では読み込まない（`APP_DEBUG` で既に制御済み）

---

### 3.2 404 ページの統一 ✅実施済み

**対応**: `404.php` を新規作成。`.htaccess` に `ErrorDocument 404 /404.php` を追加。トップ・チャットへのリンクを表示。

---

## 4. ユーザビリティ

### 4.1 フォーム検証の強化

**現状**: ログイン・OTP・パスワード設定フォームで、クライアント側の簡易チェックはあるが、一部項目で不足の可能性がある。

**推奨**: 必須項目や形式の検証を統一し、エラーメッセージを明確にする。  
例：メール形式、パスワード強度、OTP 桁数など。

---

### 4.2 エラーメッセージの分かりやすさ

**現状**: 一部で「サーバーエラーが発生しました」など汎用的なメッセージのみ。

**推奨**: 状況に応じたメッセージを返す（例：ネットワークエラー、タイムアウト、再試行を促す文言など）。

---

## 5. 実装優先度まとめ

| 優先度 | 項目 | 効果 | 工数 |
|--------|------|------|------|
| 高 | 1.1 ログインページ XSS 対策 | セキュリティ | 小 |
| 高 | 1.2 デバッグ API アクセス禁止 | セキュリティ | 小 |
| 高 | 2.1 filemtime の安全性 | バグ防止 | 小 |
| 中 | 2.2 API ブートストラップ統一 | バグ防止・一貫性 | 中 |
| 中 | 2.3 display_errors 本番無効 | エラー非表示 | 小 |
| 中 | 3.2 404 ページの統一 | UX | 小 |
| 低 | 4.1 フォーム検証強化 | UX | 中 |
| 低 | 4.2 エラーメッセージ改善 | UX | 中 |

---

## 6. 実施済みの修正（2026-02）

1. **index.php**: `showAlert()` を `textContent` で表示するよう変更済み
2. **.htaccess**: `debug_login.php`、`debug_otp.php`、`test-helper.php` へのアクセスを禁止済み
3. **chat.php**: `asset_helper.php` の `assetVersion()` で `filemtime` を安全に利用済み
4. **config/app.local.production.php**: `APP_ENV`、`APP_DEBUG` を追加し、本番で `display_errors` が無効になるよう設定済み
5. **404.php**: 存在しない URL 用のカスタム 404 ページを新規作成。`.htaccess` に `ErrorDocument 404` を追加
6. **api/error-log.php**: 管理者アクションにログイン必須チェックを追加
7. **config/session.php**: `X-Forwarded-Proto` 対応を追加し、リバースプロキシ経由の HTTPS でも secure クッキーを有効化
8. **API ブートストラップ統一**: messages, conversations, upload, tasks, settings, notifications, memos, calls, users, friends を api-bootstrap に移行
