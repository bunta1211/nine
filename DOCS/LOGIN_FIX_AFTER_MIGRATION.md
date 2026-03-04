# サーバー移転後のログイン不具合 修正メモ

**関連:** 移転で使えなくなる可能性がある機能の一覧と改善計画は [MIGRATION_ISSUES_AND_PLAN.md](./MIGRATION_ISSUES_AND_PLAN.md) を参照。

## 目的
移転後に「ログインできない」ユーザーが多数出ている問題を解消する。

## 想定原因
1. **セッション保存先** … 本番で PHP デフォルト(/tmp)のみで、パスや権限が移転先で不整合
2. **api/auth.php** … `session_start()` を直接使用しており、`config/session.php` のクッキー設定（Secure/Path 等）と不統一
3. **リダイレクト** … 相対URLのため、移転先ドメイン/サブディレクトリでずれる可能性

## 修正タスク一覧

| # | 内容 | 状態 | 対象ファイル |
|---|------|------|--------------|
| 1 | 本番でもセッション保存パスを明示し永続化 | 済（既存実装で tmp/sessions 共通） | config/session.php |
| 2 | api/auth.php を session.php に統一 | 済 | api/auth.php |
| 3 | ログイン後リダイレクトを絶対URLに | 済 | index.php, api/auth.php, Auth.php |
| 4 | requireLogin() のリダイレクトを絶対URLに | 済 | config/session.php |
| 5 | check_access_route() のリダイレクトを絶対URLに | 済 | config/session.php |
| 6 | 自動ログアウト時リダイレクトを絶対URLに | 済 | config/session.php |
| 7 | デプロイ用スクリプト ensure_tmp_sessions.sh | 済 | scripts/ensure_tmp_sessions.sh |

---

## 実装済みの内容

- **config/session.php** … 本番・ローカルとも `tmp/sessions` を保存先に使用（要ディレクトリ作成・書込可）
- **api/auth.php** … `config/session.php` を require し `start_session_once()` 使用。セッションキーは Auth クラスと同一。`getBaseUrl()` でリダイレクト先を絶対URLに。
- **index.php** … ログイン済み時・パスワードログイン成功時のリダイレクトを `getBaseUrl()` で絶対URLに。
- **Auth.php** … `session_regenerate_id(true)`、`auth_level` / `is_minor` / `is_org_admin` / `avatar` / `last_activity` をセッションに設定。
- **config/session.php requireLogin()** … 未ログイン時は `getBaseUrl()` があれば絶対URLで index.php へリダイレクト（移転後も同一オリジンへ）。
- **config/session.php check_access_route()** … 未ログイン時リダイレクトを絶対URLに統一。
- **config/session.php checkAutoLogout()** … タイムアウト時のリダイレクトを絶対URLに統一。
- **scripts/ensure_tmp_sessions.sh** … 本番で `tmp/sessions` を自動作成するスクリプト（デプロイ後1回実行可）。

---

## 本番サーバー（AWS 等）での確認事項

1. **tmp/sessions の作成と権限**  
   セッション保存先が存在し、Web サーバーから書けること。
   ```bash
   # 手動の場合
   mkdir -p /var/www/html/tmp/sessions
   chown apache:apache /var/www/html/tmp/sessions   # または nginx:nginx 等
   chmod 0770 /var/www/html/tmp/sessions

   # または scripts を使用（プロジェクトルートで実行）
   chmod +x scripts/ensure_tmp_sessions.sh
   sudo ./scripts/ensure_tmp_sessions.sh
   ```
2. **APP_URL**  
   `config/app.local.php` または環境変数で、本番のベースURL（例: `https://social9.jp`）が設定されていること。
3. **HTTPS 時の X-Forwarded-Proto**  
   リバースプロキシ利用時は、`X-Forwarded-Proto: https` が PHP に渡るようにすること（セッションクッキー Secure 付与のため）。

---

## 次に進められること（小分け）

- [x] **タスク5** … `check_access_route()` のリダイレクトを絶対URLに … 済
- [x] **タスク6** … 自動ログアウト時のリダイレクトを絶対URLに … 済
- [x] **タスク7** … デプロイ用スクリプト `scripts/ensure_tmp_sessions.sh` … 済

---

## アップロード対象ファイル一覧（移転後ログイン対応）

一括アップロードする場合は次のファイルを本番に反映してください。

| ファイル | 変更内容 |
|----------|----------|
| `config/session.php` | タスク4〜6: requireLogin / check_access_route / checkAutoLogout のリダイレクトを絶対URLに |
| `scripts/ensure_tmp_sessions.sh` | タスク7: 本番で tmp/sessions 作成用スクリプト（新規） |
| `DOCS/LOGIN_FIX_AFTER_MIGRATION.md` | 本メモ（任意） |

**本番で実施すること:**  
1. 上記ファイルをアップロード  
2. `tmp/sessions` が存在・書込可能であることを確認（手動または `scripts/ensure_tmp_sessions.sh` 実行）  
3. `APP_URL`（または app.local.php）が本番のベースURLになっていることを確認  

---
*最終更新: タスク5〜7 完了*
