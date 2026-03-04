# 金庫機能 実装進捗ログ

計画書: DOCS/MEMO_VAULT_WEBAUTHN_PLAN.md（および Cursor プラン）

## 完了済み

- **DB マイグレーション** `database/migration_vault.sql`
  - `vault_sessions`, `vault_items`, `webauthn_credentials`
- **WebAuthn バックエンド** `api/webauthn.php`
  - register_options / register_verify / auth_options / auth_verify
  - composer.json に `lbuchs/webauthn` 追加済み（サーバーで `composer install` 要）
- **金庫暗号化ヘルパー** `includes/VaultCrypto.php`（AES-256-GCM）
- **金庫 API** `api/vault.php`
  - list / get / create / update / delete（X-Vault-Token 検証）
- **メモページに金庫ボタン** `memos.php`
  - ヘッダーに「🔐 金庫」ボタン
  - 開錠モーダル（WebAuthn 認証呼び出し）
  - 金庫中身モーダル（一覧・追加フォーム内蔵）
- **金庫 UI / 初回登録フロー** `assets/js/memo-vault.js`
  - WebAuthn 初回登録（registerVaultWebAuthn）
  - 一覧・開く・削除・追加フォーム
  - sessionStorage でトークン管理

## デプロイ時の注意

1. `composer install` をサーバーで実行（lbuchs/webauthn 導入）
2. `database/migration_vault.sql` をサーバー DB に実行
3. 本番では `config/app.local.php` に `VAULT_MASTER_KEY` を安全な値で設定
4. WebAuthn は HTTPS が必須（localhost は例外）
5. **金庫の保存には VAULT_MASTER_KEY が必須**。未設定だと「VAULT_MASTER_KEY が設定されていません」となり保存できない。開錠は各ユーザーのログインパスワードのみ。本番では次のいずれかで設定する:
   - **config/vault.local.php** を用意（`config/vault.local.example.php` をコピーし、キーを置き換え）。推奨。
   - **config/app.local.php** に `define('VAULT_MASTER_KEY', '32文字以上のランダム文字列');` を追加
   - サーバーの環境変数 **VAULT_MASTER_KEY** を設定
   - キー生成例: `openssl rand -hex 32`

## ファイル一覧

| ファイル | 役割 |
|---|---|
| `database/migration_vault.sql` | テーブル作成 |
| `api/webauthn.php` | WebAuthn 登録・認証 |
| `api/vault.php` | 金庫 CRUD |
| `includes/VaultCrypto.php` | AES-256-GCM 暗号化・復号 |
| `assets/js/memo-vault.js` | フロントエンド（開錠・一覧・登録） |
| `memos.php` | 金庫ボタン・モーダル HTML |
| `config/app.php` | VAULT_MASTER_KEY 定数 |

## 更新履歴

- 2026-02-23: DB マイグレーション作成
- 2026-02-23: api/webauthn.php 作成
- 2026-02-23: includes/VaultCrypto.php, api/vault.php 作成
- 2026-02-23: memos.php に金庫ボタン・モーダル追加、assets/js/memo-vault.js 作成
- 2026-02-23: memo-vault.js に初回 WebAuthn 登録フロー追加、追加フォーム内蔵化
- 2026-02-23: 金庫「編集」機能追加（一覧に編集ボタン、追加フォーム兼用で update API 呼出し）
- 2026-02-23: api/webauthn.php 503 対応。lbuchs 名前空間（\lbuchs\WebAuthn\WebAuthn）、getCreateArgs 引数順・pack('N')、base64url→バイナリデコード（register_verify / auth_verify）を修正
- 2026-02-23: api/webauthn.php に webauthnBase64urlDecode 追加済み。rpId をドメインのみに正規化（ポート除去）

- 2026-02-23: options.publicKey を参照するよう memo-vault.js 修正（challenge/user.id/allowCredentials/excludeCredentials）
- 2026-02-23: webauthn.php で crossPlatformAttachment=false（端末の顔・指紋優先）、複数 attestation 形式許可、processCreate 緩和、Throwable 捕捉・ログ、register_verify ログ追加
- 2026-02-23: memo-vault.js で登録失敗時に data.message を表示。webauthn.php の catch は常に 400 でメッセージ返却
- 2026-02-23: memos.php に「別の方法で保存」の案内を追加済み

### 503 / 400 / 500 対応メモ（本番で金庫認証が失敗した場合）

- **原因**: lbuchs/webauthn の名前空間は `\lbuchs\WebAuthn\WebAuthn`。options は `data.options.publicKey` 内に challenge 等がある。
- **対応**: api/webauthn.php でクラス名・getCreateArgs 引数・base64url デコード・複数 attestation 形式・processCreate 緩和・platform 優先。memo-vault.js で pubKey 参照と data.message 表示。
- **デプロイ**: 修正した `api/webauthn.php` と `assets/js/memo-vault.js` を WinSCP で再アップロード
- **ログ確認**: サーバーで `WebAuthn register_verify:` および `WebAuthn API:` を error_log で確認

### 金庫の開錠をパスワードに変更（2026-02-23）

- 顔・指紋認証（WebAuthn）は廃止。金庫は **Social9 ログインパスワード** で開錠する方式に変更。
- `api/vault.php` に `action=unlock` を追加（パスワード検証 → vault_sessions 発行）。
- `memos.php` の開錠モーダルはパスワード入力＋「開く」ボタンのみ。
- `memo-vault.js` から WebAuthn 関連を削除し、`unlockVaultWithPassword()` のみ使用。`api/webauthn.php` は金庫では未使用（削除してもよい）。

### 金庫デプロイ時に送るファイル（WinSCP）

| ローカル | リモート |
|----------|----------|
| `api/webauthn.php` | `/var/www/html/api/` |
| `api/vault.php` | `/var/www/html/api/` |
| `includes/VaultCrypto.php` | `/var/www/html/includes/` |
| `assets/js/memo-vault.js` | `/var/www/html/assets/js/` |
| `memos.php` | `/var/www/html/` |
| `database/migration_vault.sql` | `/var/www/html/database/` |
| `composer.json` | `/var/www/html/` |
