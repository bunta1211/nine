# ログイン・認証まわりの根本調査レポート

ログイン関連のフォルダ・データ・フローを調査し、問題点と改善案を整理したドキュメントです。

---

## 1. 構成の整理

### 1.1 認証の入口

| 入口 | ファイル | 方式 | セッション設定 |
|------|----------|------|----------------|
| ログイン画面（フォーム） | `index.php` | POST → `Auth::login()` | config/session.php |
| API ログイン | `api/auth.php` | POST action=login | config/session.php |
| OTP ログイン | `api/auth_otp.php` | send_code → verify_code | config/session.php |
| Google ログイン | `api/google-login-auth.php` → `api/google-login-callback.php` | OAuth2 | config/session.php |

いずれも `config/session.php` を require しており、`start_session_once()` で同一のセッション設定（保存先 `tmp/sessions`、cookie 設定）が使われている。

### 1.2 パスワードリセットの二系統

| 系統 | トリガー | トークン保存先 | 使用ファイル |
|------|----------|----------------|--------------|
| A: フォーム「パスワードを忘れた」 | forgot_password.php | テーブル `password_reset_tokens` | forgot_password.php → reset_password.php |
| B: OTP 後のパスワード設定 | auth_otp verify_code 後 | `users.password_reset_token` / `password_reset_expires` | api/auth_otp.php set_password |

- **問題**: パスワードリセットが「テーブル」と「users カラム」の2通りに分かれており、運用・説明が分かりにくい。
- **推奨**: どちらか一方に統一するか、役割を明確に分けてドキュメント化する（例: フォーム＝`password_reset_tokens`、OTP 経由＝users カラムのみ、など）。

### 1.3 セッション保存

- **保存先**: `config/session.php` で `session_save_path(__DIR__ . '/../tmp/sessions')`。
- **tmp の保護**: `tmp/.htaccess` で `Deny from all` により HTTP アクセス不可。PHP はファイルとして読み書きするため問題なし。
- **.gitignore**: `tmp/sessions/*` が除外され、セッションファイルはリポジトリに含まれない。

---

## 2. 発見した問題と対応状況

### 2.1 修正済み

#### (1) OTP ログイン・パスワード設定後の `is_org_admin` が常に 0

- **内容**: `api/auth_otp.php` の `verifyCode` と `setPassword` で、`$_SESSION['is_org_admin'] = 0` と固定していたため、組織管理者が OTP や「パスワード設定」でログインすると一般ユーザー扱いになっていた。
- **対応**: `api/auth.php` の handleLogin と同様に、`fullUser['role']` から `is_org_admin` を算出するように変更済み。あわせて `organization_id` を `(int)` で統一。

#### (2) API ログアウトで online_status が更新されない

- **内容**: `api/auth.php` の `handleLogout` ではセッション破棄のみで、DB の `users.online_status` を `offline` にしていなかった。
- **対応**: ログアウト前に `user_id` を保持し、`session_destroy()` の後に `UPDATE users SET online_status = 'offline', last_seen = NOW() WHERE id = ?` を実行するよう変更済み。

### 2.2 要対応（推奨）

#### (1) OTP 認証コードの平文保存と error_log 出力

- **場所**: `api/auth_otp.php`
- **内容**:
  - `email_verification_codes.code` に平文で保存している（コメントに「問題特定後にハッシュに戻す」とあり）。
  - `error_log("OTP Code for {$email}: {$code} ...")` でコードがログに残る。
- **リスク**: DB 漏洩やログ閲覧で認証コードが露出する。
- **推奨**:
  - コードはハッシュ（例: `password_hash($code, PASSWORD_DEFAULT)`）で保存し、検証時は `password_verify($input, $stored)` を使用する。
  - 本番では認証コードを `error_log` に出力しない（`APP_DEBUG` 時のみなどに制限するか削除）。

#### (2) デバッグ用 API の本番無効化

- **ファイル**: `api/debug_login.php`, `api/debug_otp.php`
- **内容**: テーブル構造・カラム情報・環境情報を JSON で返しており、情報漏洩の元になりうる。
- **推奨**:
  - 本番ではこれらのファイルにアクセスできないようにする（例: `APP_ENV === 'production'` のときは 404 または 403 を返す）。
  - または Web サーバで `api/debug_*.php` へのアクセスを拒否する。

#### (3) パスワードリセットの二系統の整理

- **現状**: `password_reset_tokens` テーブル（forgot_password / reset_password）と、`users.password_reset_token` / `password_reset_expires`（OTP 経由）が併存。
- **推奨**:
  - 運用方針を決め、どちらを正とするか（または役割分担）を DOCS に明記する。
  - 必要なら「忘れた場合」も `users` カラムに統一する、あるいは OTP 側をテーブルに寄せるなど、将来的に一本化を検討する。

#### (4) getBaseUrl() とサブディレクトリ配置

- **内容**: `getBaseUrl()` は `APP_URL` が未定義のとき `scheme + HTTP_HOST` のみを返すため、アプリがサブディレクトリ（例: `/nine`）にあると、リダイレクト先が `/chat.php` のようにルートになり、意図した URL にならない。
- **推奨**: 本番・ステージングでは `config/app.local.php` 等で `APP_URL` を必ず設定する（例: `https://example.com/nine`）。ドキュメントに「サブディレクトリ運用時は APP_URL 必須」と記載する。

#### (5) ログイン試行の記録が index.php 経由では行われない

- **内容**: `api/auth.php` の handleLogin では `$security->recordLoginAttempt()` および `isAccountLocked()` を呼んでいるが、`index.php` から `Auth::login()` を使うフォームログインでは、Security クラスを使っていない。
- **推奨**: index.php の POST 処理でも、ログイン成功・失敗時に `Security::recordLoginAttempt` を呼び、アカウントロック判定（`isAccountLocked`）を行うと、攻撃検出・ロックが一貫する。

#### (6) ログインフォームの CSRF トークン

- **内容**: 現状、index.php のログインフォームに CSRF トークンは見当たらない。セッション固定化は `session_regenerate_id(true)` で緩和されているが、第三者送信対策はない。
- **推奨**: ログイン・パスワードリセット申請・OTP 送信など、状態を変えるフォームには CSRF トークンを導入する。

---

## 3. セキュリティまわり（良い点）

- **セッション**: `session.cookie_httponly` / `use_strict_mode` を有効化。HTTPS 時は `cookie_secure` を設定。
- **パスワード**: `password_hash(..., PASSWORD_DEFAULT)` と `password_verify` を使用。
- **ログイン失敗**: api/auth.php では IP ブロック・アカウントロック・試行記録（Security）を実施。
- **Google OAuth**: state パラメータで CSRF 検証を実施。
- **Interceptor**: ログインとは別レイヤーで、SQL インジェクション・XSS 等のパターン検出とブロックを実施。

---

## 4. 参照ファイル一覧（ログイン関連）

| 種別 | パス |
|------|------|
| セッション | config/session.php |
| 認証クラス | includes/auth/Auth.php |
| 認証ヘルパー | includes/auth.php |
| ログイン画面 | index.php |
| ログイン API | api/auth.php |
| OTP API | api/auth_otp.php |
| Google 認証 | api/google-login-auth.php, api/google-login-callback.php, config/google_login.php |
| パスワード忘れ | forgot_password.php, reset_password.php |
| セキュリティ | includes/security.php, includes/interceptor.php |
| デバッグ（要制限） | api/debug_login.php, api/debug_otp.php |

---

## 5. まとめ

- **今回の修正**: OTP/パスワード設定後の `is_org_admin` と `organization_id` の正しい設定、API ログアウト時の `online_status` 更新を実施済み。
- **優先して対応したい点**: OTP コードの平文保存・error_log 出力の廃止、デバッグ API の本番無効化、パスワードリセット二系統の整理とドキュメント化。
- **中期的に検討**: index.php 経由ログインへの Security 連携、ログイン系フォームへの CSRF トークン導入、サブディレクトリ時の APP_URL 明示のルール化。

以上を踏まえ、必要に応じて順次対応することを推奨します。
