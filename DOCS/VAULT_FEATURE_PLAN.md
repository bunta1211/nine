# メモページ「金庫」機能 計画書

## 1. 概要

### 1.1 目的
メモページ内に**金庫（Vault）**を設け、重要なパスワード・メモ・ファイルを保管する。金庫へのアクセスは**顔認証**または**指紋認証**で行い、第三者に内容を見られないようにする。

### 1.2 対象ユーザー
- Social9 にログイン済みのユーザー
- パスワードや証明書画像など、機密性の高い情報を一箇所で管理したいユーザー

### 1.3 提供価値
- **集中管理**: 重要なパスワード・ファイルをメモと同一画面で管理できる
- **生体認証**: 端末の顔・指紋で開錠し、他人に開けられにくくする
- **既存メモとの分離**: 通常メモはそのまま、金庫内のみ特別に保護する

---

## 2. 用語・前提

| 用語 | 説明 |
|------|------|
| **金庫** | 暗号化されて保存される、パスワード・メモ・ファイルの保管領域（1ユーザー1金庫） |
| **開錠** | 生体認証（または代替手段）に成功し、金庫の中身を閲覧・編集できる状態にすること |
| **生体認証** | ブラウザ/OS が提供する顔認証（Face ID / Windows Hello 等）または指紋認証（Touch ID 等）。本計画では **WebAuthn** で利用する |

---

## 3. 機能要件

### 3.1 金庫で保管できるもの
- **パスワード・秘密のメモ**: タイトル・URL・ユーザー名・パスワード・備考などのテキスト
- **ファイル**: 画像・PDF 等の重要書類（容量上限は別途検討。例: 1件 5MB、合計 50MB まで）

### 3.2 認証方式（開錠方法）
- **第一候補: 顔認証 / 指紋認証**  
  - 端末が対応していれば、OS の顔認証（Face ID / Windows Hello 等）または指紋認証（Touch ID 等）で開錠する。
  - ブラウザでは **WebAuthn（Web Authentication API）** の「プラットフォーム認証子」で実現する。
- **代替手段（必須）**  
  - 生体認証が使えない端末・ブラウザ向けに、**金庫用マスターパスワード（PIN）** で開錠できるようにする。
  - 初回のみ「金庫のマスターパスワードを設定」し、以後はそのパスワードまたは生体で開錠する。

### 3.3 セキュリティ要件
- 金庫内データは**サーバー上で暗号化して保存**（AES-256-GCM 等）する。
- 暗号化キーは**ユーザーが設定したマスターパスワードからクライアント側で導出**し、サーバーには平文のマスターパスワードを送らない。
- 生体認証は**端末内で完結**（WebAuthn）し、生体データをサーバーに送らない。
- 開錠後は**セッションまたはメモリ上で一時的に復号**し、一定時間で自動ロックする。

---

## 4. 技術方針

### 4.1 生体認証（顔・指紋）の実現方法
- **WebAuthn（Web Authentication API）** を利用する。
  - **プラットフォーム認証子**（端末内の生体センサー）を利用し、対応環境では「顔」または「指紋」で認証できる。
  - 対応例: Safari（iOS/macOS）の Face ID / Touch ID、Chrome/Edge の Windows Hello（顔・指紋・PIN）、Android の指紋/顔認証。
- フロー概要:
  1. 金庫利用開始時に、**WebAuthn で認証子を登録**（create）する（オプション。マスターパスワードは必須で設定）。
  2. 開錠時: **WebAuthn で assertion（get）** を取得し、サーバーに送信。
  3. サーバーは assertion を検証し、成功したら**金庫の暗号化キー（またはセッション用トークン）を返す**か、**復号済みデータを一時的に返す**。

### 4.2 暗号化・鍵管理
- **クライアント側**:
  - マスターパスワードから **PBKDF2 または Argon2** で鍵を導出。
  - 金庫の「マスターキー」をこの鍵でラップ（暗号化）してサーバーに保存する方式、または
  - 各アイテムをマスターパスワード導出鍵で暗号化し、暗号文のみサーバーに保存する方式のいずれかを採用。
- **サーバー側**:
  - 金庫用テーブルには**暗号文（Ciphertext）のみ**を保存。平文のパスワードや鍵は保存しない。
  - 開錠リクエスト時に、WebAuthn 検証成功またはマスターパスワード検証成功時のみ、**一時トークン**を発行し、そのトークンで金庫 API にアクセス可能にする。

### 4.3 データベース
- **新規テーブル案**:
  - `user_vaults`: ユーザーごとの金庫メタデータ（作成日、マスターキーをラップした値の保存先など）
  - `vault_items`: 金庫内アイテム（タイトル、種類=パスワード/メモ/ファイル、暗号化された payload、IV 等）
  - `vault_webauthn_credentials`: WebAuthn 認証子 ID と credentialId の対応（生体で開錠するために必要）
- 既存の `memos` テーブルは変更せず、金庫は別テーブルで管理する。

### 4.4 API
- 金庫用の API を新設（例: `api/vault.php` または `api/vault/` 配下）。
  - 例: 開錠（生体 or パスワード）、ロック、一覧取得、アイテム追加・更新・削除、ファイルアップロード・ダウンロード。
- 開錠前は「金庫がロックされている」旨のみ返し、中身は返さない。

#### 4.4.1 API エンドポイント一覧（案）

| メソッド | パス / action | 説明 | 認証 |
|----------|----------------|------|------|
| GET/POST | `vault.php?action=status` | 金庫の有無・ロック状態を返す | ログインセッション |
| POST | `vault.php?action=setup` | 金庫を初回作成（マスターパスワード設定） | ログインセッション |
| POST | `vault.php?action=unlock` | マスターパスワードで開錠、一時トークン発行 | ログインセッション + 本文にパスワード（ハッシュ等） |
| POST | `vault.php?action=webauthn_register_options` | WebAuthn 登録の challenge 取得 | ログインセッション |
| POST | `vault.php?action=webauthn_register` | WebAuthn 認証子登録完了 | ログインセッション |
| POST | `vault.php?action=webauthn_assert_options` | WebAuthn 認証の challenge 取得 | ログインセッション |
| POST | `vault.php?action=webauthn_unlock` | WebAuthn assertion で開錠、一時トークン発行 | ログインセッション |
| POST | `vault.php?action=lock` | 手動ロック（トークン無効化） | 金庫トークン |
| GET | `vault.php?action=list` | 金庫内アイテム一覧（復号済み） | 金庫トークン |
| POST | `vault.php?action=item_add` | アイテム追加（パスワード/メモ） | 金庫トークン |
| POST | `vault.php?action=item_update` | アイテム更新 | 金庫トークン |
| POST | `vault.php?action=item_delete` | アイテム削除 | 金庫トークン |
| POST | `vault.php?action=file_upload` | ファイルアップロード（Phase 3） | 金庫トークン |
| GET | `vault.php?action=file_download&id=` | ファイルダウンロード（Phase 3） | 金庫トークン |

- 金庫トークン: 開錠成功時に発行する短期トークン（例: 有効期限 5〜15 分、HTTP-only Cookie または Authorization ヘッダー）。

#### 4.4.2 データベーススキーマ（案）

```sql
-- ユーザーごとの金庫メタデータ（1ユーザー1行）
CREATE TABLE user_vaults (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL UNIQUE,
  wrapped_key_b64 VARCHAR(512) COMMENT 'マスターキーをマスターパスワード導出鍵でラップした値（Base64）',
  salt_b64 VARCHAR(128) COMMENT '鍵導出用ソルト',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- WebAuthn 認証子（生体で開錠するために登録した credential）
CREATE TABLE vault_webauthn_credentials (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  credential_id_b64 VARCHAR(512) NOT NULL,
  public_key_b64 TEXT NOT NULL,
  sign_count INT UNSIGNED DEFAULT 0,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uk_user_cred (user_id, credential_id_b64(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 金庫内アイテム（パスワード・メモ・ファイル参照）
CREATE TABLE vault_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  item_type ENUM('password','memo','file') NOT NULL DEFAULT 'memo',
  title_encrypted BLOB COMMENT 'タイトル（暗号化）',
  payload_encrypted LONGBLOB COMMENT '本文またはメタ（暗号化）',
  iv_b64 VARCHAR(64) COMMENT 'IV（Base64）',
  file_path VARCHAR(512) NULL COMMENT 'ファイルの場合の保存パス（Phase 3）',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY idx_user_order (user_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- 実際の暗号化はクライアントで行い、`title_encrypted` / `payload_encrypted` / `iv_b64` をサーバーに送る方式、またはサーバー側でマスターキーを一時復元して暗号化する方式のいずれかを採用する。

#### 4.4.3 WebAuthn フロー概要（テキスト）

**登録フロー（生体を金庫開錠に紐づける）**
1. クライアント: `vault.php?action=webauthn_register_options` で challenge 取得
2. クライアント: `navigator.credentials.create()` でプラットフォーム認証子を作成（端末が顔/指紋を要求）
3. クライアント: 作成結果を `vault.php?action=webauthn_register` に送信
4. サーバー: credentialId と publicKey を検証し、`vault_webauthn_credentials` に保存

**開錠フロー（生体で開く）**
1. クライアント: `vault.php?action=webauthn_assert_options` で challenge 取得
2. クライアント: `navigator.credentials.get()` で assertion 取得（端末が顔/指紋を要求）
3. クライアント: assertion を `vault.php?action=webauthn_unlock` に送信
4. サーバー: assertion を検証し、金庫トークンを発行して返す

---

## 5. UI/UX 案

### 5.1 メモページでの入口
- メモ一覧またはメモページのヘッダー／サイドに「**金庫**」ボタンまたはアイコンを配置。
- タップで「金庫」画面に遷移。

### 5.2 金庫画面
- **ロック時**:
  - 金庫のイラストまたはアイコンと、「顔・指紋で開く」／「パスワードで開く」の選択。
  - 「顔・指紋で開く」選択時は WebAuthn の get を呼び出し、生体認証プロンプトを表示。
  - 「パスワードで開く」選択時はマスターパスワード入力欄を表示。
- **初回のみ**:
  - 「金庫を有効にする」→ マスターパスワードの設定 → （オプション）「生体認証も使う」で WebAuthn 登録。
- **開錠後**:
  - パスワード・メモ・ファイルの一覧と、追加・編集・削除。
  - 一定時間（例: 5分）無操作で自動ロック。手動ロックボタンも用意。

### 5.3 アクセシビリティ
- 生体認証が使えない・使いたくないユーザー向けに、必ずマスターパスワードでの開錠を用意する。
- 画面読み上げやキーボード操作でも、パスワード入力で開錠できるようにする。

---

## 6. 実装フェーズ案

| フェーズ | 内容 | 目安 |
|----------|------|------|
| **Phase 0** | 計画書の確定、技術検証（WebAuthn の登録・認証の PoC） | 1〜2 週間 |
| **Phase 1** | 金庫の枠だけ作成（DB・API・「金庫」入口）。開錠は**マスターパスワードのみ**。パスワード/メモの保存・一覧・編集・削除。 | 2〜3 週間 |
| **Phase 2** | WebAuthn による生体認証（顔・指紋）の登録と開錠を追加。 | 2 週間 |
| **Phase 3** | 金庫内でのファイル保管（アップロード・ダウンロード・容量制限）。 | 2 週間 |
| **Phase 4** | 自動ロック時間の設定、監査ログ（開錠履歴）等の運用・強化。 | 1〜2 週間 |

---

## 7. リスクと対策

| リスク | 対策 |
|--------|------|
| マスターパスワード忘れ | 金庫は「本人のみ」が前提のため、パスワードリセットは提供しない方針を明示。忘れた場合は金庫内データは復旧できない旨を利用規約・UI で案内。 |
| 生体認証が使えない環境 | 常にマスターパスワードでの開錠を用意。WebAuthn 非対応ブラウザでは生体オプションを非表示にする。 |
| サーバー侵害 | 金庫内は暗号文のみ保存し、鍵はクライアントで導出。サーバーが漏洩しても中身は解読困難にする。 |
| 紛失・盗難 | 端末にログイン済みでも、金庫は別途開錠が必要。自動ロック時間を短くするオプションを提供。 |

---

## 8. 代替案・今後の検討

- **生体のみで開錠（パスワードなし）**: 利便性は高いが、端末初期化や乗り換え時に金庫にアクセスできなくなるリスクがある。現状は「マスターパスワード必須＋生体オプション」を推奨。
- **パスワードマネージャー連携**: 将来的にエクスポート形式（CSV/JSON）を用意し、他ツールへ移行しやすくする検討は可能。
- **2要素認証（2FA）との統合**: 金庫開錠時にアプリの 2FA を要求するオプションは、Phase 4 以降の検討候補。

---

## 9. 参照

- [WebAuthn (Web Authentication API)](https://developer.mozilla.org/en-US/docs/Web/API/Web_Authentication_API)
- [WebAuthn のプラットフォーム認証子（生体認証）](https://www.w3.org/TR/webauthn-2/#platform-authenticator)
- 既存: `memos.php`（メモページ）、`api/memos.php`（メモ API）
- 設計規格: `DOCS/STANDARD_DESIGN_SPEC.md`

### 9.1 実装時の依存関係（想定）

- **フロント**: WebAuthn 利用のため、対応ブラウザの確認（Chrome, Safari, Edge, Firefox の最新版）。必要に応じて [@simplewebauthn/browser](https://github.com/SimpleWebAuthn/SimpleWebAuthn) 等のヘルパーライブラリを検討。
- **バックエンド**: PHP で WebAuthn 検証を行う場合は [web-auth/webauthn-lib](https://github.com/web-auth/webauthn-lib) 等の利用を検討。暗号化は OpenSSL（AES-256-GCM）または Sodium を利用。
- **DB**: 上記スキーマのマイグレーションを `database/migration_vault_*.sql` として追加。

---

## 10. ドキュメント履歴

| 日付 | 内容 |
|------|------|
| 2026-02-23 | 初版作成（金庫・顔認証・指紋認証の計画書） |
| 2026-02-23 | 継続追記: API エンドポイント一覧、DB スキーマ（SQL）、WebAuthn 登録/開錠フロー、実装時依存関係（9.1） |
