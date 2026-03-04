# config/ 設定ファイル 依存関係

このディレクトリには、アプリケーションの設定ファイルが含まれています。

## ファイル一覧

| ファイル | 役割 | 変更時の影響 |
|---------|------|-------------|
| `app.php` | アプリケーション基本設定 | 🔴 全機能に影響 |
| `app.local.php` | アプリ設定（ローカル上書き） | 🔴 全機能に影響 |
| `app.local.example.php` | ローカル設定サンプル | なし |
| `database.php` | データベース接続設定 | 🔴 全機能に影響 |
| `session.php` | セッション設定 | 🔴 認証に影響 |
| `ai_config.php` | AI機能設定（キャラクター・記憶・リマインダー・カレンダー・改善提案聞き取り指示・**AI利用料金表用単価**） | 🟡 AI機能のみ |
| `ai_config.local.php` | AI設定（ローカル上書き） | 🟡 AI機能のみ |
| `push.php` | プッシュ通知設定 | 🟡 通知のみ |
| `google_calendar.php` | GoogleカレンダーOAuth設定 | 🟡 カレンダー連携のみ |
| `google_calendar.local.example.php` | カレンダー設定サンプル | なし |
| `google_login.php` | GoogleログインOAuth設定 | 🟡 ログインのみ |
| `google_login.local.example.php` | Googleログイン設定サンプル | なし |
| `show_mysql_connection.php` | DB設定から MySQL 接続コマンド・icon_style マイグレーション用コマンドを表示（CLI・コピー用） | なし |
| `sms.php` | SMS送信設定（SMS_DRIVER: log / twilio / sns） | 🟡 認証コード送信のみ |
| `sms.local.example.php` | SMSローカル設定サンプル（Twilio/AWS SNS） | なし |
| `storage.php` | 共有フォルダ設定（S3接続、容量制限、**STORAGE_UNLIMITED_ORGANIZATION_IDS**・**STORAGE_UNLIMITED_QUOTA** で組織別無制限、全銀データ委託者情報） | 🟡 共有フォルダ機能のみ |
| `storage.local.php` | 保管庫設定（ローカル上書き） | 🟡 保管庫機能のみ |

## ローカル設定ファイルの使い方

環境固有の設定は `*.local.php` ファイルで上書きできます。

```bash
# 1. サンプルをコピー
cp app.local.example.php app.local.php

# 2. 環境に合わせて編集
# app.local.php で APP_ENV, APP_DEBUG などを設定
```

ローカル設定ファイルは `.gitignore` に含まれているため、
各環境で独自の設定を持つことができます。

---

## app.php

### 提供する定数/変数

| 名前 | 用途 | 例 |
|-----|------|-----|
| `APP_NAME` | アプリ名 | "Social9" |
| `APP_URL` | ベースURL | "https://social9.jp" |
| `APP_ENV` | 環境 | "production" / "development" |
| `APP_DEBUG` | デバッグ・エラー表示 | true / false。本番では false で display_errors=0 |
| `TODAY_TOPICS_LIMIT_USER_IDS` | 今日の話題の配信対象限定（JSON配列文字列）。未定義または空で全員対象 | `'[6]'`（KENのみ）。DOCS/TODAY_TOPICS_PHASED_ROLLOUT.md |

### 主な関数

| 名前 | 用途 |
|-----|------|
| `getBaseUrl()` | リダイレクト用ベースURL取得 |
| `is_mobile_request()` | 携帯・スマートフォンからのリクエストか（User-Agent ベース）。携帯版ではグループチャット一覧がトップのため index/chat で使用 |
| `formatDatetimeForClient()` | MySQL日時をクライアント用 ISO 8601 に変換 |

### 依存しているファイル

このファイルは他に依存していません（最上位設定）。

### このファイルに依存しているもの

- `includes/api-bootstrap.php`
- `includes/auth.php`
- ほぼ全てのPHPファイル

---

## database.php

### 提供する定数

| 名前 | 用途 |
|-----|------|
| `DB_HOST` | ホスト名 |
| `DB_NAME` | データベース名 |
| `DB_USER` | ユーザー名 |
| `DB_PASS` | パスワード |
| `DB_CHARSET` | 文字セット |

### このファイルに依存しているもの

- `includes/db.php` - PDO接続を作成

### 本番環境との違い

```php
// ローカル環境
define('DB_HOST', 'localhost');

// 本番環境
define('DB_HOST', 'mysql.social9.jp');
```

**注意**: このファイルはGitにコミットしないでください（.gitignore推奨）

---

## session.php

### 設定内容

| 設定 | 用途 |
|-----|------|
| `session.save_path` | セッションファイル保存先 |
| `session.cookie_lifetime` | クッキー有効期限（全デバイスで SESSION_LIFETIME、常時ログオン） |
| `session.gc_maxlifetime` | セッション最大寿命 |

- 全クライアント（アプリ・PC・携帯）でセッションは `SESSION_LIFETIME`（app.php で 30 日）まで維持。
- 自動ログアウトは「しない」(0) を全員のデフォルトとし、選択肢は「自動ログアウトしない」と「24時間でログアウト」(1440分) の2つのみ。0/1440 以外の既存値はセッション・表示で 0 に正規化。本番で一括 0 にする場合は `database/migration_auto_logout_force_off.sql` を実行。

### 主な関数（認証・権限）

- `requireOrgAdmin()` … 組織管理者以上を要求。グローバルな管理者（role / is_org_admin）に加え、**現在選択中の組織のオーナー/管理者**（`current_org_id` + `current_org_role` が owner または admin）も許可する。

### このファイルに依存しているもの

- `includes/auth.php`
- `includes/api-bootstrap.php`

---

## ai_config.php（AI利用料金表用定数）

管理画面「利用料請求」「AI使用量」の料金表で使用します。請求単価 = 弊社コスト × `AI_BILLING_MARKUP_RATE`（既定 1.2）。

| 定数 | 用途 | 既定値 |
|------|------|--------|
| `AI_BILLING_MARKUP_RATE` | マージン率 | 1.2 |
| `AI_CHAT_COST_JPY_PER_1K_CHARS` | AI秘書 1,000文字あたり（円） | 1 |
| `AI_TASK_MEMO_SEARCH_COST_JPY_PER_REQUEST` | タスク検索 1回あたり（円） | 1 |
| `PLACES_API_COST_JPY_PER_REQUEST` | Places API 1リクエストあたり（円）。0で非表示 | 0 |
| `SMS_COST_JPY_PER_MESSAGE` | SMS 1通あたり（円）。0で非表示 | 0 |
| `MAIL_COST_JPY_PER_MESSAGE` | メール 1通あたり（円）。0で非表示 | 0 |

翻訳単価は `OPENAI_INPUT_COST_PER_1M` / `OPENAI_OUTPUT_COST_PER_1M` と `USD_TO_JPY_RATE` から算出。

---

## 設定変更時のチェックリスト

### database.php を変更する場合
- [ ] 本番/ステージング/ローカル環境の違いを確認
- [ ] 接続テスト実施
- [ ] 認証情報を安全に管理（環境変数推奨）

### app.php を変更する場合
- [ ] `APP_URL` が正しいか確認
- [ ] `DEBUG_MODE` が本番で false になっているか確認

### session.php を変更する場合
- [ ] セッション保存先のパーミッションを確認
- [ ] 既存セッションへの影響を確認

---

## storage.php（共有フォルダ・組織別無制限）

| 定数 | 用途 | 既定値 |
|------|------|--------|
| `STORAGE_UNLIMITED_ORGANIZATION_IDS` | ストレージ容量を無制限とする組織IDの配列。例: Clover International = 6。本番は `storage.local.php` で上書き可 | `[6]` |
| `STORAGE_UNLIMITED_QUOTA` | 無制限扱い時の quota_bytes に使う値（実質的に上限チェックを通さない） | `PHP_INT_MAX` |

参照: `includes/storage_s3_helper.php` の `getStorageSubscription()`、`api/storage.php` の `get_usage`、`cron/storage_usage_check.php`、`admin/storage_billing.php`。
