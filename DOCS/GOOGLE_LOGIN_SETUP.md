# Googleログイン設定ガイド

Social9でGoogleアカウントでのログインを有効にする手順です。

## 前提条件

- Google Cloud Console でプロジェクトが作成済み
- 本番環境では HTTPS が必須

## 設定手順

### 1. Google Cloud Console で OAuth 2.0 クライアントIDを作成

1. [Google Cloud Console](https://console.cloud.google.com/) にアクセス
2. プロジェクトを選択（または新規作成）
3. **APIとサービス** → **認証情報** を開く
4. **認証情報を作成** → **OAuth クライアント ID** を選択
5. アプリケーションの種類: **ウェブアプリケーション**
6. **承認済みのリダイレクト URI** に以下を追加:
   - 本番: `https://social9.jp/api/google-login-callback.php`
   - ローカル: `http://localhost/nine/api/google-login-callback.php`

### 2. OAuth 同意画面の設定

1. **OAuth 同意画面** で必要なスコープを設定
2. 必要なスコープ: `email`, `profile`, `openid`

### 3. 認証情報の設定

**オプション A: Googleカレンダーと同じ credentials を使用（推奨）**

既に `config/google_calendar.local.php` で Client ID/Secret を設定している場合、そのまま利用できます。

認証情報のリダイレクトURIに以下を**追加**してください:
- `https://あなたのドメイン/api/google-login-callback.php`

**オプション B: 別の OAuth クライアントを使用**

`config/google_login.local.php` を作成:

```php
<?php
define('GOOGLE_LOGIN_CLIENT_ID', 'xxxxx.apps.googleusercontent.com');
define('GOOGLE_LOGIN_CLIENT_SECRET', 'GOCSPX-xxxxx');
```

### 4. データベースマイグレーションの実行

```bash
mysql -u ユーザー名 -p データベース名 < database/migration_google_login.sql
```

## 動作確認

1. ログイン画面 (`index.php`) に「Googleでログイン」ボタンが表示されることを確認
2. クリックして Google 認証画面へ遷移することを確認
3. 認証後、チャット画面へリダイレクトされることを確認

## トラブルシューティング

| エラー | 原因 | 対処 |
|-------|------|------|
| **403: disallowed_useragent** / アクセスをブロック | アプリ内ブラウザ（WebView）で開いている | **Chrome や Safari で social9.jp を開き直して**から「Googleでログイン」を試す。ログイン画面の案内文のとおりに操作すること。 |
| Googleログインは設定されていません | Client ID/Secret が未設定 | google_calendar.local.php または google_login.local.php を確認 |
| state_mismatch | セッション切れ、または複数タブ | 一度ログイン画面に戻り、再度試行 |
| invalid_callback | リダイレクトURIの不一致 | Google Console のリダイレクトURIを確認 |
| google_no_email | メールアドレス取得に失敗 | OAuth 同意画面で email スコープを許可 |

### アプリ内ブラウザで「アクセスをブロック」と表示される場合

Google は「安全なブラウザ」ポリシーのため、**アプリ内ブラウザ（WebView）** からの OAuth ログインをブロックすることがあります（エラー 403: disallowed_useragent）。

**対処:** ユーザーには「Chrome」「Safari」など、端末の**通常のブラウザ**で https://social9.jp を開いてもらい、その画面から「Googleでログイン」を実行してもらってください。ログイン画面にはその旨の案内を表示しています。

## アップロードするファイル一覧

- `config/google_login.php`
- `config/google_login.local.example.php`
- `api/google-login-auth.php`
- `api/google-login-callback.php`
- `database/migration_google_login.sql`
- `index.php`（修正分）
- `config/DEPENDENCIES.md`
- `api/DEPENDENCIES.md`
- `database/DEPENDENCIES.md`
